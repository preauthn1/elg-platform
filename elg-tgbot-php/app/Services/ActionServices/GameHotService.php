<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class GameHotService extends BaseService
{
    protected function show()
    {
        $list = $this->platformApiCallService->getHotVendorList();

        $buttonRows = new TgBotButtonRowBuilder;
        foreach ($list as $vendor) {
            $buttonRows->addAppButton($vendor['vendor_name'], $vendor['launch_url']);
            $buttonRows->nextRow();
        }
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.game.please_select_game_lobby'),
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }
}
