<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class DailySignInRemindService extends BaseService
{
    protected function show()
    {
        $eventRemind = $this->platformApiCallService->getEventRemind(1);
        $status = $eventRemind['data']['status'];
        $statusText = $status == 1 ? __('tgbot.daily_sign_in.remind_enable') : __('tgbot.daily_sign_in.remind_disable');

        $message = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.daily_sign_in.remind') . '(' . $statusText . ')',
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND_ENABLE);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND_DISABLE);
        $buttonRows->nextRow();

        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }

    /**
     * 開啟每日簽到提醒
     */
    public function enable(): void
    {
        $this->platformApiCallService->setEventRemind(1, 1);
        $this->back();
    }

    /**
     * 關閉每日簽到提醒
     */
    public function disable(): void
    {
        $this->platformApiCallService->setEventRemind(1, 0);
        $this->back();
    }
}
