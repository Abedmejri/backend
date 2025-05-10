<?php
// Commission.php (Model)
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    // Relationship with members (users)
    public function members()
    {
        return $this->belongsToMany(User::class, 'commission_user', 'commission_id', 'user_id');
    }

    // Relationship with meetings
    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}