<?php

namespace App\Http\Controllers;

use App\Models\Slot;
use App\Models\TelegramState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TelegramBotController extends Controller
{
    public function webhook(Request $request)
    {
        $update = $request->all();
        
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
        
        return response()->json(['ok' => true]);
    }
    
    /* ================== TELEGRAM API ================== */
    
    protected function tg(string $method, array $params = [])
    {
        $token = config('services.telegram.bot_token');
        
        return Http::asForm()
            ->post("https://api.telegram.org/bot{$token}/{$method}", $params)
            ->json();
    }
    
    protected function sendMessage($chatId, string $text, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        
        $this->tg('sendMessage', $params);
    }
    
    protected function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->tg('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ]);
    }
    
    /* ================== STATE ================== */
    
    protected function loadState(int $userId): ?array
    {
        $state = TelegramState::find($userId);
        if (!$state) {
            return null;
        }
        
        return [
            'step' => $state->step,
            'data' => $state->data ?? [],
        ];
    }
    
    protected function saveState(int $userId, string $step, array $data = []): void
    {
        TelegramState::updateOrCreate(
            ['user_id' => $userId],
            ['step' => $step, 'data' => $data]
        );
    }
    
    protected function clearState(int $userId): void
    {
        TelegramState::where('user_id', $userId)->delete();
    }
    
    /* ================== HANDLERS ================== */
    
    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? trim(
            ($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')
        );
        $text = trim($message['text'] ?? '');
        
        if ($text === '/start') {
            $this->clearState($userId);
            $this->showMainMenu($chatId);
            return;
        }
        
        if ($text === '/my') {
            $this->showMyBookings($chatId, $userId);
            return;
        }
        
        if ($text === '/admin_slots') {
            $adminId = (int)config('services.telegram.admin_chat_id');
            if ($chatId === $adminId) {
                $this->showAdminSlots($chatId);
            } else {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
            }
            return;
        }
        
        if ($text === 'Показать свободные слоты') {
            $this->showFreeSlots($chatId, $userId);
            return;
        }
        
        // цифры — выбор слотов (1, 12, 123 ...)
        if ($text !== '' && preg_match('/^[1-9]+$/u', $text)) {
            $this->handleSlotDigits($chatId, $userId, $username, $text);
            return;
        }
        
        $this->sendMessage(
            $chatId,
            "Я вас не понял.\nНажмите «Показать свободные слоты» или команду /my."
        );
    }
    
    protected function handleCallback(array $callback): void
    {
        $data = $callback['data'] ?? '';
        $userId = $callback['from']['id'];
        $chatId = $callback['message']['chat']['id'];
        $username = $callback['from']['username'] ?? trim(
            ($callback['from']['first_name'] ?? '') . ' ' . ($callback['from']['last_name'] ?? '')
        );
        $cbId = $callback['id'];
        
        $this->answerCallback($cbId);
        
        if ($data === 'cancel') {
            $this->clearState($userId);
            $this->sendMessage($chatId, 'Ок, бронь отменена.');
            $this->showMainMenu($chatId);
            return;
        }
        
        if ($data === 'confirm1') {
            $state = $this->loadState($userId);
            if (!$state || $state['step'] !== 'confirm_1') {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты».');
                return;
            }
            
            $this->saveState($userId, 'confirm_2', $state['data']);
            
            $this->sendMessage(
                $chatId,
                "Вы уверены что хотите подтвердить бронь?\n\nЕсли передумали — жмите «Отмена».",
                [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Отмена', 'callback_data' => 'cancel'],
                            ['text' => 'Да, я хочу пиццу', 'callback_data' => 'confirm2'],
                        ],
                    ],
                ]
            );
            return;
        }
        
        if ($data === 'confirm2') {
            $state = $this->loadState($userId);
            if (!$state || $state['step'] !== 'confirm_2') {
                $this->sendMessage($chatId, 'Сначала выберите слоты.');
                return;
            }
            
            $this->confirmBooking($chatId, $userId, $username, $state['data']);
            $this->clearState($userId);
            return;
        }
    }
    
    /* ================== UI / БИЗНЕС-ЛОГИКА ================== */
    
    protected function showMainMenu($chatId): void
    {
        $keyboard = [
            'keyboard' => [
                [['text' => 'Показать свободные слоты']],
                [['text' => '/my']],
            ],
            'resize_keyboard' => true,
        ];
        
        $this->sendMessage(
            $chatId,
            "Привет! Это пицца-бот.\n\n" .
            "• Нажмите «Показать свободные слоты», чтобы забронировать время.\n" .
            "• Команда /my — посмотреть ваши брони.",
            $keyboard
        );
    }
    
    protected function showFreeSlots($chatId, int $userId): void
    {
        $slots = Slot::query()
            ->where('slot_time', '>', now())
            ->whereNull('booked_by')
            ->orderBy('slot_time')
            ->limit(6)
            ->get(['id', 'slot_time'])
            ->values()
            ->all();
        
        if (empty($slots)) {
            $this->sendMessage($chatId, 'Свободных слотов пока нет. Попробуйте позже.');
            return;
        }
        
        $this->saveState($userId, 'select_slots', ['slots' => $slots]);
        
        $lines = ['Свободные слоты:'];
        foreach ($slots as $i => $slot) {
            $num = $i + 1;
            $lines[] = $num . ') ' . $slot['slot_time']->format('H:i');
        }
        $lines[] = '';
        $lines[] = 'Напишите цифрами ПОДРЯД номера слотов, которые хотите занять.';
        $lines[] = 'Примеры: <code>1</code>, <code>12</code>, <code>123</code>';
        $lines[] = '(можно бронировать только подряд идущие слоты)';
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    
    protected function handleSlotDigits($chatId, int $userId, string $username, string $digits): void
    {
        $state = $this->loadState($userId);
        if (!$state || $state['step'] !== 'select_slots') {
            $this->sendMessage($chatId, 'Сначала нажмите «Показать свободные слоты».');
            return;
        }
        
        $slots = $state['data']['slots'] ?? [];
        if (empty($slots)) {
            $this->sendMessage($chatId, 'Свободные слоты устарели. Нажмите «Показать свободные слоты» ещё раз.');
            return;
        }
        
        $idx = [];
        foreach (mb_str_split($digits) as $ch) {
            $n = (int)$ch;
            if ($n < 1 || $n > count($slots)) {
                $this->sendMessage($chatId, "Неверный номер слота: {$n}");
                return;
            }
            $idx[$n] = true;
        }
        $idx = array_keys($idx);
        sort($idx);
        
        // проверяем, что номера подряд
        for ($i = 1; $i < count($idx); $i++) {
            if ($idx[$i] !== $idx[$i - 1] + 1) {
                $this->sendMessage(
                    $chatId,
                    "Можно бронировать только подряд идущие слоты.\n" .
                    "Примеры: 1, 12, 123."
                );
                return;
            }
        }
        
        $chosen = [];
        foreach ($idx as $n) {
            $chosen[] = $slots[$n - 1];
        }
        
        $state['data']['chosen_idx'] = $idx;
        $this->saveState($userId, 'confirm_1', $state['data']);
        
        $times = array_map(
            fn($s) => (new \Carbon\Carbon($s['slot_time']))->format('H:i'),
            $chosen
        );
        
        $text = "Вы выбрали слоты: " . implode(', ', $times) . "\n\nПодтверждаете бронь?";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Отмена', 'callback_data' => 'cancel'],
                    ['text' => 'Подтверждаю бронь', 'callback_data' => 'confirm1'],
                ],
            ],
        ];
        
        $this->sendMessage($chatId, $text, $keyboard);
    }
    
    protected function confirmBooking($chatId, int $userId, string $username, array $data): void
    {
        $slots = $data['slots'] ?? [];
        $idx = $data['chosen_idx'] ?? [];
        
        if (empty($slots) || empty($idx)) {
            $this->sendMessage($chatId, 'Не найден список выбранных слотов, начните заново.');
            return;
        }
        
        $chosen = [];
        $ids = [];
        foreach ($idx as $n) {
            $slot = $slots[$n - 1];
            $chosen[] = $slot;
            $ids[] = $slot['id'];
        }
        
        if (empty($ids)) {
            $this->sendMessage($chatId, 'Слоты не выбраны.');
            return;
        }
        
        $usernameShort = $username !== '' ? $username : (string)$userId;
        
        $updated = DB::transaction(function () use ($ids, $userId, $usernameShort) {
            return Slot::query()
                ->whereIn('id', $ids)
                ->whereNull('booked_by')
                ->update([
                    'booked_by' => $userId,
                    'booked_username' => $usernameShort,
                ]);
        });
        
        if ($updated !== count($ids)) {
            $this->sendMessage(
                $chatId,
                "К сожалению, один или несколько выбранных слотов уже заняты.\n" .
                "Попробуйте ещё раз: «Показать свободные слоты»."
            );
            return;
        }
        
        $times = array_map(
            fn($s) => (new \Carbon\Carbon($s['slot_time']))->format('H:i'),
            $chosen
        );
        
        $this->sendMessage($chatId, 'Готово! За вами слоты: ' . implode(', ', $times));
        
        $adminId = (int)config('services.telegram.admin_chat_id');
        $label = $usernameShort[0] === '@' ? $usernameShort : '@' . $usernameShort;
        
        $this->sendMessage(
            $adminId,
            'Новая бронь:' . PHP_EOL .
            '[' . implode(' ', $times) . ' ' . $label . ']'
        );
        
        $this->showMainMenu($chatId);
    }
    
    protected function showMyBookings($chatId, int $userId): void
    {
        $slots = Slot::query()
            ->where('booked_by', $userId)
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, 'За вами пока нет броней.');
            return;
        }
        
        $lines = ['Ваши брони:'];
        foreach ($slots as $slot) {
            $lines[] = $slot->slot_time->format('d.m H:i');
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    
    protected function showAdminSlots($chatId): void
    {
        $rows = Slot::query()
            ->whereNotNull('booked_by')
            ->orderBy('slot_time')
            ->get(['slot_time', 'booked_by', 'booked_username']);
        
        if ($rows->isEmpty()) {
            $this->sendMessage($chatId, 'Занятых слотов нет.');
            return;
        }
        
        $chunks = [];
        
        $rows->groupBy('booked_by')->each(function ($group, $userId) use (&$chunks) {
            $username = $group->first()->booked_username ?: $userId;
            $times = $group->map(fn($s) => $s->slot_time->format('H:i'))->all();
            $label = $username[0] === '@' ? $username : '@' . $username;
            $chunks[] = '[' . implode(' ', $times) . ' ' . $label . ']';
        });
        
        $this->sendMessage($chatId, "Занятые слоты:\n" . implode("\n", $chunks));
    }
}
