<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class GameService extends BaseService
{
    protected function show()
    {
        $gameLobbyUrl = $this->platformApiCallService->getGameLobbyUrl();
        $walletBalance = $this->platformApiCallService->getWalletBalance();

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::GAME_RECENTLY);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::GAME_HOT);
        $buttonRows->nextRow();
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::GAME_LOBBY, $gameLobbyUrl);
        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $content = __('tgbot.game.please_select_category_or_lobby') . "\n" . $walletBalance->toText();
        $message = new TgBotApiMessageParameterDTO(
            ChatMessagesTypeEnum::TEXT,
            content: $content,
        );
        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }
}
