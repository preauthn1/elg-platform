<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberScore;

use App\Http\Requests\AbstractRequest;

/**
 * 積分紀錄主表（target/tickets#4232；專屬優惠 › 積分紀錄）。
 *
 * 每會員一列，只列有積分錢包列的會員（沒發過積分的不列）；範圍＝整棵下線。
 * per_page 上限放寬到 10000 供前端匯出一次撈整批（比照會員列表匯出：前端超過一萬筆提示縮小條件）。
 */
class MemberScoreRecordListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string|null 會員帳號（精確比對）
             *
             * @example "vwtest001"
             */
            'username' => 'nullable|string|max:50',
            /**
             * @var string|null 所屬代理帳號——列出該代理整條線（含下線代理）的會員
             *
             * @example "agent01"
             */
            'agent_username' => 'nullable|string|max:50',
            'page' => 'required|integer|min:1',
            'per_page' => 'required|integer|min:1|max:10000',
        ];
    }
}
