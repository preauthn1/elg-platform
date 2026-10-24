<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Exceptions\ApiException;

/**
 * Livechat Bot 活動列表查詢 service。
 *
 * 整合 V1 (events / prize_members / event_processes) 與
 * V2 (event_v2 / event_v2_user_joins) 兩套活動系統，並依會員當下狀態
 * 分為三大分類：activePromotions、claimablePromotions、conditionedPromotions。
 */
class PromotionsService
{
    public function __construct(
        protected PromotionV1Builder $v1Builder,
        protected PromotionV2Builder $v2Builder,
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getPromotions(string $username): array
    {
        $member = $this->context->findMemberByUsername($username);

        if ($member === null) {
            throw new ApiException('Member not found', 404);
        }

        // V1 沒有 active 概念（活動進行中等同於 prize 已產出），故只走 V2
        $activePromotions = $this->v2Builder->buildActive($member);

        $claimablePromotions = array_merge(
            $this->v2Builder->buildClaimable($member),
            $this->v1Builder->buildClaimable($member),
        );

        $conditionedPromotions = array_merge(
            $this->v2Builder->buildConditioned($member),
            $this->v1Builder->buildConditioned($member),
        );

        return [
            'activePromotions' => $activePromotions,
            'claimablePromotions' => $claimablePromotions,
            'conditionedPromotions' => $conditionedPromotions,
        ];
    }
}
