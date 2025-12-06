<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramState extends Model
{
    protected $table = 'telegram_states';
    
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    
    protected $fillable = [
        'user_id',
        'step',
        'data',
    ];
    
    protected $casts = [
        'data' => 'array',
    ];
}
