<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use App\Models\ShareholderApplication;
use App\Models\ShareholderMemberThreshold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ShareHolderService
{
    /** 股東分潤往前回溯天數上限（舊版無上限，見 getProfit 說明） */
    private const int PROFIT_MAX_LOOKBACK_DAYS = 90;

    /**
     * 申請前的檢查
     */
    public function applyCheck(Member $member, array $agentSettings): void
    {
        // 是否開放會員申請成為代理
        $isEnable = $agentSettings[AgentCustomizedSettingKeyEnum::MEMBER_UPGRADE_AGENT_VISIBLE];
        if (!$isEnable) {
            throw new ApiException(__('error_message.1852'));
        }

        // 檢查上級代理是否是透過會員申請 (不能有多層會員代理)
        $hasAgentApplication = ShareholderApplication::query()
            ->where('agent_id', $member->parent)
            ->exists();
        if ($hasAgentApplication) {
            throw new ApiException(__('error_message.1851'));
        }

        // 檢查會員是否已經提出申請
        $hasMemberApplication = ShareholderApplication::query()
            ->where('member_id', $member->id)
            ->exists();
        if ($hasMemberApplication) {
            throw new ApiException(__('error_message.1852'));
        }

        // 判斷上級代理股份是否低於 "股份門檻" 上限
        $agentSharePointLimit = (int) $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SHARE_POINT_LIMIT];
        $agentSharePoint = (int) $agentSettings[AgentPersonalSettingKeyEnum::SHAREHOLDER_POINT];
        if ($agentSharePointLimit > $agentSharePoint) {
            // 代理股份低於門檻
            throw new ApiException(__('error_message.1853'));
        }
    }

    /**
     * 替會員註冊代理帳號
     */
    public function registerAgent(Member $member, array $input): Agent
    {
        $parentAgent = $member->agent;

        $data = [
            'username' => $input['username'] ?? '',
            'nickname' => $input['username'] ?? '',
            'password' => Hash::make($input['password'] ?? ''),
            'parent' => $member->parent,
            'code' => $this->generateShareCode(),
            'email' => '',
            'cs_sync_flag' => 1,
            'mobile' => $member->mobile,
            'frontend_register' => $parentAgent->frontend_register,
            'has_chat' => $parentAgent->has_chat,
            'has_pc' => $parentAgent->has_pc,
            'has_mobile' => $parentAgent->has_mobile,
            'has_online_payment' => $parentAgent->has_online_payment,
            'has_app' => $parentAgent->has_app,
            'has_apk' => $parentAgent->has_apk,
            'has_ipa' => $parentAgent->has_ipa,
            'has_2fa' => $parentAgent->has_2fa,
            'transaction_all' => $parentAgent->transaction_all,
            'agent_backend_transaction' => $parentAgent->agent_backend_transaction,
            'mobile_validate' => 1,
            'mobile_bind' => 1,
            'need_username' => $parentAgent->need_username,
            'agent_mode' => $parentAgent->agent_mode,
            'customized_setting_group_id' => $parentAgent->customized_setting_group_id,
            'cash_flow_group_id' => $parentAgent->cash_flow_group_id,
            'sms_gateway_group_id' => $parentAgent->sms_gateway_group_id,
            'email_gateway_group_id' => $parentAgent->email_gateway_group_id,
            'chat_group_id' => $parentAgent->chat_group_id,
            'vendor_customize_group_id' => $parentAgent->vendor_customize_group_id,
            'organization_id' => $parentAgent->organization_id,
            'created_by' => 51,
            'admin' => 51,
        ];

        $agent = Agent::create($data);

        $agent->update([
            'hierarchy' => str_replace('[-1,', '[-1,' . $agent->id . ',', $parentAgent->hierarchy),
            'hierarchy_path' => $parentAgent->hierarchy_path . '/' . $agent->id,
        ]);

        return $agent;
    }

    /**
     * 為代理分配必要的後台菜單權限
     */
    public function assignAgentPermission(Agent $agent): void
    {
        $menuCodes = [
            'cj7_shareholder_report',
            'cj7_shareholder_win_lose_report',
            'cj7_rebate_report',
            'cj7_user_management',
            'cj7_user_list',
            'cj7_add_user',
            'cj7_qrcode',
            'cj7_cash',
            'shareholder_agent_transaction_logs',
            'cj7_user_instant_bets',
        ];

        // 取得所有符合條件的 agent_menu_id
        $menuIds = AgentMenu::whereIn('code', $menuCodes)->pluck('id')->toArray();

        // 已存在的權限 agent_menu_id
        $existingMenuIds = AgentPermission::where('agent_id', $agent->id)
            ->whereIn('agent_menu_id', $menuIds)
            ->pluck('agent_menu_id')
            ->toArray();

        // 準備要插入的權限資料
        $newPermissions = [];
        foreach ($menuIds as $menuId) {
            if (!in_array($menuId, $existingMenuIds)) {
                $newPermissions[] = [
                    'agent_id' => $agent->id,
                    'agent_menu_id' => $menuId,
                    'action' => '',
                    'admin' => 1,
                ];
            }
        }

        // 批量插入新權限
        AgentPermission::insert($newPermissions);
    }

    /**
     * 生成唯一的代理邀請碼
     * 前三位英文, 後四位數字, 共七碼
     */
    private function generateShareCode(): string
    {
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $numbers = '23456789';
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = '';
            for ($j = 0; $j < 3; $j++) {
                $code .= $letters[random_int(0, strlen($letters) - 1)];
            }
            for ($j = 0; $j < 4; $j++) {
                $code .= $numbers[random_int(0, strlen($numbers) - 1)];
            }
            if (!Agent::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('無法生成唯一代理邀請碼');
    }

    /**
     * 根據代理群組ID獲取最小股份點數
     */
    public function createShareholderApplication(Member $member, Agent $agent): void
    {
        ShareholderApplication::create([
            'orig_agent_id' => $member->parent,
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => 0,
            'admin' => 51,
        ]);
    }

    /**
     * 根據代理群組ID獲取最小股份點數
     */
    public function getMinSharePointByAgentGroupId(Agent $agent): int
    {
        $result = ShareholderMemberThreshold::query()
            ->where('agent_group_id', $agent->customized_setting_group_id)
            ->orderBy('min_active_members')
            ->first();

        return $result ? $result->share_point : 0;
    }

    /**
     * 取得最近一次已派發的股東分潤（免登入，依 domain 判定品牌）
     *
     * 遷移自 API_Frontend ShareholderService::getProfit（TP 專屬，vw-master 無此功能）。
     * 沿用舊版做法：自「昨日」起以單日區間逐日往前找，直到找到有派發紀錄的一天為止。
     *
     * @return array{profit_time: string, total_profit: string}
     */
    public function getProfit(Agent $agent): array
    {
        // 舊版以 UTC+8 的「昨日」整日為起始區間
        $yesterday = now()->toFrontendTime()->subDay();
        $start = $yesterday->copy()->startOfDay();
        $end = $yesterday->copy()->endOfDay();

        for ($i = 0; $i < self::PROFIT_MAX_LOOKBACK_DAYS; $i++) {
            if ($i > 0) {
                $start->subDay();
                $end->subDay();
            }

            $profit = $this->queryProfit(
                $agent,
                $start->copy()->toSystemTime()->toDateTimeString(),
                $end->copy()->toSystemTime()->toDateTimeString(),
            );

            if ($profit !== null) {
                return [
                    'profit_time' => $end->format('Y/m/d'),
                    'total_profit' => (string) $profit->total_profit,
                ];
            }
        }

        // 舊版此處為 while(true) 無上限往前找，查無資料會無限迴圈；本端點免登入，
        // 故加上回溯上限，耗盡時回 0 讓前端仍可正常顯示（刻意偏離舊版，見遷移文件 16-1）
        return [
            'profit_time' => $end->format('Y/m/d'),
            'total_profit' => '0',
        ];
    }

    /**
     * 單日區間的股東分潤加總
     *
     * 純聚合唯讀查詢且三張表皆無對應 Model，依 CLAUDE.md 例外允許使用 DB::table。
     */
    private function queryProfit(Agent $agent, string $startTime, string $endTime): ?object
    {
        $agentGroupId = $agent->customized_setting_group_id;

        return DB::table('summary_shareholder_temps as spbt')
            ->join('shareholder_categories as sc', function ($join) use ($agentGroupId) {
                $join->on('sc.id', 'spbt.shareholder_category_id')
                    ->whereIn('sc.agent_group_id', [0, $agentGroupId]);
            })
            ->join('shareholder_payout_logs as spl', function ($join) {
                $join->on('spl.summary_time', 'spbt.summary_time')
                    ->on('spl.agent_group_id', 'spbt.agent_group_id')
                    ->where('spl.status', 1);
            })
            ->whereBetween('spbt.summary_time', [$startTime, $endTime])
            ->where('spbt.agent_group_id', $agentGroupId)
            ->havingRaw('(SUM(spbt.turnover) != 0 or SUM(spbt.nett_profit) != 0) or sc.id = 1')
            ->select(
                'sc.id as shareholder_category_id',
                DB::raw('ROUND(SUM(spbt.nett_profit), 2) as total_profit'),
            )
            ->first();
    }
}
