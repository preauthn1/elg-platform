<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Member;

use App\Services\Agent\MemberContactMaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class MemberTraceResource extends JsonResource
{
    /** 幣別固定呈現 MYR（3963 需求定案） */
    private const CURRENCY = 'MYR';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 會員ID
             *
             * @example 12345
             */
            'member_id' => (int) $this->id,
            /**
             * @var string 玩家帳號
             *
             * @example "frank01"
             */
            'username' => $this->username,
            /**
             * @var string 所屬代理帳號
             *
             * @example "agentA"
             */
            'agent_username' => $this->agent_username,
            /**
             * @var array<int, string> 風控標記(命中的條件，供前端渲染色標)
             *
             * @example ["password","email"]
             */
            'risk_marks' => $this->riskMarks(),
            /**
             * @var string 幣別
             *
             * @example "MYR"
             */
            'currency' => self::CURRENCY,
            /**
             * @var string 姓名
             *
             * @example "Lim chee sian"
             */
            'realname' => $this->realname(),
            /**
             * @var string 聯絡電話
             *
             * @example "601232109309"
             */
            'mobile' => MemberContactMaskService::mobile($request, $this->mobile),
            /**
             * @var string 電子郵件
             *
             * @example "user@example.invalid"
             */
            'email' => $this->email,
            /**
             * @var array<int, string> 銀行帳戶(僅啟用中)
             *
             * @example ["CIMB Bank - 9239021093112"]
             */
            'bank_accounts' => $this->bank_accounts ?? [],
            /**
             * @var array<int, string> 登入IP清單(去重；與查詢條件撞到的置頂，其後為近期 IP，上限 20)
             *
             * @example ["0.0.0.0","0.0.0.0"]
             */
            'login_ips' => $this->login_ips ?? [],
            /**
             * @var array<int, string> 與查詢 IP 條件撞到的 IP(login_ips 的置頂子集，供前端標色)
             *
             * @example ["0.0.0.0"]
             */
            'matched_login_ips' => $this->matched_login_ips ?? [],
            /**
             * @var int 登入紀錄總筆數(原始列數，供「近期 N 個 IP／共 X 筆登入」標示)
             *
             * @example 940
             */
            'login_log_total' => (int) ($this->login_log_total ?? 0),
            /**
             * @var string 註冊時間（後台時區 GMT+8；DB 存 UTC，target/tickets#4492）
             *
             * @example "2026-08-05 14:30:00"
             */
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString() : '',
            /**
             * @var string 上次登入IP
             *
             * @example "0.0.0.0"
             */
            'last_login_ip' => $this->last_login_ip,
            /**
             * @var string 上次登入日期（後台時區 GMT+8；DB 存 UTC，target/tickets#4492）
             *
             * @example "2026-08-05 16:20:00"
             */
            'last_login' => $this->last_login ? Carbon::parse($this->last_login)->toBackendTime()->toDateTimeString() : '',
        ];
    }

    /**
     * 命中的條件 key 清單。顏色對應由前端決定，後端只給語意。
     *
     * @return array<int, string>
     */
    private function riskMarks(): array
    {
        // 反查對象本人不標風控（跟自己當然全中，標記無意義）
        if ($this->is_trace_target ?? false) {
            return [];
        }

        $marks = [
            'password' => (int) ($this->hit_password ?? 0),
            'email' => (int) ($this->hit_email ?? 0),
            'mobile' => (int) ($this->hit_mobile ?? 0),
            'ip' => (int) ($this->hit_ip ?? 0),
            'realname' => (int) ($this->hit_realname ?? 0),
            'bank' => (int) ($this->hit_bank ?? 0),
        ];

        return array_keys(array_filter($marks));
    }

    /**
     * 真實姓名。正牌欄位為 first_name，空才 fallback last_name（舊資料）；不串接以免兩欄都有值時黏字。
     */
    private function realname(): string
    {
        $firstName = trim((string) $this->first_name);

        return $firstName !== '' ? $firstName : trim((string) $this->last_name);
    }
}
