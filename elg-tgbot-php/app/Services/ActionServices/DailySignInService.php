<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ErrorCodeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class DailySignInService extends BaseService
{
    protected function show()
    {
        $homepageData = $this->platformApiCallService->getHomepageInfo();
        $checkInList = $this->platformApiCallService->getCheckInList();

        // $checkInList 範例
        // [{
        //     "day": 3,
        //     "is_checkin": false,
        //     "checkin_day": false,
        //     "prize": [
        //       {
        //         "prize_type": 1,
        //         "prize_amount": "20.00"
        //       }
        //     ]
        // }]

        if (empty($checkInList)) {
            throw new \Exception(
                __('tgbot.daily_sign_in.error_empty_checkin_list'),
                ErrorCodeEnum::RESPONSE_DATA_EMPTY->value
            );
        }

        // 畫出每日簽到記錄 [❌] [✅] [❌] [4] [5] [6] [7]
        $signInRecord = [];
        $beforeToday = true;
        $todayCheckInData = null;
        $continuousDays = 0;
        foreach ($checkInList as $row) {
            // 連續簽到天數
            if ($beforeToday) {
                if ($row['is_checkin']) {
                    $continuousDays++;
                } else {
                    $continuousDays = 0;
                }
            }

            // 檢查是否是今天以前(不含今天)
            if ($row['checkin_day']) {
                $beforeToday = false;
                $todayCheckInData = $row;
            }

            if ($row['is_checkin']) {
                $signInRecord[] = '[✅]';
            } else {
                $signInRecord[] = $beforeToday ? '[❌]' : '[' . $row['day'] . ']';
            }
        }
        // 沒有今天簽到資料
        if (empty($todayCheckInData)) {
            throw new \Exception(
                __('tgbot.daily_sign_in.error_empty_today_checkin_data'),
                ErrorCodeEnum::RESPONSE_DATA_EMPTY->value
            );
        }

        $signInStr = implode(' ', $signInRecord);

        // 畫出獎勵
        $prizeArr = [];
        foreach ($todayCheckInData['prize'] as $prize) {
            $prizeArr[] = $prize['prize_amount'] . ' ' . $prize['prize_type'];
        }
        $prizeStr = implode('+', $prizeArr);
        $todayStatusStr = $todayCheckInData['is_checkin'] ? __('tgbot.daily_sign_in.signed_in') : __('tgbot.daily_sign_in.not_sign_in');

        // 上半部內容
        $content = '【' . __('tgbot.daily_sign_in.rule_detail') . '】' . "\n";
        $content .= __('tgbot.daily_sign_in.continuous_sign_in_can_get_rich_rewards') . "\n";
        $content .= __('tgbot.daily_sign_in.current_sign_in_record') . ': ' . $signInStr . "\n";
        $content .= __('tgbot.daily_sign_in.continuous_sign_in', ['days' => $continuousDays]) . "\n";
        $content .= __('tgbot.daily_sign_in.today_sign_in_reward') . ': ' . $prizeStr . "\n";
        $content .= __('tgbot.daily_sign_in.today_sign_in_status') . ': ' . $todayStatusStr . "\n";
        $content .= __('tgbot.daily_sign_in.next_sign_in_time');

        $message = new TgBotApiMessageParameterDTO(
            type: $homepageData->vipCoverMedia->getChatMessagesTypeEnum(),
            content: $content,
            mediaUrl: $homepageData->vipCoverMedia->getMediaUrl()
        );

        // 下半部按鈕
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);

        if (!$todayCheckInData['is_checkin']) {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN_SIGN);
        } else {
            $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN_DONE);
        }

        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND);

        $buttonRows->nextRow();
        $buttonRows->addAppButtonByActionEnum(TgBotCallbackActionEnum::HISTORIES, $homepageData->historyLink);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $message->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($message);
    }

    public function signIn()
    {
        $this->platformApiCallService->checkIn();
        $this->back();
        $this->show();
    }
}
