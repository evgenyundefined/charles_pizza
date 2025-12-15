<?php

namespace App\Http\Controllers;

use App\Models\Slot;
use App\Models\TelegramMessage;
use App\Models\TelegramState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Lang;
class TelegramBotController extends Controller
{
    private const BTN_SHOW_SLOTS = 'Показать свободные слоты 🍕';
    private const BTN_MY_ORDERS = 'Мои заказы 📦';
    private const BTN_ORDER_HISTORY = 'История заказов 📜';
    private const CACHE_MAINTENANCE_KEY = 'pizza_bot.maintenance';
    private const BTN_LEAVE_REVIEW = 'Оставить отзыв ⭐';
    private const BTN_REVIEWS      = 'Отзывы ⭐';
    protected array $supportedLanguages = ['ru', 'en'];
    
    protected function t(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?: config('app.locale', 'ru');
        
        return Lang::get("telegram.$key", $replace, $locale);
    }
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
        return (bool)Cache::get(self::CACHE_MAINTENANCE_KEY, false);
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
        $adminChatId = (int)config('services.telegram.admin_chat_id');
        
        $username = $message['from']['username'] ?? trim(
            ($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')
        );
        $text   = trim($message['text'] ?? '');
        
        // телефон, если пользователь отправил контакт
        $phone = null;
        if (!empty($message['contact'])) {
            $contact = $message['contact'];
            if (
                isset($contact['phone_number']) &&
                (!isset($contact['user_id']) || $contact['user_id'] == $userId)
            ) {
                $phone = $contact['phone_number'];
            }
        }
        $this->logIncomingMessage($message);
        // СИНХРОНИЗАЦИЯ пользователя
        $telegramUser = $this->syncTelegramUser($message['from'], $chatId, $phone);
        $locale = $telegramUser->language ?? 'ru';
        
        $btnShowSlots   = $this->t('btn_show_slots', [], $locale);
        $btnHistory     = $this->t('btn_orders_history', [], $locale);
        $btnChangeLang  = $this->t('btn_change_language', [], $locale);
        $btnReviews     = $this->t('btn_reviews', [], $locale);
        
        if ($state && ($state['step'] ?? null) === 'review') {
            $reviewText = trim($text);
            
            if ($reviewText === '') {
                $this->sendMessage(
                    $chatId,
                    $this->tForUser($userId, 'telegram.reviews.ask_text')
                );
                return;
            }
            
            $slotId = $state['data']['slot_id'] ?? null;
            
            if (!$slotId) {
                $this->clearState($userId);
                $this->sendMessage($chatId, 'Не удалось определить заказ для отзыва 🤔');
                return;
            }
            
            /** @var \App\Models\Slot|null $slot */
            $slot = Slot::query()
                ->where('id', $slotId)
                ->where('booked_by', $userId)    // подстраховка — отзыв только к своему заказу
                ->first();
            
            if (!$slot) {
                $this->clearState($userId);
                $this->sendMessage($chatId, 'Не нашёл ваш заказ для отзыва 🙈');
                return;
            }

// сохраняем отзыв прямо в slots
            $slot->review_text   = $reviewText;
            $slot->review_rating = null;     // если рейтинг пока не используем
            $slot->reviewed_at   = now();
            $slot->save();
            
            $this->clearState($userId);

// спасибо пользователю
            $this->sendMessage(
                $chatId,
                $this->tForUser($userId, 'telegram.reviews.thanks')
            );

// опционально уведомляем админа
            $adminChatId = (int) config('services.telegram.admin_chat_id');
            $timeLabel   = $slot->slot_time->format('d.m.Y H:i');
            
            $this->sendMessage(
                $adminChatId,
                "⭐ Новый отзыв за слот {$timeLabel} от {$userId}:\n\n{$reviewText}"
            );
            
            return;
        }
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
            
            $data = $state['data'] ?? [];
            $messageId = $data['message_id'] ?? null;
            
            $this->confirmBooking($chatId, $userId, $username, $data, $messageId, $comment);
            $this->clearState($userId);
            
            return;
        }
        if ($text === '/start') {
            $this->clearState($userId);
            $this->showMainMenu($chatId , $locale);
            return;
        }
        if ($text === '/admin_logs') {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            // /admin_logs [telegram_id]
            $parts = preg_split('/\s+/', $text, 2);
            $arg   = $parts[1] ?? null;
            
            $this->adminLogs($chatId, $arg);
            
            return;
        }
        if ($text === '/admin_users') {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $this->adminUsersList($chatId);
            return;
        }
        if ($text === '/admin_statistic') {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $this->adminStatistic($chatId);
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
                "/admin_slots [YYYY-MM-DD] – занятые слоты 🍕 (кнопки «Выполнен» отмечают заказ как выполненный ✅)\n" .
                "/admin_slots all – все активные (не просроченные) брони 📅\n" .
                "/admin_slots available [YYYY-MM-DD] – свободные слоты на дату (по умолчанию сегодня) ✅\n" .
                "/admin_slots disable HH:MM [YYYY-MM-DD] – выключить слот на дату (по умолчанию сегодня) 🚫\n" .
                "/admin_slots enable HH:MM [YYYY-MM-DD] – включить слот обратно на дату (по умолчанию сегодня) ✅\n" .
                "/admin_slots clear_booking HH:MM [YYYY-MM-DD] – снять бронь с одного слота, но не удалять слот 🔄\n" .
                "/admin_slots clear [YYYY-MM-DD] – удалить все слоты на дату (по умолчанию сегодня, если нет броней) 🧹\n" .
                "/admin_slots clear_booked [YYYY-MM-DD] – сбросить брони на дату, слоты остаются 🔄\n" .
                "/admin_slots generate N [YYYY-MM-DD] – сгенерировать слоты на сегодня с шагом N минут ⏱️ (например 10, 15)\n\n" .
                "Уведомления:\n" .
                "/admin_notify_new_slots – разослать клиентам, что появились свободные слоты 🔔\n" .
                "/admin_notify ТЕКСТ – массовая рассылка произвольного сообщения клиентам 📢\n\n" .
                "Пользователи:\n" .
                "/admin_users – список всех, кто бронировал, и сколько слотов у каждого 👥\n\n" .
                "Статистика:\n" .
                "/admin_statistic – по датам сколько выполненных слотов 📊\n\n" .
                "Логи:\n" .
                "/admin_logs – последние 30 записей лога (входящие/исходящие) 📜\n" .
                "/admin_logs TELEGRAM_ID – логи только по этому пользователю 📜\n\n" .
                "Техработы:\n" .
                "/admin_techworks disable – включить режим технического обслуживания 🚧 (бот отвечает всем заглушкой)\n" .
                "/admin_techworks enable – выключить техобслуживание ✅ (бот снова принимает заказы)\n";
            
            $this->sendMessage($chatId, $help);
            return;
        }

        if ($text === $btnShowSlots) {
            $this->showFreeSlotsMenu($chatId, $userId, $locale);
            return;
        }
        if ($text === $btnHistory) {
            $this->showMyBookings($chatId, $userId, false, $locale);
            return;
        }
        if ($text === $btnChangeLang) {
            $this->showLanguageChooser($chatId, $userId, $locale);
            return;
        }
        if ($text === $btnReviews) {
            $this->showReviews($chatId);
            return;
        }
        if ($text === '/cancel' || $text === '/cancel_booking') {
            $this->showMyBookings($chatId, $userId, true, $locale); // только сегодня
            return;
        }
        if ($text === '/admin_notify_new_slots') {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $this->adminNotifyNewSlots($chatId);
            return;
        }
        if (str_starts_with($text, '/admin_notify')) {
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            // Может быть просто "/admin_notify" без текста
            $parts = explode(' ', $text, 2);
            $body  = trim($parts[1] ?? '');
            
            if ($body === '') {
                $this->sendMessage(
                    $chatId,
                    "Использование:\n" .
                    "/admin_notify Ваш текст рассылки\n\n" .
                    "Пример:\n" .
                    "/admin_notify Сегодня добавили новые виды пиццы, загляните в меню! 🍕"
                );
                return;
            }
            
            $this->adminNotifyCustom($chatId, $body);
            return;
        }
        
        if (str_starts_with($text, '/admin_slots')) {
            
            if ($chatId !== $adminChatId) {
                $this->sendMessage($chatId, 'Эта команда только для владельца.');
                return;
            }
            
            $parts = preg_split('/\s+/', $text);
            $sub = strtolower($parts[1] ?? '');
            $arg = $parts[2] ?? null;
            
            switch ($sub) {
                case '':
                    $this->showAdminSlots($chatId);
                    break;
                case 'available':
                case 'availiable': // на всякий случай, если напишешь с опечаткой :)
                    $dateStr = $parts[2] ?? null;
                    $this->showAdminAvailableSlots($chatId, $dateStr);
                    break;
                case 'disable': {
                    $timeStr = $parts[2] ?? null;          // HH:MM
                    $dateStr = $parts[3] ?? null;          // опционально YYYY-MM-DD
                    $this->adminDisableSlot($chatId, $timeStr, $dateStr);
                    break;
                }
                
                case 'enable': {
                    $timeStr = $parts[2] ?? null;
                    $dateStr = $parts[3] ?? null;
                    $this->adminEnableSlot($chatId, $timeStr, $dateStr);
                    break;
                }
                
                case 'clear_booking': {
                    $timeStr = $parts[2] ?? null;          // HH:MM
                    $dateStr = $parts[3] ?? null;          // [YYYY-MM-DD]
                    $this->adminClearSingleBooking($chatId, $timeStr, $dateStr);
                    break;
                }
                case 'generate':
                    $interval = isset($parts[2]) ? (int)$parts[2] : 0;
                    if ($interval <= 0) {
                        $this->sendMessage($chatId, "Укажите шаг в минутах, например:\n/admin_slots generate 10\nили\n/admin_slots generate 15 2025-12-08");
                        return;
                    }
                    $dateStr = $parts[3] ?? null; // опциональная дата YYYY-MM-DD
                    $this->adminGenerateSlots($chatId, $interval, $dateStr);
                    break;
                case 'clear':
                    // опционально: /admin_slots clear YYYY-MM-DD
                    $this->adminClearSlots($chatId, $arg);
                    break;
                
                case 'clear_booked':
                    // опционально: /admin_slots clear_booked YYYY-MM-DD
                    $this->adminClearBookedSlots($chatId, $arg);
                    break;
                case 'all':
                    $this->showAdminAllActiveSlots($chatId);
                    break;
                default:
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sub)) {
                        $this->showAdminSlots($chatId, $sub);
                        break;
                    }
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
            $mode = strtolower($parts[1] ?? '');
            
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
        if ($text === self::BTN_LEAVE_REVIEW || $text === '/review') {
            $this->startReviewFlow($chatId, $userId);
            return;
        }
        
        if ($text === self::BTN_REVIEWS || $text === '/reviews') {
            $this->showReviews($chatId);
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
        $data = $callback['data'] ?? '';
        $userId = $callback['from']['id'];
        $chatId = $callback['message']['chat']['id'];
        $username = $callback['from']['username'] ?? trim(
            ($callback['from']['first_name'] ?? '') . ' ' . ($callback['from']['last_name'] ?? '')
        );
        $cbId = $callback['id'];
        $messageId = $callback['message']['message_id'] ?? null;
        $adminChatId = (int)config('services.telegram.admin_chat_id');
        
        $this->logIncomingCallback($callback);
        $telegramUser = $this->syncTelegramUser($callback['from'] , $chatId);
        $locale = $telegramUser->language ?? 'ru';
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
            $slotId = (int)substr($data, 5);
            
            $slot = Slot::query()->find($slotId);
            if (!$slot) {
                $this->sendMessage($chatId, 'Слот не найден.');
                [$text, $replyMarkup] = $this->buildAdminSlotsView(); // сегодня
            } else {
                // Отметим как выполненный
                $slot->is_completed = true;
                $slot->save();
                
                // Дата слота для перерисовки списка
                $date = $slot->slot_time->copy()->startOfDay();
                
                // Уведомим пользователя, если он есть
                if ($slot->booked_by) {
                    $timeLabel = $slot->slot_time->format('H:i');
                    $dateLabel = $slot->slot_time->format('d.m.Y');
                    
                    $this->sendMessage(
                        $slot->booked_by,
                        "🍕 Ваша пицца на {$dateLabel} {$timeLabel} готова!\n" .
                        "Забирайте, пока горячая 🔥"
                    );
                }
                
                [$text, $replyMarkup] = $this->buildAdminSlotsView($date);
            }
            
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
            
            return;
        }
        if (str_starts_with($data, 'slot:')) {
            $index = (int)substr($data, 5); // номера слотов 1..N
            
            $state = $this->loadState($userId);
            if (!$state || $state['step'] !== 'select_slots') {
                // старый апдейт / нет состояния
                return;
            }
            
            $slots = $state['data']['slots'] ?? [];
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
            $slotId = (int)substr($data, strlen('cancel_slot:'));
            
            $slot = Slot::query()->find($slotId);
            
            if (!$slot || $slot->booked_by !== $userId) {
                $this->sendMessage($chatId, 'Не удалось найти вашу бронь для отмены.');
                return;
            }
            
            $now = now();
            $cutoff = $slot->slot_time->copy()->subHours(2); // за 2 часа до слота
            
            // Нельзя отменять, если:
            // - заказ уже выполнен
            // - время уже после cutoff (меньше 2 часов до слота)
            if ($slot->is_completed || $now->gte($cutoff)) {
                $this->sendMessage($chatId, 'Эту бронь уже нельзя отменить ⏰');
                return;
            }
            
            $timeLabel = $slot->slot_time->format('H:i');
            $usernameShort = $slot->booked_username ?: $slot->booked_by;
            
            $slot->update([
                'booked_by' => null,
                'booked_username' => null,
                'comment' => null,
                'is_completed' => false,
                'booked_at' => null,
            ]);
            
            
            $label = is_string($usernameShort) && str_starts_with($usernameShort, '@')
                ? $usernameShort
                : '@' . $usernameShort;
            
            $this->sendMessage(
                $adminChatId,
                "🚫 Отмена брони:\n[{$timeLabel} {$label}]"
            );
            
            [$text, $replyMarkup] = $this->buildMyBookingsView($userId, true , $locale);
            
            if ($messageId ?? null) {
                $params = [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
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
        if (str_starts_with($data, 'admin_cancel:')) {
            $slotId = (int) substr($data, strlen('admin_cancel:'));
            
            $slot = Slot::query()->find($slotId);
            if (!$slot) {
                $this->sendMessage($chatId, 'Слот не найден.');
                return;
            }
            
            $timeLabel = $slot->slot_time->format('H:i');
            $dateLabel = $slot->slot_time->format('d.m.Y');
            $userToNotify = $slot->booked_by;
            
            // освобождаем слот
            $slot->update([
                'booked_by'       => null,
                'booked_username' => null,
                'comment'         => null,
                'is_completed'    => false,
                'booked_at'       => null,
            ]);
            
            // уведомляем пользователя, если он есть
            if ($userToNotify) {
                $this->sendMessage(
                    $userToNotify,
                    "❌ Ваша бронь на {$dateLabel} {$timeLabel} была отменена пиццерией.\n" .
                    "Если это неожиданно — напишите нам."
                );
            }
            
            // перерисуем админ-список
            [$text, $replyMarkup] = $this->buildAdminSlotsView();
            
            if (!empty($messageId)) {
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
        if ($data === 'change_lang') {
            $this->showLanguageChooser($chatId, $userId, $locale);
            return;
        }
        
        if (str_starts_with($data, 'set_lang:')) {
            $lang = substr($data, strlen('set_lang:'));
            
            if (!in_array($lang, $this->supportedLanguages, true)) {
                $this->answerCallback($cbId, 'Unsupported language');
                return;
            }
            
            $telegramUser->language = $lang;
            $telegramUser->save();
            
            $this->sendMessage(
                $chatId,
                $this->t('language_set', [
                    'lang' => $lang === 'ru'
                        ? $this->t('lang_ru_label', [], $lang)
                        : $this->t('lang_en_label', [], $lang),
                ], $lang)
            );
            
            // Показать меню уже на новом языке
            $this->showMainMenu($chatId, $lang);
            return;
        }
        if ($data === 'cancel_choose_date') {
            $this->clearState($userId);
            $this->sendMessage($chatId, 'Выбор даты отменён ❌');
            return;
        }
        if ($data === 'slots_done') {
            $state = $this->loadState($userId);
            if (!$state || ($state['step'] ?? null) !== 'select_slots') {
                return;
            }
            
            $slots = $state['data']['slots'] ?? [];
            $idx   = $state['data']['chosen_idx'] ?? [];
            
            if (empty($idx) || empty($slots)) {
                $this->sendMessage($chatId, 'Вы не выбрали ни одного слота 😅');
                return;
            }
            
            sort($idx);
            
            // Собираем выбранные слоты по индексам
            $chosen = [];
            foreach ($idx as $n) {
                if (!isset($slots[$n - 1])) {
                    continue;
                }
                $chosen[] = $slots[$n - 1];
            }
            
            if (count($chosen) === 0) {
                $this->sendMessage($chatId, 'Не удалось определить выбранные слоты, попробуйте ещё раз.');
                return;
            }
            
            // --- Проверка "подрядности" по реальному интервалу ---
            if (count($chosen) > 1) {
                // дата всех выбранных слотов (они в один день)
                $firstDate = Carbon::parse($chosen[0]['slot_time'])->toDateString();
                
                // все слоты этого дня (занятые, свободные, выключенные — не важно)
                $allTimes = Slot::query()
                    ->whereDate('slot_time', $firstDate)
                    ->orderBy('slot_time')
                    ->pluck('slot_time');
                
                // базовый интервал — минимальная разница между соседними слотами
                $baseInterval = null;
                for ($i = 1; $i < $allTimes->count(); $i++) {
                    /** @var \Carbon\Carbon $prev */
                    /** @var \Carbon\Carbon $cur */
                    $prev = $allTimes[$i - 1];
                    $cur  = $allTimes[$i];
                    $diff = $cur->diffInMinutes($prev);
                    
                    if ($diff > 0 && ($baseInterval === null || $diff < $baseInterval)) {
                        $baseInterval = $diff;
                    }
                }
                
                if ($baseInterval !== null) {
                    // сортируем выбранные по времени и проверяем, что разница = базовому интервалу
                    usort($chosen, fn($a, $b) => strcmp($a['slot_time'], $b['slot_time']));
                    
                    for ($i = 1; $i < count($chosen); $i++) {
                        $prev = Carbon::parse($chosen[$i - 1]['slot_time']);
                        $cur  = Carbon::parse($chosen[$i]['slot_time']);
                        $diff = $cur->diffInMinutes($prev);
                        
                        if ($diff !== $baseInterval) {
                            $this->sendMessage(
                                $chatId,
                                "Можно бронировать только подряд идущие слоты.\n" .
                                "Выберите слоты снова ⏰."
                            );
                            return;
                        }
                    }
                }
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
            $this->sendMessage($chatId, $this->t('telegram.'));
            $this->showMainMenu($chatId, $locale);
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
            $idx = $dataState['chosen_idx'];
            
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
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
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
                null,
                locale: $locale
            );
            
            $this->clearState($userId);
            return;
        }
        if ($data === 'my_today') {
            $this->showMyBookings($chatId, $userId, false, $locale);
            return;
        }
        if ($data === 'my_history') {
            $this->showMyBookings($chatId, $userId, false, $locale);
            return;
        }
        if ($data === 'menu_show_slots') {
            $this->showFreeSlotsMenu($chatId, $userId);
            return;
        }
        if ($data === 'leave_review') {
            $this->startReviewFlow($chatId, $userId);
            return;
        }
        
        if ($data === 'show_reviews') {
            $this->showReviews($chatId);
            return;
        }
    }
    
    /* ================== LOGGING ================== */
    
    protected function logIncomingMessage(array $message): void
    {
        try {
            TelegramMessage::create([
                'telegram_id' => $message['from']['id'] ?? null,
                'chat_id'     => (string)($message['chat']['id'] ?? ''),
                'direction'   => 'in',
                'type'        => 'message',
                'message_id'  => $message['message_id'] ?? null,
                'text'        => $message['text'] ?? null,
                'payload'     => $message,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TG logIncomingMessage error: ' . $e->getMessage());
        }
    }
    
    protected function logIncomingCallback(array $callback): void
    {
        try {
            TelegramMessage::create([
                'telegram_id' => $callback['from']['id'] ?? null,
                'chat_id'     => (string)($callback['message']['chat']['id'] ?? ''),
                'direction'   => 'in',
                'type'        => 'callback',
                'message_id'  => $callback['message']['message_id'] ?? null,
                'text'        => $callback['data'] ?? null,
                'payload'     => $callback,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TG logIncomingCallback error: ' . $e->getMessage());
        }
    }
    
    /* ================== UI / БИЗНЕС-ЛОГИКА ================== */
    
    protected function showMainMenu($chatId, ?string $locale = null): void
    {
        $locale = $locale ?: config('app.locale', 'ru');
        
        $text = $this->t('main_menu_text', [], $locale);
        
        $btnShowSlots     = $this->t('btn_show_slots', [], $locale);
        $btnHistory       = $this->t('btn_orders_history', [], $locale);
        $btnChangeLang    = $this->t('btn_change_language', [], $locale);
        $btnReviews       = $this->t('btn_reviews', [], $locale);
        
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $btnShowSlots,   'callback_data' => 'menu_show_slots'],
                ],
                [
                    ['text' => $btnHistory,     'callback_data' => 'my_history'],
                ],
                [
                   // ['text' => self::BTN_LEAVE_REVIEW, 'callback_data' => 'leave_review'],
                    ['text' => $btnReviews,     'callback_data' => 'show_reviews'],
                ],
                [
                    ['text' => $btnChangeLang,  'callback_data' => 'change_lang'],
                ],
            ],
        ];
        
        $this->sendMessage($chatId, $text, $inlineKeyboard);
        
        $replyKeyboard = [
            'keyboard' => [
                [
                    ['text' => $btnShowSlots],
                ],
                [
                    ['text' => $btnHistory],
                ],
                [
                //    ['text' => self::BTN_LEAVE_REVIEW, 'callback_data' => 'leave_review'],
                    ['text' => $btnReviews,      'callback_data' => 'show_reviews'],
                ],
                [
                    ['text' => $btnChangeLang],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];
        
        $this->sendMessage(
            $chatId,
            $this->t('main_menu_keyboard_hint', [], $locale),
            $replyKeyboard
        );
    }
    
    protected function showAdminAllActiveSlots(int $chatId): void
    {
        $now = now();
        
        $slots = Slot::query()
            ->whereNotNull('booked_by')
            ->where('slot_time', '>', $now)              // только не прошедшие
            ->orderBy('slot_time')
            ->get(['slot_time', 'booked_by', 'booked_username', 'comment', 'is_completed']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, "Активных (не просроченных) броней сейчас нет 🍀");
            return;
        }
        
        $lines = ["📋 Все активные брони (не просрочены):"];
        $currentDate = null;
        
        foreach ($slots as $slot) {
            /** @var \App\Models\Slot $slot */
            $dateLabel = $slot->slot_time->format('d.m.Y');
            $timeLabel = $slot->slot_time->format('H:i');
            
            if ($dateLabel !== $currentDate) {
                $currentDate = $dateLabel;
                $lines[] = "";               // пустая строка между датами
                $lines[] = "📅 {$dateLabel}";
            }
            
            $username = $slot->booked_username ?: $slot->booked_by;
            if (!str_starts_with((string)$username, '@')) {
                $username = '@' . $username;
            }
            
            $status = $slot->is_completed ? '✅ выполнен' : '⏳ ожидает';
            
            $lines[] = "• {$timeLabel} — {$username} — {$status}";
            
            if (!empty($slot->comment)) {
                $lines[] = '   💬 ' . $slot->comment;
            }
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }

    protected function showFreeSlotsMenu(int $chatId, int $userId, ?string $locale = null): void
    {
        $locale = $locale ?: 'ru';
        $now = now();
        
        $slots = Slot::query()
            ->where('slot_time', '>', $now)
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->orderBy('slot_time')
            ->get(['slot_time']);
        
        if ($slots->isEmpty()) {
            $this->sendMessage($chatId, $this->t('no_free_slots', [], $locale));
            return;
        }
        
        // Собираем список дат
        $dates = [];
        foreach ($slots as $slot) {
            $dateKey = $slot->slot_time->toDateString(); // YYYY-MM-DD
            if (!isset($dates[$dateKey])) {
                $dates[$dateKey] = $slot->slot_time->copy();
            }
        }
        
        // Одна дата — сразу к слотам (чтобы не мучить лишним шагом)
        if (count($dates) === 1) {
            /** @var Carbon $date */
            $date = reset($dates);
            $this->showFreeSlotsForDate($chatId, $userId, $date);
            return;
        }
        
        ksort($dates);
        $todayStr = $now->toDateString();
        
        $text = "Выберите дату для бронирования 📅";
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($dates as $dateKey => $dt) {
            $isToday = ($dateKey === $todayStr);
            
            $label = $isToday
                ? 'Сегодня ' . $dt->format('d.m') . ' 🕒'
                : $dt->format('d.m.Y');
            
            $keyboard['inline_keyboard'][] = [[
                'text' => $label,
                'callback_data' => 'choose_date:' . $dateKey,
            ]];
        }
        
        $keyboard['inline_keyboard'][] = [[
            'text' => 'Отмена ❌',
            'callback_data' => 'cancel_choose_date',
        ]];
        
        $this->sendMessage($chatId, $text, $keyboard);
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
                'id' => $slot->id,
                'slot_time' => $slot->slot_time->toDateTimeString(),
            ];
        }
        
        // текст — просто список времени
        $lines = ["Свободные слоты на " . $date->format('d.m.Y') . " ⏰ (1 слот = 1 пицца):"];
        
        /*foreach ($slotData as $s) {
            $lines[] = Carbon::parse($s['slot_time'])->format('H:i');
        }*/
        
        // клавиатура строим через существующий helper,
        // он уже делает callback_data вида 'slot:1', 'slot:2', ...,
        // а также кнопки 'Готово' и 'Отмена' c 'slots_done' и 'cancel'
        $keyboard = [
            'inline_keyboard' => $this->buildSlotsKeyboard($slotData, []),
        ];
        
        // самое главное: step = 'select_slots', как ожидают callback'и
        $this->saveState($userId, 'select_slots', [
            'slots' => $slotData,
            'chosen_idx' => [],
        ]);
        
        $this->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    protected function buildSlotsKeyboard(array $slots, array $selectedIdx = []): array
    {
        $rows = [];
        $row = [];
        
        foreach ($slots as $i => $slot) {
            $num = $i + 1; // номер слота для пользователя
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
        
        $rows[] = [
            [
                'text'          => '✅ Готово (подтвердить)',
                'callback_data' => 'slots_done',
            ],
            [
                'text'          => '❌ Отмена',
                'callback_data' => 'cancel',
            ],
        ];
        
        return $rows;
    }
    
    protected function confirmBooking(
        $chatId,
        int $userId,
        string $username,
        array $data,
        ?int $messageId = null,
        ?string $comment = null,
        ?string $locale = 'ru'
    ): void
    {
        $slots = $data['slots'] ?? [];
        $idx = $data['chosen_idx'] ?? [];
        $adminId = (int)config('services.telegram.admin_chat_id');
        
        if (empty($slots) || empty($idx)) {
            $this->sendMessage($chatId, 'Не найден список выбранных слотов, начните заново.');
            return;
        }
        
        $chosen = [];
        $ids = [];
        
        foreach ($idx as $n) {
            if (!isset($slots[$n - 1])) {
                continue;
            }
            
            $slot = $slots[$n - 1];
            $chosen[] = $slot;
            $ids[] = $slot['id'];
        }
        
        if (empty($ids)) {
            $this->sendMessage($chatId, 'Слоты не выбраны.');
            return;
        }
        
        $displayName = trim($username);
        
        if ($displayName !== '' && !str_contains($displayName, ' ')) {
            if (!str_starts_with($displayName, '@')) {
                $displayName = '@' . $displayName;
            }
        } else {
            // username нет — используем имя + id
            if ($displayName === '') {
                $displayName = (string) $userId;
            }
            $displayName = $displayName . ' (' . $userId . ')';
        }
        
        $usernameShort = $displayName;
        
        $updated = \DB::transaction(function () use ($ids, $userId, $usernameShort, $comment) {
            return Slot::query()
                ->whereIn('id', $ids)
                ->whereNull('booked_by')
                ->where('is_disabled', false)
                ->update([
                    'booked_by' => $userId,
                    'booked_username' => $usernameShort,
                    'comment' => $comment,
                    'booked_at' => now(),
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
            fn($s) => \Carbon\Carbon::parse($s['slot_time'])->format('H:i'),
            $chosen
        );
        
        $timesStr = implode(', ', $times);
        $text = $this->t('booking_done', ['times' => $timesStr], $locale);
        
        //$text = 'Готово! 🎉 За вами слоты: ' . implode(', ', $times) . " 🍕";
        
        $inlineKeyboard = [
            'inline_keyboard' => [
                /*[
                    ['text' => 'Мои заказы 📦', 'callback_data' => 'my_today'],
                ],*/
                [
                    ['text' => 'История заказов 📜', 'callback_data' => 'my_history'],
                ],
            ],
        ];
        
        if ($messageId) {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE),
            ];
            
            $this->tg('editMessageText', $params);
        } else {
            $this->sendMessage($chatId, $text, $inlineKeyboard);
        }
        
        
        $label = str_starts_with($usernameShort, '@') ? $usernameShort : '@' . $usernameShort;
        
        $firstDate = \Carbon\Carbon::parse($chosen[0]['slot_time']);
        $dateLabel = $firstDate->format('d.m.Y');
        
        $adminText = '🍕 Новая бронь:' . PHP_EOL .
            '[' . $dateLabel . ' ' . implode(' ', $times) . ' ' . $label . ']';
        
        if ($comment !== null && $comment !== '') {
            $adminText .= PHP_EOL . '💬 Комментарий: ' . $comment;
        }
        
        $this->sendMessage($adminId, $adminText);
    }
    protected function buildMyBookingsView(int $userId, bool $todayOnly = false , ?string $locale = 'ru'): array
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
                ? $this->t('no_bookings_today', [], $locale)
                : $this->t('no_bookings_any', [], $locale);
            
            return [$msg, null];
        }
        
        $lines = [
            $todayOnly
                ? $this->t('my_bookings_today', [], $locale)
                : $this->t('my_bookings_all', [], $locale),
        ];
        
        $currentDate = null;
        $now = now();
        
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
            
            // можно ли отменить? — пока до слота больше 2 часов
            if (
                !$slot->is_completed
            ) {
                $cutoff = $slot->slot_time->copy()->subHours(2); // точка «за 2 часа до слота»
                
                if ($now->lt($cutoff)) {
                    $keyboard['inline_keyboard'][] = [[
                        'text' => "Отменить {$timeLabel} ❌",
                        'callback_data' => 'cancel_slot:' . $slot->id,
                    ]];
                }
            }
        }
        
        if ($keyboard && empty($keyboard['inline_keyboard'])) {
            $keyboard = null;
        }
        
        return [implode("\n", $lines), $keyboard];
    }
    protected function showMyBookings($chatId, int $userId, bool $todayOnly = false , ?string $locale = 'ru'): void
    {
        [$text, $replyMarkup] = $this->buildMyBookingsView($userId, $todayOnly , $locale);
        
        if ($replyMarkup) {
            $this->sendMessage($chatId, $text, $replyMarkup);
        } else {
            $this->sendMessage($chatId, $text);
        }
    }
    protected function showAdminSlots($chatId, ?string $dateStr = null): void
    {
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
        
        [$text, $replyMarkup] = $this->buildAdminSlotsView($date);
        
        if ($replyMarkup) {
            $this->sendMessage($chatId, $text, $replyMarkup);
        } else {
            $this->sendMessage($chatId, $text);
        }
    }
    protected function showLanguageChooser(int $chatId, int $userId, string $locale): void
    {
        $text = $this->t('choose_language', [], $locale);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text'          => $this->t('lang_ru_label', [], 'ru'),
                        'callback_data' => 'set_lang:ru',
                    ],
                ],
                [
                    [
                        'text'          => $this->t('lang_en_label', [], 'en'),
                        'callback_data' => 'set_lang:en',
                    ],
                ],
            ],
        ];
        
        $this->sendMessage($chatId, $text, $keyboard);
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
    protected function adminDisableSlot($chatId, ?string $timeStr, ?string $dateStr = null): void
    {
        if (!$timeStr) {
            $this->sendMessage(
                $chatId,
                "Использование: /admin_slots disable HH:MM [YYYY-MM-DD]\n" .
                "Например:\n" .
                "/admin_slots disable 15:30\n" .
                "/admin_slots disable 15:30 2025-12-09"
            );
            return;
        }
        
        $timeStr = trim($timeStr);
        
        try {
            $time = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        } catch (\Throwable $e) {
            $this->sendMessage(
                $chatId,
                "Неверный формат времени ⏱️\n" .
                "Ожидаю HH:MM, например 15:30"
            );
            return;
        }
        
        // Дата: либо указанная, либо сегодня
        if ($dateStr) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr, config('app.timezone'))->startOfDay();
            } catch (\Throwable $e) {
                $this->sendMessage(
                    $chatId,
                    "Неверный формат даты.\nИспользуйте YYYY-MM-DD, например: 2025-12-09"
                );
                return;
            }
        } else {
            $date = today();
        }
        
        $dateDb    = $date->toDateString();
        $dateHuman = $date->format('d.m.Y');
        
        $slot = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereTime('slot_time', $time->format('H:i:00'))
            ->first();
        
        if (!$slot) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на дату {$dateHuman} не найден ❓"
            );
            return;
        }
        
        if ($slot->booked_by !== null) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на {$dateHuman} уже забронирован, отключать не буду ⚠️"
            );
            return;
        }
        
        $slot->is_disabled = true;
        $slot->save();
        
        $this->sendMessage(
            $chatId,
            "Слот {$time->format('H:i')} на {$dateHuman} помечен как недоступный 🚫"
        );
    }
    
    protected function adminEnableSlot($chatId, ?string $timeStr, ?string $dateStr = null): void
    {
        if (!$timeStr) {
            $this->sendMessage(
                $chatId,
                "Использование: /admin_slots enable HH:MM [YYYY-MM-DD]\n" .
                "Например:\n" .
                "/admin_slots enable 15:30\n" .
                "/admin_slots enable 15:30 2025-12-09"
            );
            return;
        }
        
        $timeStr = trim($timeStr);
        
        try {
            $time = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        } catch (\Throwable $e) {
            $this->sendMessage(
                $chatId,
                "Неверный формат времени ⏱️\n" .
                "Ожидаю HH:MM, например 15:30"
            );
            return;
        }
        
        if ($dateStr) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr, config('app.timezone'))->startOfDay();
            } catch (\Throwable $e) {
                $this->sendMessage(
                    $chatId,
                    "Неверный формат даты.\nИспользуйте YYYY-MM-DD, например: 2025-12-09"
                );
                return;
            }
        } else {
            $date = today();
        }
        
        $dateDb    = $date->toDateString();
        $dateHuman = $date->format('d.m.Y');
        
        $slot = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereTime('slot_time', $time->format('H:i:00'))
            ->first();
        
        if (!$slot) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на дату {$dateHuman} не найден ❓"
            );
            return;
        }
        
        if ($slot->booked_by !== null) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на {$dateHuman} уже забронирован, включать/выключать нет смысла ⚠️"
            );
            return;
        }
        
        if (!$slot->is_disabled) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на {$dateHuman} и так активен ✅"
            );
            return;
        }
        
        $slot->is_disabled = false;
        $slot->save();
        
        $this->sendMessage(
            $chatId,
            "Слот {$time->format('H:i')} на {$dateHuman} снова доступен ✅"
        );
    }
    protected function adminClearSingleBooking(int $chatId, ?string $timeStr, ?string $dateStr = null): void
    {
        if (!$timeStr) {
            $this->sendMessage(
                $chatId,
                "Использование: /admin_slots clear_booking HH:MM [YYYY-MM-DD]\n" .
                "Например:\n" .
                "/admin_slots clear_booking 19:00\n" .
                "/admin_slots clear_booking 19:00 2025-12-09"
            );
            return;
        }
        
        $timeStr = trim($timeStr);
        
        try {
            $time = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        } catch (\Throwable $e) {
            $this->sendMessage(
                $chatId,
                "Неверный формат времени ⏱️\n" .
                "Ожидаю HH:MM, например 19:00"
            );
            return;
        }
        
        if ($dateStr) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateStr, config('app.timezone'))->startOfDay();
            } catch (\Throwable $e) {
                $this->sendMessage(
                    $chatId,
                    "Неверный формат даты.\nИспользуйте YYYY-MM-DD, например: 2025-12-09"
                );
                return;
            }
        } else {
            $date = today();
        }
        
        $dateDb    = $date->toDateString();
        $dateHuman = $date->format('d.m.Y');
        
        $slot = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereTime('slot_time', $time->format('H:i:00'))
            ->first();
        
        if (!$slot) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на дату {$dateHuman} не найден ❓"
            );
            return;
        }
        
        if ($slot->booked_by === null) {
            $this->sendMessage(
                $chatId,
                "Слот {$time->format('H:i')} на {$dateHuman} сейчас не забронирован 🙂"
            );
            return;
        }
        
        $userId    = $slot->booked_by;
        $timeLabel = $slot->slot_time->format('H:i');
        $dateLabel = $slot->slot_time->format('d.m.Y');
        
        // сбрасываем бронь
        $slot->update([
            'booked_by'       => null,
            'booked_username' => null,
            'comment'         => null,
            'is_completed'    => false,
            'booked_at'       => null,
        ]);
        
        // уведомим пользователя, если есть
        if ($userId) {
            $this->sendMessage(
                $userId,
                "❌ Ваша бронь на {$dateLabel} {$timeLabel} была снята администратором.\n" .
                "Если это неожиданно — напишите нам."
            );
        }
        
        $this->sendMessage(
            $chatId,
            "🔄 Бронь на {$dateLabel} {$timeLabel} снята, слот очищен."
        );
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
        $end = $date->copy()->setTime(20, 0); // 20:00
        
        $created = 0;
        
        // 4) Идём по интервалу и создаём только отсутствующие слоты
        for ($time = $start->copy(); $time < $end; $time->addMinutes($intervalMinutes)) {
            $slot = Slot::query()->firstOrCreate(
                ['slot_time' => $time],
                [
                    'is_disabled' => false,
                    'booked_by' => null,
                    'booked_username' => null,
                    'comment' => null,
                    'is_completed' => false,
                    'booked_at' => null,
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
    protected function buildAdminSlotsView(?Carbon $date = null): array
    {
        $date = $date ? $date->copy()->startOfDay() : today();
        $dateStr = $date->toDateString();      // 2025-12-08
        $dateHuman = $date->format('d.m.Y');     // 08.12.2025
        
        $rows = Slot::query()
            ->whereNotNull('booked_by')
            ->whereDate('slot_time', $dateStr)
            ->orderBy('slot_time')
            ->get(['id', 'slot_time', 'booked_by', 'booked_username', 'comment', 'is_completed']);
        
        if ($rows->isEmpty()) {
            return ["На {$dateHuman} занятых слотов нет 🍀", null];
        }
        
        $lines = ["📋 Занятые слоты на {$dateHuman} ({$dateStr}):"];
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($rows as $slot) {
            /** @var \App\Models\Slot $slot */
            $time = $slot->slot_time->format('H:i');
            
            $username = $slot->booked_username ?: $slot->booked_by;
            if (!str_starts_with((string)$username, '@')) {
                $username = '@' . $username;
            }
            
            $line = "[{$time} {$username}]";
            
            if ($slot->comment) {
                $line .= " 💬 {$slot->comment}";
            }
            
            if ($slot->is_completed) {
                // заказ уже выполнен — только текст с ✅, без кнопок
                $line .= " ✅";
            } else {
                // ещё не выполнен — показываем обе кнопки
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => "✅ {$username} {$time} ✅",
                        'callback_data' => 'done:' . $slot->id,
                    ],
                    [
                        'text' => "❌ {$username} {$time} ❌",
                        'callback_data' => 'admin_cancel:' . $slot->id,
                    ],
                ];
            }
            
            $lines[] = $line;
        }
        
        if (empty($keyboard['inline_keyboard'])) {
            $keyboard = null;
        }
        
        return [implode("\n", $lines), $keyboard];
    }
    protected function adminClearSlots($chatId, ?string $dateStr = null): void
    {
        // определяем дату
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
        
        $dateDb = $date->toDateString();      // для whereDate
        $dateHuman = $date->format('d.m.Y');     // для текста
        
        // сначала проверим, нет ли броней
        $bookedCount = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereNotNull('booked_by')
            ->count();
        
        if ($bookedCount > 0) {
            $this->sendMessage(
                $chatId,
                "На {$dateHuman} уже есть забронированные слоты ({$bookedCount} шт.), " .
                "очистка отменена ❌"
            );
            return;
        }
        
        // удаляем все слоты на эту дату
        $total = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->delete();
        
        $this->sendMessage(
            $chatId,
            "🧹 Все слоты на {$dateHuman} ({$dateDb}) удалены.\n" .
            "Удалено записей: {$total}."
        );
    }
    /**
     * Рассылает клиентам уведомление о том, что на сегодня есть свободные слоты.
     * Сейчас: берём всех, кто когда-либо бронировал (distinct booked_by),
     * и шлём им сообщение.
     */
    protected function adminNotifyNewSlots(int $chatId): void
    {
        $today = now()->toDateString();
        $now   = now();
        
        // Свободные слоты на сегодня (ещё не прошедшие)
        $freeCount = Slot::query()
            ->whereDate('slot_time', $today)
            ->whereNull('booked_by')
            ->where('is_disabled', false)
            ->where('slot_time', '>', $now)
            ->count();
        
        if ($freeCount === 0) {
            $this->sendMessage(
                $chatId,$this->t('no_free_slots')
            );
            return;
        }
        
        // Кому шлём: всем пользователям, которых мы знаем в telegram_users
        $userIds = TelegramUser::query()
            ->pluck('telegram_id')
            ->filter()
            ->values();
        
        if ($userIds->isEmpty()) {
            $this->sendMessage(
                $chatId,
                "Нет пользователей в базе telegram_users — рассылать некому 🤷‍♂️"
            );
            return;
        }
        
        $dateLabel = $now->format('d.m.Y');
        $sent = 0;
        
        foreach ($userIds as $uid) {
            try {
                $this->sendMessage(
                    $uid,
                    "🍕 Появились свободные слоты на {$dateLabel}!\n\n" .
                    "Нажмите кнопку «Показать свободные слоты 🍕», чтобы выбрать время."
                );
                $sent++;
            } catch (\Throwable $e) {
                // игнорируем ошибки отправки одному юзеру
            }
        }
        
        $this->sendMessage(
            $chatId,
            "Готово! 🔔 Отправил уведомление {$sent} пользователям.\n" .
            "Свободных слотов на сегодня: {$freeCount}."
        );
    }
    
    protected function adminClearBookedSlots($chatId, ?string $dateStr = null): void
    {
  
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
        
        $dateDb = $date->toDateString();
        $dateHuman = $date->format('d.m.Y');
        
        // сколько сейчас занято
        $bookedCount = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereNotNull('booked_by')
            ->count();
        
        if ($bookedCount === 0) {
            $this->sendMessage(
                $chatId,
                "На {$dateHuman} нет занятых слотов — сбрасывать нечего 🙂"
            );
            return;
        }
        
        // сбрасываем только занятые на эту дату
        $updated = Slot::query()
            ->whereDate('slot_time', $dateDb)
            ->whereNotNull('booked_by')
            ->update([
                'booked_by' => null,
                'booked_username' => null,
                'comment' => null,
                'is_completed' => false,
                'booked_at' => null,
            ]);
        
        $this->sendMessage(
            $chatId,
            "🔄 Занятые брони на {$dateHuman} ({$dateDb}) сброшены.\n" .
            "Освобождено слотов: {$updated}."
        );
    }
    /**
     * Массовая рассылка произвольного сообщения всем клиентам,
     * которые когда-либо бронировали слоты.
     */
    protected function adminNotifyCustom(int $chatId, string $body): void
    {
        $body = trim($body);
        
        if ($body === '') {
            $this->sendMessage(
                $chatId,
                "Текст уведомления пустой.\n" .
                "Использование: /admin_notify Ваш текст рассылки"
            );
            return;
        }
        
        // Все пользователи, которых знаем (независимо от того, бронировали ли)
        $userIds = TelegramUser::query()
            ->pluck('telegram_id')
            ->filter()
            ->values();
        
        if ($userIds->isEmpty()) {
            $this->sendMessage(
                $chatId,
                "Нет пользователей в базе telegram_users — рассылать некому 🤷‍♂️"
            );
            return;
        }
        
        $sent = 0;
        
        foreach ($userIds as $uid) {
            try {
                $this->sendMessage(
                    $uid,
                    "📢 Сообщение:\n\n{$body}"
                );
                $sent++;
            } catch (\Throwable $e) {
                // игнорируем ошибки отправки отдельным пользователям
            }
        }
        
        $this->sendMessage(
            $chatId,
            "Готово! 📢 Отправил сообщение {$sent} пользователям."
        );
    }
    
    /**
     * Выводит список всех пользователей, которые когда-либо бронировали слоты,
     * и в скобках — сколько слотов у каждого.
     */
    protected function adminUsersList(int $chatId): void
    {
        $rows = TelegramUser::query()
            ->leftJoin('slots', 'slots.booked_by', '=', 'telegram_users.telegram_id')
            ->selectRaw('telegram_users.*, COUNT(slots.id) as cnt')
            ->groupBy('telegram_users.telegram_id', 'telegram_users.display_name')
            ->orderByDesc('cnt')
            ->get();
        
        if ($rows->isEmpty()) {
            $this->sendMessage($chatId, 'Пока ещё никто не писал боту 😴');
            return;
        }
        
        $lines = ["👥 Пользователи бота:"];
        
        $i = 1;
        foreach ($rows as $row) {
            $label = $this->formatTelegramUserName($row);
            $count = (int) $row->cnt;
            
            $lines[] = "{$i}) {$label} ({$count})";
            $i++;
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    
    protected function adminStatistic(int $chatId): void
    {
        $rows = Slot::query()
            ->where('is_completed', true)
            ->selectRaw('DATE(slot_time) as d, COUNT(*) as cnt')
            ->groupBy('d')
            ->orderBy('d', 'desc')
            ->limit(30) // последние 30 дней/дат
            ->get();
        
        if ($rows->isEmpty()) {
            $this->sendMessage($chatId, 'Пока нет завершённых заказов 📭');
            return;
        }
        
        $lines = ["📊 Статистика по выполненным слотам (последние 30 дат):"];
        
        foreach ($rows as $row) {
            $date = \Carbon\Carbon::parse($row->d)->format('d.m.Y');
            $count = (int) $row->cnt;
            
            $lines[] = "{$date} — {$count} ";
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    /**
     * Слово "слот" в правильной форме.
     */
    protected function formatTelegramUserName($row): string
    {
        $parts = [];
        if (!is_null($row->username)) {
            $uname = '@' . ltrim($row->username, '@');
            return $uname;
        }
        // 1) display_name — главный
        if (!is_null($row->display_name)) {
            return $row->display_name;
        }
        // 3) Имя + фамилия
        $fullName = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        if ($fullName !== '') {
            if (!in_array($fullName, $parts, true)) {
                $parts[] = $fullName;
            }
        }
        
        // 4) Телефон
        if (!empty($row->phone)) {
            $parts[] = $row->phone;
        }
        
        // 5) Fallback — telegram_id
        if (empty($parts)) {
            $parts[] = (string) $row->telegram_id;
        }
        
        return implode(' | ', $parts);
    }
    protected function syncTelegramUser(array $from, int|string $chatId, ?string $phone = null)
    {
        $telegramId   = (int)  $from['id'];
        $username     = $from['username'] ?? null;
        $firstName    = $from['first_name']   ?? null;
        $lastName     = $from['last_name']    ?? null;
        $languageCode = $from['language_code'] ?? null;
        $isPremium    = (bool) ($from['is_premium'] ?? false);
        $isBot        = (bool) ($from['is_bot'] ?? false);
        
        // если телефон пришёл — всегда обновляем; если нет — не трогаем существующий
        $update = [
            'username'      => $username,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'language_code' => $languageCode,
            //'language'      => $languageCode,
            'is_premium'    => $isPremium,
            'is_bot'        => $isBot,
            'last_chat_id'  => (string) $chatId,
            'last_seen_at'  => now(),
        ];
        
        if ($phone !== null) {
            $update['phone'] = $phone;
        }
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $telegramId],
            $update
        );
        
        if (!$user->language) {
            $user->language = 'ru';
            $user->save();
        }
        return $user;
    }
    /**
     * /admin_logs [telegram_id]
     * Показывает последние 30 логов (входящие/исходящие).
     * Если указан telegram_id — фильтруем только по нему.
     */
    protected function adminLogs(int $chatId, ?string $arg = null): void
    {
        $telegramId = null;
        
        if ($arg !== null && trim($arg) !== '' && ctype_digit($arg)) {
            $telegramId = (int) $arg;
        }
        
        $query = TelegramMessage::query()
            ->orderByDesc('id')
            ->limit(30);
        
        if ($telegramId) {
            $query->where('telegram_id', $telegramId);
        }
        
        $rows = $query->get();
        
        if ($rows->isEmpty()) {
            $msg = $telegramId
                ? "Логи для пользователя {$telegramId} не найдены 📭"
                : "Пока нет записанных логов 📭";
            
            $this->sendMessage($chatId, $msg);
            return;
        }
        
        // подтянем данные пользователей из таблицы telegram_users
        $ids = $rows->pluck('telegram_id')->filter()->unique()->all();
        
        $userMap = [];
        if (!empty($ids)) {
            $users = \DB::table('telegram_users')
                ->whereIn('telegram_id', $ids)
                ->get(['telegram_id', 'username', 'first_name', 'last_name']);
            
            foreach ($users as $u) {
                $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                $username = $u->username ? '@' . ltrim($u->username, '@') : null;
                
                if ($username && $name) {
                    $label = "{$username} ({$name})";
                } elseif ($username) {
                    $label = $username;
                } elseif ($name) {
                    $label = $name;
                } else {
                    $label = (string) $u->telegram_id;
                }
                
                $userMap[$u->telegram_id] = $label;
            }
        }
        
        $header = $telegramId
            ? "📜 Логи для пользователя {$telegramId}:"
            : "📜 Последние:";
        
        $lines = [$header];
        
        foreach ($rows as $row) {
            /** @var \App\Models\TelegramMessage $row */
            $ts = $row->created_at
                ? $row->created_at->timezone(config('app.timezone'))->format('d.m H:i')
                : '-';
            
            $dirIcon = '';//$row->direction === 'out' ? ' ➡ ️' : ' ⬅️ ';
            $type    = '';//$row->type ?: '';
            
            $uid    = $row->telegram_id;
            $label  = $uid ? ($userMap[$uid] ?? (string) $uid) : '-';
            
            $text = $row->text ?? '';
            $text = trim($text) === '' ? '(без текста)' : $text;
            
            if (mb_strlen($text) > 120) {
                $text = mb_substr($text, 0, 117) . '...';
            }
            
            // пример строки:
            // 08.12 19:10 ⬅️ [@user (Имя Фамилия)] message: /start
            $lines[] = "{$ts} {$dirIcon} [{$label}] {$text}";
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    protected function startReviewFlow(int $chatId, int $userId): void
    {
        /** @var \App\Models\Slot|null $slot */
        $slot = Slot::query()
            ->where('booked_by', $userId)
            ->where('is_completed', true)
            ->whereNull('review_text')
            ->where('slot_time', '<', now())      // слот уже в прошлом
            ->orderByDesc('slot_time')
            ->first();
        
        if (!$slot) {
            $this->sendMessage(
                $chatId,
                "У вас нет выполненных заказов без отзыва 😊\n" .
                "Как только попробуете пиццу — нажмите «Оставить отзыв ⭐» или команду /review."
            );
            return;
        }
        
        $timeLabel = $slot->slot_time->format('d.m.Y H:i');
        
        $this->saveState($userId, 'review', [
            'slot_id' => $slot->id,
        ]);
        
        $this->sendMessage(
            $chatId,
            "Оставим отзыв на заказ от {$timeLabel} 🍕\n\n" .
            "Напишите, пожалуйста, одним сообщением:\n" .
            "— понравилась ли пицца,\n" .
            "— что можно улучшить.\n\n" .
            "Можно начать с оценки от 1 до 5, например:\n" .
            "«5 — всё супер» ⭐"
        );
    }
    protected function showReviews(int $chatId): void
    {
        $reviews = Slot::query()
            ->whereNotNull('review_text')
            ->where('is_completed', true)
            ->orderByDesc('slot_time')
            ->limit(10)
            ->get(['slot_time', 'review_text', 'booked_username', 'review_rating']);
        
        if ($reviews->isEmpty()) {
            $this->sendMessage(
                $chatId,
                "Пока отзывов нет — вы можете стать первым! ⭐\n" .
                "После заказа нажмите «Оставить отзыв ⭐»."
            );
            return;
        }
        
        $lines = ["⭐ Несколько последних отзывов:"];
        
        foreach ($reviews as $slot) {
            /** @var \App\Models\Slot $slot */
            $date = $slot->slot_time->format('d.m');
            $time = $slot->slot_time->format('H:i');
            
            $user = trim((string) $slot->booked_username);
            $userLabel = $user !== '' ? $user : '';
            
            $review = trim($slot->review_text);
            if (mb_strlen($review) > 250) {
                $review = mb_substr($review, 0, 247) . '…';
            }
            
            $rating = $slot->review_rating;
            $ratingText = $rating ? " ({$rating}⭐)" : '';
            
            $lines[] = "";
            $header = "📅 {$date} {$time}{$ratingText}";
            if ($userLabel !== '') {
                $header .= " — {$userLabel}";
            }
            $lines[] = $header;
            $lines[] = "«{$review}»";
        }
        
        $this->sendMessage($chatId, implode("\n", $lines));
    }
    
    protected function tForUser(int $userId, string $key, array $replace = []): string
    {
        $lang = $this->getUserLocale($userId); // ru|en
        return __($key, $replace, $lang);
    }
    protected function getUserLocale(int $userId): string
    {
        /** @var TelegramUser|null $user */
        $user = TelegramUser::find($userId);
        
        // 1) Явно выбранный язык (если ты его куда-то пишешь, напр. в колонку locale)
        if ($user && !empty($user->locale)) {
            return $user->locale;
        }
        
        // 2) language_code, который прислал телеграм (ru, en, de, …)
        if ($user && !empty($user->language_code)) {
            $code = strtolower($user->language_code);
            
            // все «русские» коды сводим к ru
            if (in_array($code, ['ru', 'uk', 'be', 'ru-ru', 'ru_ru'], true)) {
                return 'ru';
            }
            
            // всё английское — к en
            if (str_starts_with($code, 'en')) {
                return 'en';
            }
            
            // при желании можешь добавить ещё маппинги:
            // if (str_starts_with($code, 'de')) return 'de';
        }
        
        // 3) дефолт
        return config('app.locale', 'ru');
    }
}

