<?php

namespace App\Models;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Models\PV;
use App\Models\Meeting;
use App\Models\Commission;
use App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PV extends Model
{
    use HasFactory;

    protected $table = 'pvs'; // Ensure this matches the table name in the database

    protected $fillable = [
        'meeting_id',
        'content',
        'pdf_path',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

public function generatePDF(Request $request)
{
    $request->validate([
        'pv_id' => 'required|exists:pvs,id',
    ]);

    $pv = PV::findOrFail($request->pv_id);
    $meeting = Meeting::findOrFail($pv->meeting_id);
    $commission = Commission::findOrFail($meeting->commission_id);
    $users = $meeting->users; // Assuming a relationship between Meeting and User

    $data = [
        'meetingTitle' => $meeting->title,
        'commissionName' => $commission->name,
        'content' => $pv->content,
        'users' => $users,
    ];

    $pdf = Pdf::loadView('pv-template', $data);
    return $pdf->download('pv.pdf');
}
}