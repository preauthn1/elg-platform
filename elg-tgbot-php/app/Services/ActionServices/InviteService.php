<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ErrorCodeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class InviteService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $inviteEvent = $this->platformApiCallService->getInviteEvent();
        if (empty($inviteEvent)) {
            throw new \Exception(
                __('tgbot.invite_event.error_empty_invite_event'),
                ErrorCodeEnum::RESPONSE_DATA_EMPTY->value
            );
        }

        // $inviteEvent 範例
        // {
        //         "id": 872,
        //         "event_detail_id": 4770,
        //         "title": "新邀請活動-Neil",
        //         "content": "test",
        //         "agent_id": 59497,
        //         "prize_id": 753,
        //         "prize_expire_date": "2026-03-01 23:59:59",
        //         "event_start_time": "2025-02-24 00:00:00",
        //         "event_end_time": "2026-03-01 00:00:00",
        //         "code": "UABNRGBVR",
        //         "prize_contents": [
        //           {
        //             "prize_content_id": 1756,
        //             "name": "邀請獎勵",
        //             "type": 0,
        //             "achieve_people": 20,
        //             "achieve_number": 0,
        //             "point": 20,
        //             "show_exchange": 0,
        //             "text": "無法領取"
        //           },
        //           {
        //             "prize_content_id": 1757,
        //             "name": "儲值獎勵",
        //             "type": 1,
        //             "achieve_people": 3,
        //             "achieve_number": 0,
        //             "point": 20,
        //             "show_exchange": 0,
        //             "text": "無法領取"
        //           }
        //         ]
        //     }
        //

        $claimDoneCount = 0;
        $canClaimCount = 0;
        $progressingCount = 0;
        $prizeCount = count($inviteEvent['prize_contents']);

        // 上半部內容
        // 【規則詳情】
        $content = '【' . __('tgbot.invite_event.rule_detail') . '】' . "\n";
        // 【進度】
        $content .= '【' . __('tgbot.invite_event.progress') . '】' . "\n";
        foreach ($inviteEvent['prize_contents'] as $row) {
            // 🔸註冊人數:10/20
            $content .= '🔸' . $row['name'] . ': ' . $row['achieve_number'] . '/' . $row['achieve_people'] . "\n";
            // [█████-----]50%
            $content .= $this->drawProgress($row['achieve_number'], $row['achieve_people']) . "\n";
            // 獎勵:200 Rolling
            $content .= __('tgbot.invite_event.reward') . ': ' . $row['point'] . ' ' . __('tgbot.invite_event.prize_point_type_1') . "\n";

            if ($row['show_exchange'] == 1) {
                // 可領取 ❗
                $status = __('tgbot.invite_event.can_claim');
                $canClaimCount++;
            } elseif ($row['achieve_number'] >= $row['achieve_people']) {
                // 已領取 ✅
                $status = __('tgbot.invite_event.claim_done');
                $claimDoneCount++;
            } else {
                // 進行中⏳
                $status = __('tgbot.invite_event.progressing');
                $progressingCount++;
            }
            // 狀態:進行中⏳
            $content .= __('tgbot.invite_event.status') . ': ' . $status . "\n\n";
        }
        $now = now()->format('Y-m-d H:i:s');
        // 🔄 最近更新: 2025-03-27 14:30
        $content .= __('tgbot.invite_event.last_update', ['last_update' => $now]) . "\n";
        // 📅 活動將於 2025-05-26 00:00:00 結束
        $content .= __('tgbot.invite_event.end_time', ['end_time' => $inviteEvent['event_end_time']]);

        $message = new TgBotApiMessageParameterDTO(
            type: $homepageData->vipCoverMedia->getChatMessagesTypeEnum(),
            content: $content,
            mediaUrl: $homepageData->vipCoverMedia->getMediaUrl()
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);

        if ($canClaimCount > 0) {
            // 可領取
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::INVITE_CLAIM_REWARDS);
        } elseif ($claimDoneCount == $prizeCount) {
            // 已領取
            // $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::INVITE_CLAIM_DONE);
        } else {
            // 進行中
            // $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::INVITE_CANNOT_CLAIM);
        }

        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::INVITE_SHARE);

        $buttonRows->nextRow();
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::HISTORIES, $homepageData->historyLink);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }

    /**
     * 領取邀請活動獎勵
     */
    public function claimRewards()
    {
        // 取得任務列表
        $inviteEvent = $this->platformApiCallService->getInviteEvent();

        // 篩選可以領取的任務列表
        $canClaimPrizeList = array_filter($inviteEvent['prize_contents'], function ($prize) {
            return $prize['show_exchange'] == 1;
        });

        // 領取獎勵
        foreach ($canClaimPrizeList as $prize) {
            $this->platformApiCallService->claimInviteEvent($inviteEvent['id'], $prize['prize_content_id']);
        }

        if (count($canClaimPrizeList) > 0) {
            // 刷新頁面
            $this->back();
            $this->show();
        }
    }
}
