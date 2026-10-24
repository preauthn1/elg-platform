<?php

declare(strict_types=1);

namespace App\Enums\NewbieChallenge;

enum NewbieChallengeStageEnum: int
{
    case CHALLENGING = 1;  // 進行中(挑戰流水中)
    case BONUS_PENDING = 2;  // 待領取彩金
    case BONUS_WAGERING = 3;  // 彩金任務中
    case BONUS_COMPLETED = 4;  // 彩金任務完成，本金流水跑完後提款自動解鎖（靠通用 checkFlowLimit()），已是整個活動的終態，不再另外轉 COMPLETED
    case OPERATOR_QUIT = 5;  // 代理後台強制取消，跟會員自己放棄(QUIT)區分開，比照 EventV2UserJoinStatusEnum::OPERATOR_QUIT 的既有慣例
    case QUIT = 6;  // 已放棄
}
