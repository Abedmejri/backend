<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Meeting; // Assuming you have a Meeting model

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// --- AUTHORIZATION FOR VIDEO MEETING PRESENCE CHANNEL ---
// Channel name format: presence-meeting.{meetingId}
Broadcast::channel('presence-meeting.{meetingId}', function ($user, $meetingId) {
    \Log::info("[TEMP AUTH] Allowing User {$user->id} for Meeting {$meetingId}");
    return ['id' => $user->id, 'name' => $user->name]; // Always allow
});

// Broadcast::routes(); // This is usually called in a Service Provider or Http Kernel, not here.
                      // Ensure it IS called somewhere for broadcasting routes to work.