<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class UpdateLastSeen
{
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {
            \Log::info('Updating last_seen_at for user: ' . Auth::user()->id);
            $user = Auth::user();
            $user->last_seen_at = now();
            $user->save(); // Use save() instead of update()
        }

        return $next($request);
    }
}