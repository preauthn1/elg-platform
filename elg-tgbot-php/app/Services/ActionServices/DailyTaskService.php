<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class DailyTaskService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $missions = $this->platformApiCallService->getMissions();
        $missionList = $missions['missions'] ?? [];
        $lastSummaryTime = $missions['last_summary_time'] ?? '';

        // $missionList 範例
        // [{
        //     "id": 32,
        //     "name": "JILI投注",
        //     "description": "",
        //     "reset_type": "daily",
        //     "action_type": "bet",
        //     "progress": 0,
        //     "is_claimed": 0,
        //     "reward_type": "Platform point",
        //     "reward_value": 123,
        //     "requirement": 1
        // }]

        // 上半部內容
        $content = '【' . __('tgbot.daily_task.rule_detail') . '】' . "\n";
        $content .= '【' . __('tgbot.daily_task.progress') . '】' . "\n";
        $missionCount = count($missionList);
        $claimDoneCount = 0;
        $canClaimCount = 0;
        $progressingCount = 0;
        foreach ($missionList as $mission) {
            $name = $mission['name'];
            $progress = $mission['progress'];
            $requirement = $mission['requirement'];
            $reward = $mission['reward_value'] . ' ' . $mission['reward_type'];
            if ($progress >= $requirement) {
                if ($mission['is_claimed']) {
                    $status = __('tgbot.daily_task.claim_done');
                    $claimDoneCount++;
                } else {
                    $status = __('tgbot.daily_task.can_claim');
                    $canClaimCount++;
                }
            } else {
                $status = __('tgbot.daily_task.progressing');
                $progressingCount++;
            }

            $content .= '🔸' . $name . ': ' . __('tgbot.daily_task.progress') . ': ' . $progress . '/' . $requirement . "\n";
            $content .= $this->drawProgress($progress, $requirement) . "\n";
            $content .= __('tgbot.daily_task.reward') . ': ' . $reward . "\n";
            $content .= __('tgbot.daily_task.status') . ': ' . $status . "\n\n"; // 進行中⏳ 已領取 ✅ 已達成 ❗
        }
        $content .= __('tgbot.daily_task.last_update') . ': ' . $lastSummaryTime . "\n";
        $content .= __('tgbot.daily_task.data_reset_at');

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
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_CAN_CLAIM);
        } elseif ($claimDoneCount == $missionCount) {
            // 已領取
            // $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_CLAIM_DONE);
        } else {
            // 進行中
            // $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_CANNOT_CLAIM);
        }

        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_PROGRESS);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_TASK_REMIND);

        $buttonRows->nextRow();
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::HISTORIES, $homepageData->historyLink);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }

    /**
     * 領取每日任務獎勵
     */
    public function claim()
    {
        // 取得任務列表
        $missions = $this->platformApiCallService->getMissions();
        $missionList = $missions['missions'] ?? [];

        // 篩選可以領取的任務列表
        $canClaimMissionList = array_filter($missionList, function ($mission) {
            return $mission['is_claimed'] == 0 && $mission['progress'] >= $mission['requirement'];
        });

        // 領取獎勵
        foreach ($canClaimMissionList as $mission) {
            $this->platformApiCallService->claimMission($mission['id']);
        }

        if (count($canClaimMissionList) > 0) {
            // 刷新頁面
            $this->back();
            $this->show();
        }
    }
}
