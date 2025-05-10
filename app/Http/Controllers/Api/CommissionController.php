<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Meeting; // Import the Meeting model
use Illuminate\Http\Request;
use App\Events\WebsiteChange;
class CommissionController extends Controller
{
    // Get all commissions with the count of meetings
    public function index()
    {
        try {
            $commissions = Commission::with('members')->withCount(['meetings', 'members'])->get();
            
            return response()->json($commissions);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch commissions',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Get a single commission with the count of meetings
    public function show($id)
    {
        try {
            $commission = Commission::with('members')->withCount('meetings')->find($id);
            if (!$commission) {
                return response()->json(['error' => 'Commission not found'], 404);
            }
            return response()->json($commission);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch commission',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Create a new commission
    public function store(Request $request)
{
    try {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'members' => 'nullable|array',
            'members.*' => 'exists:users,id',
        ]);

        $commission = Commission::create([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        if ($request->has('members')) {
            $commission->members()->sync($request->members);
        }

        $commission->load('members');

        // Broadcast the WebsiteChange event
        event(new WebsiteChange('A new commission has been created: ' . $commission->name));
        

        return response()->json($commission, 201);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to create commission',
            'message' => $e->getMessage(),
        ], 500);
    }
}
    // Update a commission
    public function update(Request $request, Commission $commission)
    {
        event(new WebsiteChange('A new commission has been updated: ' . $commission->name));
        try {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'members' => 'nullable|array',
                'members.*' => 'exists:users,id',
            ]);

            $commission->update([
                'name' => $request->name,
                'description' => $request->description,
            ]);

            if ($request->has('members')) {
                $commission->members()->sync($request->members);
            }

            $commission->load('members');
            return response()->json($commission);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update commission',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Delete a commission
    public function destroy(Commission $commission)
    {
        event(new WebsiteChange('A new commission has been deleted: ' . $commission->name));
        try {
            $commission->delete();
            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete commission',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Get meetings for a commission
    public function meetings($commissionId)
    {
        try {
            $meetings = Meeting::where('commission_id', $commissionId)->get();
            return response()->json($meetings);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch meetings',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Add a meeting for a commission
    public function storeMeeting(Request $request, $commissionId)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'date' => 'required|date',
                'location' => 'required|string|max:255',
            ]);

            $meeting = Meeting::create([
                'title' => $request->title,
                'date' => $request->date,
                'location' => $request->location,
                'commission_id' => $commissionId,
            ]);

            return response()->json($meeting, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create meeting',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Delete a meeting
    public function destroyMeeting($meetingId)
    {
        try {
            $meeting = Meeting::findOrFail($meetingId);
            $meeting->delete();
            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete meeting',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // CommissionController.php

// Add a user to a commission
public function addUser(Request $request, $commissionId)
{
    try {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $commission = Commission::findOrFail($commissionId);
        $commission->members()->attach($request->user_id);

        return response()->json([
            'message' => 'User added to commission successfully',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to add user to commission',
            'message' => $e->getMessage(),
        ], 500);
    }
}

// Remove a user from a commission
public function removeUser(Request $request, $commissionId, $userId)
{
    try {
        $commission = Commission::findOrFail($commissionId);
        $commission->members()->detach($userId);

        return response()->json([
            'message' => 'User removed from commission successfully',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to remove user from commission',
            'message' => $e->getMessage(),
        ], 500);
    }
}
  
public function updateUsers(Request $request, $commissionId)
{
    try {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $commission = Commission::findOrFail($commissionId);
        $commission->members()->sync($request->user_ids); // Sync users with the commission

        return response()->json([
            'message' => 'Users updated successfully',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to update users',
            'message' => $e->getMessage(),
        ], 500);
    }
}
}