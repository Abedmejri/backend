<?php

use Illuminate\Support\Facades\Route;
use App\Events\WebsiteChange;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log; // Make sure Log is imported
use App\Http\Controllers\Auth\GoogleController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
// Google Auth Routes
Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login'); // Route for starting the redirect
Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback'])->name('google.callback'); // Route Google redirects back to

Route::get('/', function () {
    return view('welcome');
});


Route::get('/test-notification', function () {
    // Trigger the WebsiteChange event
    event(new WebsiteChange('Test notification!'));
    return "Notification event triggered!";
});
Route::get('/test-middleware', function () {
    return response()->json(['message' => 'Middleware test']);
})->middleware('update.last.seen');

// routes/web.php or routes/api.php


Route::get('/testmail', function () {
    try {
        $testEmail = 'your-personal-email@example.com'; // <-- PUT YOUR REAL EMAIL HERE to receive the test
        Log::info("Attempting to send test email to: " . $testEmail); // Log attempt
        Mail::raw('This is a test email body from Laravel.', function ($message) use ($testEmail) {
            $message->to($testEmail)
                    ->subject('Laravel Gmail SMTP Test');
        });
        Log::info('Test email dispatched successfully via Mail facade.'); // Log success
        return 'Test email dispatch attempted! Check inbox/spam for ' . $testEmail . ' and check laravel.log for details.';
    } catch (\Exception $e) {
        Log::error("Mail test failed: " . $e->getMessage()); // Log the specific error
        return 'Mail test failed: ' . $e->getMessage(); // Show error in browser
    }
});