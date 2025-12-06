<?php

namespace App\Http\Controllers;

use App\Models\Slot;
use App\Models\TelegramState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class TelegramBotController extends Controller
{
    private const BTN_SHOW_SLOTS = 'Показать свободные слоты 🍕';
    private const BTN_MY_ORDERS  = 'Мои заказы 📦';
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
        $token = config('services.telegram.bot_token');
        
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
    private const CACHE_MAINTENANCE_KEY = 'pizza_bot.maintenance';
    
    protected function isMaintenance(): bool
    {
        return (bool) Cache::get(self::CACHE_MAINTENANCE_KEY, false);
    }
    
    protected function setMaintenance(bool $on): void
    {
        Cache::forever(self::CACHE_MAINTENANCE_KEY, $on);
    }
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
        $state = $this->loadState($userId);
        $adminChatId = (int) config('services.telegram.admin_chat_id');
        
        
        if ($state && ($state['step'] ?? null) === 'comment') {
            $comment = trim($text);
            
            if ($comment === '') {
                $this->sendMessage(
                    $chatId,
                    "Комментарий пустой 🤔\nНапишите что-нибудь или отправьте «-», если комментарий не нужен."
                );
                return;
            }
            
            $lower = mb_strtolower($comment);
            if ($comment === '-' || $lower === 'нет') {
                $comment = null;
            }
            
            $data      = $state['data'] ?? [];
            $messageId = $data['message_id'] ?? null;
            
            $this->confirmBooking($chatId, $userId, $username, $data, $messageId, $comment);
            $this->clearState($userId);
            
            return;
        }
        if ($text === '/start') {
            $this->clearState($userId);
            $this->showMainMenu($chatId);
            return;
        }
        
        if (in_array($text, ['/my', self::BTN_MY_ORDERS], true)) {
            $this->showMyBookings($chatId, $userId);
            return;
        }
        
        if (in_array($text, ['Показать свободные слоты', self::BTN_SHOW_SLOTS], true)) {
            $this->showFreeSlots($chatId, $userId);
            return;
        }
        
        if (str_starts_with($text, '/admin_slots')) {
            $adminChatId = (int) config('services.telegram.admin_chat_id');
            
            // реагируем только в админ-чате (группе)
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $parts = preg_split('/\s+/', $text);
            $sub   = strtolower($parts[1] ?? '');     // подкоманда
            $arg   = $parts[2] ?? null;              // аргумент, например время или шаг
            
            switch ($sub) {
                case '':
                    // просто /admin_slots — старое поведение
                    $this->showAdminSlots($chatId);
                    break;
                
                case 'available':
                case 'availiable': // на всякий случай, если напишешь с опечаткой :)
                    $this->showAdminAvailableSlots($chatId);
                    break;
                
                case 'disable':
                    $this->adminDisableSlot($chatId, $arg);
                    break;
                
                case 'enable':
                    $this->adminEnableSlot($chatId, $arg);
                    break;
                
                case 'generate':
                    $this->adminGenerateSlots($chatId, $arg);
                    break;
                
                default:
                    $this->sendMessage($chatId,
                        "Команды /admin_slots:\n" .
                        "/admin_slots – занятые слоты 🍕\n" .
                        "/admin_slots available – свободные слоты ✅\n" .
                        "/admin_slots disable HH:MM – выключить слот 🚫\n" .
                        "/admin_slots enable HH:MM – включить слот обратно ✅\n" .
                        "/admin_slots generate N – сгенерировать слоты на сегодня с шагом N минут ⏱️"
                    );
                    break;
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
        
        // 1) Сначала — спецкоманда /admin_techworks, она должна работать даже в режиме техработ
        if (str_starts_with($text, '/admin_techworks')) {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $parts = preg_split('/\s+/', $text);
            $mode  = strtolower($parts[1] ?? '');
            
            if ($mode === 'disable') {
                $this->setMaintenance(true);
                $this->sendMessage(
                    $chatId,
                    "🚧 Режим технического обслуживания ВКЛЮЧЕН.\n" .
                    "Бот временно не принимает новые заказы."
                );
            } elseif ($mode === 'enable') {
                $this->setMaintenance(false);
                $this->sendMessage(
                    $chatId,
                    "✅ Режим технического обслуживания ВЫКЛЮЧЕН.\n" .
                    "Бот снова принимает заказы."
                );
            } else {
                $this->sendMessage(
                    $chatId,
                    "Использование: /admin_techworks enable|disable\n" .
                    "• enable — включить бота\n" .
                    "• disable — включить режим техобслуживания 🚧"
                );
            }
            
            return;
        }
        
        // 2) Если техработы включены — ВСЕ остальные пользователи получают заглушку
        if ($this->isMaintenance() && $chatId !== $adminChatId) {
            $this->sendMessage(
                $chatId,
                "🚧 Извините, мы сейчас на техническом обслуживании.\n" .
                "Попробуйте чуть позже 🙏"
            );
            return;
        }
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
        
        $adminChatId = (int) config('services.telegram.admin_chat_id');
        
        if ($chatId && $this->isMaintenance() && $chatId !== $adminChatId) {
            $cbId = $callback['id'] ?? null;
            if ($cbId) {
                $this->answerCallback($cbId);
            }
            $this->sendMessage(
                $chatId,
                "🚧 Извините, мы сейчас на техническом обслуживании.\n" .
                "Попробуйте чуть позже 🙏"
            );
            return;
        }
        
        $this->answerCallback($cbId);
        $messageId = $callback['message']['message_id'] ?? null;
        
        // выбор / снятие выбора слота по кнопке
        if (str_starts_with($data, 'slot:')) {
            $index = (int) substr($data, 5); // номера слотов 1..N
            
            $state = $this->loadState($userId);
            if (!$state || $state['step'] !== 'select_slots') {
                // старый апдейт / нет состояния
                return;
            }
            
            $slots  = $state['data']['slots'] ?? [];
            if ($index < 1 || $index > count($slots)) {
                return;
            }
            
            $chosen = $state['data']['chosen_idx'] ?? [];
            
            if (in_array($index, $chosen, true)) {
                // снимаем выбор
                $chosen = array_values(array_diff($chosen, [$index]));
            } else {
                // добавляем
                $chosen[] = $index;
            }
            sort($chosen);
            
            $state['data']['chosen_idx'] = $chosen;
            $this->saveState($userId, 'select_slots', $state['data']);
            
            // обновляем клавиатуру в том же сообщении
            if ($messageId) {
                $keyboard = [
                    'inline_keyboard' => $this->buildSlotsKeyboard($slots, $chosen),
                ];
                
                $this->tg('editMessageReplyMarkup', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
                ]);
            }
            
            return;
        }
        
        // пользователь нажал "Готово"
        if ($data === 'slots_done') {
            $state = $this->loadState($userId);
            if (!$state || $state['step'] !== 'select_slots') {
                return;
            }
            
            $slots = $state['data']['slots'] ?? [];
            $idx   = $state['data']['chosen_idx'] ?? [];
            
            if (empty($idx)) {
                $this->sendMessage($chatId, 'Вы не выбрали ни одного слота 😅');
                return;
            }
            
            sort($idx);
            
            // проверяем, что номера подряд
            for ($i = 1; $i < count($idx); $i++) {
                if ($idx[$i] !== $idx[$i - 1] + 1) {
                    $this->sendMessage(
                        $chatId,
                        "Можно бронировать только подряд идущие слоты.\n" .
                        "Выберите слоты снова ⏰."
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
                fn($s) => Carbon::parse($s['slot_time'])->format('H:i'),
                $chosen
            );
            
            $text = "Вы выбрали слоты ⏰: " . implode(', ', $times) . "\n\nПодтверждаете бронь? ✅";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Отмена ❌', 'callback_data' => 'cancel'],
                        ['text' => 'Подтверждаю бронь 🔒', 'callback_data' => 'confirm1'],
                    ],
                ],
            ];
            
            $this->sendMessage($chatId, $text, $keyboard);
            return;
        }
        
        if ($data === 'cancel') {
            $this->clearState($userId);
            $this->sendMessage($chatId, 'Бронь отменена ❌');
            $this->showMainMenu($chatId);
            return;
        }
        
        if ($data === 'confirm1') {
            $state = $this->loadState($userId);
            $dataState = $state['data'] ?? [];
            
            // проверяем только наличие данных, а не конкретный step
            if (
                !$state ||
                empty($dataState['slots'] ?? []) ||
                empty($dataState['chosen_idx'] ?? [])
            ) {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты».');
                return;
            }
            
            // переводим состояние к confirm_2
            $this->saveState($userId, 'confirm_2', $dataState);
            
            $text = "Вы уверены, что хотите подтвердить бронь? 🔒\n\n" .
                "Если передумали — жмите «Отмена» ❌.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Отмена ❌', 'callback_data' => 'cancel'],
                        ['text' => 'Да, я хочу пиццу 🍕', 'callback_data' => 'confirm2'],
                    ],
                ],
            ];
            
            if ($messageId ?? null) {
                $this->tg('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $this->sendMessage($chatId, $text, $keyboard);
            }
            
            return;
        }
        
        if ($data === 'confirm2') {
            $state = $this->loadState($userId);
            $dataState = $state['data'] ?? [];
            
            if (
                !$state ||
                empty($dataState['slots'] ?? []) ||
                empty($dataState['chosen_idx'] ?? [])
            ) {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты».');
                return;
            }
            
            // запомним message_id, чтобы потом этим же сообщением показать "Готово!"
            if (($messageId ?? null) !== null) {
                $dataState['message_id'] = $messageId;
            }
            
            // переводим пользователя в шаг "ожидание комментария"
            $this->saveState($userId, 'comment', $dataState);
            
            $text = "Отлично! 🎉\n\n" .
                "Теперь вы можете оставить комментарий к заказу 💬\n" .
                "Например: как резать пиццу, без лука, поострее, телефон и т.п.\n\n" .
                "Просто отправьте комментарий одним сообщением.\n" .
                "Если комментарий не нужен — отправьте «-».";
            
            // убираем старые кнопки и заменяем текст того же сообщения
            if (($messageId ?? null) !== null) {
                $this->tg('editMessageText', [
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->sendMessage($chatId, $text);
            }
            
            return;
        }
        
    }
    
    /* ================== UI / БИЗНЕС-ЛОГИКА ================== */
    
    protected function showMainMenu($chatId): void
    {
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => self::BTN_SHOW_SLOTS],
                ],
                [
                    ['text' => self::BTN_MY_ORDERS],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
        
        $this->sendMessage(
            $chatId,
            "Привет! Это пицца-бот 🍕🤖\n\n" .
            "➡️ Нажмите «" . self::BTN_SHOW_SLOTS . "», чтобы забронировать время.\n" .
            "📋 Нажмите «" . self::BTN_MY_ORDERS . "», чтобы посмотреть свои брони.",
            $keyboard
        );
    }
    
    protected function showFreeSlots($chatId, int $userId): void
    {
        $slots = Slot::query()
            ->where('slot_time', '>', now())
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->orderBy('slot_time')
            ->limit(6)
            ->get(['id', 'slot_time'])
            ->map(function (Slot $slot) {
                return [
                    'id' => $slot->id,
                    'slot_time' => $slot->slot_time->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
        
        if (empty($slots)) {
            $this->sendMessage($chatId, 'Свободных слотов пока нет 😔 Попробуйте позже чуть позже.');
            return;
        }
        
        // сохраняем слоты и пока пустой выбор
        $this->saveState($userId, 'select_slots', [
            'slots' => $slots,
            'chosen_idx' => [],
        ]);
        
        $lines = ['Свободные слоты на сегодня ⏰:'];
        foreach ($slots as $i => $slot) {
            $num  = $i + 1;
            $time = Carbon::parse($slot['slot_time'])->format('H:i');
            $lines[] = "{$num}) {$time}";
        }
        $lines[] = '';
        $lines[] = '👇 Нажмите на кнопки со слотами, которые хотите занять, затем на «Готово».';
        
        $replyMarkup = [
            'inline_keyboard' => $this->buildSlotsKeyboard($slots, []),
        ];
        
        $this->sendMessage($chatId, implode("\n", $lines), $replyMarkup);
    }
    
    
    protected function handleSlotDigits(int $chatId, int $userId, string $text): void
    {
        // оставляем только цифры
        $digits = preg_replace('/\D+/', '', $text);
        if ($digits === '') {
            $this->sendMessage(
                $chatId,
                "Используйте только номера слотов, например: 1, 12, 123."
            );
            return;
        }
        
        $state = $this->loadState($userId);
        if (!$state || empty($state['data']['slots'] ?? [])) {
            $this->sendMessage(
                $chatId,
                "Сначала нажмите «Показать свободные слоты 🍕»."
            );
            return;
        }
        
        $slots = $state['data']['slots'];
        $idx   = [];
        
        // разбираем строку на отдельные цифры
        foreach (preg_split('//u', $digits, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $n = (int) $ch;
            if ($n < 1 || $n > count($slots)) {
                $this->sendMessage(
                    $chatId,
                    "Номер слота {$n} вне диапазона. Попробуйте ещё раз."
                );
                return;
            }
            if (!in_array($n, $idx, true)) {
                $idx[] = $n;
            }
        }
        
        sort($idx);
        
        // проверяем, что слоты идут подряд
        for ($i = 1; $i < count($idx); $i++) {
            if ($idx[$i] !== $idx[$i - 1] + 1) {
                $this->sendMessage(
                    $chatId,
                    "Можно бронировать только подряд идущие слоты.\n" .
                    "Попробуйте ещё раз."
                );
                return;
            }
        }
        
        // сохраняем выбранные индексы в state
        $state['data']['chosen_idx'] = $idx;
        $this->saveState($userId, 'confirm_1', $state['data']);
        
        // строим список времени выбранных слотов
        $chosen = [];
        foreach ($idx as $n) {
            $chosen[] = $slots[$n - 1];
        }
        
        $times = array_map(
            fn($s) => \Carbon\Carbon::parse($s['slot_time'])->format('H:i'),
            $chosen
        );
        
        $outText = "Вы выбрали слоты ⏰: " . implode(', ', $times) . "\n\nПодтверждаете бронь? ✅";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Отмена ❌', 'callback_data' => 'cancel'],
                    ['text' => 'Подтверждаю бронь ✅', 'callback_data' => 'confirm1'],
                ],
            ],
        ];
        
        $this->sendMessage($chatId, $outText, $keyboard);
    }
    
    /**
     * Строим inline-клавиатуру для выбора слотов.
     *
     * @param array $slots       массив слотов из state ['id' => ..., 'slot_time' => 'Y-m-d H:i:s']
     * @param array $selectedIdx номера выбранных слотов (1..N)
     */
    protected function buildSlotsKeyboard(array $slots, array $selectedIdx = []): array
    {
        $rows = [];
        $row  = [];
        
        foreach ($slots as $i => $slot) {
            $num  = $i + 1; // номер слота для пользователя
            $time = Carbon::parse($slot['slot_time'])->format('H:i');
            $selected = in_array($num, $selectedIdx, true);
            
            $row[] = [
                'text' => ($selected ? '✅ ' : '') . $time,
                'callback_data' => 'slot:' . $num,
            ];
            
            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        
        if (!empty($row)) {
            $rows[] = $row;
        }
        
        // последняя строка — действия
        $rows[] = [
            ['text' => 'Готово', 'callback_data' => 'slots_done'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ];
        
        return $rows;
    }
    
    protected function confirmBooking(
        $chatId,
        int $userId,
        string $username,
        array $data,
        ?int $messageId = null,
        ?string $comment = null   // <-- новый параметр с дефолтом
    ): void {
        $slots = $data['slots'] ?? [];
        $idx   = $data['chosen_idx'] ?? [];
        
        if (empty($slots) || empty($idx)) {
            $this->sendMessage($chatId, 'Не найден список выбранных слотов, начните заново.');
            return;
        }
        
        $chosen = [];
        $ids    = [];
        
        foreach ($idx as $n) {
            if (!isset($slots[$n - 1])) {
                continue;
            }
            
            $slot      = $slots[$n - 1];
            $chosen[]  = $slot;
            $ids[]     = $slot['id'];
        }
        
        if (empty($ids)) {
            $this->sendMessage($chatId, 'Слоты не выбраны.');
            return;
        }
        
        $usernameShort = $username !== '' ? $username : (string) $userId;
        
        // ВАЖНО: передаём $comment в use(), иначе его не видно внутри транзакции
        $updated = \DB::transaction(function () use ($ids, $userId, $usernameShort, $comment) {
            return Slot::query()
                ->whereIn('id', $ids)
                ->whereNull('booked_by')
                ->where('is_disabled', false)
                ->update([
                    'booked_by'       => $userId,
                    'booked_username' => $usernameShort,
                    'comment'         => $comment,   // сохраняем комментарий
                ]);
        });
        
        if ($updated !== count($ids)) {
            $this->sendMessage(
                $chatId,
                "К сожалению, один или несколько выбранных слотов уже заняты.\n" .
                "Попробуйте ещё раз: «Показать свободные слоты 🍕»."
            );
            return;
        }
        
        // Формируем список времени для пользователя и админа
        $times = array_map(
            fn ($s) => \Carbon\Carbon::parse($s['slot_time'])->format('H:i'),
            $chosen
        );
        
        $text = 'Готово! 🎉 За вами слоты: ' . implode(', ', $times) . " 🍕" .
            "\n\n🧾 Посмотреть свои брони: /my";
        
        // Редактируем уже существующее сообщение, если знаем message_id
        if ($messageId) {
            $this->tg('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        } else {
            $this->sendMessage($chatId, $text);
        }
        
        // Уведомление в админ-чат
        $adminId = (int) config('services.telegram.admin_chat_id');
        $label   = str_starts_with($usernameShort, '@') ? $usernameShort : '@' . $usernameShort;
        
        $adminText = '🍕 Новая бронь:' . PHP_EOL .
            '[' . implode(' ', $times) . ' ' . $label . ']';
        
        if ($comment !== null && $comment !== '') {
            $adminText .= PHP_EOL . '💬 Комментарий: ' . $comment;
        }
        
        $this->sendMessage($adminId, $adminText);
    }
    
    
    protected function showMyBookings($chatId, int $userId): void
    {
        $slots = Slot::query()
            ->where('booked_by', $userId)
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, 'У вас пока нет броней 😴');
            return;
        }
        
        $lines = ['🧾 Ваши брони:'];
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
            ->get(['slot_time', 'booked_by', 'booked_username', 'comment']);
        
        if ($rows->isEmpty()) {
            $this->sendMessage($chatId, 'Занятых слотов нет.');
            return;
        }
        
        $lines = ["📋 Занятые слоты:"];
        
        foreach ($rows as $slot) {
            /** @var \App\Models\Slot $slot */
            $time = $slot->slot_time->format('H:i');
            
            $username = $slot->booked_username ?: $slot->booked_by;
            if (!str_starts_with((string) $username, '@')) {
                $username = '@' . $username;
            }
            
            $line = "[{$time} {$username}]";
            
            if ($slot->comment) {
                $line .= " 💬 {$slot->comment}";
            }
            
            $lines[] = $line;
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    
    
    protected function showAdminAvailableSlots($chatId): void
    {
        $slots = Slot::query()
            ->whereDate('slot_time', now()->toDateString())
            ->where('slot_time', '>', now())
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, 'На сегодня свободных слотов нет ✅');
            return;
        }
        
        $times = $slots->map(fn (Slot $s) => $s->slot_time->format('H:i'))->all();
        
        $this->sendMessage(
            $chatId,
            "Свободные слоты сегодня ⏰:\n" . implode(' ', $times)
        );
    }
    protected function adminDisableSlot($chatId, ?string $timeStr): void
    {
        if (!$timeStr) {
            $this->sendMessage($chatId, "Использование: /admin_slots disable HH:MM\nНапример: /admin_slots disable 15:30");
            return;
        }
        
        $timeStr = trim($timeStr);
        
        try {
            $dt = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Неверный формат времени ⏱️\nОжидаю HH:MM, например 15:30");
            return;
        }
        
        $slot = Slot::query()
            ->whereDate('slot_time', now()->toDateString())
            ->whereTime('slot_time', $dt->format('H:i:00'))
            ->first();
        
        if (!$slot) {
            $this->sendMessage($chatId, "Слот {$dt->format('H:i')} на сегодня не найден ❓");
            return;
        }
        
        if ($slot->booked_by !== null) {
            $this->sendMessage($chatId, "Слот {$dt->format('H:i')} уже забронирован, отключать не буду ⚠️");
            return;
        }
        
        $slot->is_disabled = true;
        $slot->save();
        
        $this->sendMessage($chatId, "Слот {$dt->format('H:i')} помечен как недоступный 🚫");
    }
    protected function adminEnableSlot($chatId, ?string $timeStr): void
    {
        if (!$timeStr) {
            $this->sendMessage($chatId, "Использование: /admin_slots enable HH:MM\nНапример: /admin_slots enable 15:30");
            return;
        }
        
        $timeStr = trim($timeStr);
        
        try {
            $dt = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Неверный формат времени ⏱️\nОжидаю HH:MM, например 15:30");
            return;
        }
        
        $slot = Slot::query()
            ->whereDate('slot_time', now()->toDateString())
            ->whereTime('slot_time', $dt->format('H:i:00'))
            ->first();
        
        if (!$slot) {
            $this->sendMessage($chatId, "Слот {$dt->format('H:i')} на сегодня не найден ❓");
            return;
        }
        
        if ($slot->booked_by !== null) {
            $this->sendMessage($chatId, "Слот {$dt->format('H:i')} уже забронирован, включать/выключать нет смысла ⚠️");
            return;
        }
        
        if (!$slot->is_disabled) {
            $this->sendMessage($chatId, "Слот {$dt->format('H:i')} и так активен ✅");
            return;
        }
        
        $slot->is_disabled = false;
        $slot->save();
        
        $this->sendMessage($chatId, "Слот {$dt->format('H:i')} снова доступен ✅");
    }
    protected function adminGenerateSlots($chatId, ?string $stepStr): void
    {
        if (!$stepStr || !ctype_digit($stepStr)) {
            $this->sendMessage(
                $chatId,
                "Использование: /admin_slots generate N\n" .
                "Где N — шаг в минутах, например 10 или 15."
            );
            return;
        }
        
        $step = (int) $stepStr;
        if ($step <= 0 || $step > 180) {
            $this->sendMessage($chatId, "Странный шаг: {$step} минут 🤔\nПопробуйте что-то от 5 до 180.");
            return;
        }
        
        $date = now()->toDateString();
        
        Artisan::call('slots:generate', [
            'date'    => $date,
            '--step'  => $step,
        ]);
        
        $output = trim(Artisan::output());
        
        $this->sendMessage(
            $chatId,
            "Генерация слотов на {$date} с шагом {$step} минут завершена ✅\n\n{$output}"
        );
    }
}
