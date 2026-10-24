<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberScore;

use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

/**
 * 積分異動明細（target/tickets#4232；主表「查看」彈窗）。
 *
 * 日期以後台時區（+8）輸入，服務端轉 UTC 比對 created_at；不帶＝全期間。
 */
class MemberScoreRecordLogsRequest extends AbstractRequest
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
             * @var string|null 起（含），後台時區
             *
             * @example "2026-09-01 00:00:00"
             */
            'start_time' => 'nullable|date_format:Y-m-d H:i:s',
            /**
             * @var string|null 迄（含），後台時區
             *
             * @example "2026-09-30 23:59:59"
             */
            'end_time' => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            /**
             * @var int|null 只看某一批（member_score_batches.id）；跨批扣除拆出的多筆各對自己的批
             *
             * @example 18
             */
            'batch_id' => 'nullable|integer|min:1',
            /**
             * @var int|null 做了什麼 ScoreLogTypeEnum：1 系統發放 2 系統消耗 3 人工調整 4 到期失效
             *
             * @example 3
             */
            'type' => ['nullable', 'integer', Rule::enum(ScoreLogTypeEnum::class)],
            /**
             * @var int|null 1 增加 2 扣除（與 type 併用可分出人工發放／人工扣除）
             *
             * @example 2
             */
            'direction' => ['nullable', 'integer', Rule::enum(ScoreLogDirectionEnum::class)],
            'page' => 'required|integer|min:1',
            'per_page' => 'required|integer|min:1|max:500',
        ];
    }
}
