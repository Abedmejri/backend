<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast; // <--- IMPLEMENT
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Http\Resources\UserResource; // Optional: Use resource for consistency

class UserActivityUpdated implements ShouldBroadcast // <--- IMPLEMENT
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Public property will be broadcast by default
    public array $user; // Use array to send specific fields

    /**
     * Create a new event instance.
     *
     * @param \App\Models\User $userModel The user whose activity was updated
     * @return void
     */
    public function __construct(User $userModel)
    {
         // Only select fields needed by the frontend listener
         // Ensure last_seen_at is included and in a consistent format (ISO string)
        $this->user = [
            'id' => $userModel->id,
            'last_seen_at' => optional($userModel->last_seen_at)->toISOString() // Use optional() and toISOString() for safety
        ];
        // Alternatively, use API Resource if you prefer:
        // $this->user = (new UserResource($userModel->only(['id', 'last_seen_at'])))->resolve();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // Broadcast on the public channel your frontend is listening to
        return new Channel('user-status');
    }

    /**
     * The event's broadcast name. Match the frontend listener.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'user.activity.updated';
    }

    /**
    * Get the data to broadcast.
    * We define this explicitly to ensure the payload matches { user: { ... } }
    * as expected by the frontend listener.
    * @return array
    */
   public function broadcastWith(): array
   {
       return ['user' => $this->user];
   }
}