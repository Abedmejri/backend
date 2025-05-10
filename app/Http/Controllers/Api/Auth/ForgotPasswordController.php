<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    /**
     * Send a reset link to the given user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendResetLinkEmail(Request $request)
    {
        // 1. Validate the email input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email', // Ensure email exists in users table
        ]);

        if ($validator->fails()) {
             // Return validation errors specifically for the email field if it exists
             if ($validator->errors()->has('email')) {
                return response()->json(['message' => $validator->errors()->first('email')], 422);
             }
             // Generic validation error if something else went wrong (unlikely here)
             return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // 2. Attempt to send the password reset link
        $status = Password::broker()->sendResetLink(
            $request->only('email')
            // Important: You might need a custom Notification to generate the *frontend* URL
            // See Step 4 below.
        );

        // 3. Check the status and return appropriate JSON response
        if ($status == Password::RESET_LINK_SENT) {
             return response()->json(['message' => trans($status)], 200); // e.g., "We have emailed your password reset link!"
        }

        // If sending failed (e.g., user not found, though validation should catch this)
        // Or if throttled
        // The 'exists:users,email' validation makes 'invalid user' less likely here
        // But throttling is possible.
         return response()->json(['message' => trans($status)], 400); // e.g., "Unable to send password reset link." or Throttling message
    }
}