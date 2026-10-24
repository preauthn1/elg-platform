<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\LanguageTypeEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Enums\TgBotCallbackExtraParamEnum;
use App\Helpers\CommonHelper;
use App\Helpers\LanguageHelper;

class LanguageService extends BaseService
{
    protected function show()
    {
        $action = TgBotCallbackActionEnum::LANGUAGE_SELECT->value;
        $extraParam = TgBotCallbackExtraParamEnum::LANGUAGE_SELECTED->value;
        $buttonRows = new TgBotButtonRowBuilder;
        $counter = 0;
        foreach (LanguageTypeEnum::getLanguageButtonList() as $type => $displayText) {
            $buttonRows->addButton(
                text: $displayText,
                callbackData: "action={$action}&{$extraParam}=" . $type
            );

            $counter++;
            if ($counter % 2 == 0) {
                $buttonRows->nextRow();
            }
        }
        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.language.please_select_language')
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }

    public function handle_SelectLanguage(): void
    {
        $extraParam = TgBotCallbackExtraParamEnum::LANGUAGE_SELECTED->value;
        $language = $this->tgWebhookData->callbackActionExtraDatas[$extraParam];

        // CommonHelper::setLocale($language);
        LanguageHelper::setLocale($language);

        HomepageService::init()->show();

        $messageId = $this->tgWebhookData->messageId;
        $this->tgBotApiCallService->deleteMessage($messageId);
    }
}
