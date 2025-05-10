<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log; // Optional: for logging

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user(); // Consider using stateless() for APIs

            // Find or create the user in your database
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Ensure required fields are present if your User model expects more
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(), // Optional: Store Google ID
                    'email_verified_at' => now(), // Mark email as verified for Google users
                    'password' => Hash::make(Str::random(24)), // Random password
                ]);
                 Log::info('New user created via Google Login.', ['user_id' => $user->id, 'email' => $user->email]);
            } else {
                // Optional: Update Google ID or other details if user already exists
                // if (!$user->google_id) {
                //     $user->update(['google_id' => $googleUser->getId()]);
                // }
                Log::info('Existing user logged in via Google Login.', ['user_id' => $user->id, 'email' => $user->email]);
            }

            // Log in the user within the Laravel session (might not be needed for pure API)
            // Auth::login($user); // You might skip this if only relying on Sanctum tokens

            // --- FIX IS HERE ---
            // Generate a token for the user and get the plain text version
            $newToken = $user->createToken('GoogleToken'); // Returns NewAccessToken object
            $plainTextToken = $newToken->plainTextToken; // Access the plain text token string
            // --- END FIX ---

            // Redirect to the frontend with the PLAIN TEXT token
            // Ensure the base URL for the frontend is correct
            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/'); // Use config or default
            $redirectUrl = $frontendUrl . "/login?token=" . $plainTextToken;

            Log::info('Redirecting to frontend after Google login.', ['url' => $redirectUrl]);

            return redirect()->away($redirectUrl);

        } catch (\Exception $e) {
            Log::error('Google Login Failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Log trace for debugging
            ]);
             $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
            // Redirect back to frontend login with an error message
            return redirect()->away($frontendUrl . "/login?error=" . urlencode('Google login failed. Please try again.'));
        }
    }
}