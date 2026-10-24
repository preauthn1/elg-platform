<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Data\BankAccountData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\CashFlowEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentDomain;
use App\Models\AgentPersonalSetting;
use App\Models\BankAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class AgentService
{
    public function getSpecificAgentSetting(Agent $agent, int $settingKey): mixed
    {
        $setting = $this->getAgentSettings($agent);

        return $setting[$settingKey] ?? null;
    }

    public function getAgentSettings(Agent $agent): array
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value) ?? 1;
        $cacheKey = CacheKeys::agentSettings($agent->id, $languageId);

        /** @var array $data */
        $data = Cache::remember($cacheKey, CacheTtl::ONE_MINUTE->value, function () use ($agent, $languageId) {
            $list = DB::table('agent_setting_defines as d')
                ->leftJoin('agent_customized_settings as s', function ($join) use ($agent) {
                    $join->on('s.setting_key', 'd.setting_id');
                    $join->where('s.agent_group_id', $agent->customized_setting_group_id);
                    // $join->where(function ($sub_query) use ($languageId) {
                    //     $sub_query->where('s.sub_category', $languageId);
                    //     $sub_query->orWhere('s.sub_category', '');
                    // });
                })
                ->leftJoin('agent_personal_settings as p', function ($join) use ($agent) {
                    $join->on('p.setting_id', 'd.setting_id')
                        ->where('p.agent_id', $agent->id);
                })
                ->leftJoin('language_texts as lt', function ($join) use ($languageId) {
                    $join->on('lt.language_text_code', 'd.language_text_code')
                        ->where('lt.language_id', $languageId)
                        ->where('d.data_type', 'language_text');
                })
                ->where(function ($query) {
                    $query->whereIn('d.type', [2, 3]);
                })
                ->where(function ($query) use ($languageId) {
                    $query->where('d.data_type', '!=', 'language_text');
                    $query->orWhere(function ($sub_query) use ($languageId) {
                        $sub_query->where('d.data_type', 'language_text');
                        $sub_query->where('s.sub_category', $languageId);
                    });
                })
                ->orderBy('d.setting_id', 'asc')
                ->select(
                    'lt.text as language_text',
                    'p.value as personal_value',
                    's.value as group_value',
                    'd.default_value',
                    'd.setting_id',
                    'd.data_type',
                    'd.type',
                )
                ->get();

            $data = [];
            foreach ($list as $obj) {
                // 多語系文字, 取得順序
                // 1. agent_customized_settings 的 value
                // 2. language_texts 的 text
                // 3. agent_setting_defines 的 default_value
                if ($obj->data_type === 'language_text') {
                    if (!empty($obj->group_value)) {
                        $data[$obj->setting_id] = $obj->group_value;
                    } elseif (!empty($obj->language_text)) {
                        $data[$obj->setting_id] = $obj->language_text;
                    } else {
                        $data[$obj->setting_id] = $obj->default_value;
                    }
                } else {
                    if ($obj->type === 2) {
                        // type=2 代理個人設定, 資料從 agent_personal_settings 取得
                        $data[$obj->setting_id] = $obj->personal_value ?? $obj->default_value;
                    }
                    if ($obj->type === 3) {
                        // type=3 代理分組設定, 資料從 agent_customized_settings 取得
                        $data[$obj->setting_id] = $obj->group_value ?? $obj->default_value;
                    }
                    // 轉換為數字
                    // TODO Unsupported operand types: string * int
                    // if ($obj->data_type === 'boolean') {
                    //     $data[$obj->setting_id] *= 1;
                    // }
                }
            }

            return $data;
        });

        return $data;
    }

    /**
     * 取得自助洗分的單次提領金額上下限
     */
    public function getWithdrawalAmountLimit(Agent $agent): array
    {
        $result = [];
        $agentCashFlow = AgentCashFlow::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('cash_flow_id', CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL)
            ->where('status', 1)
            ->first();

        if ($agentCashFlow) {
            $result = $agentCashFlow->info;

            // 相容性處理：若存在舊鍵名則映射到新鍵名
            if (isset($result['MinAmount'])) {
                $result['min_amount'] ??= $result['MinAmount'];
            }
            if (isset($result['MaxAmount'])) {
                $result['max_amount'] ??= $result['MaxAmount'];
            }
        } else {
            $result['min_amount'] = 0;
            $result['max_amount'] = 0;
        }

        return $result;
    }

    /**
     * OLD: BankController getBankAccount
     *
     * @return BankAccountData[]
     */
    public function getAvailableBankAccounts(
        Agent $agent,
        int $cashFlowId = CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
        ?int $bankId = null,
        ?array $bankAccountWhitelist = null,
    ): array {
        $bankAccounts = BankAccount::where('is_deposit', 1)
            ->where('status', 1)
            ->when($bankId, function ($query) use ($bankId) {
                $query->where('id', $bankId);
            })
            ->when($bankAccountWhitelist !== null, function ($query) use ($bankAccountWhitelist) {
                $query->whereIn('id', $bankAccountWhitelist);
            })
            ->whereHas('orderType', function ($q) use ($cashFlowId) {
                $q->where('status', 1)
                    ->where('cash_flow_id', $cashFlowId);
            })
            ->whereHas('orderType.agentCashFlowOrderTypes.agentCashFlow', function ($query) use ($agent) {
                $query->where('agent_group_id', $agent->cash_flow_group_id);
            })
            ->with([
                'orderType:id,name,key,currency_id,image_path',
                'orderType.agentCashFlowOrderTypes' => function ($q) use ($agent) {
                    $q->whereHas('agentCashFlow', function ($q) use ($agent) {
                        $q->where('agent_group_id', $agent->cash_flow_group_id);
                    });

                    $q->select('id', 'agent_cash_flow_id', 'order_type_id');
                },
            ])
            ->select(
                'id',
                'order_type_id',
                'account_name',
                'account_nickname',
                'account_no',
                'qrcode',
                'rate',
                DB::raw('TRUNCATE(min_amount, 0) as min_amount'),
                DB::raw('TRUNCATE(max_amount, 0) as max_amount')
            )
            ->get();

        $result = [];
        foreach ($bankAccounts as $bankAccount) {
            $orderType = $bankAccount->orderType;

            // already used whereHas orderType.agentCashFlowOrderTypes.agentCashFlow to filter.
            // should be only one row and not null.
            $agentCashFlowOrderType = $orderType->agentCashFlowOrderTypes->first();

            $result[] = BankAccountData::from([
                'order_type_id' => $bankAccount->order_type_id,
                'bank_id' => $bankAccount->id,
                'bank_name' => $orderType->name,
                'bank_code' => $orderType->key,
                'currency_id' => $orderType->currency_id,
                'account_name' => $bankAccount->account_name,
                'account_nickname' => $bankAccount->account_nickname,
                'account_no' => $bankAccount->account_no,
                'qrcode' => $bankAccount->qrcode,
                'bank_icon' => $orderType->image_path,
                'agent_cash_flow_id' => $agentCashFlowOrderType->agent_cash_flow_id,
                'rate' => $bankAccount->rate,
                'min_amount' => $bankAccount->min_amount,
                'max_amount' => $bankAccount->max_amount,
            ]);
        }

        return $result;
    }

    /**
     * 寫入代理個人設定
     */
    public function setAgentPersonalSetting(Agent $agent, $settingKey, $value, $subCategory = ''): void
    {
        $exists = AgentPersonalSetting::query()
            ->where('agent_id', $agent->id)
            ->where('setting_id', $settingKey)
            ->exists();

        if (!$exists) {
            $data = [
                'agent_id' => $agent->id,
                'setting_id' => $settingKey,
                'sub_category' => $subCategory,
                'value' => $value,
                'created_by' => 51, // Admin ID
            ];

            AgentPersonalSetting::create($data);
        }
    }

    /**
     * 代理鏈中，由近到遠，取得第一個有綁網域的代理的所有網域
     *
     * @return Collection<int, AgentDomain>
     */
    public function getAgentDomains(Agent $agent): Collection
    {
        $hierarchy = $agent->hierarchy_array;
        $agentIdString = implode(',', $hierarchy);

        $agentDomains = AgentDomain::query()
            ->whereIn('agent_id', $hierarchy)
            ->where('status', 1)
            ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
            ->get();

        if ($agentDomains->isEmpty()) {
            return $agentDomains;
        }

        $nearestAgentId = $agentDomains->first()->agent_id;

        return $agentDomains
            ->where('agent_id', $nearestAgentId)
            ->values();
    }

    /**
     * 檢查請求網域是否屬於代理鏈上最近有綁網域的代理
     */
    public function checkAgentDomain(Agent $agent, string $domain): bool
    {
        return $this->getAgentDomains($agent)->contains(
            fn (AgentDomain $agentDomain): bool => $agentDomain->domain === $domain
        );
    }

    public function getMaintainAgents(): Collection
    {
        return Cache::remember(CacheKeys::maintainAgents(), CacheTtl::ONE_MINUTE->value, function () {
            $utcNow = gmdate('Y-m-d H:i:s');

            return DB::table('agent_personal_settings as agent')
                ->leftJoin('agent_personal_settings as exclude', function ($join) {
                    $join->on('agent.agent_id', '=', 'exclude.agent_id')
                        ->where('exclude.setting_id', AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_EXCLUDE_AGENTS);
                })
                ->leftJoin('agent_personal_settings as start', function ($join) {
                    $join->on('agent.agent_id', '=', 'start.agent_id')
                        ->where('start.setting_id', AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME);
                })
                ->leftJoin('agent_personal_settings as end', function ($join) {
                    $join->on('agent.agent_id', '=', 'end.agent_id')
                        ->where('end.setting_id', AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME);
                })
                ->where('agent.value', '=', '1')
                ->where('agent.setting_id', '=', AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG)
                ->whereNotNull('start.value')
                ->whereNotNull('end.value')
                ->where('start.value', '<', $utcNow)
                ->where('end.value', '>', $utcNow)
                ->select(
                    'agent.agent_id',
                    DB::raw("COALESCE(exclude.value, '') as exclude_agents"),
                    'start.value as start_time',
                    'end.value as end_time'
                )
                ->get();
        });
    }

    /**
     * 取得推薦連結使用的網域
     *
     * 啟用固定域名且有設定值時使用固定域名, 否則使用前端帶入的 domain
     */
    public function getReferralLinkDomain(Agent $agent, ?string $requestDomain): ?string
    {
        $isFixedDomainEnabled = (int) $this->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::REFERRAL_LINK_FIXED_DOMAIN_ENABLED
        );
        $fixedDomain = $this->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::REFERRAL_LINK_FIXED_DOMAIN
        );

        return ($isFixedDomainEnabled === 1 && Common::isNotEmptyString($fixedDomain)) ? $fixedDomain : $requestDomain;
    }
}
