<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache; // Optional: for throttling updates

class UpdateUserActivity
{
    public function handle(Request $request, Closure $next)
    {
        // Process the request first
        $response = $next($request);

        // Check if a user is authenticated after the request
        if (Auth::check()) {
            $user = Auth::user();
            $now = Carbon::now();

            // --- OPTION 1: Update on every request (simpler, more DB writes) ---
            // $user->last_seen_at = $now;
            // $user->save(); // Use normal save() to trigger observers/events

            // --- OPTION 2: Throttle updates using Cache (reduces DB writes) ---
            $cacheKey = "last_activity_{$user->id}";
            $lastUpdate = Cache::get($cacheKey);

            // Update if never updated or if last update was >= 1 minute ago
            if (!$lastUpdate || Carbon::parse($lastUpdate)->diffInMinutes($now) >= 1) {
                // Fetch fresh instance to avoid potential stale data issues if needed,
                // but often updating Auth::user() directly is okay if observer handles it well.
                $user->last_seen_at = $now;
                $user->save(); // Use save() to trigger potential observers/events

                // Store the timestamp of this update in cache for 1 minute (or desired throttle time)
                Cache::put($cacheKey, $now->toIso8601String(), now()->addMinutes(1));
            }
        }

        return $response;
    }
}