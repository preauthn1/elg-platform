<?php

namespace App\Http\Controllers;

use App\DTOs\TgWebhookDataDTO;
use App\Enums\ChatMessages\ChatMessagesSenderEnum;
use App\Enums\ChatMessages\ChatMessagesStatusEnum;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\LogTypeEnum;
use App\Helpers\CacheHelper;
use App\Helpers\CacheKeysHelper;
use App\Jobs\TgbotWebhookHandlerJob;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class TgBotController extends Controller
{
    public function webhook(Request $request, string $botId)
    {
        $tgWebhookData = TgWebhookDataDTO::fromRequest($botId, $request);

        if (empty($tgWebhookData)) {
            Log::error("Bot id: {$botId}", [
                'data' => $request->all(),
                'extra' => ['type' => LogTypeEnum::TG_WEBHOOK->value],
            ]);

            return;
        }

        Log::info("tgbot_webhook, Bot id: {$botId}, Chat id: {$tgWebhookData->chatId}, Message id: {$tgWebhookData->messageId}", [
            'data' => $request->all(),
            'extra' => ['type' => LogTypeEnum::TG_WEBHOOK->value],
        ]);

        // Lock to prevent concurrent webhook requests (spamming button clicks)
        $cacheKey = CacheKeysHelper::getCocurrentLockerKey($botId, $tgWebhookData->chatId);
        $cacheData = Cache::get($cacheKey);
        if (!empty($cacheData)) {
            ChatMessage::create([
                'bot_id' => $botId,
                'chat_id' => $tgWebhookData->chatId,
                'message_id' => $tgWebhookData->messageId,
                'sender' => ChatMessagesSenderEnum::USER->value,
                'type' => ChatMessagesTypeEnum::TEXT->value,
                'action' => $tgWebhookData->callbackAction ?? null,
                'status' => ChatMessagesStatusEnum::DROPPED->value,
                'content' => $tgWebhookData->message['text'] ?? '',
                'info' => $tgWebhookData->rawJson,
            ]);

            Log::info(
                "Concurrent locker found: {$cacheKey}",
                ['extra' => ['type' => LogTypeEnum::TG_WEBHOOK->value]]
            );

            return;
        }

        CacheHelper::putCacheAndLog($cacheKey, true);

        try {
            $chatMessage = ChatMessage::create([
                'bot_id' => $botId,
                'chat_id' => $tgWebhookData->chatId,
                'message_id' => $tgWebhookData->messageId,
                'sender' => ChatMessagesSenderEnum::USER->value,
                'type' => ChatMessagesTypeEnum::TEXT->value,
                'action' => $tgWebhookData->callbackAction ?? null,
                'status' => ChatMessagesStatusEnum::PENDING->value,
                'content' => $tgWebhookData->message['text'] ?? '',
                'info' => $tgWebhookData->rawJson,
            ]);

            $requestId = Context::getHidden('REQUEST_ID');

            TgbotWebhookHandlerJob::dispatch(
                requestId: $requestId,
                tgWebhookData: $tgWebhookData,
                lockKey: $cacheKey,
                chatMessageId: $chatMessage->id,
                dispatchMicroTime: microtime(true)
            )->delay(0);
        } catch (\Exception $e) {
            Cache::forget($cacheKey);
            Log::error('Platform controller error handler', [
                'bot_id' => $tgWebhookData->botId,
                'chat_id' => $tgWebhookData->chatId,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
