<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberScore;

use App\Http\Requests\AbstractRequest;

/**
 * 後台人工發放積分（target/tickets#4233；帳號管理 › 功能 › 開洗分 › 「積分發放」）。
 *
 * 扣除走隔壁的 MemberScoreDeductRequest（總量 FIFO）。有效期單位是小時、不給＝無限期，
 * 與活動端 reward_config.expire 同一套語意。
 */
class MemberScoreGrantRequest extends AbstractRequest
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
             * @var string 發放積分，正數、最多 2 位小數（字串傳遞避免浮點誤差）
             *
             * @example "100.50"
             */
            'score' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            /**
             * @var int|null 有效期小時數；不給或 null ＝ 無限期
             *
             * @example 240
             */
            'hours' => 'nullable|integer|min:1|max:87600',
            /**
             * @var string 備註（必填，寫進積分異動紀錄）
             *
             * @example "活動補發"
             */
            'remark' => 'required|string|max:500',
        ];
    }
}
