<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class ResetPasswordNotification extends Notification // implements ShouldQueue // Optional: for queueing emails
{
    use Queueable;

    /**
     * The password reset token.
     *
     * @var string
     */
    public $token;

     /**
      * The user's email. Required to construct the frontend URL.
      *
      * @var string
      */
     public $email; // Add this property


    /**
     * Create a new notification instance.
     * @param string $token
     * @param string $email // Accept email in constructor
     * @return void
     */
    public function __construct($token, $email) // Modify constructor
    {
        $this->token = $token;
        $this->email = $email; // Store email
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $resetUrl = $this->resetUrl($notifiable); // Use helper method

        return (new MailMessage)
                    ->subject(Lang::get('Reset Password Notification'))
                    ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
                    ->action(Lang::get('Reset Password'), $resetUrl) // Use the generated URL
                    ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
                    ->line(Lang::get('If you did not request a password reset, no further action is required.'));
    }

    /**
     * Get the reset URL for the given notifiable.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function resetUrl($notifiable)
    {
        // Define your frontend URL in .env (e.g., APP_FRONTEND_URL=http://localhost:3000)
        $appUrl = 'http://localhost:5173'; // Fallback to app.url if frontend_url not set

        // Construct the URL for your React Reset Password component
        // Example: http://localhost:3000/reset-password/TOKEN?email=USER_EMAIL
        return $appUrl . '/reset-password/' . $this->token . '?email=' . urlencode($this->email);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}