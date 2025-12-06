<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slot extends Model
{
    protected $fillable = [
        'slot_time',
        'booked_by',
        'booked_username',
        'is_disabled',
        'comment',
        'is_completed',
        'booked_at',
    ];
    
    protected $casts = [
        'slot_time'    => 'datetime',
        'is_disabled'  => 'boolean',
        'is_completed' => 'boolean',
        'booked_at'    => 'datetime',
    ];
}



