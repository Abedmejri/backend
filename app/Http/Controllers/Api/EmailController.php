<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Commission;
use App\Mail\CommissionDetailsMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile; // Ensure this is imported if using type hints

class EmailController extends Controller
{
    public function sendCommissionDetails(Request $request)
    {
        // --- 1. Validation ---
        // (Keep your existing validation rules, including for attachments)
        $maxFileSizeKB = 10 * 1024; // 10 MB
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => ['required', 'integer', Rule::exists('users', 'id')],
            'commission_id' => ['required', 'integer', Rule::exists('commissions', 'id')],
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'attachments' => 'nullable|array',
            'attachments.*' => [
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,txt,csv',
                'max:' . $maxFileSizeKB,
            ],
        ]);
        // (Keep your total size check if you have one)

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation Failed', 'errors' => $validator->errors()], 422);
        }

        // --- Prepare Attachment Data (Store files first!) ---
        $validatedData = $validator->validated();
        $attachmentData = []; // << Initialize ARRAY FOR STORED FILE INFO

        // Process attachments if they exist
        if ($request->hasFile('attachments')) {
            // $uploadedFiles = $request->file('attachments'); // This holds the UploadedFile objects

            foreach ($request->file('attachments') as $file) { // Loop through the UploadedFile objects
                // Check if it's a valid instance and was uploaded successfully
                if ($file instanceof UploadedFile && $file->isValid()) {
                    try {
                        // Store the file (e.g., in storage/app/public/email_attachments)
                        // Make sure the 'public' disk is configured correctly & linked (`php artisan storage:link`)
                        // Choose a disk appropriate for your setup ('local', 's3', etc.)
                        $path = $file->store('email_attachments', 'public'); // Returns the path *string*

                        if ($path) {
                             // << Add an ARRAY OF STRINGS to $attachmentData >>
                            $attachmentData[] = [
                                'path'          => $path, // The string path returned by store()
                                'disk'          => 'public', // The disk name used in store()
                                'original_name' => $file->getClientOriginalName(), // String
                                'mime_type'     => $file->getClientMimeType(), // String
                            ];
                        } else {
                            // Handle storage failure - log and maybe return error
                            Log::error('Failed to store uploaded file for email attachment.', ['original_name' => $file->getClientOriginalName()]);
                            // Consider returning a 500 error if storage is critical
                             return response()->json(['message' => 'Server error: Could not store attachment ' . $file->getClientOriginalName()], 500);
                        }
                    } catch (\Exception $storageException) {
                         Log::error('Exception during file storage for email attachment.', [
                            'original_name' => $file->getClientOriginalName(),
                            'error' => $storageException->getMessage()
                        ]);
                         return response()->json(['message' => 'Server error storing attachment: ' . $storageException->getMessage()], 500);
                    }
                } else {
                     Log::warning('Invalid file object encountered in attachments array during email prep.', ['file_details' => $file]);
                     // Decide if you want to ignore or return an error for invalid file objects
                }
            }
        }
        // --- End Preparing Attachment Data ---


        // --- 2. Fetch Models ---
        try {
            $users = User::whereIn('id', $validatedData['user_ids'])->get();
            $commission = Commission::with(['meetings']) // Eager load as needed
                                    ->findOrFail($validatedData['commission_id']);

            if ($users->isEmpty()) {
                 return response()->json(['message' => 'No valid users found for the provided IDs.'], 404);
            }
        } catch (ModelNotFoundException $e) {
            Log::warning('Commission not found during email send attempt.', ['commission_id' => $validatedData['commission_id']]);
            return response()->json(['message' => 'Commission not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching data for email: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error preparing email data.'], 500);
        }


        // --- 3. Queue Emails ---
        $successfullyQueuedCount = 0;
        $failedUsers = [];

        foreach ($users as $user) {
            try {
                // << THE CRITICAL LINE >>
                // Ensure $attachmentData (the array of strings) is passed,
                // NOT $uploadedFiles or $request->file('attachments')
                Mail::to($user->email)
                    ->queue(new CommissionDetailsMail(
                        $commission,
                        $validatedData['subject'],
                        $validatedData['body'],
                        $user,
                        $attachmentData // Pass the array of stored file info
                    ));
                 $successfullyQueuedCount++;
            } catch (\Exception $e) {
                // Log the detailed error including the stack trace for easier debugging
                Log::error('Error queueing commission email for user: ' . $user->id, [
                    'user_email' => $user->email,
                    'commission_id' => $commission->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString() // Log stack trace
                ]);
                $failedUsers[] = $user->email;
            }
        }

        // --- 4. Return Response ---
        // (Keep your existing response logic based on $successfullyQueuedCount)
        if ($successfullyQueuedCount === $users->count()) {
             return response()->json([
                 'message' => 'Emails are being processed and sent to ' . $successfullyQueuedCount . ' users.'
             ], 200);
        } elseif ($successfullyQueuedCount > 0) {
            // ... partial success response ...
             return response()->json([
                 'message' => 'Emails are being processed for ' . $successfullyQueuedCount . ' users. Failed to queue for: ' . implode(', ', $failedUsers),
                 // ... details ...
             ], 207);
        } else {
            // ... total failure response ...
             return response()->json([
                 'message' => 'Failed to queue emails for all requested users. Check server logs for specific errors.', // Updated message
                 // ... details ...
             ], 500);
        }
    }
}