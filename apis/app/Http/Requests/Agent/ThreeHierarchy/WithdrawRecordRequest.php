<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\ThreeHierarchy;

use App\Http\Requests\AbstractRequest;

/**
 * 三層代提現紀錄（target/tickets#4214）
 *
 * 對應舊系統 API_Agent 的 `api/three_hierarchy/withdraw_record`，比舊系統多了：
 * 代理商帳號篩選、來源欄（依 admin 欄位判斷：51 是排程自動派發、0 是會員申請）、
 * 統計卡（總額／筆數／去重人數）。
 * 期間上限 32 天：工單沒有要求，是為了和派發紀錄（target/tickets#4199）一致的大表防護。
 */
class WithdrawRecordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理商帳號（精確比對；列出該代理線下所有會員的提現紀錄）
             *
             * @example "vwtest"
             */
            'agent' => 'nullable|string|max:64',
            /**
             * @var string 會員帳號（精確比對；與代理商帳號同填時取交集）
             *
             * @example "vwuser001"
             */
            'member' => 'nullable|string|max:64',
            /**
             * @var string 提現單號（＝three_hierarchy_withdraw_logs.id，參數名與舊系統 API_Agent 的接口相同）
             *
             * @example "672"
             */
            'order_no' => 'nullable|string|max:20',
            /**
             * @var string 申請時間起（GMT+8 牆鐘）
             *
             * @example "2026-08-24 00:00:00"
             */
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var string 申請時間迄（GMT+8 牆鐘；與起始差距上限 32 天）
             *
             * @example "2026-08-26 23:59:59"
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
