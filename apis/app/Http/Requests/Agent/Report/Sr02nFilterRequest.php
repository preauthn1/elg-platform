<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Enums\General\ContextKeyEnum;
use App\Enums\Report\Sr02nPairEnum;
use App\Enums\Report\Sr02nSourceEnum;
use App\Http\Requests\AbstractRequest;
use App\Models\Agent;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SR02N 開洗分明細表：列表與合計共用的篩選規則（target/tickets#4356）。
 *
 * 列表與合計必須帶同一組篩選，合計才會等於列表逐列加總。
 *
 * @knowledge rules/report-sr02n
 */
abstract class Sr02nFilterRequest extends AbstractRequest
{
    /** 查詢期間上限（天，SR02 `validateDateRange` 同值） */
    public const int MAX_PERIOD_DAYS = 60;

    public function rules(): array
    {
        return [
            /**
             * @var string 代理帳號（精確；須在登入者線下，否則回空）
             *
             * @example "ga01"
             */
            'agent_username' => 'nullable|alpha_dash|between:1,30',
            /**
             * @var string 會員帳號（精確；有值時代理兩段不併）
             *
             * @example "m01"
             */
            'member_username' => 'nullable|alpha_dash|between:1,30',
            /**
             * @var string 會員暱稱（精確；會員帳號有值時忽略）
             */
            'member_nickname' => 'nullable|string|between:1,30',
            /**
             * @var string 單號：我方單號，來源存款／取款時另比對三方單號
             */
            'transaction_no' => 'nullable|string|between:1,50',
            /**
             * @var string 執行者帳號
             */
            'execution_username' => 'nullable|alpha_dash|between:1,30',
            /**
             * @var int 來源 0:全部 1:客服系統 3:存款支付商 4:取款支付商
             *
             * @example 0
             */
            'source' => ['nullable', Rule::enum(Sr02nSourceEnum::class)],
            /**
             * @var int 支付商 cash_flows.id（來源 3／4 時生效）
             */
            'cash_flow_id' => 'nullable|integer|min:1',
            /**
             * @var int 支付商戶 agent_cash_flows.id（來源 3／4 時生效）
             */
            'agent_cash_flow_id' => 'nullable|integer|min:1',
            /**
             * @var int 支付通道 order_types.id（僅來源 3；取款無通道層）
             */
            'order_type_id' => 'nullable|integer|min:1|prohibited_if:source,' . Sr02nSourceEnum::ONLINE_PAYOUT->value,
            /**
             * @var string 開始時間（前端時區牆鐘）
             *
             * @example "2026-09-01 00:00:00"
             */
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var string 結束時間（前端時區牆鐘；與開始相差上限 60 天）
             *
             * @example "2026-09-01 23:59:59"
             */
            'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
            /**
             * @var bool 是否以訂單建立時間查詢（orders.created_at）
             */
            'is_created_period' => 'nullable|boolean',
            /**
             * @var array<int, string> 篩選配對（多選為 OR；空陣列＝不過濾）；admin 僅 Admin 線可帶
             *
             * @example ["ga_member", "agent_member"]
             */
            'pairs' => 'nullable|array|max:' . count(Sr02nPairEnum::cases()),
            'pairs.*' => ['distinct', Rule::enum(Sr02nPairEnum::class)],
        ];
    }

    /** 期間上限（`before:start +60 days` 的相對日期語法實際無效，須手算差值）；admin 配對僅 Admin 線可帶 */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $start = strtotime((string) $this->input('start_date'));
            $end = strtotime((string) $this->input('end_date'));
            if ($start !== false && $end !== false && ($end - $start) > self::MAX_PERIOD_DAYS * 86400) {
                $validator->errors()->add('end_date', 'period must not exceed ' . self::MAX_PERIOD_DAYS . ' days');
            }

            $pairs = (array) $this->input('pairs', []);
            if (in_array(Sr02nPairEnum::ADMIN->value, $pairs, true) && !$this->isPlatformAdminViewer()) {
                $validator->errors()->add('pairs', 'pair admin is only available to platform admin');
            }
        });
    }

    private function isPlatformAdminViewer(): bool
    {
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        return $agent instanceof Agent && $agent->isPlatformAdmin();
    }
}
