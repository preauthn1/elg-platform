<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberScore;

use App\Http\Requests\AbstractRequest;

/**
 * 後台人工扣除積分（target/tickets#4233；開洗分 › 積分扣除）。
 *
 * PM 2026-09-16 定案：填總扣除量，系統依到期日由近到遠自動拆批（與活動消耗同一套 FIFO），
 * 運營不選批次。餘額不足整筆擋下。
 */
class MemberScoreDeductRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => 'required|integer|min:1',
            /**
             * @var string 扣除積分，正數、最多 2 位小數（字串傳遞避免浮點誤差）
             *
             * @example "250"
             */
            'score' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            /**
             * @var string 備註（必填，寫進每一筆積分異動紀錄）
             *
             * @example "誤發收回"
             */
            'remark' => 'required|string|max:500',
        ];
    }
}
