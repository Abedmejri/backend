<?php

namespace App\Http\Controllers\Api;

use App\Models\Commission;
use App\Models\Meeting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Events\WebsiteChange;
use Illuminate\Support\Facades\Log; // Added for logging
use Illuminate\Validation\ValidationException; // For specific catch

class MeetingController extends Controller
{
    public function index()
    {
        $meetings = Meeting::with('commission')->orderBy('date', 'asc')->get();
        return response()->json($meetings);
    }

    public function show(Meeting $meeting)
    {
        $meeting->load('commission');
        return response()->json($meeting);
    }

    public function store(Request $request)
    {
        Log::info('BACKEND MeetingController@store - 0. Request received.');
        Log::info('BACKEND MeetingController@store - 1. ALL Request Data:', $request->all());

        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'date' => 'required|date_format:Y-m-d H:i:s', // Expects UTC string in this format
                'location' => 'required|string|max:255',
                'gps' => 'nullable|string',
                'commission_id' => 'required|exists:commissions,id',
            ]);
            Log::info('BACKEND MeetingController@store - 2. Validated Data:', $validatedData);
            Log::info('BACKEND MeetingController@store - 3. Value for "date" from validation: ' . ($validatedData['date'] ?? 'NOT SET'));

            $meeting = new Meeting();
            $meeting->title = $validatedData['title'];
            $meeting->date = $validatedData['date']; // Mutator in Meeting.php will be called
            $meeting->location = $validatedData['location'];
            $meeting->gps = $validatedData['gps'] ?? null;
            $meeting->commission_id = $validatedData['commission_id'];

            Log::info('BACKEND MeetingController@store - 4. Meeting object BEFORE save: ', [
                'date_attr_get_raw_original' => $meeting->getRawOriginal('date'), // Will be null before save
                'date_attr_direct_access' => isset($meeting->getAttributes()['date']) ? $meeting->getAttributes()['date'] : 'not_in_attributes_yet',
                'date_carbon_on_model' => $meeting->date ? ($meeting->date instanceof \Carbon\Carbon ? $meeting->date->toIso8601String() : 'date_is_not_carbon_obj') : 'date_is_null_on_model'
            ]);

            $meeting->save();

            Log::info('BACKEND MeetingController@store - 5. Meeting object AFTER save (ID: '.$meeting->id.') - Date from model (should be UTC): ' . ($meeting->date ? ($meeting->date instanceof \Carbon\Carbon ? $meeting->date->toDateTimeString() . ' (UTC)' : 'date_is_not_carbon_obj_after_save') : 'null_after_save'));
            Log::info('BACKEND MeetingController@store - 5a. Raw date attribute after save (from getAttributes): ' . ($meeting->getAttributes()['date'] ?? 'not_in_attributes_after_save'));


            $meeting->load('commission');
            event(new WebsiteChange('A new meeting has been created: ' . $meeting->title));
            return response()->json(['message' => 'Meeting created successfully', 'meeting' => $meeting], 201);

        } catch (ValidationException $e) {
            Log::error('BACKEND MeetingController@store - ValidationException:', ['errors' => $e->errors(), 'input' => $request->all()]);
            return response()->json(['error' => 'Validation failed', 'messages' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('BACKEND MeetingController@store - General Exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Meeting $meeting)
    {
        Log::info('BACKEND MeetingController@update - 0. Request received for meeting ID: ' . $meeting->id);
        Log::info('BACKEND MeetingController@update - 1. ALL Request Data:', $request->all());
        try {
            $validatedData = $request->validate([
                'title' => 'sometimes|string|max:255',
                'date' => 'sometimes|nullable|date_format:Y-m-d H:i:s', // Expects UTC string
                'location' => 'sometimes|string',
                'gps' => 'sometimes|nullable|string|regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/',
                'commission_id' => 'sometimes|exists:commissions,id',
            ]);

            Log::info('BACKEND MeetingController@update - 2. Validated Data:', $validatedData);
            if (isset($validatedData['date'])) {
                 Log::info('BACKEND MeetingController@update - 3. Value for "date" from validation: ' . $validatedData['date']);
            } else {
                 Log::info('BACKEND MeetingController@update - 3. "date" was not present in validated data.');
            }


            $meeting->update($validatedData); // Mutator will handle date if present
            Log::info('BACKEND MeetingController@update - 4. Meeting object AFTER update (ID: '.$meeting->id.') - Date from model (should be UTC): ' . ($meeting->date ? ($meeting->date instanceof \Carbon\Carbon ? $meeting->date->toDateTimeString() . ' (UTC)' : 'date_is_not_carbon_obj_after_update') : 'null_after_update'));
            Log::info('BACKEND MeetingController@update - 4a. Raw date attribute after update (from getAttributes): ' . ($meeting->getAttributes()['date'] ?? 'not_in_attributes_after_update'));


            $meeting->load('commission');
            event(new WebsiteChange('A meeting has been updated: ' . $meeting->title));
            return response()->json($meeting);

        } catch (ValidationException $e) {
            Log::error('BACKEND MeetingController@update - ValidationException:', ['errors' => $e->errors(), 'input' => $request->all()]);
            return response()->json(['error' => 'Validation failed', 'messages' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('BACKEND MeetingController@update - General Exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred during update.'], 500);
        }
    }

    public function destroy(Meeting $meeting)
    {
        try {
            $meetingTitle = $meeting->title;
            $meeting->delete();
            event(new WebsiteChange('A meeting has been deleted: ' . $meetingTitle));
            return response()->noContent();
        } catch (\Exception $e) {
            Log::error('BACKEND MeetingController@destroy - Deletion failed for meeting ID ' . $meeting->id . ': ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete meeting.'], 500);
        }
    }

    public function getMeetingsByCommission($commissionId)
    {
        try {
            if (!ctype_digit((string)$commissionId) || $commissionId <= 0) {
                 return response()->json(['error' => 'Invalid commission ID format.'], 400);
            }
            $commission = Commission::find($commissionId);
            if (!$commission) {
                return response()->json(['error' => 'Commission not found.'], 404);
            }
            $meetings = Meeting::where('commission_id', $commissionId)->with('commission')->orderBy('date', 'asc')->get();
            return response()->json($meetings);
        } catch (\Exception $e) {
            Log::error('BACKEND MeetingController@getMeetingsByCommission - Error: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred while fetching meetings.'], 500);
        }
    }
}