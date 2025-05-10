<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Meeting;
use App\Models\Pv; // Import PV model
use App\Models\User; // Import User model
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException; // For handling validation manually
use Throwable;
use Carbon\Carbon; // For date formatting
use Illuminate\Database\Eloquent\ModelNotFoundException; // Specific exception

class ChatbotController extends Controller
{
    /**
     * Handle incoming chatbot messages, understand intent, fetch data, perform actions, or navigate.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleMessage(Request $request): JsonResponse
    {
        // Log request entry and authenticated user ID
        $userId = Auth::id(); // Get user ID early for logging
        Log::info('[Chatbot] Request received', ['user_id' => $userId]);

        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:2000'], // User's current message
            'history' => ['nullable', 'array'],              // Optional chat history
            'history.*.sender' => ['required_with:history', 'string', 'in:user,bot'], // Validate history structure
            'history.*.text' => ['required_with:history', 'string', 'max:2000'],       // Validate history structure
        ]);

        if ($validator->fails()) {
            Log::warning('[Chatbot] Validation failed.', ['errors' => $validator->errors()->toArray(), 'user_id' => $userId]);
            return response()->json(['message' => 'Invalid message provided.', 'errors' => $validator->errors()], 422);
        }

        // Get the authenticated user; fail if not authenticated
        $user = Auth::user();
        if (!$user) {
            // This check might be redundant if using auth middleware, but good for belt-and-suspenders
            Log::warning('[Chatbot] Unauthenticated user attempt.');
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Extract validated data
        $userMessage = $request->input('message');
        $history = $request->input('history', []);

        try {
            // --- Groq API Configuration ---
            $groqApiKey = config('services.groq.key');
            $groqModel = config('services.groq.model', 'llama3-8b-8192'); // Provide a default model
            $groqTimeout = (int) config('services.groq.timeout', 45);
            $groqApiUrl = "https://api.groq.com/openai/v1/chat/completions";

            if (empty($groqApiKey) || empty($groqModel)) {
                Log::error('[Chatbot] Groq service API Key or Model is not configured in config/services.php or .env.');
                throw new \RuntimeException('Chatbot service is not configured.', 503);
            }

            // --- Construct System Prompt ---
            $systemPrompt = $this->buildSystemPrompt($user);

            // --- Prepare Messages for Groq API ---
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            $messages = $this->appendHistory($messages, $history);
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            Log::debug('[Chatbot] Messages sent to Groq (Count): ' . count($messages), ['user_id' => $user->id]);

            // --- Call Groq API ---
            $response = Http::withToken($groqApiKey)
                ->timeout($groqTimeout)
                ->retry(1, 500, function ($exception) { // Retry once on connection errors or 429
                    return $exception instanceof ConnectionException || ($exception instanceof RequestException && $exception->response?->status() === 429);
                }, throw: false) // Don't throw exception on retryable failures
                ->post($groqApiUrl, [
                    'model' => $groqModel,
                    'messages' => $messages,
                    'temperature' => 0.3, // Lower temperature for more deterministic JSON/intent output
                    'max_tokens' => 450, // Slightly larger max for complex responses/suggestions
                    // 'response_format' => ['type' => 'json_object'], // Use with caution
                ]);

            // --- Handle Groq API Response ---
            if (!$response || !$response->successful()) {
                $statusCode = $response?->status() ?? 503; // Default to 503 if no response
                $errorBody = $response?->body() ?? 'No response from Groq API.';
                 Log::error('[Chatbot] Groq API call failed.', [
                    'status' => $statusCode,
                    'response' => Str::limit($errorBody, 500), // Log limited response body
                    'user_id' => $user->id
                 ]);
                 $userFriendlyError = "Sorry, the AI assistant is currently unavailable or encountered an error.";
                 if ($statusCode === 429) $userFriendlyError = "AI assistant is busy, please try again shortly.";
                 if ($statusCode === 401) $userFriendlyError = "AI assistant authentication failed (check API key).";
                 if ($statusCode === 400) $userFriendlyError = "There was an issue with the request to the AI assistant (e.g., content policy).";

                throw new \RuntimeException($userFriendlyError, $statusCode >= 400 ? $statusCode : 503);
            }

            // --- Process Successful Groq Response ---
            $responseData = $response->json();
            // Check for potential errors within the successful response structure
            if (isset($responseData['error'])) {
                 Log::error('[Chatbot] Groq API returned an error structure in successful response.', ['error_details' => $responseData['error'], 'user_id' => $user->id]);
                 throw new \RuntimeException('The AI assistant reported an internal error.', 500);
            }

             // Check if choices array exists and is not empty
             if (!isset($responseData['choices']) || !is_array($responseData['choices']) || empty($responseData['choices'])) {
                  Log::error('[Chatbot] Groq response missing or empty "choices" array.', ['response_data' => $responseData, 'user_id' => $user->id]);
                  throw new \RuntimeException('Chatbot received an unexpected response structure from AI.', 500);
             }

             // Check message content within the first choice
             if (!isset($responseData['choices'][0]['message']['content'])) {
                  Log::error('[Chatbot] Groq response missing "content" in the first choice message.', ['response_data' => $responseData, 'user_id' => $user->id]);
                  throw new \RuntimeException('Chatbot received incomplete response content from AI.', 500);
             }


            $rawContent = $responseData['choices'][0]['message']['content'] ?? null;
            Log::debug('[Chatbot] Raw content from Groq:', ['content' => $rawContent, 'user_id' => $user->id]);

            if (empty($rawContent)) {
                // Even if structure is correct, content might be empty string
                throw new \RuntimeException('Chatbot received empty content from AI.', 500);
            }

            // Attempt to parse the response content as JSON
            // Trim potential markdown code fences before parsing
            $trimmedContent = trim(str_replace(['```json', '```'], '', $rawContent));
            $parsedContent = json_decode($trimmedContent, true);
            $jsonError = json_last_error();
            $isStructuredJson = ($jsonError === JSON_ERROR_NONE) && is_array($parsedContent);

            // --- Route Logic Based on Parsed Response ---
             if ($isStructuredJson && isset($parsedContent['intent'])) {
                 Log::info('[Chatbot] Handling structured intent', ['intent' => $parsedContent['intent'], 'user_id' => $user->id]);
                 return $this->handleIntent($user, $parsedContent['intent'], $parsedContent['params'] ?? []);

             } elseif ($isStructuredJson && isset($parsedContent['action']['type']) && $parsedContent['action']['type'] === 'navigate') {
                  Log::info('[Chatbot] Handling navigation action', ['action' => $parsedContent['action'], 'user_id' => $user->id]);
                  return $this->handleNavigation($user, $parsedContent['action'], $parsedContent['reply'] ?? 'Okay, navigating...');

             } elseif (!$isStructuredJson && is_string($rawContent)) {
                 // Handle simple text replies from the LLM (Use the original raw content)
                 Log::info('[Chatbot] Received simple text reply from Groq.', ['user_id' => $user->id]);
                 return response()->json(['reply' => $rawContent]);
             } else {
                 // Handle unexpected response structure after attempting JSON parse
                 Log::error('[Chatbot] Parsed Groq response lacks expected structure or failed parsing, even after trimming.', [
                     'parsed_content' => $parsedContent, // May be null if json_decode failed
                     'trimmed_content' => $trimmedContent, // Show what was parsed
                     'raw_content' => $rawContent,
                     'json_error_code' => $jsonError,
                     'json_error_msg' => json_last_error_msg(),
                     'user_id' => $user->id,
                 ]);
                 // Fallback: return the raw content if it's a string, otherwise error
                 if (is_string($rawContent)) {
                      Log::warning('[Chatbot] Falling back to raw string response after JSON parse failure or invalid structure.');
                      return response()->json(['reply' => $rawContent]);
                 }
                 throw new \RuntimeException('Chatbot response structure was invalid.', 500);
             }

        } catch (Throwable $e) {
             // --- General Exception Handling ---
             Log::error('[Chatbot] Exception: ' . $e->getMessage(), [
                 'exception_class' => get_class($e),
                 'exception_code' => $e->getCode(),
                 'user_id' => $userId, // Use ID captured at start
                 'file' => $e->getFile(),
                 'line' => $e->getLine(),
                 'trace_snippet' => Str::limit($e->getTraceAsString(), 1000)
             ]);

             // Determine appropriate status code
             $statusCode = 500; // Default internal server error
              if ($e instanceof RequestException || $e instanceof ConnectionException || ($e instanceof \RuntimeException && $e->getCode() === 503)) {
                  $statusCode = 503; // Service unavailable
              } elseif ($e instanceof ModelNotFoundException) { // Specific handling for model not found
                  $statusCode = 404;
              } elseif ($e instanceof ValidationException) { // Specific handling for validation errors from *our* code
                  $statusCode = 422;
              } elseif (method_exists($e, 'getStatusCode') && is_callable([$e, 'getStatusCode'])) { // Check for HttpException status code
                   $httpStatusCode = $e->getStatusCode();
                   if ($httpStatusCode >= 400 && $httpStatusCode < 600) $statusCode = $httpStatusCode;
              } elseif (method_exists($e, 'getCode') && is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600) {
                  $statusCode = $e->getCode(); // Use exception code if it's a valid HTTP error code
              }
               if ($statusCode < 400 || $statusCode >= 600) $statusCode = 500; // Ensure it's a valid HTTP error code

             // Determine user-friendly message
              $userMsg = ($e instanceof ModelNotFoundException || $e instanceof ValidationException || ($e instanceof \RuntimeException && $statusCode != 500))
                         ? $e->getMessage() // Use specific message from these exceptions or RuntimeExceptions with specific codes
                         : 'Sorry, an internal error occurred. Please try again later.'; // Generic message otherwise

             // Special case for validation errors to return the errors array
              if ($e instanceof ValidationException) {
                 return response()->json(['message' => $userMsg, 'errors' => $e->errors()], $statusCode);
              }

             return response()->json(['message' => $userMsg], $statusCode);
        }
    }

    /**
     * Builds the enhanced system prompt for the Groq API call.
     *
     * @param User $user The currently authenticated user.
     * @return string The system prompt string.
     */
    protected function buildSystemPrompt(User $user): string
    {
        // Use heredoc syntax for readability
        // Added generate_pv_text intent
        return <<<PROMPT
You are an intelligent AI assistant integrated into a commission and meeting management application for user ID {$user->id} ({$user->name}). Your goal is to be helpful, understand context, and assist with tasks related to Commissions, Meetings, PVs (Procès-verbaux/Minutes), and Users.

**Capabilities & Response Formats:**

1.  **Answering Questions & General Chat:** Provide informative and relevant text responses.

2.  **Listing Data (User-Specific):** When asked to list commissions, meetings, users, or PVs:
    *   Identify the intent: `list_commissions`, `list_meetings`, `list_users`, `list_pvs`.
    *   Extract filtering criteria (e.g., timeframe "this week", "upcoming"; specific commission name/ID; meeting title; username/email).
    *   Respond **ONLY** with JSON: `{"intent": "list_...", "params": {"filter_criteria": "value", ...}}` (or just `{"intent": "..."}` if no criteria). The backend will fetch and format the list.

3.  **Creating Items (Commissions, Meetings):**
    *   Identify the intent: `create_commission`, `create_meeting`.
    *   Extract **all** available parameters (name, description, title, date, location, commission name/ID, gps).
    *   If **enough** information is present (name for commission; title, date, location, commission for meeting), respond **ONLY** with JSON: `{"intent": "create_...", "params": {...extracted params...}}`. Ensure 'date' is reasonably parsable (e.g., 'tomorrow 2pm', '2024-08-15 14:00'). Use `commission_name_or_id` for meetings.
    *   If critical information is **missing**, ask a clear, concise clarifying question in **simple text**.

4.  **Managing Commission Members:**
    *   Identify the intent: `add_commission_member`, `remove_commission_member`.
    *   Extract parameters: `commission_name_or_id` (required), `user_name_or_email` (required).
    *   Respond **ONLY** with JSON: `{"intent": "add_commission_member", "params": {"commission_name_or_id": "...", "user_name_or_email": "..."}}` (or `remove_...`).
    *   If info is missing, ask for clarification in **simple text**.

5.  **Navigation & Guiding Actions:** When asked to navigate or perform actions requiring a dedicated interface (like generating a PV or sending a detailed email):
    *   Identify intent: `navigate`.
    *   Extract target page/action (`/generate-pv`, `/send-email`, `/meetings`, `/commissions`, `/users`). Include relevant parameters if possible (e.g., `meeting_id` for generate-pv, `commission_id` for send-email).
    *   Respond **ONLY** with navigation JSON: `{"reply": "Okay, let's head over to [action description]...", "action": {"type": "navigate", "target": "/...", "params": {"key": "value"}}}`.
    Available targets: `/generate-pv` (can take `meeting_id`), `/send-email` (can take `commission_id`), `/meetings`, `/commissions`, `/users`. You can also suggest navigating to a specific commission's page if asked.

6.  **Suggestion / "Auto-fill" Requests:** When asked to "suggest details" or "auto-fill" for creation:
    *   Identify the intent: `suggest_details`.
    *   Extract the type of item (meeting, commission).
    *   Extract any context provided by the user (e.g., "suggest details for a budget meeting").
    *   Respond **ONLY** with JSON: `{"intent": "suggest_details", "params": {"item_type": "meeting", "context": "budget meeting"}}`.

7.  **Generating PV Text:**
    *   Identify intent: `generate_pv_text`.
    *   Extract parameters: `pv_id` (required, must be a number).
    *   Respond **ONLY** with JSON: `{"intent": "generate_pv_text", "params": {"pv_id": 123}}`.
    *   If `pv_id` is missing or not a number, ask for it in **simple text**.

**General Rules:**
*   **Prioritize JSON for Actions/Intents:** If you identify a list, create, manage member, navigate, suggest, or generate_pv_text intent, use the specified JSON format *exclusively*. No extra text.
*   **Use Simple Text Otherwise:** For general chat, answers, or clarification questions, respond with plain text.
*   **Be Concise:** Keep replies brief.
*   **Leverage History:** Refer to previous messages for context.
*   **Do NOT Make Up Data:** Only extract parameters explicitly mentioned or clearly implied. Ensure IDs are numbers.
*   **Clarify Ambiguity:** If a commission or user name is ambiguous, ask for clarification (e.g., "Which 'Finance Commission' do you mean?").
*   **Parameter Naming:** Use the parameter names specified above (e.g., `commission_name_or_id`, `user_name_or_email`, `pv_id`).
PROMPT;
    }

    /**
     * Appends relevant history to the messages array, managing token limits.
     *
     * @param array $messages The initial messages array (with system prompt).
     * @param array $history The history array from the request.
     * @param int $maxHistoryTokens Approximate token limit for history.
     * @return array The updated messages array including history.
     */
    protected function appendHistory(array $messages, array $history, int $maxHistoryTokens = 1500): array
    {
        $historyTokens = 0;
        // Iterate history in reverse to prioritize recent messages
        foreach (array_reverse($history) as $histItem) {
             if (isset($histItem['sender'], $histItem['text'])) {
                 $role = $histItem['sender'] === 'user' ? 'user' : 'assistant';
                 $content = $histItem['text'];
                 // Simple token estimation (adjust divisor if needed, 4 is generally safer)
                 $approxTokens = strlen($content) / 4;
                 if ($historyTokens + $approxTokens <= $maxHistoryTokens) {
                     // Prepend history messages after the system prompt
                     array_splice($messages, 1, 0, [['role' => $role, 'content' => $content]]);
                     $historyTokens += $approxTokens;
                 } else {
                     Log::debug('[Chatbot] History token limit reached, stopping history append.');
                     break; // Stop adding history if token limit is reached
                 }
             }
        }
        return $messages;
    }


    /**
     * Handles intents like listing data, creating items, suggesting details, managing members, generating PV text.
     *
     * @param User $user
     * @param string $intent
     * @param array $params
     * @return JsonResponse
     */
    protected function handleIntent(User $user, string $intent, array $params): JsonResponse
    {
        Log::debug('[Chatbot] Handling intent', ['intent' => $intent, 'params' => $params, 'user_id' => $user->id]);
        // Use a switch for better organization
        switch ($intent) {
            // Listing Intents
            case 'list_commissions':
                return $this->listUserCommissions($user);
            case 'list_meetings':
                return $this->listUserMeetings($user, $params);
            case 'list_users':
                return $this->listUsers($user, $params);
            case 'list_pvs':
                return $this->listPvs($user, $params);

            // Creation Intents
            case 'create_commission':
                // --- Permission Check Placeholder ---
                 // if ($user->cannot('create', Commission::class)) {
                 //    Log::warning('[Chatbot] Permission denied: create commission.', ['user_id' => $user->id]);
                 //    return response()->json(['reply' => "Sorry, you don't have permission to create commissions."], 403);
                 // }
                return $this->createCommission($user, $params);
            case 'create_meeting':
                // Permission check handled within createMeeting after resolving commission
                return $this->createMeeting($user, $params);

            // Commission Member Management Intents
            case 'add_commission_member':
                // Permission check handled within manageCommissionMember after resolving commission
                return $this->manageCommissionMember($user, $params, 'add');
            case 'remove_commission_member':
                 // Permission check handled within manageCommissionMember after resolving commission
                return $this->manageCommissionMember($user, $params, 'remove');

            // Suggestion Intent
            case 'suggest_details':
                return $this->suggestDetails($user, $params);

            // PV Text Generation Intent
            case 'generate_pv_text':
                return $this->generatePvText($user, $params);

            // Default fallback for recognized but unhandled intents
            default:
                Log::warning('[Chatbot] Received unhandled structured intent', ['intent' => $intent, 'user_id' => $user->id]);
                return response()->json(['reply' => "I understood the request type '{$intent}', but I cannot perform that specific action yet."]);
        }
    }

    /**
     * Handles navigation actions after permission checks.
     *
     * @param User $user
     * @param array $action Should contain 'type' => 'navigate', 'target', optionally 'params'
     * @param string $reply The text reply accompanying the navigation action.
     * @return JsonResponse
     */
    protected function handleNavigation(User $user, array $action, string $reply): JsonResponse
    {
         $target = $action['target'] ?? null;
         $params = $action['params'] ?? [];
         Log::debug('[Chatbot] Handling navigation request', ['target' => $target, 'params' => $params, 'user_id' => $user->id]);

         // --- Permission Check Placeholders ---
         $canNavigate = true; // Assume true initially
         $denialMessage = "Sorry, you may not have permission to access that area."; // Default denial

         if (!$target) {
              Log::warning('[Chatbot] Navigation action requested without a target.', ['action' => $action, 'user_id' => $user->id]);
              return response()->json(['reply' => "I'm not sure where you want to navigate to."]);
         }

         switch ($target) {
             case '/generate-pv':
                 // Example: Check if user can create PVs generally
                 // $meetingId = $params['meeting_id'] ?? null;
                 // if ($meetingId) {
                 //    $meeting = Meeting::find($meetingId);
                 //    if (!$meeting || $user->cannot('createPvFor', $meeting)) $canNavigate = false;
                 // } elseif ($user->cannot('create', Pv::class)) {
                 //     $canNavigate = false;
                 //     $denialMessage = "Sorry, you don't have permission to generate PVs.";
                 // }
                 break;
             case '/send-email':
                  // Example: Check if user has a role that can send bulk emails
                  // $commissionId = $params['commission_id'] ?? null;
                 // if ($commissionId) {
                 //    $commission = Commission::find($commissionId);
                 //    if (!$commission || $user->cannot('sendEmailFor', $commission)) $canNavigate = false;
                 // } elseif (!$user->hasRole('commission-manager')) { // Example role check
                 //     $canNavigate = false;
                 //     $denialMessage = "Sorry, you don't have permission to send commission emails.";
                 // }
                 break;
             case '/users':
                  // Example: Check if user can view the user list
                 // if ($user->cannot('viewAny', User::class)) {
                 //     $canNavigate = false;
                 //     $denialMessage = "Sorry, you don't have permission to view the user list.";
                 // }
                  break;
             // Add more checks for other targets like /meetings, /commissions if needed
             default:
                  // Check specific commission access if target is like /commissions/{id}/members
                  if (preg_match('/^\/commissions\/(\d+)(\/.*)?$/', $target, $matches)) {
                      $commissionId = $matches[1];
                      $commission = Commission::find($commissionId);
                      // if (!$commission || $user->cannot('view', $commission)) {
                      //     $canNavigate = false;
                      //     $denialMessage = "Sorry, you don't have permission to access that specific commission's page.";
                      // }
                  }
                  // Basic check for allowed top-level authenticated routes
                  elseif (!Str::startsWith($target, ['/', '/commissions', '/meetings', '/users', '/generate-pv', '/send-email'])) {
                     Log::warning('[Chatbot] Attempted navigation to potentially invalid target.', ['target' => $target, 'user_id' => $user->id]);
                     $canNavigate = false; // Disallow unknown top-level paths
                     $denialMessage = "I cannot navigate to that location.";
                  }
                 break;
         }

         if (!$canNavigate) {
             Log::warning('[Chatbot] Navigation permission denied.', ['user_id' => $user->id, 'target' => $target]);
             // Return denial message instead of the navigation action
             return response()->json(['reply' => $denialMessage], 403);
         }

         // Return the structured action for the frontend to execute
         Log::info('[Chatbot] Navigation permitted.', ['user_id' => $user->id, 'target' => $target]);
         return response()->json(['reply' => $reply, 'action' => $action]);
    }


    // --- Helper Methods for Finding Models ---

    /**
     * Finds a Commission by name or ID, ensuring the user has access if required by context.
     * @param string $identifier Name or ID
     * @param User $user The user performing the action
     * @param bool $checkMembership Require the user to be a member (for actions *within* a commission)?
     * @return Commission
     * @throws ModelNotFoundException If not found, ambiguous, or access denied based on $checkMembership.
     */
    protected function findCommissionOrFail(string $identifier, User $user, bool $checkMembership = false): Commission
    {
        $query = Commission::query();
        $originalIdentifier = $identifier; // Keep for error messages

        // Prioritize finding by ID if it looks numeric
        if (is_numeric($identifier)) {
             $query->where('id', $identifier);
             // If searching by ID, also check name just in case name is purely numeric
             $query->orWhere('name', $identifier);
        } else {
            // Allow finding by name (case-insensitive partial match - use exact if possible)
            // Let's try exact match first, then partial if needed
             $exactMatch = Commission::where('name', $identifier)->first();
              if ($exactMatch) {
                  $commissions = collect([$exactMatch]); // Use exact match if found
              } else {
                  // Fallback to partial match if exact not found
                  $query->where('name', 'LIKE', '%' . $identifier . '%');
                  $commissions = $query->limit(2)->get(); // Limit 2 to detect ambiguity
              }
        }
        // If query was built (i.e. identifier was numeric, or exact match failed) get results
        if (!isset($commissions)) {
            $commissions = $query->limit(2)->get();
        }


        if ($commissions->count() === 0) {
             Log::warning('[Chatbot] Commission not found.', ['identifier' => $originalIdentifier, 'user_id' => $user->id]);
             throw new ModelNotFoundException("I couldn't find a commission matching '{$originalIdentifier}'. Please provide a valid name or ID.");
        }

        if ($commissions->count() > 1) {
            Log::warning('[Chatbot] Ambiguous commission identifier.', ['identifier' => $originalIdentifier, 'found' => $commissions->pluck('name','id')->all(), 'user_id' => $user->id]);
             // Improved ambiguity message
             $names = $commissions->map(fn($c) => "'{$c->name}' (ID: {$c->id})")->implode(' or ');
            throw new ModelNotFoundException("Which commission did you mean? I found {$names}. Please use the exact name or the ID.");
        }

        $commission = $commissions->first();

        // --- Permission Check Placeholder: Can the user *view* this commission at all? ---
        // This check should happen *before* the membership check if applicable
        // if ($user->cannot('view', $commission)) {
        //    Log::warning('[Chatbot] Permission denied: view commission.', ['user_id' => $user->id, 'commission_id' => $commission->id]);
        //    throw new ModelNotFoundException("You don't have permission to access the '{$commission->name}' commission."); // Use ModelNotFound for consistency, though AccessDeniedHttpException might be semantically better
        // }

        // Optional: Check if the user is a member of this specific commission
        if ($checkMembership && !$commission->members()->where('user_id', $user->id)->exists()) {
             Log::warning('[Chatbot] User tried to access commission they are not part of (membership required).', ['user_id' => $user->id, 'commission_id' => $commission->id, 'commission_name' => $commission->name]);
             // Throw exception indicating lack of membership for the action
             throw new ModelNotFoundException("You need to be a member of the '{$commission->name}' commission to perform this action.");
        }

        return $commission;
    }

     /**
      * Finds a User by name or email.
      * @param string $identifier Name or Email
      * @param User $actingUser The user performing the action (for logging/context)
      * @return User
      * @throws ModelNotFoundException If not found or ambiguous
      */
     protected function findUserOrFail(string $identifier, User $actingUser): User
     {
         $query = User::query();
         $originalIdentifier = $identifier;

         // Search by email first (more unique)
         if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
             $query->where('email', $identifier);
         } else {
             // Search by name (case-insensitive partial match - prefer exact)
              $exactMatch = User::where('name', $identifier)->first();
              if ($exactMatch) {
                   $users = collect([$exactMatch]);
              } else {
                   $query->where('name', 'LIKE', '%' . $identifier . '%');
                   $users = $query->limit(2)->get();
              }
         }
         // If query was built (i.e., email search, or exact name failed), get results
         if (!isset($users)) {
              $users = $query->limit(2)->get();
         }


         if ($users->count() === 0) {
              Log::warning('[Chatbot] User not found.', ['identifier' => $originalIdentifier, 'acting_user_id' => $actingUser->id]);
              throw new ModelNotFoundException("I couldn't find a user matching '{$originalIdentifier}'. Please provide their full name or email address.");
         }

         if ($users->count() > 1) {
             Log::warning('[Chatbot] Ambiguous user identifier.', ['identifier' => $originalIdentifier, 'found' => $users->pluck('name', 'email')->all(), 'acting_user_id' => $actingUser->id]);
             $details = $users->map(fn($u) => "'{$u->name}' ({$u->email})")->implode(' or ');
             throw new ModelNotFoundException("Which user did you mean? I found {$details}. Please use their full email address for clarity.");
         }

          // --- Permission Check Placeholder: Can the acting user interact with the found user? ---
          // E.g., can they add this user to a commission? Usually this check happens later
          // based on the *action* being performed, but a basic visibility check could be here.
          // if ($actingUser->cannot('view', $users->first())) {
          //    Log::warning('[Chatbot] Permission denied: view target user.', ['acting_user_id' => $actingUser->id, 'target_user_id' => $users->first()->id]);
          //    throw new ModelNotFoundException("You don't have permission to interact with user '{$users->first()->name}'.");
          // }


         return $users->first();
     }


    // --- LIST Methods ---
    protected function listUserCommissions(User $user): JsonResponse
    {
        // --- Permission Check Placeholder ---
        // Typically users can list their own commissions, but add check if needed.
        // if ($user->cannot('listOwnCommissions')) { return response()->json(...); }

        Log::debug('[Chatbot] Listing user commissions', ['user_id' => $user->id]);
        $commissions = $user->commissions()
                            ->orderBy('name')
                            ->select(['commissions.id', 'commissions.name']) // Explicitly select columns
                            ->get();

        if ($commissions->isEmpty()) {
            return response()->json(['reply' => "You are not currently a member of any commissions."]);
        }

        $replyText = "Here are the commissions you are a member of:\n" .
                     $commissions->map(fn($c) => "- {$c->name} (ID: {$c->id})")->implode("\n"); // Include ID

        return response()->json(['reply' => $replyText]);
    }

    protected function listUserMeetings(User $user, array $params): JsonResponse
    {
         // --- Permission Check Placeholder ---
         // Usually users can list meetings for commissions they are in.
         // if ($user->cannot('listOwnMeetings')) { return response()->json(...); }

        Log::debug('[Chatbot] Listing user meetings', ['user_id' => $user->id, 'params' => $params]);
        $commissionIds = $user->commissions()->pluck('commissions.id');
        if ($commissionIds->isEmpty()) {
             return response()->json(['reply' => "You need to be in a commission to see meetings."]);
        }

        $query = Meeting::whereIn('commission_id', $commissionIds)->with('commission:id,name');
        $timeframeDescription = ''; // For the reply message

        // Handle Timeframe Filtering
        if (!empty($params['timeframe'])) {
            try {
                $now = Carbon::now($user->timezone ?? config('app.timezone'));
                $timeframe = strtolower(trim($params['timeframe']));
                $timeframeDescription = " for '{$params['timeframe']}'";

                if ($timeframe === 'today') {
                    $query->whereDate('date', $now->toDateString());
                } elseif (in_array($timeframe, ['this week', 'this_week'])) {
                     $query->whereBetween('date', [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()]);
                     $timeframeDescription = " for this week";
                } elseif ($timeframe === 'upcoming') {
                     $query->where('date', '>=', $now->toDateTimeString())->orderBy('date', 'asc'); // Order upcoming ascending
                     $timeframeDescription = " (upcoming)";
                } elseif (in_array($timeframe, ['past', 'recent', 'previous'])) {
                     $query->where('date', '<', $now->toDateTimeString())->orderBy('date', 'desc');
                     $timeframeDescription = " (past)";
                } else {
                    // Try parsing relative terms like 'next monday', 'last month'
                    $parsedDateStart = Carbon::parse($timeframe, $user->timezone ?? config('app.timezone'));
                    // Define a range based on the parsed term
                    if (Str::contains($timeframe, ['year'])) {
                        $query->whereBetween('date', [$parsedDateStart->startOfYear()->toDateTimeString(), $parsedDateStart->endOfYear()->toDateTimeString()]);
                        $timeframeDescription = " for the year " . $parsedDateStart->year;
                    } elseif (Str::contains($timeframe, ['month'])) {
                         $query->whereBetween('date', [$parsedDateStart->startOfMonth()->toDateTimeString(), $parsedDateStart->endOfMonth()->toDateTimeString()]);
                         $timeframeDescription = " for " . $parsedDateStart->format('F Y');
                    } elseif (Str::contains($timeframe, ['week'])) {
                         $query->whereBetween('date', [$parsedDateStart->startOfWeek()->toDateTimeString(), $parsedDateStart->endOfWeek()->toDateTimeString()]);
                         $timeframeDescription = " for the week starting " . $parsedDateStart->startOfWeek()->format('M j');
                    } else { // Assume it's a specific day
                        $query->whereDate('date', $parsedDateStart->toDateString());
                        $timeframeDescription = " for " . $parsedDateStart->format('M j, Y');
                    }
                    Log::debug('[Chatbot] Parsed timeframe for meetings list', ['timeframe' => $timeframe, 'user_id' => $user->id, 'parsed_start' => $parsedDateStart->toDateTimeString()]);
                }

            } catch (\Exception $e) {
                Log::warning('[Chatbot] Could not parse timeframe for meeting list.', ['timeframe' => $params['timeframe'], 'error' => $e->getMessage(), 'user_id' => $user->id]);
                $timeframeDescription = ''; // Reset description
                // Optionally inform user, but maybe just proceed without filter
                 return response()->json(['reply' => "Sorry, I couldn't understand the timeframe '{$params['timeframe']}'. Please try 'today', 'this week', 'upcoming', 'past', or a specific date."]);
                // $query->orderBy('date', 'desc'); // Default sort if timeframe fails
            }
        } else {
             $query->orderBy('date', 'desc'); // Default order if no timeframe
        }

        // Handle Commission Filter
        $commissionContext = '';
        if (!empty($params['commission_name_or_id'])) {
             try {
                 // We check membership=true because user is listing meetings FOR a commission they should be in
                 $commission = $this->findCommissionOrFail($params['commission_name_or_id'], $user, true);
                 $query->where('commission_id', $commission->id);
                 $commissionContext = " for the '{$commission->name}' commission";
             } catch (ModelNotFoundException $e) {
                 // If commission not found OR user not a member, return error
                 return response()->json(['reply' => $e->getMessage()], $e->getCode() === 0 ? 404 : 403); // Use 403 if membership failed
             }
        }

        $meetings = $query->limit(15)->get(['id', 'title', 'date', 'location', 'commission_id']);

        if ($meetings->isEmpty()) {
            return response()->json(['reply' => "No meetings found{$commissionContext}{$timeframeDescription}."]);
        }

        $replyText = "Here are the meetings{$commissionContext}{$timeframeDescription}:\n" .
                     $meetings->map(function($meeting) {
                        // Ensure date is Carbon instance before formatting
                        $meetingDate = $meeting->date instanceof Carbon ? $meeting->date : Carbon::parse($meeting->date);
                        $dateStr = $meetingDate->format('D, M j H:i'); // Use user's timezone implicitly if set globally, otherwise app timezone
                        $commissionName = $meeting->commission?->name ?? 'Unknown';
                        return "- {$meeting->title} (ID: {$meeting->id}) for '{$commissionName}' on {$dateStr} at {$meeting->location}"; // Include ID
                     })->implode("\n");

        return response()->json(['reply' => $replyText]);
    }

     protected function listUsers(User $user, array $params): JsonResponse
     {
         Log::debug('[Chatbot] Listing users', ['user_id' => $user->id, 'params' => $params]);
         $query = User::query();
         $filteredByCommission = false;
         $commissionName = '';

         // --- Permission Check Strategy ---
         // Option 1: Check if user can view *any* users first.
         // Option 2: Check permission based on *filters* applied (commission filter requires specific perm).

         // Filter by Commission
         if (!empty($params['commission_name_or_id'])) {
             try {
                 // Find commission, don't require membership for listing unless policy demands it
                 $commission = $this->findCommissionOrFail($params['commission_name_or_id'], $user, false);
                 $commissionName = $commission->name;

                 // --- Permission Check Placeholder: Can user view members of THIS commission? ---
                 // if ($user->cannot('viewMembers', $commission)) {
                 //     Log::warning('[Chatbot] Permission denied: list members for commission.', ['user_id' => $user->id, 'commission_id' => $commission->id]);
                 //     return response()->json(['reply' => "Sorry, you don't have permission to view members of the '{$commissionName}' commission."], 403);
                 // }

                 // Ensure members relationship exists and apply filter
                 if (method_exists($commission, 'members')) {
                    $memberIds = $commission->members()->pluck('users.id');
                    $query->whereIn('users.id', $memberIds);
                    $filteredByCommission = true;
                 } else {
                      Log::error('[Chatbot] "members" relationship not found on Commission model.', ['commission_id' => $commission->id]);
                      return response()->json(['reply' => "There was an issue retrieving members for that commission."], 500);
                 }

             } catch (ModelNotFoundException $e) {
                 return response()->json(['reply' => $e->getMessage()], 404); // Commission not found or ambiguous
             }
         } else {
              // No commission filter applied, check permission to view general user list
              // --- Permission Check Placeholder: Can user view ANY user? ---
              // if ($user->cannot('viewAny', User::class)) {
              //    Log::warning('[Chatbot] Permission denied: list all users.', ['user_id' => $user->id]);
              //    return response()->json(['reply' => "Sorry, you don't have permission to view the general user list. Try specifying a commission you have access to."], 403);
              // }
         }

         // Add other filters if needed (e.g., role, search term)
         if(!empty($params['name_or_email'])) {
             $searchTerm = $params['name_or_email'];
             $query->where(function($q) use ($searchTerm) {
                 $q->where('name', 'LIKE', '%'.$searchTerm.'%')
                   ->orWhere('email', 'LIKE', '%'.$searchTerm.'%');
             });
         }

         $usersList = $query->orderBy('name')->limit(20)->get(['id', 'name', 'email']);

         if ($usersList->isEmpty()) {
             $replyMsg = "No users found";
             if ($filteredByCommission) $replyMsg .= " in the '{$commissionName}' commission";
             if (!empty($params['name_or_email'])) $replyMsg .= " matching '{$params['name_or_email']}'";
             $replyMsg .= ".";
             return response()->json(['reply' => $replyMsg]);
         }

         $context = $filteredByCommission ? " in the '{$commissionName}' commission" : "";
         if (!empty($params['name_or_email'])) $context .= " matching '{$params['name_or_email']}'";
         $replyText = "Here are the users I found{$context}:\n" .
                      $usersList->map(fn($u) => "- {$u->name} ({$u->email})")->implode("\n");

         return response()->json(['reply' => $replyText]);
     }

     protected function listPvs(User $user, array $params): JsonResponse
     {
        Log::debug('[Chatbot] Listing PVs', ['user_id' => $user->id, 'params' => $params]);
        // Fetch PVs related to meetings in commissions the user is a member of
        $commissionIds = $user->commissions()->pluck('commissions.id');
        if ($commissionIds->isEmpty()) {
            return response()->json(['reply' => "You need to be part of a commission to view PVs."]);
        }

        // --- Permission Check Placeholder ---
        // if ($user->cannot('viewAny', Pv::class)) { // Basic check
        //    return response()->json(['reply' => "Sorry, you don't have permission to view PVs."], 403);
        // }

        $query = Pv::query()->with(['meeting:id,title,date,commission_id', 'meeting.commission:id,name']);

        // --- Permission Scope ---
        // Filter PVs based on meetings in commissions the user has access to
        $query->whereHas('meeting', fn($q) => $q->whereIn('commission_id', $commissionIds));

        // --- Filtering ---
        $filterDescription = "";
        $commissionName = '';

        // Filter by specific commission?
        if (!empty($params['commission_name_or_id'])) {
             try {
                  // User must be member to list PVs *for* that commission
                 $commission = $this->findCommissionOrFail($params['commission_name_or_id'], $user, true);
                 $commissionName = $commission->name;
                 $query->whereHas('meeting', fn($q) => $q->where('commission_id', $commission->id));
                 $filterDescription .= " for '{$commissionName}' commission";
             } catch (ModelNotFoundException $e) {
                 return response()->json(['reply' => $e->getMessage()], $e->getCode() === 0 ? 404 : 403);
             }
        }

        // Filter by meeting title
        if (!empty($params['meeting_title'])) {
             $query->whereHas('meeting', fn($q) => $q->where('title', 'like', '%'.$params['meeting_title'].'%'));
             $filterDescription .= " for meetings titled '{$params['meeting_title']}'";
        }

        // Filter by timeframe (based on meeting date)
         if (!empty($params['timeframe'])) {
              try {
                 $now = Carbon::now($user->timezone ?? config('app.timezone'));
                 $timeframe = strtolower(trim($params['timeframe']));
                 $query->whereHas('meeting', function($q) use ($timeframe, $now, $user) {
                     // Apply similar date logic as in listUserMeetings
                     if ($timeframe === 'today') {
                         $q->whereDate('date', $now->toDateString());
                     } elseif (in_array($timeframe, ['this week', 'this_week'])) {
                          $q->whereBetween('date', [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()]);
                     } elseif ($timeframe === 'upcoming') {
                          $q->where('date', '>=', $now->toDateTimeString());
                     } elseif (in_array($timeframe, ['past', 'recent', 'previous'])) {
                          $q->where('date', '<', $now->toDateTimeString());
                     } else {
                          $parsedDateStart = Carbon::parse($timeframe, $user->timezone ?? config('app.timezone'));
                           if (Str::contains($timeframe, ['year'])) {
                              $q->whereBetween('date', [$parsedDateStart->startOfYear()->toDateTimeString(), $parsedDateStart->endOfYear()->toDateTimeString()]);
                          } elseif (Str::contains($timeframe, ['month'])) {
                               $q->whereBetween('date', [$parsedDateStart->startOfMonth()->toDateTimeString(), $parsedDateStart->endOfMonth()->toDateTimeString()]);
                          } elseif (Str::contains($timeframe, ['week'])) {
                               $q->whereBetween('date', [$parsedDateStart->startOfWeek()->toDateTimeString(), $parsedDateStart->endOfWeek()->toDateTimeString()]);
                          } else {
                              $q->whereDate('date', $parsedDateStart->toDateString());
                          }
                     }
                 });
                 $filterDescription .= " from timeframe '{$params['timeframe']}'";
              } catch (\Exception $e) {
                 Log::warning('[Chatbot] Could not parse timeframe for PV list.', ['timeframe' => $params['timeframe'], 'error' => $e->getMessage(), 'user_id' => $user->id]);
                 // Ignore filter on error, maybe return message?
                 return response()->json(['reply' => "Sorry, I couldn't understand the timeframe '{$params['timeframe']}'."]);
              }
         }

        $pvs = $query->latest('pvs.created_at') // Order by PV creation date
                    ->limit(10)
                    ->select(['pvs.id', 'pvs.meeting_id', 'pvs.created_at']) // Exclude 'content' for listing
                    ->get();

        if ($pvs->isEmpty()) {
            return response()->json(['reply' => "No PVs found matching your criteria{$filterDescription}."]);
        }

        $replyText = "Here are the latest PVs{$filterDescription}:\n" .
                     $pvs->map(function($pv) {
                        $meetingTitle = $pv->meeting?->title ?? 'Unknown Meeting';
                         // Ensure date is Carbon instance before formatting
                        $meetingDateObj = $pv->meeting?->date;
                        $meetingDate = ($meetingDateObj instanceof Carbon ? $meetingDateObj : Carbon::parse($meetingDateObj))->format('M j, Y');
                        $commissionName = $pv->meeting?->commission?->name ?? 'Unknown Commission';
                        return "- PV ID {$pv->id} for meeting '{$meetingTitle}' ({$commissionName} on {$meetingDate})";
                     })->implode("\n");
         $replyText .= "\nYou can ask to generate the text for a specific PV using its ID (e.g., 'generate pv text for id 123').";

        return response()->json(['reply' => $replyText]);
    }


    // --- CREATE Methods ---
    protected function createCommission(User $user, array $params): JsonResponse
    {
         // --- Permission check placeholder already in handleIntent ---

        Log::debug('[Chatbot] Attempting to create commission', ['user_id' => $user->id, 'params' => $params]);
        try {
            // Validate parameters received from LLM
            $validatedData = Validator::make($params, [
                'name' => ['required', 'string', 'max:255', 'unique:commissions,name'],
                'description' => ['nullable', 'string', 'max:1000'],
            ], [
                'name.required' => 'I need a name to create the commission.', // Custom messages
                'name.unique' => 'A commission with that name already exists.',
            ])->validate(); // Throws ValidationException on failure

            // Perform the creation
            $newCommission = Commission::create([
                'name' => $validatedData['name'],
                'description' => $validatedData['description'] ?? null,
                // Consider adding 'created_by_user_id' => $user->id, if your schema supports it
            ]);
            // Automatically add the creator as a member if relationship exists
            if (method_exists($newCommission, 'members')) {
                $newCommission->members()->attach($user->id);
                 Log::info('[Chatbot] Attached creator as member to new commission.', ['commission_id' => $newCommission->id, 'user_id' => $user->id]);
            } else {
                 Log::warning('[Chatbot] Could not attach creator as member - "members" relationship missing?', ['commission_id' => $newCommission->id]);
            }

            Log::info('[Chatbot] Commission created successfully via chatbot.', ['id' => $newCommission->id, 'name' => $validatedData['name'], 'user_id' => $user->id]);
            return response()->json(['reply' => "OK, I've created the commission: \"{$validatedData['name']}\" (ID: {$newCommission->id}) and added you as a member."]);

        } catch (ValidationException $e) {
            Log::warning('[Chatbot] Validation failed for commission creation.', ['errors' => $e->errors(), 'user_id' => $user->id]);
            $errorMessages = collect($e->errors())->flatten()->implode(' ');
            // Return the specific validation error message
            return response()->json(['reply' => "I couldn't create the commission. Problem: " . $errorMessages], 422);
        } catch (Throwable $dbError) {
            Log::error('[Chatbot] Database error creating commission:', ['error' => $dbError->getMessage(), 'user_id' => $user->id]);
            return response()->json(['reply' => "Sorry, I encountered a database error while creating the commission."], 500);
        }
    }

    protected function createMeeting(User $user, array $params): JsonResponse
    {
        Log::debug('[Chatbot] Attempting to create meeting', ['user_id' => $user->id, 'params' => $params]);
        try {
            // --- Resolve Commission ---
            $commissionIdentifier = $params['commission_name_or_id'] ?? null;
            if (!$commissionIdentifier) {
                 return response()->json(['reply' => "Which commission is this meeting for? Please provide the name or ID."], 422);
            }
             // Find commission AND check if user is member (required to create meeting *for* it)
            $commission = $this->findCommissionOrFail($commissionIdentifier, $user, true);

             // --- Permission Check Placeholder: Can user create meetings in this specific commission? ---
             // if ($user->cannot('createMeetingIn', $commission)) {
             //    Log::warning('[Chatbot] Permission denied: create meeting in commission.', ['user_id' => $user->id, 'commission_id' => $commission->id]);
             //    return response()->json(['reply' => "Sorry, you don't have permission to create meetings for the '{$commission->name}' commission."], 403);
             // }

            // Add resolved ID to params for validation
            $params['commission_id'] = $commission->id;

            // --- Parse Date ---
             if (isset($params['date'])) {
                 try {
                     // Attempt to parse various relative/absolute formats
                     $parsedDate = Carbon::parse($params['date'], $user->timezone ?? config('app.timezone')); // Use user's timezone
                     $now = Carbon::now($user->timezone ?? config('app.timezone'));

                     // Heuristic: If only time is given (or date is today but time is past), assume next occurrence (could be today later, or tomorrow)
                    $isOnlyTime = !preg_match('/\d{4}-\d{2}-\d{2}|\d{1,2}[-\/]\d{1,2}|\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/i', $params['date']);
                    if ($isOnlyTime && $parsedDate->isPast()) {
                         $parsedDate->addDay(); // Assume user meant the next day if only time was given and it's passed for today
                         Log::debug('[Chatbot] Assuming meeting date is next day based on past time.', ['original' => $params['date'], 'parsed' => $parsedDate->toDateTimeString(), 'user_id' => $user->id]);
                    } elseif ($parsedDate->isPast() && $parsedDate->isToday() && $parsedDate->diffInMinutes($now) > 5) {
                         // If specific date was today but time already passed recently, still suggest next day? More complex. Let's stick to only-time for now.
                         // Or ask for clarification? For now, we'll allow scheduling for past times on the same day if date was specified.
                         Log::debug('[Chatbot] Meeting date/time is in the past for today.', ['original' => $params['date'], 'parsed' => $parsedDate->toDateTimeString(), 'user_id' => $user->id]);
                    }

                      // Check if the parsed date makes sense (not excessively far in past/future unless specified clearly)
                      if ($parsedDate->isPast() && $parsedDate->diffInDays($now) > 30 && !Str::contains(strtolower($params['date']), ['last', 'past', 'ago'])) {
                            Log::warning('[Chatbot] Meeting date parsed significantly in the past.', ['original' => $params['date'], 'parsed' => $parsedDate->toDateTimeString(), 'user_id' => $user->id]);
                            // Maybe ask for confirmation instead of failing?
                            // return response()->json(['reply' => "That date seems quite far in the past ({$parsedDate->format('Y-m-d H:i')}). Is that correct?"]);
                      }
                      if ($parsedDate->isFuture() && $parsedDate->diffInYears($now) > 3 && !Str::contains(strtolower($params['date']), ['next year', 'in 2 years', 'in 3 years'])) {
                           Log::warning('[Chatbot] Meeting date parsed significantly in the future.', ['original' => $params['date'], 'parsed' => $parsedDate->toDateTimeString(), 'user_id' => $user->id]);
                           // Consider asking for confirmation
                      }

                     $params['date'] = $parsedDate->format('Y-m-d H:i:s'); // Standard format for validation/storage
                 } catch (\Exception $e) {
                     Log::warning('[Chatbot] Failed to parse date from LLM.', ['date_string' => $params['date'], 'error' => $e->getMessage(), 'user_id' => $user->id]);
                     return response()->json(['reply' => "The date '{$params['date']}' doesn't look right. Please provide it like 'YYYY-MM-DD HH:MM' or 'next Tuesday at 3pm'."], 422);
                 }
             }

             // --- Validate All Params ---
            $validatedData = Validator::make($params, [
                'title' => ['required', 'string', 'max:255'],
                'date' => ['required', 'date_format:Y-m-d H:i:s', 'after_or_equal:'.Carbon::now()->subYears(5)->format('Y-m-d H:i:s')], // Add reasonable past limit
                'location' => ['required', 'string', 'max:255'],
                'commission_id' => ['required', 'integer', 'exists:commissions,id'],
                 // Relax GPS regex slightly, allow space after comma
                'gps' => ['nullable', 'string', 'max:100', 'regex:/^-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/'],
            ], [
                 'title.required' => 'What is the title of the meeting?',
                 'date.required' => 'When is the meeting? Please provide a date and time.',
                 'date.date_format' => 'Please provide the date like YYYY-MM-DD HH:MM or a relative term like "tomorrow 2pm".',
                 'date.after_or_equal' => 'The meeting date seems too far in the past.',
                 'location.required' => 'Where will the meeting take place?',
                 'gps.regex' => 'GPS coordinates should be in the format "latitude,longitude" (e.g., 40.7128,-74.0060).',
                 'commission_id.required' => 'Internal error: Commission ID missing after resolving.',
                 'commission_id.exists' => 'Internal error: Commission ID is invalid.', // Should be caught by findCommissionOrFail
            ])->validate(); // Throws ValidationException

            // --- Perform Creation ---
            $newMeeting = Meeting::create($validatedData);

            Log::info('[Chatbot] Meeting created successfully via chatbot.', ['id' => $newMeeting->id, 'title' => $validatedData['title'], 'user_id' => $user->id]);
            $friendlyDate = Carbon::parse($validatedData['date'])->format('l, F jS \a\t g:i A'); // User friendly format
            return response()->json(['reply' => "OK, I've scheduled the meeting \"{$validatedData['title']}\" (ID: {$newMeeting->id}) for the '{$commission->name}' commission on {$friendlyDate} at '{$validatedData['location']}'."]);

        } catch (ModelNotFoundException $e) {
             // Catch specific errors from findCommissionOrFail
             Log::warning("[Chatbot] Model not found during meeting creation: " . $e->getMessage(), ['user_id' => $user->id]);
             return response()->json(['reply' => $e->getMessage()], $e->getCode() === 0 ? 404 : 403); // Use 403 if membership check failed
        } catch (ValidationException $e) {
            Log::warning('[Chatbot] Validation failed for meeting creation.', ['errors' => $e->errors(), 'user_id' => $user->id]);
            $errorMessages = collect($e->errors())->flatten()->implode(' ');
            return response()->json(['reply' => "I couldn't schedule the meeting. Problem: " . $errorMessages], 422);
        } catch (Throwable $dbError) {
            Log::error('[Chatbot] Database error creating meeting:', ['error' => $dbError->getMessage(), 'user_id' => $user->id]);
            return response()->json(['reply' => "Sorry, I encountered a database error while scheduling the meeting."], 500);
        }
    }


    // --- Commission Member Management ---
    protected function manageCommissionMember(User $user, array $params, string $action): JsonResponse
    {
        // $action should be 'add' or 'remove'
        Log::debug("[Chatbot] Attempting to {$action} commission member", ['user_id' => $user->id, 'params' => $params]);
        try {
            // --- Validate Required Params from LLM ---
             $paramValidator = Validator::make($params, [
                'commission_name_or_id' => ['required', 'string'],
                'user_name_or_email' => ['required', 'string'],
             ], [
                 'commission_name_or_id.required' => "Which commission are we modifying?",
                 'user_name_or_email.required' => "Which user do you want to {$action}?",
             ]);

             if ($paramValidator->fails()) {
                 // Return validation error directly
                 return response()->json(['reply' => $paramValidator->errors()->first()], 422);
             }
             $validatedParams = $paramValidator->validated();

             // --- Resolve Commission (Don't check acting user's membership here, check permission below) ---
             $commission = $this->findCommissionOrFail($validatedParams['commission_name_or_id'], $user, false);

            // --- Permission Check Placeholder: Can the current user modify members of *this* commission? ---
             // if ($user->cannot('updateMembers', $commission)) {
             //     Log::warning('[Chatbot] Permission denied: modify commission members.', ['user_id' => $user->id, 'commission_id' => $commission->id]);
             //     return response()->json(['reply' => "Sorry, you don't have permission to manage members for the '{$commission->name}' commission."], 403);
             // }

            // --- Resolve User ---
            $userToManage = $this->findUserOrFail($validatedParams['user_name_or_email'], $user);

             // --- Permission Check Placeholder: Can the target user BE managed (e.g. cannot remove admin)? ---
             // if ($action === 'remove' && $user->cannot('removeMember', [$commission, $userToManage])) {
             //     Log::warning('[Chatbot] Permission denied: remove specific user from commission.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
             //     return response()->json(['reply' => "Sorry, you cannot remove '{$userToManage->name}' from this commission."], 403);
             // }
              // if ($action === 'add' && $user->cannot('addMember', [$commission, $userToManage])) {
             //     Log::warning('[Chatbot] Permission denied: add specific user to commission.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
             //     return response()->json(['reply' => "Sorry, you cannot add '{$userToManage->name}' to this commission."], 403);
             // }


            // --- Perform Action ---
             $message = '';
             $relationship = $commission->members(); // Get the relationship instance

             if ($action === 'add') {
                 if ($relationship->where('user_id', $userToManage->id)->exists()) {
                      $message = "'{$userToManage->name}' is already a member of '{$commission->name}'.";
                      Log::info('[Chatbot] User already member, skipping add.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
                 } else {
                      $relationship->attach($userToManage->id);
                      $message = "OK, I've added '{$userToManage->name}' to the '{$commission->name}' commission.";
                      Log::info('[Chatbot] Added user to commission.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
                      // TODO: Optionally trigger WebsiteChange event or notification
                      // event(new WebsiteChange("User {$userToManage->name} added to commission {$commission->name}"));
                 }
             } elseif ($action === 'remove') {
                  // Prevent user from removing themselves via chatbot? Let's allow for now.
                  // Can add check: if ($user->id === $userToManage->id) { ... }

                  if (!$relationship->where('user_id', $userToManage->id)->exists()) {
                       $message = "'{$userToManage->name}' is not a member of '{$commission->name}', so I cannot remove them.";
                       Log::info('[Chatbot] User not member, skipping remove.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
                  } else {
                      $relationship->detach($userToManage->id);
                      $message = "OK, I've removed '{$userToManage->name}' from the '{$commission->name}' commission.";
                      Log::info('[Chatbot] Removed user from commission.', ['user_id' => $user->id, 'target_user_id' => $userToManage->id, 'commission_id' => $commission->id]);
                      // TODO: Optionally trigger WebsiteChange event or notification
                       // event(new WebsiteChange("User {$userToManage->name} removed from commission {$commission->name}"));
                  }
             }

             return response()->json(['reply' => $message]);

        } catch (ModelNotFoundException $e) {
             Log::warning("[Chatbot] Model not found during member management: " . $e->getMessage(), ['user_id' => $user->id]);
             // Status code depends on whether it was commission or user lookup
             return response()->json(['reply' => $e->getMessage()], 404);
        } catch (ValidationException $e) { // Catch validation errors from the initial param check
             Log::warning('[Chatbot] Validation failed for member management params.', ['errors' => $e->errors(), 'user_id' => $user->id]);
             return response()->json(['reply' => "I seem to be missing some information: " . collect($e->errors())->flatten()->implode(' ')], 422);
        } catch (Throwable $dbError) {
            Log::error('[Chatbot] Database error managing commission member:', ['error' => $dbError->getMessage(), 'user_id' => $user->id]);
            return response()->json(['reply' => "Sorry, I encountered a database error while managing the commission member."], 500);
        }
    }


    // --- Suggestion ---
    protected function suggestDetails(User $user, array $params): JsonResponse
    {
        // --- Permission Check Placeholder ---
        // Should user be allowed to get suggestions? Usually yes.
        // if ($user->cannot('getSuggestions')) { ... }

        $itemType = $params['item_type'] ?? null;
        $context = $params['context'] ?? '';
        Log::info('[Chatbot] Suggesting details', ['item_type' => $itemType, 'context' => $context, 'user_id' => $user->id]);

        $suggestions = []; // Store key-value pairs for frontend pre-fill
        $replyLines = []; // Build the text reply line by line
        $replyLines[] = "Okay, thinking about details";

        if ($itemType === 'meeting') {
            $replyLines[0] .= " for the meeting"; // Append to the first line
             if ($context) { $replyLines[0] .= " about '{$context}'"; }
            $replyLines[0] .= ".";

            // Suggest Commission: Last used by this user OR most active user commission
            // Ensure user is actually member of the suggested commission
             $lastMeetingCommission = $user->commissions() // Start from user's commissions
                                       ->join('meetings', 'commissions.id', '=', 'meetings.commission_id')
                                       ->orderByDesc('meetings.created_at') // Order by meeting creation
                                       ->select('commissions.id', 'commissions.name')
                                       ->first();


            if ($lastMeetingCommission) {
                 $suggestions['commission_name_or_id'] = $lastMeetingCommission->id; // Suggest ID for reliability
                 $replyLines[] = "- **Commission:** How about '{$lastMeetingCommission->name}' (ID: {$lastMeetingCommission->id})? You used it recently.";
            } else {
                 // If no recent meeting, suggest their first commission?
                 $firstCommission = $user->commissions()->orderBy('commission_user.created_at')->first(['commissions.id', 'commissions.name']);
                 if($firstCommission) {
                     $suggestions['commission_name_or_id'] = $firstCommission->id;
                     $replyLines[] = "- **Commission:** Maybe '{$firstCommission->name}' (ID: {$firstCommission->id})?";
                 } else {
                    $replyLines[] = "- **Commission:** Which commission should this be for? (I couldn't easily suggest one).";
                 }
            }

            // Suggest Date/Time: Next suitable slot (e.g., weekday 10 AM or 2 PM)
            $now = Carbon::now($user->timezone ?? config('app.timezone'));
            $nextDate = $now->copy();
             // Find next available slot, skipping current hour if close to end
            if ($now->minute > 45) $nextDate->addHour();
            else $nextDate->addMinutes(60 - $now->minute); // Go to start of next hour

            // Try 10 AM or 2 PM on next weekday
             $suggestedHour = ($nextDate->hour < 12 || $nextDate->hour > 16) ? 10 : 14; // Aim for 10am or 2pm
             $suggestedDateTime = $nextDate->copy()->setHour($suggestedHour)->minute(0)->second(0);

             // If suggested time already passed today, move to next day
             if ($suggestedDateTime->isPast()) {
                 $suggestedDateTime->addDay();
             }
             // Ensure it's a weekday
            if ($suggestedDateTime->isWeekend()) {
                 $suggestedDateTime->next(Carbon::MONDAY)->setHour($suggestedHour)->minute(0)->second(0);
            }

            $suggestions['date'] = $suggestedDateTime->format('Y-m-d H:i'); // Format for potential use
            $replyLines[] = "- **Date/Time:** Maybe {$suggestedDateTime->format('l \\a\\t g:i A')} ({$suggestions['date']})?";

             // Suggest Location: Last used non-online location by user or a default
             $lastPhysicalMeeting = Meeting::whereHas('commission.members', fn($q) => $q->where('user_id', $user->id))
                                          ->whereNotIn(Str::lower('location'), ['online', 'teams', 'zoom', 'google meet', 'webex', 'remote']) // Case insensitive check
                                          ->whereNotNull('location')
                                          ->latest('meetings.created_at')
                                          ->value('location'); // Get just the location value

             if ($lastPhysicalMeeting) {
                 $suggestions['location'] = $lastPhysicalMeeting;
                 $replyLines[] = "- **Location:** Use '{$lastPhysicalMeeting}' again?";
             } else {
                 $suggestions['location'] = 'Conference Room A'; // Sensible physical default
                  $replyLines[] = "- **Location:** Maybe '{$suggestions['location']}' or 'Online' if remote?";
             }

            // Suggest Title based on context
            if ($context) {
                 $baseTitle = Str::title(Str::limit($context, 50));
                 $suggestions['title'] = $baseTitle . " Meeting";
                 $replyLines[] = "- **Title:** How about '{$suggestions['title']}'?";
            } else {
                 $replyLines[] = "- **Title:** What should the meeting title be?";
            }


        } elseif ($itemType === 'commission') {
             $replyLines[0] .= " for the commission";
              if ($context) { $replyLines[0] .= " related to '{$context}'"; }
             $replyLines[0] .= ".";

             // Suggest Name based on context
              if ($context) {
                  $baseName = Str::title(Str::limit($context, 40));
                   // Check if name might exist
                   $potentialName = $baseName . " Commission";
                   $exists = Commission::where('name', $potentialName)->exists();
                  $suggestions['name'] = $potentialName . ($exists ? ' (' . date('Y') . ')' : ''); // Add year if exists
                  $replyLines[] = "- **Name:** How about '{$suggestions['name']}'" . ($exists ? ' (added year as similar exists)' : '') . "?";
              } else {
                  $replyLines[] = "- **Name:** What should the commission be called?";
              }

             // Suggest Description
             $suggestions['description'] = "A commission focused on " . ($context ? Str::limit($context, 100) : 'relevant activities and objectives.');
             $replyLines[] = "- **Description:** We could use: '{$suggestions['description']}'.";
        }
        else {
            return response()->json(['reply' => "I can suggest details, but I need to know if it's for a 'meeting' or a 'commission'. What are we setting up?"]);
        }

        $replyLines[] = "\nHow do these suggestions look? You can use them or provide your own details.";

        return response()->json([
            'reply' => implode("\n", $replyLines), // Join lines into a single reply string
            'suggestions' => $suggestions // Send suggestions object for frontend use
        ]);
    }

    // --- Generate PV Text ---
    protected function generatePvText(User $user, array $params): JsonResponse
    {
        Log::debug('[Chatbot] Attempting to generate PV text link', ['user_id' => $user->id, 'params' => $params]);
        try {
            $validatedData = Validator::make($params, [
                'pv_id' => ['required', 'integer', 'min:1'], // Ensure it's a positive integer
            ], [
                'pv_id.required' => "Which PV do you want the text for? Please provide the PV ID number.",
                'pv_id.integer' => "The PV ID needs to be a number.",
                'pv_id.min' => "The PV ID needs to be a valid number.",
            ])->validate();

            $pvId = $validatedData['pv_id'];

            // Find the PV and eagerly load necessary relations for permission check
            $pv = Pv::with('meeting:id,title,commission_id')->find($pvId);

            if (!$pv) {
                 throw new ModelNotFoundException("I couldn't find a PV with ID {$pvId}.");
            }
            if (!$pv->meeting) {
                 Log::error('[Chatbot] PV found but missing meeting relation.', ['pv_id' => $pvId, 'user_id' => $user->id]);
                 throw new \RuntimeException("There's an issue with the data for PV ID {$pvId} (missing meeting link).", 500);
            }

            // --- Permission Check: Can the user access this PV? ---
            // Check if the meeting belongs to a commission the user is a member of
            $commissionIds = $user->commissions()->pluck('commissions.id');
            if (!$commissionIds->contains($pv->meeting->commission_id)) {
                // --- Additionally, check specific PV permission policy if exists ---
                // if ($user->cannot('view', $pv)) { ... }

                Log::warning('[Chatbot] User attempted to generate text for inaccessible PV.', [
                    'user_id' => $user->id, 'pv_id' => $pvId, 'meeting_id' => $pv->meeting->id, 'commission_id' => $pv->meeting->commission_id
                ]);
                // Use 403 for permission denied
                return response()->json(['reply' => "Sorry, you don't have permission to access the text for PV ID {$pvId} (Meeting: '{$pv->meeting->title}')."], 403);
            }

            // Generate the URL to the existing PVController endpoint
            // Ensure you have a named route like 'api.pvs.generateText' in routes/api.php
            // Example route definition: Route::get('/pvs/{pv_id}/generate-text', [App\Http\Controllers\Api\PVController::class, 'generateText'])->name('api.pvs.generateText');
             try {
                 $downloadUrl = route('api.pvs.generateText', ['pv_id' => $pvId]);
             } catch (\Exception $routeException) {
                 Log::error('[Chatbot] Failed to generate route for PV text download.', ['error' => $routeException->getMessage()]);
                 return response()->json(['reply' => "Sorry, I couldn't create the download link due to a configuration issue."], 500);
             }


             Log::info('[Chatbot] Providing PV text generation link.', ['user_id' => $user->id, 'pv_id' => $pvId]);

            // Instruct the frontend to initiate the download
            return response()->json([
                'reply' => "Okay, here is the link to download the text file for PV ID {$pvId} ('{$pv->meeting->title}').",
                'action' => [
                    'type' => 'download', // Custom action type for frontend
                    'url' => $downloadUrl,
                     'filename' => 'pv_' . $pvId . '.txt' // Suggested filename
                ]
            ]);

        } catch (ValidationException $e) {
             // Return specific validation error
             return response()->json(['reply' => collect($e->errors())->flatten()->implode(' ')], 422);
        } catch (ModelNotFoundException $e) {
             // Return not found error
             return response()->json(['reply' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            Log::error('[Chatbot] Error preparing PV text download link:', ['error' => $e->getMessage(), 'user_id' => $user->id, 'pv_id' => $params['pv_id'] ?? 'unknown']);
            return response()->json(['reply' => "Sorry, an unexpected error occurred while preparing the PV text download link."], 500);
        }
    }

}