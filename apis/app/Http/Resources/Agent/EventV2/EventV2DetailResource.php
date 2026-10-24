<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use App\Services\EventV2\AngpowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2DetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var int 活動ID
             *
             * @example 1
             **/
            'event_v2_id' => $this->event_v2_id,
            /**
             * @var int 代理ID
             *
             * @example 4249
             **/
            'agent_id' => $this->agent_id,
            /**
             * @var string|null 參加條件公式
             *
             * @example "deposit == 30"
             **/
            'condition_formula' => $this->condition_formula,
            /**
             * @var string|null 參加條件客製化設定
             *
             * @example "{\"deposit\":30}"
             **/
            'condition_config' => AngpowService::timeWindowToBackend($this->condition_config),
            /**
             * @var string|null 活動獎勵公式
             *
             * @example "10"
             **/
            'reward_formula' => $this->reward_formula,
            /**
             * @var string|null 活動獎勵客製化設定
             *
             * @example null
             **/
            'reward_config' => $this->reward_config,
            /**
             * @var string|null 餘額限制公式
             *
             * @example "(deposit + reward) * 4"
             **/
            'balance_formula' => $this->balance_formula,
            /**
             * @var string|null 餘額限制客製化設定
             *
             * @example null
             **/
            'balance_config' => $this->balance_config,
            /**
             * @var string|null 流水限制公式
             *
             * @example null
             **/
            'flow_formula' => $this->flow_formula,
            /**
             * @var string|null 流水限制客製化設定
             *
             * @example null
             **/
            'flow_config' => $this->flow_config,
            /**
             * @var string|null 提款限制公式
             *
             * @example "1000"
             **/
            'withdraw_formula' => $this->withdraw_formula,
            /**
             * @var string|null 提款限制客製化設定
             *
             * @example null
             **/
            'withdraw_config' => $this->withdraw_config,
            /**
             * @var int|null 活動序號vendor id
             *
             * @example 126
             **/
            'bonus_vendor_id' => $this->bonus_vendor_id,
            /**
             * @var array 是否阻擋重複IP(0:停用,1:啟用)
             *
             * @example 1
             **/
            'ip_block' => $this->ip_block ? explode(',', $this->ip_block) : [],
            /**
             * @var int 是否阻擋重複手機號(0:停用,1:啟用)
             *
             * @example 1
             **/
            'phone_block' => $this->phone_block ? explode(',', $this->phone_block) : [],
            /**
             * @var int 是否阻擋重複真實姓名(0:停用,1:啟用)
             *
             * @example 1
             **/
            'account_block' => $this->account_block ? explode(',', $this->account_block) : [],
            /**
             * @var int 是否為參加即領獎(0:停用,1:啟用)
             *
             * @example 1
             **/
            'join_withdraw' => $this->join_withdraw,
            /**
             * @var int 成本類型，說明請參考 EventV2DetailCostTypeEnum
             *
             * @example 1
             **/
            'cost_type' => $this->cost_type,
            /**
             * @var int 領取去向(1:主錢包,2:積分)，見 EventV2WithdrawTypeEnum
             *
             * @example 1
             **/
            'withdraw_type' => $this->withdraw_type->value,
            /**
             * @var int 會員週期／可重複參加(0:不分 1:日 2:週 3:月 4:年)，只有紅包會非 0；見 EventV2PeriodEnum
             *
             * @example 0
             **/
            'member_period' => $this->member_period?->value ?? 0,
            /**
             * @var int 全站週期／包括期間(0:不分 1:日 2:週 3:月 4:年)：max_join／max_amount 的累計區間，只有紅包會非 0
             *
             * @example 0
             **/
            'total_period' => $this->total_period?->value ?? 0,
            /**
             * @var string 開始時間
             *
             * @example "2025-05-10 12:19:16"
             **/
            'start_time' => \Carbon\Carbon::parse($this->start_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var string 結束時間
             *
             * @example "2025-07-23 12:19:19"
             **/
            'end_time' => \Carbon\Carbon::parse($this->end_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var int 排序
             *
             * @example 1
             **/
            'sort' => $this->sort,
            /**
             * @var int 是否回收(0:停用,1:啟用)
             *
             * @example 1
             **/
            'is_recycle' => $this->is_recycle,
            /**
             * @var int 狀態(0:停用,1:啟用)
             *
             * @example 1
             **/
            'status' => $this->status,
            /**
             * @var EventV2DetailLangResource 活動詳細語系
             **/
            'detail_lang' => $this->detailLang ? EventV2DetailLangResource::collection($this->detailLang) : [],
            /**
             * @var int 目標用戶類型(0:不限制 1:指定會員 2:會員群組)，見 MemberRestrictionTargetEnum（target/tickets#4234）
             *
             * @example 2
             **/
            'target_type' => $this->relationLoaded('targets') && $this->targets->isNotEmpty()
                ? $this->targets->first()->targetEnum()->value
                : 0,
            /**
             * @var array<int, array{id: int, name: string}> 已勾選的目標：會員給帳號、群組給群組名，供編輯時回填
             *
             * @example [{"id": 3, "name": "VIP"}]
             **/
            'targets' => $this->relationLoaded('targets')
                ? $this->targets->map(fn ($t) => [
                    'id' => (int) $t->target_id,
                    'name' => (string) ($t->target?->username ?? $t->target?->name ?? ''),
                ])->values()->all()
                : [],
            /**
             * @var EventV2DetailExcludeAgentResource 活動詳細排除代理
             **/
            'exclude_agent' => $this->detailExcludeAgents ? EventV2DetailExcludeAgentResource::collection($this->detailExcludeAgents) : [],
            /**
             * @var EventV2DetailGameTypeResource 限制遊戲分類
             **/
            'restrict_game_type' => $this->detailGameTypes ? EventV2DetailGameTypeResource::collection($this->detailGameTypes) : [],
            /**
             * @var EventV2DetailVendorResource 限制遊戲廠商
             **/
            'restrict_vendor' => $this->detailVendors ? EventV2DetailVendorResource::collection($this->detailVendors) : [],
            /**
             * @var EventV2DetailIncludeVendorResource 排除遊戲廠商
             **/
            'exclude_vendor' => $this->detailExcludeVendors ? EventV2DetailExcludeVendorResource::collection($this->detailExcludeVendors) : [],
        ];
    }
}
