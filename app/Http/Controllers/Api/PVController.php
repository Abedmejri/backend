<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PV;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Events\WebsiteChange;

use Barryvdh\DomPDF\Facade as PDF;



class PVController extends Controller
{
    // Get all PVs
    public function index()
    {
        $pvs = PV::with('meeting')->get();
        return response()->json($pvs);
    }

    // Get a single PV
    public function show(PV $pv)
    {
        return response()->json($pv);
    }

    // Create a new PV
    public function store(Request $request)
    {
        \Log::info('Request Data:', $request->all()); // Log the request data
        event(new WebsiteChange('A new PV has been created: ' . $pv->name));
        $request->validate([
            'meeting_id' => 'required|exists:meetings,id',
            'content' => 'required|string',
        ]);
    
        try {
            $pv = PV::create($request->all());
            return response()->json($pv, 201);
        } catch (\Exception $e) {
            \Log::error('Error creating PV:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    // Update a PV
    public function update(Request $request, PV $pv)
    {
        $request->validate([
            'meeting_id' => 'sometimes|exists:meetings,id',
            'content' => 'sometimes|string',
        ]);
        event(new WebsiteChange('A new PV has been updated: ' . $pv->name));
        $pv->update($request->all());
        return response()->json($pv);
    }

    // Delete a PV
    public function destroy(PV $pv)
    {
        // Delete the associated PDF file
        if ($pv->pdf_path && Storage::exists($pv->pdf_path)) {
            Storage::delete($pv->pdf_path);
        }
        event(new WebsiteChange('A new PV has been deleted: ' . $pv->name));
        $pv->delete();
        return response()->noContent();
    }
    public function generateText(Request $request)
    {
        $pvId = $request->input('pv_id');
        $pv = PV::find($pvId);
        
        if (!$pv) {
            return response()->json(['error' => 'PV not found'], 404);
        }
    
        // Prepare the content as a plain text file
        $content = "Meeting Title: " . $pv->meeting->title . "\n\n";
        $content .= "Content:\n" . $pv->content . "\n";
    
        // Generate the .txt file
        $fileName = 'pv_' . $pvId . '.txt';
        $path = storage_path('app/' . $fileName);
        file_put_contents($path, $content);
    
        // Return the file as a response
        return response()->download($path, $fileName)->deleteFileAfterSend(true);
    }
}