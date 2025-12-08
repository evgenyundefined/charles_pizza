<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'telegram_id',
        'chat_id',
        'direction',
        'type',
        'message_id',
        'text',
        'payload',
    ];
    
    protected $casts = [
        'payload' => 'array',
    ];
}
