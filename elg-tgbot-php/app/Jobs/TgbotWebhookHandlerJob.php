<?php

namespace App\Jobs;

use App\DTOs\TgWebhookDataDTO;
use App\Enums\ChatMessages\ChatMessagesStatusEnum;
use App\Helpers\LanguageHelper;
use App\Models\ChatMessage;
use App\Services\WebhookHandleService;
use App\Singletons\PlatformApiCallService;
use App\Singletons\TgBotApiCallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TgbotWebhookHandlerJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $requestId,
        private TgWebhookDataDTO $tgWebhookData,
        private string $lockKey,
        private int $chatMessageId,
        private float $dispatchMicroTime
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::shareContext(['request_id' => $this->requestId]);
        $jobStartMicroTime = microtime(true);

        $costTimeMS = 1000 * ($jobStartMicroTime - $this->dispatchMicroTime);
        Log::info("Job start, spend time: {$costTimeMS} ms", [
            'botId' => $this->tgWebhookData->botId,
            'chatId' => $this->tgWebhookData->chatId,
            'messageId' => $this->tgWebhookData->messageId,
            'dispatchTime' => \DateTime::createFromFormat('U.u', $this->dispatchMicroTime)->format('Y-m-d H:i:s.u'),
            'jobStartTime' => \DateTime::createFromFormat('U.u', $jobStartMicroTime)->format('Y-m-d H:i:s.u'),
        ]);

        try {
            $this->prepare();
            LanguageHelper::setLocale();

            // ? use singleton?
            (new WebhookHandleService)->exec();
            ChatMessage::where('id', $this->chatMessageId)
                ->update([
                    'status' => ChatMessagesStatusEnum::DONE->value,
                ]);

        } catch (\Exception $e) {
            ChatMessage::where('id', $this->chatMessageId)
                ->update([
                    'status' => ChatMessagesStatusEnum::FAILED->value,
                    'extra_info' => $e->getMessage(),
                ]);

            Log::error($e->getMessage());
        } finally {
            /**
             * TG Api has limit for one message per second for one chat, and 30 messages per second for all chats.
             * So, we need to wait 500 ms after each message.
             * And set up to 30 maxProcesses for this job in Horizon.
             */
            // usleep(500);
            Cache::forget($this->lockKey);
        }

        $jobEndMicroTime = microtime(true);
        $costTimeMS = 1000 * ($jobEndMicroTime - $jobStartMicroTime);
        Log::info("Job done, spend time: {$costTimeMS} ms", [
            'botId' => $this->tgWebhookData->botId,
            'chatId' => $this->tgWebhookData->chatId,
            'messageId' => $this->tgWebhookData->messageId,
            'dispatchTime' => \DateTime::createFromFormat('U.u', $this->dispatchMicroTime)->format('Y-m-d H:i:s.u'),
            'jobStartTime' => \DateTime::createFromFormat('U.u', $jobStartMicroTime)->format('Y-m-d H:i:s.u'),
            'jobEndTime' => \DateTime::createFromFormat('U.u', $jobEndMicroTime)->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function prepare()
    {
        $tgWebhookData = $this->tgWebhookData;
        $chatId = $tgWebhookData->chatId;
        $botId = $tgWebhookData->botId;

        App::scoped(TgWebhookDataDTO::class, function ($app) use ($tgWebhookData) {
            return $tgWebhookData;
        });

        App::scoped(TgBotApiCallService::class, function ($app) use ($botId, $chatId) {
            return new TgBotApiCallService($botId, $chatId, $this->chatMessageId);
        });

        App::scoped(PlatformApiCallService::class, function ($app) use ($chatId, $botId) {
            return new PlatformApiCallService($botId, $chatId);
        });
    }
}
