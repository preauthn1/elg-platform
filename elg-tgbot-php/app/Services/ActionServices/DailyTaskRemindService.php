<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class DailyTaskRemindService extends BaseService
{
    protected function show()
    {
        $eventRemind = $this->platformApiCallService->getEventRemind(2);
        $status = $eventRemind['data']['status'];
        $statusText = $status == 1 ? __('tgbot.daily_task.remind_enable') : __('tgbot.daily_task.remind_disable');

        $message = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.daily_task.remind') . '(' . $statusText . ')',
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_REMIND_ENABLE);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_REMIND_DISABLE);
        $buttonRows->nextRow();

        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }

    /**
     * 開啟每日任務提醒
     */
    public function enable(): void
    {
        $this->platformApiCallService->setEventRemind(2, 1);
        $this->back();
    }

    /**
     * 關閉每日任務提醒
     */
    public function disable(): void
    {
        $this->platformApiCallService->setEventRemind(2, 0);
        $this->back();
    }
}
