<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\CoverMediaDTO;
use App\DTOs\HomepageInfoDTO;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\BotModeEnum;
use App\Enums\TgBotCallbackActionEnum;

class HomepageService extends BaseService
{
    protected string $userRequestCoverType = 'start';

    protected HomepageInfoDTO $homepageData;

    protected function show()
    {
        $this->homepageData = $this->platformApiCallService->getHomepageInfo();
        $botMode = $this->homepageData->botMode;

        if ($botMode === BotModeEnum::FIRST) {
            if ($this->platformApiCallService->isAccountExists()) {
                $this->homePageRegistered();
            } else {
                $this->homePageWithoutRegistered();
            }
        } elseif ($botMode === BotModeEnum::SECOND) {
            $this->homePageSecond();
        } else {
            throw new \Exception('Invalid bot mode');
        }
    }

    /**
     * 二代首頁
     */
    protected function homePageSecond()
    {
        $homepageData = $this->homepageData;

        $message = $this->getMessageContent($homepageData);

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::LAUNCH_SITE2, $homepageData->webAppLoginLink);
        $buttonRows->nextRow();

        // 連結按鈕陣列
        $count = 0;
        foreach ($homepageData->linkButtonsConfig as $row => $linkButtonRow) {
            foreach ($linkButtonRow as $key => $linkButton) {
                $count++;
                $callbackData = 'link_button_' . $row . '_' . $key;
                $buttonRows->addButton($linkButton['name'], $callbackData, $linkButton['link']);
            }
            $buttonRows->nextRow();
        }

        $shareButtonName = __('tgbot.buttons.' . TgBotCallbackActionEnum::TG_SHARE->value);
        if (!empty($homepageData->shareButtonName)) {
            $shareButtonName = $homepageData->shareButtonName;
        }

        if ($homepageData->mainButtonShare) {
            if (!empty($homepageData->shareLink)) {
                // 有邀請碼的話使用tg分享連結
                $buttonRows->addButton($shareButtonName, TgBotCallbackActionEnum::TG_SHARE->value, $homepageData->shareLink);
            } else {
                // 沒有邀請碼的話使用webapp邀請碼連結
                $buttonRows->addAppButton($shareButtonName, $homepageData->agentLink);
            }
        }
        if ($homepageData->mainButtonEvent) {
            $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::EVENT_INFORMATION, $homepageData->eventLink);
        }
        $buttonRows->nextRow();
        // if ($homepageData->mainButtonCsPreLogin) {
        //     $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::CUSTOMER_SERVICE, $homepageData->customerServiceLink);
        // }
        // if ($homepageData->mainButtonOfficial) {
        //     $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::OFFICIAL_TELEGRAM, $homepageData->officialTGLink);
        // }
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::LANGUAGE);

        $coverMediaDTO = $this->getMessageCoverMedia($homepageData);
        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: $coverMediaDTO->getChatMessagesTypeEnum(),
            content: $message,
            mediaUrl: $coverMediaDTO->getMediaUrl()
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }

    /**
     * 一代首頁 已註冊
     */
    protected function homePageRegistered()
    {
        $homepageData = $this->homepageData;
        $balanceData = $this->platformApiCallService->getWalletBalance();

        $username = $this->tgWebhookData->message['chat']['username'] ?? '';

        $message = __('tgbot.homepage.content', ['username' => $username]) . "\n";
        $message .= $balanceData->toText();

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DEPOSIT);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::WITHDRAWAL);

        $buttonRows->nextRow();

        if ($homepageData->mainButtonWallet) {
            $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::WALLET_MENAGEMENT, $homepageData->walletLink);
        }

        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::SELECT_GAMES);

        $buttonRows->nextRow();

        if ($homepageData->mainButtonVip) {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::VIP);
        }
        if ($homepageData->mainButtonEvent) {
            $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::EVENT_INFORMATION, $homepageData->eventLink);
        }

        $buttonRows->nextRow();

        if ($homepageData->mainButtonInvite) {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::INVITE);
        }
        if ($homepageData->mainButtonCheckin) {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN);
        }
        if ($homepageData->mainButtonMission) {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK);
        }

        $buttonRows->nextRow();

        // if ($homepageData->mainButtonCsPreLogin) {
        //     $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::CUSTOMER_SERVICE, $homepageData->customerServiceLink);
        // }
        if ($homepageData->mainButtonHistory) {
            $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::HISTORIES, $homepageData->historyLink);
        }
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::LANGUAGE);

        $buttonRows->nextRow();

        if ($homepageData->mainButtonHomepage) {
            $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::LAUNCH_SITE, $homepageData->siteLaunchLink);
        }

        $coverMediaDTO = $this->getMessageCoverMedia($homepageData);
        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: $coverMediaDTO->getChatMessagesTypeEnum(),
            content: $message,
            mediaUrl: $coverMediaDTO->getMediaUrl()
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }

    /**
     * 一代首頁 未註冊
     */
    protected function homePageWithoutRegistered()
    {
        $homepageData = $this->homepageData;

        $message = __('tgbot.homepage.content_without_registered', ['botId' => $this->tgWebhookData->botId]);

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::REGISTER);

        // if ($homepageData->mainButtonCsPreLogin) {
        //     $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::CUSTOMER_SERVICE, $homepageData->customerServiceLink);
        // }

        $buttonRows->nextRow();
        // if ($homepageData->mainButtonOfficial) {
        //     $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::OFFICIAL_TELEGRAM, $homepageData->officialTGLink);
        // }
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::LANGUAGE);

        $coverMediaDTO = $this->getMessageCoverMedia($homepageData);
        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: $coverMediaDTO->getChatMessagesTypeEnum(),
            content: $message,
            mediaUrl: $coverMediaDTO->getMediaUrl()
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->send($tgbotMessage);
    }

    protected function getMessageCoverMedia(HomepageInfoDTO $data): CoverMediaDTO
    {
        $coverMediaData = $data->startCoverMedia;
        if ($this->userRequestCoverType === 'restart') {
            $coverMediaData = $data->restartCoverMedia;
        }

        return $coverMediaData;
    }

    protected function getMessageContent(HomepageInfoDTO $homepageData): string
    {
        if ($this->userRequestCoverType === 'restart') {
            if (!empty($homepageData->restartMessage)) {
                $message = $homepageData->restartMessage;
                $message = str_replace('{username}', $this->tgWebhookData->username, $message);
            } else {
                $message = __('tgbot.webapp_welcome', ['username' => $this->tgWebhookData->username]);
            }
        } else {
            if (!empty($homepageData->startMessage)) {
                $message = $homepageData->startMessage;
                $message = str_replace('{username}', $this->tgWebhookData->username, $message);
            } else {
                $message = __('tgbot.webapp_welcome', ['username' => $this->tgWebhookData->username]);
            }
        }

        return $message;
    }
}
