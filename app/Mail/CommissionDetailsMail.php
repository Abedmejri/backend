<?php

namespace App\Mail;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment; // Import Attachment class
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log; // Import Log facade
// Note: We no longer need to import UploadedFile here as we receive stored file info

class CommissionDetailsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Commission $commission;
    public string $customSubject;
    public string $customBody;
    public User $recipient;
    /**
     * Holds information about stored attachments.
     * Expected structure for each element:
     * [
     *   'disk' => string, // e.g., 'public', 'local', 's3'
     *   'path' => string, // e.g., 'email_attachments/unique_name.pdf'
     *   'original_name' => string, // e.g., 'report.pdf'
     *   'mime_type' => string // e.g., 'application/pdf'
     * ]
     * @var array<int, array<string, string>>
     */
    public array $attachmentData;

    /**
     * Create a new message instance.
     *
     * @param Commission $commission The commission model instance.
     * @param string $customSubject The subject line for the email.
     * @param string $customBody The main text body content.
     * @param User $recipient The user receiving the email.
     * @param array<int, array{'path': string, 'disk': string, 'original_name': string, 'mime_type': string}> $attachmentData Data about stored files to attach.
     */
    public function __construct(
        Commission $commission,
        string $customSubject,
        string $customBody,
        User $recipient,
        array $attachmentData = [] // Accept attachment info array, default to empty
    )
    {
        $this->commission = $commission;
        $this->customSubject = $customSubject;
        $this->customBody = $customBody;
        $this->recipient = $recipient;
        $this->attachmentData = $attachmentData; // Store the attachment info array
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Set the actual email subject header
        return new Envelope(
            subject: $this->customSubject,
        );
    }

    /**
     * Get the message content definition.
     * Uses the 'emails.commission.details' markdown view.
     */
    public function content(): Content
    {
        // Define data available inside the Blade view
        return new Content(
            markdown: 'emails.commission.details',
            with: [
                'commissionData' => $this->commission,
                'bodyContent'    => $this->customBody,
                'recipientName'  => $this->recipient->name,
                // Ensure related data needed by the view is loaded
                // This might have been eager-loaded in the controller, or lazy-loaded here.
                'meetings'       => $this->commission->relationLoaded('meetings') ? $this->commission->meetings : [],
                'subject'        => $this->customSubject, // Passed for potential use in template
            ],
        );
    }

    /**
     * Get the attachments for the message.
     * Creates Attachment objects from the stored file information.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];
        if (!empty($this->attachmentData)) {
            foreach ($this->attachmentData as $fileInfo) {
                // Defensive check: ensure necessary keys exist
                if (!isset($fileInfo['disk'], $fileInfo['path'], $fileInfo['original_name'], $fileInfo['mime_type'])) {
                     Log::warning('Incomplete attachment data received in CommissionDetailsMail.', [
                         'data_received' => $fileInfo,
                         'recipient_email' => $this->recipient->email ?? 'N/A'
                     ]);
                     continue; // Skip this invalid entry
                }

                try {
                    // Create the attachment from the stored file using the specified disk
                    $attachments[] = Attachment::fromStorageDisk($fileInfo['disk'], $fileInfo['path'])
                        ->as($fileInfo['original_name']) // Set the filename seen by the recipient
                        ->withMime($fileInfo['mime_type']); // Set the correct MIME type

                } catch (\Exception $e) {
                    // Log an error if the file cannot be accessed or attached
                    Log::error('Failed to create attachment from stored file in CommissionDetailsMail.', [
                        'disk' => $fileInfo['disk'],
                        'path' => $fileInfo['path'],
                        'original_name' => $fileInfo['original_name'],
                        'error' => $e->getMessage(),
                        'recipient_email' => $this->recipient->email ?? 'N/A'
                    ]);
                    // Decide whether to continue processing other attachments or stop.
                    // continue; // Usually best to try and attach the others.
                }
            }
        }
        return $attachments;
    }
}