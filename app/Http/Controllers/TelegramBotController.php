<?php

namespace App\Http\Controllers;

use App\Models\Slot;
use App\Models\TelegramState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class TelegramBotController extends Controller
{
    private const BTN_SHOW_SLOTS = 'Показать свободные слоты 🍕';
    private const BTN_MY_ORDERS  = 'Мои заказы 📦';
    private const BTN_ORDER_HISTORY = 'История заказов 📜';
    private const CACHE_MAINTENANCE_KEY = 'pizza_bot.maintenance';
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
        if ($text === '/admin_help') {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $help = "Админ-команды бота 📖\n\n" .
                
                "Справка:\n" .
                "/admin_help – показать эту подсказку 📖\n\n" .
                
                "Слоты:\n" .
                "/admin_slots – занятые слоты на сегодня 🍕 (кнопки «Выполнен» отмечают заказ как выполненный ✅)\n" .
                "/admin_slots available [YYYY-MM-DD] – свободные слоты на дату (по умолчанию сегодня) ✅\n" .
                "/admin_slots disable HH:MM – выключить слот на сегодня 🚫\n" .
                "/admin_slots enable HH:MM – включить слот обратно на сегодня ✅\n" .
                "/admin_slots generate N [YYYY-MM-DD] – сгенерировать слоты на дату с шагом N минут ⏱️ (по умолчанию сегодня)\n" .
                "/admin_slots clear – удалить все слоты на сегодня (если нет броней) 🧹\n" .
                "/admin_slots clear_booked – сбросить все брони на сегодня, слоты остаются 🔄\n\n".
                
                "Техработы:\n" .
                "/admin_techworks disable – включить режим технического обслуживания 🚧 (бот отвечает всем заглушкой)\n" .
                "/admin_techworks enable – выключить техобслуживание ✅ (бот снова принимает заказы)\n";
            
            $this->sendMessage($chatId, $help);
            return;
        }
        if ($text === self::BTN_MY_ORDERS) {
            $this->showMyBookings($chatId, $userId, true);
            return;
        }
        if ($text === self::BTN_ORDER_HISTORY) {
            $this->showMyBookings($chatId, $userId, false);
            return;
        }
        if ($text === self::BTN_SHOW_SLOTS) {
            $this->showFreeSlotsMenu($chatId, $userId);
            return;
        }
        
        if (str_starts_with($text, '/admin_slots')) {
            
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $parts = preg_split('/\s+/', $text);
            $sub   = strtolower($parts[1] ?? '');
            $arg   = $parts[2] ?? null;
            
            switch ($sub) {
                case '':
                    $this->showAdminSlots($chatId);
                    break;
                case 'available':
                case 'availiable':
                    $dateStr = $parts[2] ?? null;
                    $this->showAdminAvailableSlots($chatId, $dateStr);
                    break;
                case 'disable':
                    $this->adminDisableSlot($chatId, $arg);
                    break;
                case 'enable':
                    $this->adminEnableSlot($chatId, $arg);
                    break;
                case 'generate':
                    $interval = isset($parts[2]) ? (int) $parts[2] : 0;
                    if ($interval <= 0) {
                        $this->sendMessage($chatId, "Укажите шаг в минутах, например:\n/admin_slots generate 10\nили\n/admin_slots generate 15 2025-12-08");
                        return;
                    }
                    $dateStr = $parts[3] ?? null; // опциональная дата YYYY-MM-DD
                    $this->adminGenerateSlots($chatId, $interval, $dateStr);
                    break;
                case 'clear':
                        $this->adminClearSlots($chatId);
                    break;
                case 'clear_booked':
                    $this->adminClearBookedSlots($chatId);
                    break;
                default:
                    $this->sendMessage($chatId,
                        "Команды /admin_slots:\n" .
                        "/admin_slots – занятые слоты 🍕\n" .
                        "/admin_slots available – свободные слоты ✅\n" .
                        "/admin_slots disable HH:MM – выключить слот 🚫\n" .
                        "/admin_slots enable HH:MM – включить слот обратно ✅\n" .
                        "/admin_slots generate N – сгенерировать слоты на сегодня с шагом N минут ⏱️ \n" .
                        "/admin_techworks enable – включить бота \n".
                        "/admin_techworks disable – выключить бота 🚫 \n"
                    );
                    break;
            }
            
            return;
        }
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
        
        if ($this->isMaintenance() && $chatId !== $adminChatId) {
            $this->sendMessage(
                $chatId,
                "🚧 Извините, мы сейчас на техническом обслуживании.\n" .
                "Попробуйте чуть позже 🙏"
            );
            return;
        }
        
        $this->sendMessage(
            $chatId,
            "Я вас не понял."
        );
    }
    
    protected function handleCallback(array $callback): void
    {
        $data      = $callback['data'] ?? '';
        $userId    = $callback['from']['id'];
        $chatId    = $callback['message']['chat']['id'];
        $username  = $callback['from']['username'] ?? trim(
            ($callback['from']['first_name'] ?? '') . ' ' . ($callback['from']['last_name'] ?? '')
        );
        $cbId      = $callback['id'];
        $messageId = $callback['message']['message_id'] ?? null;
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
        
        if (str_starts_with($data, 'done:')) {
            $slotId = (int) substr($data, 5);
            
            $slot = Slot::query()->find($slotId);
            if ($slot) {
                $slot->is_completed = true;
                $slot->save();
            }
            
            [$text, $replyMarkup] = $this->buildAdminSlotsView();
            
            if ($messageId) {
                $params = [
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ];
                
                if ($replyMarkup) {
                    $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
                }
                
                $this->tg('editMessageText', $params);
            } else {
                if ($replyMarkup) {
                    $this->sendMessage($chatId, $text, $replyMarkup);
                } else {
                    $this->sendMessage($chatId, $text);
                }
            }
            
            return;
        }
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
        if (str_starts_with($data, 'cancel_slot:')) {
            $slotId = (int) substr($data, strlen('cancel_slot:'));
            
            $slot = Slot::query()->find($slotId);
            
            if (!$slot || $slot->booked_by !== $userId) {
                $this->sendMessage($chatId, 'Не удалось найти вашу бронь для отмены.');
                return;
            }
            
            $now       = now();
            $threshold = $now->copy()->subMinutes(10);
            
            if ($slot->is_completed
                || !$slot->booked_at
                || $slot->booked_at->lte($threshold)
                || $slot->slot_time->lte($now)
            ) {
                $this->sendMessage($chatId, 'Эту бронь уже нельзя отменить ⏰');
                return;
            }
            
            $timeLabel      = $slot->slot_time->format('H:i');
            $usernameShort  = $slot->booked_username ?: $slot->booked_by;
            
            $slot->update([
                'booked_by'       => null,
                'booked_username' => null,
                'comment'         => null,
                'is_completed'    => false,
                'booked_at'       => null,
            ]);
            
            
            $label   = is_string($usernameShort) && str_starts_with($usernameShort, '@')
                ? $usernameShort
                : '@' . $usernameShort;
            
            $this->sendMessage(
                $adminChatId,
                "🚫 Отмена брони:\n[{$timeLabel} {$label}]"
            );
            
            [$text, $replyMarkup] = $this->buildMyBookingsView($userId, true);
            
            if ($messageId ?? null) {
                $params = [
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ];
                if ($replyMarkup) {
                    $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
                }
                $this->tg('editMessageText', $params);
            } else {
                if ($replyMarkup) {
                    $this->sendMessage($chatId, $text, $replyMarkup);
                } else {
                    $this->sendMessage($chatId, $text);
                }
            }
            
            $this->sendMessage($chatId, "Бронь на {$timeLabel} отменена ❌");
            
            return;
        }
        if (str_starts_with($data, 'choose_date:')) {
            $dateStr = substr($data, strlen('choose_date:'));
            
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
            } catch (\Exception $e) {
                $this->sendMessage($chatId, 'Не удалось распознать дату, попробуйте ещё раз 🙈');
                return;
            }
            
            $this->showFreeSlotsForDate($chatId, $userId, $date);
            return;
        }
        
        if ($data === 'cancel_choose_date') {
            $this->clearState($userId);
            $this->sendMessage($chatId, 'Выбор даты отменён ❌');
            return;
        }
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
            
            if (
                !$state ||
                empty($dataState['slots'] ?? []) ||
                empty($dataState['chosen_idx'] ?? [])
            ) {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты 🍕».');
                return;
            }
            
            // считаем выбранные времена для красоты
            $slots = $dataState['slots'];
            $idx   = $dataState['chosen_idx'];
            
            $chosen = [];
            foreach ($idx as $n) {
                if (isset($slots[$n - 1])) {
                    $chosen[] = $slots[$n - 1];
                }
            }
            
            $times = array_map(
                fn($s) => \Carbon\Carbon::parse($s['slot_time'])->format('H:i'),
                $chosen
            );
            $timesText = implode(', ', $times);
            
            // запомним message_id, чтобы потом этим же сообщением показать "Готово!"
            if (($messageId ?? null) !== null) {
                $dataState['message_id'] = $messageId;
            }
            
            // сразу переходим к выбору "хочу комментарий / нет"
            $this->saveState($userId, 'comment_choice', $dataState);
            
            $text = "Вы выбрали слоты ⏰: {$timesText}\n\n" .
                "Хотите добавить комментарий к заказу? 💬\n" .
                "Например: без лука, поострее, номер телефона и т.п.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Хочу добавить комментарий 💬', 'callback_data' => 'comment_yes'],
                    ],
                    [
                        ['text' => 'Нет, без комментария ✅', 'callback_data' => 'comment_no'],
                    ],
                ],
            ];
            
            if (($messageId ?? null) !== null) {
                $this->tg('editMessageText', [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $this->sendMessage($chatId, $text, $keyboard);
            }
            
            return;
        }
        if ($data === 'comment_yes') {
            $state = $this->loadState($userId);
            $dataState = $state['data'] ?? [];
            
            if (
                !$state ||
                ($state['step'] ?? null) !== 'comment_choice' ||
                empty($dataState['slots'] ?? []) ||
                empty($dataState['chosen_idx'] ?? [])
            ) {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты 🍕».');
                return;
            }
            
            $this->saveState($userId, 'comment', $dataState);
            
            $text = "Окей! 💬\n\n" .
                "Отправьте комментарий одним сообщением.\n" .
                "Если передумали — можете отправить «-» или «нет», и комментарий не будет сохранён.";
            
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
        if ($data === 'comment_no') {
            $state = $this->loadState($userId);
            $dataState = $state['data'] ?? [];
            if (
                !$state ||
                ($state['step'] ?? null) !== 'comment_choice' ||
                empty($dataState['slots'] ?? []) ||
                empty($dataState['chosen_idx'] ?? [])
            ) {
                $this->sendMessage($chatId, 'Сначала выберите слоты через «Показать свободные слоты 🍕».');
                return;
            }
            
            $messageIdFromState = $dataState['message_id'] ?? ($messageId ?? null);
            
            $this->confirmBooking(
                $chatId,
                $userId,
                $username,
                $dataState,
                $messageIdFromState,
                null
            );
            
            $this->clearState($userId);
            return;
        }
        if ($data === 'my_today') {
            $this->showMyBookings($chatId, $userId, true);
            return;
        }
        if ($data === 'my_history') {
            $this->showMyBookings($chatId, $userId, false);
            return;
        }
        if ($data === 'menu_show_slots') {
            $this->showFreeSlotsMenu($chatId, $userId);
            return;
        }
        
    }
    
    /* ================== UI / БИЗНЕС-ЛОГИКА ================== */
    
    protected function showMainMenu($chatId): void
    {
        $text = "Привет! Это пицца-бот 🍕🤖\n\n" .
            "Немного хаоса в начинке, немного магии внутри коробки — к каждой пицце мы приклеиваем предсказание, как в печенье удачи.";
        
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => self::BTN_SHOW_SLOTS,    'callback_data' => 'menu_show_slots'],
                ],
                [
                    ['text' => self::BTN_MY_ORDERS,     'callback_data' => 'my_today'],
                    ['text' => self::BTN_ORDER_HISTORY, 'callback_data' => 'my_history'],
                ],
            ],
        ];
        
        $this->sendMessage($chatId, $text, $inlineKeyboard);
        
        $replyKeyboard = [
            'keyboard' => [
                [
                    ['text' => self::BTN_SHOW_SLOTS],
                ],
                [
                    ['text' => self::BTN_MY_ORDERS],
                    ['text' => self::BTN_ORDER_HISTORY],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];
        
        $this->sendMessage(
            $chatId,
            "Меню на клавиатуре снизу 👇",
            $replyKeyboard
        );
    }
    protected function showFreeSlots($chatId, int $userId): void
    {
        $slots = Slot::query()
            ->where('slot_time', '>', now())
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->orderBy('slot_time')
            ->limit(24)
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
        
        $this->saveState($userId, 'select_slots', [
            'slots' => $slots,
            'chosen_idx' => [],
        ]);
        
        $lines = ['Свободные слоты на сегодня ⏰:'];
        /*foreach ($slots as $i => $slot) {
            $time = Carbon::parse($slot['slot_time'])->format('H:i');
            $lines[] = " {$time}";
        }
        $lines[] = '';*/
        $lines[] = '👇 Нажмите на кнопки со слотами, которые хотите занять, затем на «Готово».';
        
        $replyMarkup = [
            'inline_keyboard' => $this->buildSlotsKeyboard($slots, []),
        ];
        
        $this->sendMessage($chatId, implode("\n", $lines), $replyMarkup);
    }
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
        ?string $comment = null
    ): void {
        $slots = $data['slots'] ?? [];
        $idx   = $data['chosen_idx'] ?? [];
        $adminId = (int) config('services.telegram.admin_chat_id');
        
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
        
        $updated = \DB::transaction(function () use ($ids, $userId, $usernameShort, $comment) {
            return Slot::query()
                ->whereIn('id', $ids)
                ->whereNull('booked_by')
                ->where('is_disabled', false)
                ->update([
                    'booked_by'       => $userId,
                    'booked_username' => $usernameShort,
                    'comment'         => $comment,
                    'booked_at'       => now(),
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
        
        $times = array_map(
            fn ($s) => \Carbon\Carbon::parse($s['slot_time'])->format('H:i'),
            $chosen
        );
        
        $text = 'Готово! 🎉 За вами слоты: ' . implode(', ', $times) . " 🍕" .
            "\n\n👇 Быстрый доступ:\n" .
            "    📦 Мои заказы — на сегодня\n" .
            "    📜 История заказов — все ваши брони.";
        
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Мои заказы 📦',      'callback_data' => 'my_today'],
                ],
                [
                    ['text' => 'История заказов 📜', 'callback_data' => 'my_history'],
                ],
            ],
        ];
        
        if ($messageId) {
            $params = [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'text'       => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE),
            ];
            
            $this->tg('editMessageText', $params);
        } else {
            $this->sendMessage($chatId, $text, $inlineKeyboard);
        }
        
        
        $label   = str_starts_with($usernameShort, '@') ? $usernameShort : '@' . $usernameShort;
        
        $adminText = '🍕 Новая бронь:' . PHP_EOL .
            '[' . implode(' ', $times) . ' ' . $label . ']';
        
        if ($comment !== null && $comment !== '') {
            $adminText .= PHP_EOL . '💬 Комментарий: ' . $comment;
        }
        
        $this->sendMessage($adminId, $adminText);
    }
    protected function buildMyBookingsView(int $userId, bool $todayOnly = false): array
    {
        $query = Slot::query()
            ->where('booked_by', $userId);
        
        if ($todayOnly) {
            $query->whereDate('slot_time', now()->toDateString());
        }
        
        $slots = $query
            ->orderBy('slot_time')
            ->get(['id', 'slot_time', 'comment', 'is_completed', 'booked_at']);
        
        if ($slots->isEmpty()) {
            $msg = $todayOnly
                ? 'На сегодня у вас нет броней 😴'
                : 'У вас пока нет броней 😴';
            
            return [$msg, null];
        }
        
        $lines = [
            $todayOnly
                ? '🧾 Ваши брони на сегодня:'
                : '🧾 Ваши брони:',
        ];
        
        $currentDate = null;
        $now        = now();
        $threshold  = $now->copy()->subMinutes(10);
        
        // Клавиатура только для "Мои заказы" (сегодня)
        $keyboard = $todayOnly ? ['inline_keyboard' => []] : null;
        
        foreach ($slots as $slot) {
            /** @var \App\Models\Slot $slot */
            $dateLabel = $slot->slot_time->format('d.m');
            $timeLabel = $slot->slot_time->format('H:i');
            
            if (!$todayOnly && $dateLabel !== $currentDate) {
                $currentDate = $dateLabel;
                $lines[] = '';
                $lines[] = '📅 ' . $dateLabel;
            } elseif ($todayOnly && $currentDate === null) {
                $currentDate = $dateLabel;
                $lines[] = '📅 ' . $dateLabel;
            }
            
            $status = $slot->is_completed
                ? '✅ выполнен'
                : '⏳ ожидает';
            
            $lines[] = "• {$timeLabel} — {$status}";
            
            if (!empty($slot->comment)) {
                $lines[] = '   💬 ' . $slot->comment;
            }
            
            // можно ли отменить?
            if ($todayOnly
                && !$slot->is_completed
                && $slot->booked_at
                && $slot->booked_at->gt($threshold)   // прошло < 10 минут
                && $slot->slot_time->gt($now)         // и слот ещё не в прошлом
            ) {
                $keyboard['inline_keyboard'][] = [[
                    'text' => "Отменить {$timeLabel} ❌",
                    'callback_data' => 'cancel_slot:' . $slot->id,
                ]];
            }
        }
        
        if ($keyboard && empty($keyboard['inline_keyboard'])) {
            $keyboard = null;
        }
        
        return [implode("\n", $lines), $keyboard];
    }
    protected function showMyBookings($chatId, int $userId, bool $todayOnly = false): void
    {
        [$text, $replyMarkup] = $this->buildMyBookingsView($userId, $todayOnly);
        
        if ($replyMarkup) {
            $this->sendMessage($chatId, $text, $replyMarkup);
        } else {
            $this->sendMessage($chatId, $text);
        }
    }
    protected function showAdminSlots($chatId): void
    {
        [$text, $replyMarkup] = $this->buildAdminSlotsView();
        
        if ($replyMarkup) {
            $this->sendMessage($chatId, $text, $replyMarkup);
        } else {
            $this->sendMessage($chatId, $text);
        }
    }
    protected function showAdminAvailableSlots(int $chatId, ?string $dateStr = null): void
    {
        $now = now();
        
        if ($dateStr) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
            } catch (\Exception $e) {
                $this->sendMessage(
                    $chatId,
                    "Неверный формат даты.\nИспользуйте YYYY-MM-DD, например: 2025-12-08"
                );
                return;
            }
        } else {
            $date = $now->copy()->startOfDay();
        }
        
        $query = Slot::query()
            ->whereDate('slot_time', $date->toDateString())
            ->whereNull('booked_by')
            ->where('is_disabled', false);
        
        // Для сегодняшнего дня не показываем прошлое время
        if ($date->isSameDay($now)) {
            $query->where('slot_time', '>', $now);
        }
        
        $slots = $query
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage(
                $chatId,
                "Свободных слотов на " . $date->format('d.m.Y') . " нет 😔"
            );
            return;
        }
        
        $lines = [
            "Свободные слоты на " . $date->format('d.m.Y') . " ⏰:",
        ];
        
        foreach ($slots as $slot) {
            $lines[] = $slot->slot_time->format('H:i');
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
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
    protected function adminGenerateSlots(int $chatId, int $intervalMinutes, ?string $dateStr = null): void
    {
        // 1) Дата
        if ($dateStr) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
            } catch (\Exception $e) {
                $this->sendMessage(
                    $chatId,
                    "Неверный формат даты.\nИспользуйте YYYY-MM-DD, например: 2025-12-08"
                );
                return;
            }
        } else {
            $date = today();
        }
        
        // 2) Интервал
        if ($intervalMinutes <= 0 || $intervalMinutes > 180) {
            $this->sendMessage($chatId, 'Интервал должен быть от 1 до 180 минут.');
            return;
        }
        
        // 3) Диапазон времени (можно под себя подправить)
        $start = $date->copy()->setTime(15, 0); // 15:00
        $end   = $date->copy()->setTime(20, 0); // 20:00
        
        $created = 0;
        
        // 4) Идём по интервалу и создаём только отсутствующие слоты
        for ($time = $start->copy(); $time < $end; $time->addMinutes($intervalMinutes)) {
            $slot = Slot::query()->firstOrCreate(
                ['slot_time' => $time],
                [
                    'is_disabled'    => false,
                    'booked_by'      => null,
                    'booked_username'=> null,
                    'comment'        => null,
                    'is_completed'   => false,
                    'booked_at'      => null,
                ]
            );
            
            if ($slot->wasRecentlyCreated) {
                $created++;
            }
        }
        
        $this->sendMessage(
            $chatId,
            "Слоты на дату " . $date->format('d.m.Y') .
            " с шагом {$intervalMinutes} минут сгенерированы.\n" .
            "Новых слотов создано: {$created}."
        );
    }
    protected function buildAdminSlotsView(): array
    {
        $today = now()->toDateString();
        
        $rows = Slot::query()
            ->whereNotNull('booked_by')
            ->whereDate('slot_time', $today)   // ← фильтр только на сегодня
            ->orderBy('slot_time')
            ->get(['id', 'slot_time', 'booked_by', 'booked_username', 'comment', 'is_completed']);
        
        if ($rows->isEmpty()) {
            return ['На сегодня занятых слотов нет 🍀', null];
        }
        
        $lines    = ["📋 Занятые слоты на сегодня ({$today}):"];
        $keyboard = ['inline_keyboard' => []];
        
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
            
            if ($slot->is_completed) {
                $line .= " ✅";
            } else {
                $keyboard['inline_keyboard'][] = [[
                    'text' => "Выполнен {$time} ✅",
                    'callback_data' => 'done:' . $slot->id,
                ]];
            }
            
            $lines[] = $line;
        }
        
        if (empty($keyboard['inline_keyboard'])) {
            $keyboard = null;
        }
        
        return [implode("\n", $lines), $keyboard];
    }
    
    protected function adminClearSlots($chatId): void
    {
        $today = now()->toDateString();
        
        // сначала проверим, нет ли броней
        $bookedCount = Slot::query()
            ->whereDate('slot_time', $today)
            ->whereNotNull('booked_by')
            ->count();
        
        if ($bookedCount > 0) {
            $this->sendMessage(
                $chatId,
                "На сегодня уже есть забронированные слоты ({$bookedCount} шт.), " .
                "очистка отменена ❌"
            );
            return;
        }
        
        // удаляем все слоты на сегодня
        $total = Slot::query()
            ->whereDate('slot_time', $today)
            ->delete();
        
        $this->sendMessage(
            $chatId,
            "🧹 Все слоты на сегодня ({$today}) удалены.\n" .
            "Удалено записей: {$total}."
        );
    }
    protected function adminClearBookedSlots($chatId): void
    {
        $today = now()->toDateString();
        
        // сколько сейчас занято
        $bookedCount = Slot::query()
            ->whereDate('slot_time', $today)
            ->whereNotNull('booked_by')
            ->count();
        
        if ($bookedCount === 0) {
            $this->sendMessage(
                $chatId,
                "На сегодня нет занятых слотов — сбрасывать нечего 🙂"
            );
            return;
        }
        
        // сбрасываем только занятые на сегодня
        $updated = Slot::query()
            ->whereDate('slot_time', $today)
            ->whereNotNull('booked_by')
            ->update([
                'booked_by'       => null,
                'booked_username' => null,
                'comment'         => null,
                'is_completed'    => false,
                'booked_at'       => null,
            ]);
        
        $this->sendMessage(
            $chatId,
            "🔄 Занятые брони на сегодня ({$today}) сброшены.\n" .
            "Освобождено слотов: {$updated}."
        );
    }
    
    protected function showFreeSlotsMenu(int $chatId, int $userId): void
    {
        $now = now();
        
        // Все свободные слоты в будущем (сейчас + будущее дни)
        $slots = Slot::query()
            ->where('slot_time', '>', $now)
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, 'Свободных слотов пока нет 😔 Попробуйте позже.');
            return;
        }
        
        // Группируем по датам
        $dates = [];
        foreach ($slots as $slot) {
            $dateKey = $slot->slot_time->toDateString();
            if (!isset($dates[$dateKey])) {
                $dates[$dateKey] = $slot->slot_time->copy();
            }
        }
        
        // Если всего одна дата — сразу показываем слоты на неё
        if (count($dates) === 1) {
            /** @var \Carbon\Carbon $date */
            $date = reset($dates);
            $this->showFreeSlotsForDate($chatId, $userId, $date);
            return;
        }
        
        ksort($dates);
        
        $todayStr = $now->toDateString();
        $lines = ["Выберите дату для бронирования 📅"];
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($dates as $dateKey => $dt) {
            $isToday = ($dateKey === $todayStr);
            
            $label = $isToday
                ? 'Сегодня ' . $dt->format('d.m') . ' 🕒'
                : $dt->format('d.m (D)'); // D = Mon/Tue ... (англ., можно не заморачиваться)
            
            $keyboard['inline_keyboard'][] = [[
                'text' => $label,
                'callback_data' => 'choose_date:' . $dateKey,
            ]];
        }
        
        $keyboard['inline_keyboard'][] = [[
            'text' => 'Отмена ❌',
            'callback_data' => 'cancel_choose_date',
        ]];
        
        $this->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    protected function showFreeSlotsForDate(int $chatId, int $userId, Carbon $date): void
    {
        $now = now();
        
        $query = Slot::query()
            ->whereDate('slot_time', $date->toDateString())
            ->whereNull('booked_by')
            ->where('is_disabled', false);
        
        // если это сегодня — отрезаем прошлое время
        if ($date->isSameDay($now)) {
            $query->where('slot_time', '>', $now);
        }
        
        $slots = $query
            ->orderBy('slot_time')
            ->get(['id', 'slot_time']);
        
        if ($slots->isEmpty()) {
            $label = $date->isSameDay($now)
                ? 'сегодня'
                : 'на ' . $date->format('d.m.Y');
            
            $this->sendMessage($chatId, "Свободных слотов {$label} нет 😔");
            return;
        }
        
        // готовим данные слотов в том же формате, что и showFreeSlots()
        $slotData = [];
        foreach ($slots as $slot) {
            $slotData[] = [
                'id'        => $slot->id,
                'slot_time' => $slot->slot_time->toDateTimeString(),
            ];
        }
        
        // текст — просто список времени
        $lines = ["Свободные слоты на " . $date->format('d.m.Y') . " ⏰:"];
        foreach ($slotData as $s) {
            $lines[] = Carbon::parse($s['slot_time'])->format('H:i');
        }
        
        // клавиатура строим через существующий helper,
        // он уже делает callback_data вида 'slot:1', 'slot:2', ...,
        // а также кнопки 'Готово' и 'Отмена' c 'slots_done' и 'cancel'
        $keyboard = [
            'inline_keyboard' => $this->buildSlotsKeyboard($slotData, []),
        ];
        
        // самое главное: step = 'select_slots', как ожидают callback'и
        $this->saveState($userId, 'select_slots', [
            'slots'      => $slotData,
            'chosen_idx' => [],
        ]);
        
        $this->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    
}
