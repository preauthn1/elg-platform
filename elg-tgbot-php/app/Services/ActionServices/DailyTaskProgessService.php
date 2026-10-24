<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class DailyTaskProgessService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $missions = $this->platformApiCallService->getMissions();
        $missionList = $missions['missions'] ?? [];
        $lastSummaryTime = $missions['last_summary_time'] ?? '';

        // 篩選進行中的任務
        $missionList = array_filter($missionList, function ($mission) {
            return $mission['progress'] < $mission['requirement'];
        });

        // 上半部內容
        $content = '【' . __('tgbot.daily_task.progressing_task') . '】' . "\n";
        $content .= '【' . __('tgbot.daily_task.progress') . '】' . "\n";

        foreach ($missionList as $mission) {
            $name = $mission['name'];
            $progress = $mission['progress'];
            $requirement = $mission['requirement'];
            $reward = $mission['reward_value'] . ' ' . $mission['reward_type'];
            if ($progress >= $requirement) {
                if ($mission['is_claimed']) {
                    $status = __('tgbot.daily_task.claim_done');
                } else {
                    $status = __('tgbot.daily_task.can_claim');
                }
            } else {
                $status = __('tgbot.daily_task.progressing');
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
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DEPOSIT);

        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::SELECT_GAMES);

        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }
}
