<?php

namespace App\Console\Commands;

use App\Models\Slot;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateSlots extends Command
{
    /**
     * Пример:
     *  php artisan slots:generate
     *  php artisan slots:generate 2025-12-10 --start=15:00 --end=20:00 --step=10
     */
    protected $signature = 'slots:generate
                            {date? : Дата в формате Y-m-d, по умолчанию сегодня}
                            {--start=15:00 : Время начала, HH:MM}
                            {--end=20:00 : Время конца, HH:MM}
                            {--step=10 : Шаг в минутах}';
    
    protected $description = 'Генерация тайм-слотов для бронирования пиццы';
    
    public function handle(): int
    {
        // 1) Дата
        $dateInput = $this->argument('date');
        try {
            $date = $dateInput
                ? Carbon::parse($dateInput)->startOfDay()
                : now()->startOfDay();
        } catch (\Throwable $e) {
            $this->error('Неверный формат даты. Ожидаю Y-m-d, например 2025-12-06');
            return self::FAILURE;
        }
        
        // 2) Время начала/конца и шаг
        $startStr = $this->option('start'); // '15:00'
        $endStr   = $this->option('end');   // '20:00'
        $step     = (int) $this->option('step'); // минуты
        
        if ($step <= 0) {
            $this->error('Опция --step должна быть > 0');
            return self::FAILURE;
        }
        
        try {
            $start = $date->copy()->setTimeFromTimeString($startStr);
            $end   = $date->copy()->setTimeFromTimeString($endStr);
        } catch (\Throwable $e) {
            $this->error('Неверный формат времени. Ожидаю HH:MM, например 15:00');
            return self::FAILURE;
        }
        
        if ($end->lt($start)) {
            $this->error('Время конца меньше времени начала.');
            return self::FAILURE;
        }
        
        // 3) Генерация слотов
        $created = 0;
        $skipped = 0;
        
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            /** @var \App\Models\Slot $slot */
            $slot = Slot::firstOrCreate(
                ['slot_time' => $cursor->copy()->seconds(0)],
                [] // дополнительные поля не нужны
            );
            
            if ($slot->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
            
            $cursor->addMinutes($step);
        }
        
        $this->info(sprintf(
            'Слоты на %s с %s по %s каждые %d мин.: создано %d, пропущено (уже были) %d.',
            $date->toDateString(),
            $startStr,
            $endStr,
            $step,
            $created,
            $skipped
        ));
        
        return self::SUCCESS;
    }
}
