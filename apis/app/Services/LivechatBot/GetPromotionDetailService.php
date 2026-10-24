<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Exceptions\ApiException;

/**
 * Livechat Bot 活動明細查詢 service（API 10：透過促銷名稱關鍵字查詢）。
 *
 * 為 PromotionsService 的「扁平 + 關鍵字過濾」版本：
 * - 將 active / claimable / conditioned 三類結果合併到單一 array
 * - 每筆 item 加入 eligibilityStatus 標記其分類
 * - 透過 keyword（活動名稱）以 LIKE %keyword% 過濾
 */
class GetPromotionDetailService
{
    private const STATUS_ACTIVE = 'active';

    private const STATUS_CLAIMABLE = 'claimable';

    private const STATUS_CONDITIONED = 'conditioned';

    public function __construct(
        protected PromotionV1Builder $v1Builder,
        protected PromotionV2Builder $v2Builder,
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPromotionDetail(string $username, string $promotionName): array
    {
        $member = $this->context->findMemberByUsername($username);

        if ($member === null) {
            throw new ApiException('Member not found', 404);
        }

        // V1 沒有 active 概念（活動進行中等同於 prize 已產出），故只走 V2
        $active = $this->v2Builder->buildActive($member, keyword: $promotionName);

        $claimable = array_merge(
            $this->v2Builder->buildClaimable($member, keyword: $promotionName),
            $this->v1Builder->buildClaimable($member, keyword: $promotionName),
        );

        $conditioned = array_merge(
            $this->v2Builder->buildConditioned($member, keyword: $promotionName),
            $this->v1Builder->buildConditioned($member, keyword: $promotionName),
        );

        return array_merge(
            $this->tagEligibility($active, self::STATUS_ACTIVE),
            $this->tagEligibility($claimable, self::STATUS_CLAIMABLE),
            $this->tagEligibility($conditioned, self::STATUS_CONDITIONED),
        );
    }

    /**
     * 為每筆 item 加上 eligibilityStatus 欄位（不修改其他欄位）。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function tagEligibility(array $items, string $status): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = ['eligibilityStatus' => $status] + $item;
        }

        return $result;
    }
}
