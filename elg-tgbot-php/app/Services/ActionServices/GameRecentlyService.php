<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class GameRecentlyService extends BaseService
{
    protected function show()
    {
        $list = $this->platformApiCallService->getRecentlyPlayedGames();

        $buttonRows = new TgBotButtonRowBuilder;
        foreach ($list as $game) {
            $buttonRows->addAppButton($game['name'], $game['url']);
            $buttonRows->nextRow();
        }
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.game.please_select_game_to_play'),
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }
}
