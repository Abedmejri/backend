<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\PVController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\TwilioController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/send-commission-email', [EmailController::class, 'sendCommissionDetails']);
    Route::post('/chatbot', [ChatbotController::class, 'handleMessage'])->name('chatbot.message');

    Route::get('/user', function (Request $request) {
        return $request->user();
   
    });
    Broadcast::routes();
    Route::post('/commissions/{commission}/users', [CommissionController::class, 'addUser']);
    Route::delete('/commissions/{commission}/users/{user}', [CommissionController::class, 'removeUser']);
    Route::post('/commissions/{commission}/users', [CommissionController::class, 'updateUsers']);
    Route::apiResource('/users', UserController::class);
    Route::post('/commissions/{commissionId}/users/update', [CommissionController::class, 'updateUsers'])->name('commissions.users.update');
    
    Route::post('/process-recording', [RecordingController::class, 'process']);
    // Endpoint 1: Transcribe Audio
Route::post('/transcribe-audio', [RecordingController::class, 'transcribeAudio']);

// Endpoint 2: Generate Resume from Text
Route::post('/generate-resume', [RecordingController::class, 'generateResumeFromText']);
Route::post('/generate-resume', [RecordingController::class, 'generateMeetingSummary']);
// Remove or comment out the old combined route if it exists
 Route::post('/process-recording', [RecordingController::class, 'process']);
    

});




Route::post('/process-recording', [RecordingController::class, 'process']);
Route::post('/signup', [AuthController::class, 'signup']);
Route::post('/login', [AuthController::class, 'login']);
// Forgot Password Route
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
// Reset Password Route
Route::post('/reset-password', [ResetPasswordController::class, 'reset']);

Route::post('/commissions/{commission}/meetings', [MeetingController::class, 'store']);
Route::get('/commissions/{commission}/meetings', [MeetingController::class, 'index']);


Route::apiResource('/meetings', MeetingController::class);
Route::put('/meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');

Route::apiResource('/commissions', CommissionController::class);
Route::get('/commissions/{commissionId}/meetings', [MeetingController::class, 'getMeetingsByCommission']);

Route::get('pvs/{pv}/generate-pdf', [PvController::class, 'generatePvPdf'])->name('pvs.generatePdf');
Route::apiResource('/pvs', PVController::class);
Route::post('/pvs/generate-text', [PvController::class, 'generateText']);

//Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->group(function () {
    //Route::get('/admin-dashboard', [AdminController::class, 'dashboard']);
    
    
//});


Route::get('/permissions', [UserController::class, 'permissions']);
Route::post('/permissions', [UserController::class, 'createPermission']);
Route::delete('/permissions/{id}', [UserController::class, 'deletePermission']);


Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])
      ->middleware('guest') // Ensure only unauthenticated users can request this
      ->name('password.email'); // Optional: naming for convenience

// Route that will handle the actual password update after user clicks the email link
// This endpoint will be called by your *new* Reset Password React component
Route::post('/reset-password', [ResetPasswordController::class, 'reset'])
      ->middleware('guest')
      ->name('password.update'); // Matches Laravel's internal naming
     

      
Route::post('/twilio/token', [TwilioController::class, 'generateToken']);
      
  
Route::post('/twilio/voice-ai', [TwilioController::class, 'voiceAIWebhook'])->name('twilio.voice.ai');
    