<?php

namespace App\Services\ActionServices;

use App\DTOs\TgWebhookDataDTO;
use App\Singletons\PlatformApiCallService;
use App\Singletons\TgBotApiCallService;
use Illuminate\Support\Facades\Redis;
use Throwable;

class BaseService
{
    protected TgBotApiCallService $tgBotApiCallService;

    protected PlatformApiCallService $platformApiCallService;

    protected TgWebhookDataDTO $tgWebhookData;

    protected function __construct()
    {
        $this->tgBotApiCallService = app(TgBotApiCallService::class);
        $this->platformApiCallService = app(PlatformApiCallService::class);
        $this->tgWebhookData = app(TgWebhookDataDTO::class);
    }

    public static function init(): static
    {
        return new static;
    }

    /**
     * 繼承的子類別需要實作此方法
     */
    protected function show() {}

    /**
     * 外部呼叫顯示頁面的方法, 做出 middleware 的效果
     */
    public function showPage()
    {
        // TODO 改成 app/Singletons/TgBotApiCallService.php 的 editMessage 方式, 減少 post 的次數
        $this->show();
        $this->pushHistory();
        $this->deleteMessage();
    }

    /**
     * 刪除訊息
     */
    public function deleteMessage()
    {
        $messageId = $this->tgWebhookData->messageId;
        if ($messageId == 0) {
            return;
        }

        // 刪除失敗就算了, 太久以前的訊息會刪除失敗
        try {
            $this->tgBotApiCallService->deleteMessage($messageId);
        } catch (Throwable $e) {
            // pass
        }
    }

    /**
     * 返回上一頁
     *
     * @return void
     */
    public function back()
    {
        // TODO 改成 app/Singletons/TgBotApiCallService.php 的 editMessage 方式, 減少 post 的次數
        $class = $this->popHistory();
        if ($class) {
            $class::init()->show();
        }
        $this->deleteMessage();
    }

    /**
     * 畫進度條
     */
    public function drawProgress(int $progress, int $requirement): string
    {
        $total_block = 10;
        if ($requirement == 0) {
            $white_block = 0;
            $black_block = $total_block;
            $percentage = 0;
        } elseif ($progress > $requirement) {
            $white_block = $total_block;
            $black_block = 0;
            $percentage = 100;
        } else {
            $white_block = floor($total_block * $progress / $requirement);
            $black_block = $total_block - $white_block;
            $percentage = floor($progress / $requirement * 100);
        }

        $progressBar = '[';
        for ($i = 0; $i < $white_block; $i++) {
            $progressBar .= '█';
        }
        for ($i = 0; $i < $black_block; $i++) {
            $progressBar .= '░';
        }

        $progressBar .= '] ' . $percentage . '%';

        return $progressBar;
    }

    /**
     * 推進 history
     */
    public function pushHistory()
    {
        $key = 'tgbot_history:' . $this->tgWebhookData->chatId;
        Redis::rpush($key, get_class($this));
    }

    /**
     * 取得上一頁的 class
     */
    public function popHistory(): string
    {
        $key = 'tgbot_history:' . $this->tgWebhookData->chatId;

        // pop 出來的是當前頁面
        $class = Redis::rpop($key);

        // 最後一個元素才是上一頁
        $lastClass = Redis::lindex($key, -1);

        return $lastClass;
    }
}
