<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log; // Added for logging

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'date', 'location', 'gps', 'commission_id'];

    protected $casts = [
       'date' => 'datetime', // For retrieval, Carbon will try to parse based on DB format & app timezone
    ];

    /**
     * Set the date attribute.
     * Input $value is expected to be a 'Y-m-d H:i:s' string in UTC.
     *
     * @param  string|null  $value
     * @return void
     */
    public function setDateAttribute($value)
    {
        Log::info("BACKEND MeetingModel@setDateAttribute - 1. INPUT value received by mutator: " . print_r($value, true));
        if ($value) {
             try {
                 // Explicitly parse the incoming string as UTC with the expected format
                 // The third argument 'UTC' tells Carbon that the $value string IS ALREADY IN UTC.
                 $parsedDate = Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');

                 // The $parsedDate will be a Carbon object in UTC.
                 // When Eloquent saves it, it will convert it to the database's expected format.
                 // If your DB connection timezone is UTC (default for MySQL with Laravel), it will be stored as is.
                 $this->attributes['date'] = $parsedDate;

                 Log::info("BACKEND MeetingModel@setDateAttribute - 2. PARSED Carbon object (should be UTC): " . $parsedDate->toDateTimeString() . " (Timezone of Carbon obj: " . $parsedDate->tzName . ")");
                 Log::info("BACKEND MeetingModel@setDateAttribute - 3. attributes['date'] is NOW (Carbon object): " . ($this->attributes['date'] instanceof \Carbon\Carbon ? $this->attributes['date']->toDateTimeString() . " TZ: " . $this->attributes['date']->tzName : print_r($this->attributes['date'], true)));

             } catch (\Exception $e) {
                  Log::error("BACKEND MeetingModel@setDateAttribute - Date parsing FAILED: ". $e->getMessage() . " for value: '" . $value . "'", [
                    'class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                  ]);
                  $this->attributes['date'] = null; // Or handle error appropriately
             }
        } else {
            $this->attributes['date'] = null;
            Log::info("BACKEND MeetingModel@setDateAttribute - 4. Input value was null/empty, setting attributes['date'] to null.");
        }
    }

    // Relationship with commission
    public function commission()
    {
        return $this->belongsTo(Commission::class);
    }
}