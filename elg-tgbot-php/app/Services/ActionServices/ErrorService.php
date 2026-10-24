<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class ErrorService extends BaseService
{
    /**
     * 不繼承 show 方法, 不參與 history 的 push 和 pop
     */
    public function showError(string $content)
    {
        $message = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: $content,
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DELETE_MESSAGE);
        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }
}
