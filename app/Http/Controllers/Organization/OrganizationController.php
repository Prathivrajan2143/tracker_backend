<?php
 
namespace App\Http\Controllers\Organization;
 
use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\LoginType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TemporaryCredential;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use phpseclib3\Crypt\AES;

use function Symfony\Component\String\b;

class OrganizationController extends Controller
{
 
    /**
     * Decrypt a given encrypted string using AES encryption.
     *
     * @param string $encryptedString
     * @return string
     */
 
    public function decryptString($encryptedString)
    {
       
         // Retrieve the secret key from the environment file
        // $key = 'your-secret-key-16-bytes';
        $key = 'frittersgypsysaf';
 
        // Decode the Base64-encoded ciphertext
        $ciphertext = base64_decode($encryptedString);
 
        // Create AES object
        $aes = new AES('ECB');
        $aes->setKey($key);
 
        // Decrypt and Return the data
        return $aes->decrypt($ciphertext);
    }
 
 
 
    /**
     * Handle the organization invitation process.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function invite(Request $request)
    {

        // Decrypt the Request Data
        $decrypted_name = $this->decryptString($request->name);
        $decrypted_domain_name = $this->decryptString($request->domain_name);
        $decrypted_admin_name = $this->decryptString($request->admin_name);
        $decrypted_admin_email = $this->decryptString($request->admin_email);
        $decrypted_login_type = $this->decryptString($request->login_type);
        $decrypted_sso_provider = $this->decryptString($request->sso_provider);

        // Validate decrypted data
        $validator = Validator::make([
            'name' => $decrypted_name,
            'domain_name' => $decrypted_domain_name,
            'admin_name' => $decrypted_admin_name,
            'admin_email' => $decrypted_admin_email,
            'login_type' => $decrypted_login_type,
        ], [
            'name' => 'required|string|max:255',
            'domain_name' => 'required|string|max:255|unique:organizations,domain_name',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'login_type' => 'required|string|max:255',
        ]);
        
        // When Validation Faild
        if ($validator->fails()) {

            // Fetch all errors and return response
            return response()->json($validator->errors()->all(), 422);
        }

        // Sanitize and prepare data
        $name = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $decrypted_name));
        $domain_name = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $decrypted_domain_name));
        $admin_name = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $decrypted_admin_name));
       
        try {

            // Begin Transaction
            DB::beginTransaction();

            // Create a new organization record
            $organization = Organization::create([
                'name' => $name,
                'domain_name' => $domain_name,
            ]);
            
            // Find admin role ID
            $role_id = Role::where('name', 'admin')->value('id');
                        
            // Create a Organization as User
            $user = User::create([
                'organization_id' => $organization->id,
                'role_id' => $role_id,
                'name' => $admin_name,
                'email' => $decrypted_admin_email,
            ]);
                    
            // Generate a temporary password and its expiration time
            $temporaryPassword = preg_replace('/[^A-Za-z0-9\-]/', '', Str::random(10));
            $password_expires_at = Carbon::now()->addMinutes(60)->format('Y-m-d H:i:s');
                    
            // Create a Temporary Creadiantials for new organization
            TemporaryCredential::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'temporary_password' => Crypt::encrypt($temporaryPassword),
                'password_expires_at' => $password_expires_at,
            ]);
            
            // Create users Login Type
            LoginType::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'domain_name' => $domain_name,
                'login_type' => $decrypted_login_type,
                'sso_provider' => $decrypted_sso_provider,
            ]);

            // Commit		
            DB::commit();

            // Generate the invite URL
            $inviteUrl = URL::temporarySignedRoute(
                'invite.handle',
                now()->addMinutes(60),
                ['domain' => $organization->domain_name]
            );
 
            // Set the custom base URL
            $baseUrl = 'http://localhost:3000';
            $inviteUrl = Str::replaceFirst(config('app.url').':8085', $baseUrl, $inviteUrl);

            // Send the invite email
            Mail::to($user->email)->send(new \App\Mail\OrganizationInvite($inviteUrl, $temporaryPassword, $admin_name));

            // Return a success response
            return response()->json([
                'success' => true,
                'message' => 'Organization created successfully',
                'data' => $organization,
            ], 201);
                
        } catch (Exception $e) {
                
                // Roll Back
                DB::rollBack();
                
                // Log the exception for further debugging
                Log::error('Failed in organization invite: ' . $e->getMessage());
        
                // Return a user-friendly error response
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to invite the organization at this time. Please try again later.',
                ], 500);
            }  
    }
 
 
 
    /**
     * Retrieve a list of organizations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function getOrganizations()
    {
        try {

            // Get All Organizations   
            $organizations = Organization::select(
                'organizations.id', 
                'organizations.name as organization_name', 
                'organizations.domain_name'
                )
                ->with(['users' => function ($query) {
                    $query->select(
                        'users.organization_id', 
                        'users.name', 
                        'users.email', 
                        'roles.name as role_name'
                        )
                        ->join('roles', 'users.role_id', '=', 'roles.id');
                }])->get();

            // Return organizations query response
            return response()->json([
                'success' => true,
                'data' => $organizations,
            ], 200);
 
        } catch (Exception $e) {
 
           // Log the exception for further debugging
            Log::error('Failed to retrieve organizations: ' . $e->getMessage());
 
            // Return a user-friendly error response
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch organizations at this time. Please try again later.',
            ], 500);
        }
    }
 
 
 
    /**
     * Validate the invitation URL.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function validateInvite(Request $request)
    {

        // Check if the URL is valid
        if (!$request->hasValidSignature()) {
            try {
 
                //  Get Data From Organizations Table
                $get_organization = Organization::with('temporaryCrediantials')
                    ->where('domain_name', $request->domain)
                    ->first();   
            }
            catch (Exception $e) {
 
                // Log the exception for further debugging
                Log::error('Failed to retrieve organizations: ' . $e->getMessage());
 
                // Return a user-friendly error response
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to fetch organizations at this time. Please try again later.',
                ], 500);
 
            }

            if ($get_organization && $get_organization->temporaryCrediantials->isNotEmpty()) {

                // Assuming you want the first temporary credential's expiration time
                $temporary_credential = $get_organization->temporaryCrediantials->first();

                // Check if the current time is past the expiration time
                if (Carbon::now()->gt(Carbon::parse($temporary_credential->password_expires_at))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invitation has expired.',
                    ], 401);
                }
            } else {
                // Handle cases where no credentials are found or the organization doesn't exist
                return response()->json(['error' => 'No temporary credentials found or organization does not exist.'], 404);
            }

            // If no data found in organization
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired URL.',
            ], 401);
        }
 
        // If validation success
        return response()->json([
            'success' => true,
            'message' => 'Valid',
        ], 200);
    }
 
 
 
    /**
     * Handle temporary login process.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function temporaryLogin(Request $request)
    {
        // Decrypt the email and password
        $decryptedEmail = $this->decryptString($request->email);
        $decryptedPassword = $this->decryptString($request->password);
 
        // Validate decrypted data
        $validator = Validator::make([
            'email' => $decryptedEmail,
            'password' => $decryptedPassword,
        ], [
            'email' => 'required|email',
            'password' => 'required',
        ]);
 
        // When Validation Faild
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }
 
        // try {
           
            // Retrive data from organizations table
            // $orgData = Organization::where('admin_email', $decryptedEmail)
            //     ->first(['id', 'admin_email', 'otp', 'otp_expires_at', 'temporary_password', 'password_expires_at']);

         return   $organiztionData = User::select('email')
                ->where('email', $decryptedEmail)
                ->with(['temporaryCrediantials' => function ($query) {
                    $query->select(
                        'temporary_password', 
                        )
                        ->join('roles', 'users.role_id', '=', 'roles.id');
                }])
                ->get();
 
        // } catch (Exception $e) {
 
        //     // Log the exception for further debugging
        //     Log::error('Failed to retrieve organizations: ' . $e->getMessage());
 
        //     // Return a user-friendly error response
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Unable to fetch organizations at this time. Please try again later.',
        //     ], 500);
        // }
 
        // Check if the current time is past the expiration time
        if (Carbon::now()->gt(Carbon::parse($orgData->password_expires_at))) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation has expired.',
            ], 401);
        }
 
        // Validate temporary password
        if ($decryptedPassword === Crypt::decrypt($orgData->temporary_password) && $decryptedEmail === $orgData->admin_email) {
 
            // Generating OTP and creating otp expiry time with 15 mins
            $otp = rand(10000000, 100000000);
            $otp_expires_at = Carbon::now()->addMinutes(60)->format('Y-m-d H:i:s');
 
            try {
 
                // Send OTP email
                Mail::to($orgData->admin_email)->send(new OtpMail($otp));
 
                $orgData->otp = $otp;
                $orgData->otp_expires_at = $otp_expires_at;
                $orgData->save();
 
                return response()->json([
                    'success' => true,
                    'message' => 'OTP sent successfully',
                ], 200);
 
            } catch (Exception $e) {
 
                // Log the exception for further debugging
                Log::error('Failed in sending otp in mail: ' . $e->getMessage());
 
                // Return a user-friendly error response
                return response()->json([
                    'success' => false,
                    'message' => 'We were unable to send the OTP. Please check your email or try again later.',
                ], 500);
            }
        }
 
        return response()->json([
            'success' => false,
            'message' => 'Invalid credentials',
        ], 401);
    }
 
 
 
    /**
     * Resend the temporary login OTP.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function resendTemporaryLoginOtp(Request $request)
    {
       
        // Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
 
        // When Validation Faild
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }
 
        try {
 
            //  Retrieve Data From Organizations Table
            $get_organization = Organization::where('admin_email', $request->email)
                ->first(['admin_email', 'otp', 'otp_expires_at']);
 
        } catch (Exception $e) {
 
            // Log the exception for further debugging
            Log::error('Failed to retrieve organizations: ' . $e->getMessage());
 
            // Return a user-friendly error response
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch organizations at this time. Please try again later.',
            ], 500);
        }
 
        // Check if the current time is past the expiration time
        if (Carbon::now()->gt(Carbon::parse($get_organization->password_expires_at))) {
            return response()->json([
                'success' => false,
                'message' => 'Password has expired.',
            ], 401);
        }
 
        try {
 
            // Resend the OTP email
            Mail::to($get_organization->admin_email)->send(new OtpMail($get_organization->otp));
 
            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'data' => $get_organization->otp,
            ], 200);
 
        } catch (Exception $e) {
 
             // Log the exception for further debugging
             Log::error('Failed in Resending otp in mail: ' . $e->getMessage());
 
             // Return a user-friendly error response
             return response()->json([
                 'success' => false,
                 'message' => 'We were unable to resend the OTP. Please check your email or try again later.',
             ], 500);
        }
    }
 
 
   
    /**
     * Verify the temporary login OTP.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
 
    public function verifyTemporaryLoginOtp(Request $request)
    {
 
        // Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|digits:8', // Ensures OTP is exactly 8 digits long
        ]);
 
        // When Validation Faild
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }
 
        try {
 
            //  Retrieve Data From Organizations Table
            $get_organization = Organization::where('admin_email', $request->email)
                // ->where('otp', $request->otp)
                ->first(['id', 'otp', 'otp_expires_at']);
 
        } catch (Exception $e) {
 
            // Log the exception for further debugging
            Log::error('Failed to retrieve organizations: ' . $e->getMessage());
 
            // Return a user-friendly error response
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch organizations at this time. Please try again later.',
            ], 500);
        }
        // Check if the OTP is expired
        if (Carbon::now()->gt(Carbon::parse($get_organization->otp_expires_at))) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
            ], 401);
        }
 
        // Check if the OTP is valid
        if ($get_organization->otp == $request->otp) {
 
            // Changing the OTP Value and clear the expiration
            $get_organization->otp = 1;
            $get_organization->otp_expires_at = null;
            $get_organization->save();
 
            // Sending success with organization id
            return response()->json([
                'success' => true,
                'id' => $get_organization->id,
            ]);
 
        } else if ($get_organization->otp == 1) {   // If OTP is already verified
 
            return response()->json([
                'success' => true,
                'message' => 'OTP already verified',
            ]);
 
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP',
            ]);
        }
    }
 
 
}
 