<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\ErrorCodeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class InviteShareService extends BaseService
{
    protected function show()
    {
        $frontendUrls = $this->platformApiCallService->getFrontendUrls();
        if (empty($frontendUrls)) {
            throw new \Exception(
                __('tgbot.invite_event.error_empty_frontend_urls'),
                ErrorCodeEnum::RESPONSE_DATA_EMPTY->value
            );
        }
        $frontendUrl = $frontendUrls[0];
        $frontendUrl = rtrim($frontendUrl, '/');

        $qrCode = $this->platformApiCallService->getInviteEventQrCode();
        if (empty($qrCode)) {
            throw new \Exception(
                __('tgbot.invite_event.error_empty_invite_event_qr_code'),
                ErrorCodeEnum::RESPONSE_DATA_EMPTY->value
            );
        }

        // {
        //     "code": "UABNRGBVR",
        //     "invite_url": "https://example.invalid/signup/UABNRGBVR"
        //   }
        $code = $qrCode['code'];
        $inviteUrl = $qrCode['invite_url'];
        $qrCodeUrl = $frontendUrl . '/api/tgBot/invite-event/qrcode/' . $code;

        // TODO 上半部內容
        // 【規則詳情】
        $content = '【' . __('tgbot.invite_event.rule_detail') . '】' . "\n";
        // 【上方圖片 QR Code】
        $content .= '【' . __('tgbot.invite_event.top_qr_code') . '】' . "\n";
        // 【邀請碼】
        $content .= '🔑【' . __('tgbot.invite_event.invite_code') . '】: ' . $code . "\n";
        // 【邀請連結】
        $content .= '🔗【' . __('tgbot.invite_event.invite_url') . '】: ' . $inviteUrl;

        $message = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::IMAGE,
            content: $content,
            mediaUrl: $qrCodeUrl
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }
}
