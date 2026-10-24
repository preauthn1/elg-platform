<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class VipService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $vipDetail = $this->platformApiCallService->getVipDetail();

        // $vipDetail 範例
        // {
        //     "now_level": 0,
        //     "next_level": 1,
        //     "deposit_amount": "6900.0000",
        //     "target_amount": "1.0000",
        //     "privileges": {
        //       "withdrawal_amount_limit": "0.0000",
        //       "withdrawal_times_limit": 10,
        //       "support_order_types": [
        //         {
        //           "name": "銀行"
        //         },
        //         {
        //           "name": "現金"
        //         }
        //       ],
        //       "rewards": [
        //         "等級獎金",
        //         "生日獎金",
        //         "達標獎金"
        //       ]
        //     }
        //   }

        // TODO 上半部內容
        // VIP等級:1
        $content = __('tgbot.vip.vip_level') . ': ' . $vipDetail['now_level'] . "\n";
        // 下一等級:50/100
        $content .= __('tgbot.vip.next_level') . ': ' . $vipDetail['deposit_amount'] . '/' . $vipDetail['target_amount'] . "\n";
        // [█████-----]50%
        $content .= $this->drawProgress($vipDetail['deposit_amount'], $vipDetail['target_amount']) . "\n"; // 進度條
        // 特權:
        if (!empty($vipDetail['privileges'])) {
            $content .= __('tgbot.vip.privilege') . ": \n";
            $content .= '1. ' . __('tgbot.vip.privilege_today_withdraw_amount') . ': ' . $vipDetail['privileges']['withdrawal_amount_limit'] . "\n";
            $content .= '2. ' . __('tgbot.vip.privilege_today_withdraw_count') . ': ' . $vipDetail['privileges']['withdrawal_times_limit'] . "\n";
            foreach ($vipDetail['privileges']['support_order_types'] as $key => $supportOrderType) {
                $content .= ($key + 3) . '. ' . __('tgbot.vip.privilege_support') . ' ' . $supportOrderType['name'] . "\n";
            }
        }

        $message = new TgBotApiMessageParameterDTO(
            type: $homepageData->vipCoverMedia->getChatMessagesTypeEnum(),
            content: $content,
            mediaUrl: $homepageData->vipCoverMedia->getMediaUrl()
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::VIP_RULE, $homepageData->vipLink);
        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DEPOSIT);
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::HISTORIES, $homepageData->historyLink);
        $buttonRows->nextRow();

        // 客服連結
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::CUSTOMER_SERVICE, $homepageData->customerServiceLink);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }
}
