<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\OperateLog;

use App\Http\Requests\AbstractRequest;

class ExportRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 匯出動作代碼，對應 WEB_AgentBackend 各 ajax 的 action
             *
             * 大小寫都要收：WEB 端的 action 多數是 snake_case，但 transactionList／
             * transactionListNew 是駝峰式。本欄位是 fail-closed 的擋門，寫死小寫會讓
             * 那幾頁的匯出直接不能用，而不是只有稽核少一筆。
             *
             * @var string
             *
             * @example excel_event_v2_join
             */
            'action' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_]+$/'],
            /**
             * 匯出當下的選單名稱，依操作者當時語系凍結
             *
             * @var string|null
             *
             * @example 活動V2 參加紀錄
             */
            'menu_name' => ['nullable', 'string', 'max:100'],
            /**
             * 匯出當下的頁面路徑，後端以此反查 agent_menus.id；查不到時紀錄不指向任何選單
             *
             * @var string|null
             *
             * @example event_v2/join
             */
            'menu_url' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
            /**
             * 匯出時的查詢條件；前端已剔除分頁與敏感欄位，後端仍會再過濾一次
             *
             * @var array<string, string>|null
             *
             * @example {"start_time":"2026-07-01 00:00","status":"success"}
             */
            'conditions' => ['nullable', 'array', 'max:60'],
            /**
             * 實際匯出筆數，查無資料時為 0
             *
             * @var int
             *
             * @example 128
             */
            'record_count' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
