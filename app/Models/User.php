<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// --- Add these imports ---
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract; // Import the contract
use Illuminate\Auth\Passwords\CanResetPassword; // Import the trait
use App\Notifications\ResetPasswordNotification; // Import your custom notification (Make sure this exists!)
// --- End added imports ---

class User extends Authenticatable implements CanResetPasswordContract // <-- Implement the contract here
{
    // --- Add CanResetPassword to the list of traits ---
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;
    // --- End added trait ---

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'role',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        // Consider adding last_seen_at if it should be treated as a date/time object
         'last_seen_at' => 'datetime',
    ];

    /**
     * Define the many-to-many relationship with the Commission model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function commissions()
    {
        return $this->belongsToMany(Commission::class, 'commission_user', 'user_id', 'commission_id');
    }

    /**
     * Define the many-to-many relationship with the Permission model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    // --- Add this method to override the default notification behavior ---
    /**
     * Send the password reset notification.
     *
     * This method overrides the default behavior to use our custom notification,
     * which generates a link suitable for the frontend SPA.
     *
     * @param  string  $token The password reset token.
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        // Create an instance of your custom notification, passing the token
        // and the user's email (needed to build the frontend URL).
        $notification = new ResetPasswordNotification($token, $this->getEmailForPasswordReset());

        // Send the notification to the user.
        $this->notify($notification);
    }
    // --- End added method ---

    // Note: The getEmailForPasswordReset() method is inherited from Authenticatable
    // and usually just returns $this->email, so you don't typically need to override it.
}