<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    /**
     * The users that belong to the permission.
     */
    protected $fillable = ['name', 'slug'];
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}