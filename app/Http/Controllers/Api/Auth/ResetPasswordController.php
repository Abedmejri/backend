<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRules; // For password complexity rules

class ResetPasswordController extends Controller
{
    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(Request $request)
    {
        // 1. Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRules::defaults()], // Use default complexity rules, requires confirmation field
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // 2. Attempt to reset the password using the broker
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                // This closure is executed *after* the token is verified but *before* the password reset is finalized
                // It updates the user's password in the database
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60)); // Optionally reset remember token

                $user->save();

                event(new PasswordReset($user)); // Fire the PasswordReset event
            }
        );

        // 3. Check the status and return appropriate JSON response
        if ($status == Password::PASSWORD_RESET) {
            // Password was successfully reset
            return response()->json(['message' => trans($status)], 200); // e.g., "Your password has been reset!"
        }

        // If reset failed (e.g., invalid token, invalid email)
        return response()->json(['message' => trans($status)], 400); // e.g., "This password reset token is invalid."

    }
}