<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\SignupRequest;
use App\Models\User;
use http\Env\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function signup(SignupRequest $request)
{
    $data = $request->validated();
    /** @var \App\Models\User $user */
    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => bcrypt($data['password']),
        'role' => $data['role'] ?? 'user', // Default role is 'user'
    ]);

    $token = $user->createToken('main')->plainTextToken;
    return response(compact('user', 'token'));
}

public function login(LoginRequest $request)
{
    $credentials = $request->validated();
    if (!Auth::attempt($credentials)) {
        return response([
            'message' => 'Provided email or password is incorrect'
        ], 422);
    }

    /** @var \App\Models\User $user */
    $user = Auth::user();
    $token = $user->createToken('main')->plainTextToken;
    return response(compact('user', 'token'));
}

public function logout(Request $request)
{
    /** @var \App\Models\User | null $user */ // Add | null type hint for safety
    $user = $request->user();

    // Check if a user was actually authenticated via the token
    if ($user) {
        // --- Add this block ---
        // Set last_seen_at to null to immediately mark as offline
        $user->last_seen_at = null;
        $user->save(); // This should trigger the UserObserver which fires the event
        // --- End added block ---

        // Invalidate the current Sanctum token
        $user->currentAccessToken()->delete();

        // Return success response (204 No Content is fine, or 200 with message)
        return response()->json(['message' => 'Logged out successfully.'], 200);
        // return response('', 204); // Your original 204 is also okay
    }

    // If somehow the request reaches here without an authenticated user
    // (e.g., middleware issue or expired token already deleted)
    return response()->json(['message' => 'Unauthorized or no active session.'], 401);
}
    public function forgotPassword(Request $request)
{
    $request->validate(['email' => 'required|email']);

    $status = Password::sendResetLink(
        $request->only('email')
    );

    return $status === Password::RESET_LINK_SENT
        ? response()->json(['message' => __($status)])
        : response()->json(['message' => __($status)], 422);
}
}
