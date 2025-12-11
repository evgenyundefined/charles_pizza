<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramUser extends Model
{
    protected $table = 'telegram_users';
    
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'display_name',
        'language_code',
        'language',
        'is_bot',
        'last_seen_at',
    ];
    
    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_bot'       => 'boolean',
    ];
    
    public function slots(): HasMany
    {
        // связь по telegram_id ↔ booked_by
        return $this->hasMany(Slot::class, 'booked_by', 'telegram_id');
    }
}
