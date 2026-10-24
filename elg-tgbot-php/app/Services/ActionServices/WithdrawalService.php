<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class WithdrawalService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $walletData = $this->platformApiCallService->getWalletBalance();
        $flowLimitData = $this->platformApiCallService->getWithdrawalFlowLimit();
        $isPaymentPasswordSet = $this->platformApiCallService->isPaymentPasswordSet();

        $msg = $walletData->toText() . "\n";

        if (!$isPaymentPasswordSet) {
            $msg .= __('tgbot.withdrawal.payment_password_not_set');
            $setPaymentPasswordLink = str_replace(
                search: 'withdrawal/bank-bind',
                replace: 'payment-password',
                subject: $homepageData->withdrawalLink
            );

            $buttonRows = new TgBotButtonRowBuilder;
            $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
            $buttonRows->addButtonByArray([
                'text' => __('tgbot.withdrawal.set_payment_password'),
                'web_app' => ['url' => $setPaymentPasswordLink],
            ]);
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

            $message = new TgBotApiMessageParameterDTO(
                type: ChatMessagesTypeEnum::TEXT,
                content: $msg
            );
            $message->buttonRows = $buttonRows->build();

            $this->tgBotApiCallService->send($message);
        } elseif ($flowLimitData['flow_limit'] <= 0) {
            $msg .= __('tgbot.withdrawal.can_withdrawal');

            $buttonRows = new TgBotButtonRowBuilder;
            $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
            $buttonRows->addButtonByArray([
                'text' => __('tgbot.withdrawal.do_withdrawal'),
                'web_app' => ['url' => $homepageData->withdrawalLink],
            ]);
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

            $message = new TgBotApiMessageParameterDTO(
                type: ChatMessagesTypeEnum::TEXT,
                content: $msg
            );
            $message->buttonRows = $buttonRows->build();

            $this->tgBotApiCallService->send($message);
        } else {
            $msg .= __('tgbot.withdrawal.flow_limit', ['limit' => $flowLimitData['flow_limit']]);
            $msg .= __('tgbot.withdrawal.cannot_withdrawal');

            $buttonRows = new TgBotButtonRowBuilder;
            $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);
            $message = new TgBotApiMessageParameterDTO(
                type: ChatMessagesTypeEnum::TEXT,
                content: $msg
            );
            $message->buttonRows = $buttonRows->build();

            $this->tgBotApiCallService->send($message);
        }
    }
}
