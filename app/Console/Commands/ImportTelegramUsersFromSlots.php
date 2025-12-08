<?php

namespace App\Console\Commands;

use App\Models\Slot;
use App\Models\TelegramUser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ImportTelegramUsersFromSlots extends Command
{
    protected $signature = 'pizza:import-slot-users {--dry-run : Только показать, без сохранения}';
    
    protected $description = 'Импортировать пользователей из slots в telegram_users';
    
    public function handle(): int
    {
        // distinct по пользователям + берём какое-то имя и дату последней брони
        $rows = Slot::query()
            ->whereNotNull('booked_by')
            ->selectRaw('booked_by, MAX(booked_username) as name, MAX(booked_at) as last_booking_at')
            ->groupBy('booked_by')
            ->orderBy('booked_by')
            ->get();
        
        if ($rows->isEmpty()) {
            $this->info('В slots нет ни одной брони с booked_by – импортировать нечего.');
            return self::SUCCESS;
        }
        
        $created = 0;
        $updated = 0;
        
        foreach ($rows as $row) {
            $telegramId = (int) $row->booked_by;
            $rawName    = trim($row->name ?? '');
            
            $username   = null;
            $firstName  = null;
            $lastName   = null;
            
            if ($rawName !== '') {
                // если что-то вроде @nick или просто nick без пробелов — считаем ником
                $clean = ltrim($rawName, '@');
                
                if (!preg_match('/\s/u', $clean) && preg_match('/^[\p{L}\p{N}_]+$/u', $clean)) {
                    $username = $clean;
                } else {
                    // иначе это «Имя Фамилия» — кладём в first_name / last_name
                    $parts = preg_split('/\s+/', $rawName, 2);
                    $firstName = $parts[0] ?? null;
                    $lastName  = $parts[1] ?? null;
                }
            }
            
            if ($this->option('dry-run')) {
                $this->line("DRY-RUN: user {$telegramId}, username={$username}, first_name={$firstName}, last_name={$lastName}");
                continue;
            }
            
            /** @var TelegramUser $user */
            $user = TelegramUser::firstOrNew(['telegram_id' => $telegramId]);
            
            // не перезатираем уже существующие данные, только дополняем
            if ($username && !$user->username) {
                $user->username = $username;
            }
            if ($firstName && !$user->first_name) {
                $user->first_name = $firstName;
            }
            if ($lastName && !$user->last_name) {
                $user->last_name = $lastName;
            }
            
            if ($row->last_booking_at) {
                $lastBooking = Carbon::parse($row->last_booking_at);
                if (!$user->last_seen_at || $lastBooking->gt($user->last_seen_at)) {
                    $user->last_seen_at = $lastBooking;
                }
            }
            
            $wasNew = !$user->exists;
            $user->save();
            
            if ($wasNew) {
                $created++;
            } else {
                $updated++;
            }
        }
        
        if ($this->option('dry-run')) {
            $this->info("DRY-RUN завершён. Было бы обработано {$rows->count()} пользователей.");
        } else {
            $this->info("Готово. Создано: {$created}, обновлено: {$updated}, всего уникальных: {$rows->count()}.");
        }
        
        return self::SUCCESS;
    }
}
