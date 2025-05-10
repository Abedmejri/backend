<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting; // Import the Meeting model
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Throwable; // Import Throwable for broader exception catching

class RecordingController extends Controller
{
    /**
     * Endpoint 1: Transcribes audio using a local Whisper API.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transcribeAudio(Request $request): JsonResponse
    {
        Log::info('Audio transcription request received.');

        $validator = Validator::make($request->all(), [
            'recording' => ['required', 'file', 'mimes:webm,oga,ogg,mp3,wav,m4a,mp4,flac,aac,amr,wma,aiff,mkv', 'max:51200'], // 50MB max
        ]);
        if ($validator->fails()) {
            Log::warning('Transcription validation failed.', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $uploadedFile = $request->file('recording');
        $filePath = null;
        $absolutePath = null;

        try {
            $filePath = $uploadedFile->store('recordings_temp', 'local');
            if (!$filePath) throw new \RuntimeException('Failed to store uploaded file.');
            $absolutePath = Storage::disk('local')->path($filePath);
            Log::info('Audio stored temporarily.', ['path' => $filePath]);

            $pythonApiUrl = rtrim(config('services.whisper_api.url', 'http://localhost:8001'), '/') . '/transcribe';
            $apiTimeout = config('services.whisper_api.timeout', 180);
            Log::info('Sending audio to local Python Whisper API.', ['url' => $pythonApiUrl]);
            if (!file_exists($absolutePath)) throw new \RuntimeException('Temporary audio file not found: ' . $absolutePath);

            $response = Http::timeout($apiTimeout)
                ->attach('file', fopen($absolutePath, 'r'), $uploadedFile->getClientOriginalName())
                ->post($pythonApiUrl);

            if (!$response->successful()) {
                $apiError = $response->json('detail') ?? $response->body();
                Log::error('Python Whisper API request failed.', ['status' => $response->status(), 'response' => $apiError]);
                $userMessage = 'Transcription service failed. ';
                if ($response->status() >= 500) { $userMessage .= 'The service encountered an internal error.'; }
                elseif ($response->status() === 422) { $userMessage .= 'Invalid data sent to the transcription service.'; }
                else {
                    $userMessage .= 'Status: '.$response->status();
                    if ((app()->environment('local') || app()->environment('development')) && is_string($apiError)) { $userMessage .= ' Details: '. substr($apiError, 0, 150); }
                }
                 // Use status code from response if available, else determine based on error
                 $statusCode = $response->status() ?: 503;
                 if ($statusCode < 400) $statusCode = 503; // Ensure error code for failed request

                 throw new \RuntimeException($userMessage, $statusCode);
            }

            $transcriptionData = $response->json();
            if (!isset($transcriptionData['transcription'])) {
                Log::error('Invalid response format from Python Whisper API.', ['response' => $transcriptionData]);
                throw new \RuntimeException('Transcription service returned invalid response format.');
            }

            $transcriptionText = $transcriptionData['transcription'];
             if (empty(trim($transcriptionText))) {
                 Log::info('Transcription complete, but no speech detected.');
                 $transcriptionText = '';
             }

            $detectedLanguage = $transcriptionData['language'] ?? 'unknown';
            Log::info('Transcription successful (Language: '.$detectedLanguage.')');

            return response()->json([
                'message' => $transcriptionText === '' ? 'Transcription complete, but no speech detected.' : 'Transcription successful.',
                'transcription' => $transcriptionText,
                'language' => $detectedLanguage,
            ]);

        } catch (Throwable $e) { // Catch Throwable for wider coverage
            Log::error('Error during audio transcription: ' . $e->getMessage(), [
                 'exception_class' => get_class($e),
                 'exception_code' => $e->getCode(),
                 'exception_trace' => $e->getTraceAsString() // Log full trace
            ]);
            $userMessage = (app()->environment('local') || app()->environment('development'))
                ? 'Transcription error: ' . $e->getMessage() : 'An internal error occurred during transcription.';
            // Determine status code more reliably
             $statusCode = method_exists($e, 'getCode') && is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 500;
             if ($e instanceof RequestException || $e instanceof ConnectionException) {
                 $statusCode = 503; // Service unavailable for client exceptions
             }
             // Ensure status code is in error range
             if ($statusCode < 400) $statusCode = 500;


            return response()->json(['message' => $userMessage], $statusCode);

        } finally {
            if ($filePath && Storage::disk('local')->exists($filePath)) {
                try {
                    Storage::disk('local')->delete($filePath);
                    Log::info('Temporary recording file deleted.', ['path' => $filePath]);
                } catch(Throwable $deleteError) { // Catch Throwable here too
                    Log::error('Failed to delete temporary recording file.', ['path' => $filePath, 'error' => $deleteError->getMessage()]);
                }
            }
        }
    }


    /**
     * Endpoint 2: Generates a Meeting Summary/PV draft from provided text and meeting ID using Groq API.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateMeetingSummary(Request $request): JsonResponse
    {
        Log::info('Meeting Summary/PV generation request received (using Groq).');
        Log::debug('[Groq Summary] Request received', $request->all()); // OK: $request->all() is an array

        $validator = Validator::make($request->all(), [
            'transcription' => ['required', 'string', 'min:0'],
            'meeting_id' => ['required', 'integer', 'exists:meetings,id'],
        ]);
        if ($validator->fails()) {
            Log::warning('Meeting Summary generation validation failed.', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['message' => 'Invalid input provided for summary generation.', 'errors' => $validator->errors()], 422);
        }

        $transcriptionText = $request->input('transcription');
        $meetingId = $request->input('meeting_id');
        $meeting = null; // Initialize meeting variable

        try {
            // --- Fetch Meeting Details (findOrFail will throw exception if not found) ---
            $meeting = Meeting::findOrFail($meetingId);
            Log::debug('[Groq Summary] Fetched Meeting:', $meeting->toArray()); // OK: $meeting->toArray() is an array

            // Handle empty transcription
            if (empty(trim($transcriptionText))) {
                Log::info('Skipping Groq summary generation because transcription is empty.', ['meeting_id' => $meetingId]);
                 $emptySummary = <<<MARKDOWN
# Meeting Summary / Procès-Verbal (PV)

**Meeting Title:** {$meeting->title}
**Date:** {$meeting->date->format('Y-m-d H:i')}
**Location:** {$meeting->location}

## Discussion Summary
[No transcription provided or speech detected.]

## Decisions Made
[No specific decisions identified in the transcript.]

## Action Items
[No specific action items identified in the transcript.]
MARKDOWN;
                return response()->json([
                    'message' => 'Transcription was empty. Basic PV structure generated.',
                    'summary' => $emptySummary,
                ]);
            }

            // --- Call Groq API ---
            $groqApiKey = config('services.groq.key');
            $groqModel = config('services.groq.model');
            $groqTimeout = (int) config('services.groq.timeout', 60);
            $groqApiUrl = "https://api.groq.com/openai/v1/chat/completions";

            if (empty($groqApiKey) || empty($groqModel)) {
                Log::error('Summary generation service (Groq) is not configured correctly. API Key or Model missing.');
                throw new \RuntimeException('Summary generation service (Groq) is not configured.', 503); // Use 503
            }

            // --- Build Enhanced Prompt (Further Refined for Discussion Summary) ---
            // System Prompt: Add detail on what constitutes the Discussion Summary
            $systemPrompt = "You are an expert meeting secretary AI. Your task is to meticulously analyze the provided meeting transcript and generate a structured Procès-Verbal (PV) in Markdown format. Extract *only* information present in the transcript. Focus specifically on:
1.  **High-Level Discussion Points:** Summarize the overall meeting purpose, agenda items mentioned, main topics covered, and significant discussion themes under 'Discussion Summary'. Exclude specific decisions or detailed action items from this section.
2.  **Explicit Decisions Made** (e.g., agreements, approvals, final conclusions reached). List these under 'Decisions Made'.
3.  **Specific Action Items Assigned** (who needs to do what, by when if mentioned). List these under 'Action Items'.
Structure the output clearly using the required Markdown headings. Start with the provided meeting details.";

            // User Prompt: Give more explicit instructions for the Discussion Summary section
            $userPrompt = "Generate the Meeting Summary / PV in Markdown for the following meeting:\n\n" .
                          "**Meeting Title:** {$meeting->title}\n" .
                          "**Date:** {$meeting->date->format('Y-m-d H:i')}\n" .
                          "**Location:** {$meeting->location}\n\n" .
                          "Analyze the following transcript meticulously:\n\n" .
                          "```transcript\n" . $transcriptionText . "\n```\n\n" .
                          "**Required Output Format (Strictly follow this structure):**\n\n" .
                          "## Discussion Summary\n" .
                          "[Summarize the meeting's stated purpose or agenda (if mentioned at the start or during the meeting), and the main themes or topics covered in the discussion based *only* on the transcript. Focus on the general flow and context, like the example: 'The meeting aimed to align on progress, discuss challenges...'. *Do not* include specific decisions or detailed action items in this summary section. Keep it concise.]\n\n" .
                          "## Decisions Made\n" .
                          "[List any explicit decisions, agreements, or final conclusions mentioned using bullet points (e.g., '- It was decided that...'). If no specific decisions are clearly stated in the transcript, write *exactly* this line: '[No specific decisions identified in the transcript.]']\n\n" .
                          "## Action Items\n" .
                          "[List any specific tasks assigned to individuals or the group. Use bullet points, ideally in the format: '- [Assignee, if mentioned]: [Task description] (Due: [Date, if mentioned])'. If no specific action items are clearly stated in the transcript, write *exactly* this line: '[No specific action items identified in the transcript.]']\n\n" .
                          "**Important:** Do not invent information. Base the summary *solely* on the provided transcript text.";


            // --- Corrected Logging ---
            Log::debug('[Groq Summary] Transcription Text Length:', ['length' => strlen($transcriptionText)]);
            Log::debug('[Groq Summary] System Prompt:', ['prompt' => $systemPrompt]); // Log string within an array
            Log::debug('[Groq Summary] User Prompt Snippet:', ['snippet' => substr($userPrompt, 0, 500) . '...']); // Log string within an array

            Log::info('Sending text and meeting context to Groq API.', ['model' => $groqModel, 'timeout' => $groqTimeout, 'meeting_id' => $meetingId]);

            $maxRetries = 1;
            $retryDelay = 1000;

            $summaryResponse = Http::withToken($groqApiKey)
                ->timeout($groqTimeout)
                ->retry($maxRetries, $retryDelay, function ($exception, $request) {
                     $shouldRetry = $exception instanceof ConnectionException ||
                            ($exception instanceof RequestException && $exception->response?->serverError()) ||
                            ($exception instanceof RequestException && $exception->response?->status() === 429);
                      if ($shouldRetry) { Log::warning('Groq API request failed, retrying...'); }
                     return $shouldRetry;
                 }, throw: false)
                ->post($groqApiUrl, [
                    'model' => $groqModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.5, // Keep slightly factual temperature
                    'max_tokens' => 2000,
                ]);

            // --- Corrected Logging ---
            Log::debug('[Groq Summary] API Response Status:', ['status' => $summaryResponse?->status()]); // Log integer/null within an array
            Log::debug('[Groq Summary] API Raw Response Body:', ['body' => $summaryResponse?->body()]); // Log string/null within an array

            // --- Check Groq Response ---
            if (!$summaryResponse || !$summaryResponse->successful()) {
                 $statusCode = $summaryResponse?->status() ?? 0;
                 $apiErrorBody = $summaryResponse?->body() ?? 'No response received after retries.';
                 $loggableError = $apiErrorBody;
                 if (is_string($apiErrorBody)) {
                    $decodedError = json_decode($apiErrorBody, true);
                    if ($decodedError && isset($decodedError['error']['message'])) { $loggableError = $decodedError['error']['message']; }
                    else { $loggableError = substr($apiErrorBody, 0, 500); }
                 }

                 Log::error('[Groq Summary] API call failed definitively.', ['status' => $statusCode, 'response' => $loggableError, 'meeting_id' => $meetingId]);

                 $errorMessage = "Failed to generate meeting summary (via Groq). ";
                  if ($statusCode === 401 || $statusCode === 403) { $errorMessage .= "Authentication failed."; }
                  elseif ($statusCode === 429) { $errorMessage .= "Rate limit hit."; }
                  elseif ($statusCode === 400) { $errorMessage .= "Invalid request."; }
                  elseif ($statusCode >= 500) { $errorMessage .= "Groq service internal error ({$statusCode})."; }
                  else { $errorMessage .= "Status: {$statusCode}. Details: " . (is_string($loggableError) ? $loggableError : json_encode($loggableError)); }

                  // Ensure status code is in error range
                  if ($statusCode < 400) $statusCode = 503;

                 throw new \RuntimeException($errorMessage, $statusCode);
            }

            // --- Process Successful Groq Response ---
            $summaryData = $summaryResponse->json();
            Log::debug('[Groq Summary] Parsed API Response Data:', $summaryData); // OK: $summaryData should be an array

            // Defensive check before accessing nested keys
            if (!isset($summaryData['choices'][0]['message']['content'])) {
                 Log::error('[Groq Summary] Invalid structure in successful Groq response', ['data' => $summaryData]);
                 $generatedSummary = null; // Ensure it's null or empty string before fallback
            } else {
                $generatedSummary = $summaryData['choices'][0]['message']['content'] ?? null;
            }

            // --- Corrected Logging ---
            Log::debug('[Groq Summary] Extracted Summary Length:', ['length' => ($generatedSummary ? strlen($generatedSummary) : 0)]); // Log integer within an array

            // Even if structure was bad, we might want to return a basic structure
            if (!is_string($generatedSummary) || trim($generatedSummary) === '') {
                Log::warning('Groq API response empty or invalid content for meeting summary.', ['response' => $summaryData ?? 'N/A', 'meeting_id' => $meetingId]);
                 $fallbackSummary = <<<MARKDOWN
# Meeting Summary / Procès-Verbal (PV)

**Meeting Title:** {$meeting->title}
**Date:** {$meeting->date->format('Y-m-d H:i')}
**Location:** {$meeting->location}

## Discussion Summary
[The AI failed to generate a summary from the transcript, or the result was empty/invalid.]

## Decisions Made
[No specific decisions identified in the transcript.]

## Action Items
[No specific action items identified in the transcript.]
MARKDOWN;
                 return response()->json([
                    'message' => 'Summary generation via Groq finished, but the result was empty or invalid. Basic structure provided.',
                    'summary' => $fallbackSummary,
                ]);
            }

            Log::info('Meeting summary generation via Groq successful.', ['meeting_id' => $meetingId]);

            // --- Return Summary ---
            // Ensure the generated summary includes the meeting details header if the AI didn't add it
             $finalSummary = trim($generatedSummary);
             $requiredHeader = "**Meeting Title:** {$meeting->title}";
             if (!str_contains($finalSummary, $requiredHeader)) {
                 Log::warning("[Groq Summary] AI output missing meeting details header. Prepending.");
                 $header = "**Meeting Title:** {$meeting->title}\n" .
                           "**Date:** {$meeting->date->format('Y-m-d H:i')}\n" .
                           "**Location:** {$meeting->location}\n\n";
                 $finalSummary = $header . $finalSummary;
             }


            return response()->json([
                'message' => 'Meeting Summary / PV generated successfully (via Groq).',
                'summary' => $finalSummary, // Return trimmed and potentially header-added summary
            ]);

        } catch (Throwable $e) { // Catch Throwable for broader exception types
             Log::error('[Groq Summary] Exception during summary generation: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_code' => $e->getCode(),
                'meeting_id' => $meetingId, // $meetingId is always available here
                'trace' => $e->getTraceAsString() // Log full trace
                ]);

             $userMessage = (($e instanceof \RuntimeException && $e->getCode() >= 500) || str_contains($e->getMessage(), 'Groq')) // Check for 5xx runtime or Groq specific
                ? $e->getMessage() // Use the specific message we crafted
                : ((app()->environment('local') || app()->environment('development'))
                     ? 'Summary generation error: ' . $e->getMessage() // Show detailed message in dev/local
                     : 'An internal error occurred during summary generation.');

             // Determine status code more reliably
             $statusCode = method_exists($e, 'getCode') && is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 500;
              if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                 $statusCode = 404; // Meeting not found
                 $userMessage = "Meeting with ID {$meetingId} not found.";
             } elseif ($e instanceof RequestException || $e instanceof ConnectionException || ($e instanceof \RuntimeException && $e->getCode() === 503)) {
                 $statusCode = 503; // Service unavailable
             }
             // Ensure status code is in error range
             if ($statusCode < 400) $statusCode = 500;


             return response()->json(['message' => $userMessage], $statusCode);
        }
    }
}