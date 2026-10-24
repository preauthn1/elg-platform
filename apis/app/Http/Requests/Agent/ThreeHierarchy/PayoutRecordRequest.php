<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\ThreeHierarchy;

use App\Http\Requests\AbstractRequest;

/**
 * 三層代派發紀錄（target/tickets#4199）
 *
 * 對應舊系統 API_Agent 的 `api/three_hierarchy/payout_record`，比舊系統多了：
 * identity 身分篩選、計算基數/結算期間欄、提現單號、兩組統計卡。
 * 期間上限 32 天：快捷鈕最大是「上個月」整月，同時擋住任意長區間掃表。
 * TPower 2,500 萬列實測，31 天聚合 6.5 秒；舊系統不設上限的月查詢要 27 秒，必定逾時。
 */
class PayoutRecordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理商帳號（精確比對；同時過濾代理列與該代理直屬下線的會員列）
             *
             * @example "vwtest"
             */
            'agent' => 'nullable|string|max:64',
            /**
             * @var string 會員帳號（精確比對；指定時僅回會員列）
             *
             * @example "vwuser001"
             */
            'member' => 'nullable|string|max:64',
            /**
             * @var string 身分篩選：member=會員、agent=代理、空=全部
             *
             * @example "member"
             */
            'identity' => 'nullable|string|in:member,agent',
            /**
             * @var string 派發時間起（GMT+8 牆鐘，本報表固定 GMT+8 結算不隨時區設定）
             *
             * @example "2026-08-21 00:00:00"
             */
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var string 派發時間迄（GMT+8 牆鐘；與起始差距上限 32 天）
             *
             * @example "2026-08-21 23:59:59"
             */
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'nullable|integer|min:1',
            /**
             * @var int 每頁筆數（匯出時前端會帶大值，設天花板防護）
             *
             * @example 20
             */
            'per_page' => 'nullable|integer|min:1|max:100000',
        ];
    }

    /** 期間上限 32 天（`before:start_time +32 days` 的相對日期語法實際無效，須手算差值） */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $start = strtotime((string) $this->input('start_time'));
            $end = strtotime((string) $this->input('end_time'));
            if ($start !== false && $end !== false && ($end - $start) > 32 * 86400) {
                $validator->errors()->add('end_time', 'period must not exceed 32 days');
            }
        });
    }
}
