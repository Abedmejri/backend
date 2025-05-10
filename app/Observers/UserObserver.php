<?php

namespace App\Observers;

use App\Models\User;
use App\Events\UserActivityUpdated; // Import the event

class UserObserver
{
    /**
     * Handle the User "updated" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function updated(User $user)
    {
        // Check if the 'last_seen_at' field was actually changed during the update
        if ($user->wasChanged('last_seen_at')) {
            // Pass a fresh instance or specific fields to the event
            // Using fresh() ensures we send the data that was just saved
            event(new UserActivityUpdated($user->fresh()));
        }
    }
}