<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\Vendor;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * {@see database/procedures/SP_calc_profit_share.sql}
 *
 * 依賴 {@see COM_Send_Error_Message}、{@see ADMIN_get_customized_setting}；{@see TestCase::ensureStoredProcedureExists()}
 * 與 {@see SyncAgentSettingDefinesSeeder}（ADMIN_get_customized_setting 需 agent_setting_defines 列，否則 DataValue 為 NULL）。
 * 僅斷言庫內已存在同名 SP（不在測試內建 procedure，以免破壞交易）。
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/SpCalcProfitShareTest.php
 * ```
 */
class SpCalcProfitShareTest extends TestCase
{
    private const int SETTING_SHAREHOLDER_ON = 6100;

    private const int SETTING_SHAREHOLDER_GENERAL_AGENT = 6101;

    private const int SETTING_GENERAL_AGENT_SHARE_VALUE = 8001;

    private const int SETTING_AGENCY_GAP = 8004;

    private const int SETTING_COMPANY_PROFIT_SHARE_JSON = 8201;

    private const int PERSONAL_SHARE_POINT = 95001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->ensureStoredProcedureExists('COM_Send_Error_Message');
        $this->ensureStoredProcedureExists('ADMIN_get_customized_setting');
        $this->ensureStoredProcedureExists('SP_calc_profit_share');
    }

    #[Test]
    public function exits_without_touching_payout_when_shareholder_switch_off(): void
    {
        $ga = $this->createAgentAndDomain();
        $this->seedMinimalCustomizedSettings(generalAgent: $ga, shareholderOn: false);

        $payoutId = $this->insertPayoutLog(agentGroupId: (int) $ga->customized_setting_group_id, summaryDate: '2026-05-10');

        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', ['2026-05-10', $ga->id, $payoutId]);

        $this->assertSame(0, (int) DB::table('profit_share_payout_logs')->where('id', $payoutId)->value('status'));
    }

    #[Test]
    public function runs_end_to_end_and_sets_payout_status_to_settled(): void
    {
        $ctx = $this->seedMinimalProfitShareScenario(summaryDate: '2026-05-10');

        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', ['2026-05-10', $ctx['ga_id'], $ctx['payout_id']]);

        $this->assertSame(3, (int) DB::table('profit_share_payout_logs')->where('id', $ctx['payout_id'])->value('status'));

        $row = DB::table('summary_profit_shares')
            ->where('summary_time', $ctx['start_time'])
            ->where('agent_id', $ctx['child_id'])
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(-1.0, (float) $row->cap_amount);
        $this->assertSame(1.0, (float) $row->weighting);
        // winlose -100 → direct_win_lose_original 100；company_rate 100%；direct_rate 100% → direct_profit 100；profit 乘權重 1
        $this->assertSame(100.0, (float) $row->direct_profit);
        $this->assertSame(0.0, (float) $row->team_profit);
        $this->assertSame(100.0, (float) $row->profit);
    }

    #[Test]
    public function cap_amount_is_applied_for_weighting_when_below_wallet_balance(): void
    {
        $ctx = $this->seedMinimalProfitShareScenario(
            summaryDate: '2026-05-11',
            walletBalance: '1000.0000',
            capAmount: '50',
        );

        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', ['2026-05-11', $ctx['ga_id'], $ctx['payout_id']]);

        $row = DB::table('summary_profit_shares')
            ->where('summary_time', $ctx['start_time'])
            ->where('agent_id', $ctx['child_id'])
            ->firstOrFail();

        $this->assertSame(50.0, (float) $row->cap_amount);
        $this->assertEqualsWithDelta(0.5, (float) $row->weighting, 0.0001);
        $this->assertSame(100.0, (float) $row->direct_profit);
        $this->assertSame(0.0, (float) $row->team_profit);
        $this->assertEqualsWithDelta(50.0, (float) $row->profit, 0.0001);
    }

    #[Test]
    public function cap_amount_negative_one_uses_wallet_balance_for_weighting(): void
    {
        $ctx = $this->seedMinimalProfitShareScenario(
            summaryDate: '2026-05-12',
            walletBalance: '1000.0000',
            capAmount: '-1',
        );

        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', ['2026-05-12', $ctx['ga_id'], $ctx['payout_id']]);

        $row = DB::table('summary_profit_shares')
            ->where('summary_time', $ctx['start_time'])
            ->where('agent_id', $ctx['child_id'])
            ->firstOrFail();

        $this->assertSame(-1.0, (float) $row->cap_amount);
        $this->assertSame(1.0, (float) $row->weighting);
        $this->assertSame(100.0, (float) $row->direct_profit);
        $this->assertSame(0.0, (float) $row->team_profit);
        $this->assertSame(100.0, (float) $row->profit);
    }

    #[Test]
    public function cap_amount_above_wallet_balance_uses_wallet_balance_for_weighting(): void
    {
        $ctx = $this->seedMinimalProfitShareScenario(
            summaryDate: '2026-05-13',
            walletBalance: '1000.0000',
            capAmount: '5000',
        );

        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', ['2026-05-13', $ctx['ga_id'], $ctx['payout_id']]);

        $row = DB::table('summary_profit_shares')
            ->where('summary_time', $ctx['start_time'])
            ->where('agent_id', $ctx['child_id'])
            ->firstOrFail();

        $this->assertSame(5000.0, (float) $row->cap_amount);
        $this->assertSame(1.0, (float) $row->weighting);
        $this->assertSame(100.0, (float) $row->direct_profit);
        $this->assertSame(0.0, (float) $row->team_profit);
        $this->assertSame(100.0, (float) $row->profit);
    }

    /**
     * @return array{ga_id: int, child_id: int, payout_id: int, start_time: string}
     */
    private function seedMinimalProfitShareScenario(
        string $summaryDate,
        string $walletBalance = '1000.0000',
        ?string $capAmount = null,
    ): array {
        $ga = $this->createAgentAndDomain();
        $this->seedMinimalCustomizedSettings(generalAgent: $ga, shareholderOn: true);

        $child = Agent::factory()->create([
            'parent' => $ga->id,
            'role_id' => RoleEnum::AGENT->value,
            'customized_setting_group_id' => $ga->customized_setting_group_id,
            'cash_flow_group_id' => $ga->cash_flow_group_id,
            'organization_id' => $ga->organization_id,
            'host_id' => 0,
            'admin' => 1,
        ]);
        $child->hierarchy_path = "/{$ga->id}/{$child->id}";
        $child->hierarchy = '[-1,' . $ga->id . ',' . $child->id . ',-2]';
        $child->save();

        $vendor = Vendor::factory()->create();
        DB::table('vendors')->where('id', $vendor->id)->update(['calc_on_profit_share' => 1]);

        $groupId = (int) $ga->customized_setting_group_id;
        $categoryId = DB::table('shareholder_categories')->insertGetId([
            'agent_group_id' => $groupId,
            'name' => 'tcat',
            'code' => 'tcat',
            'sort' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        DB::table('shareholder_category_settings')->insert([
            'shareholder_category_id' => $categoryId,
            'vendor_id' => $vendor->id,
            'game_type_id' => 0,
            'calc_bet' => 1,
            'calc_win_lose' => 1,
            'memo' => '',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        $startTime = $this->profitShareStartTime($summaryDate);

        DB::table('summary_profit_share_winloses')->insert([
            'summary_time' => $startTime,
            'agent_id' => $child->id,
            'vendor_id' => $vendor->id,
            'game_type_id' => 1,
            'turnover' => '1000.0000',
            'winlose' => '-100.0000',
            'game_spending' => '0.0000',
            'game_profit' => '0.0000',
            'cost' => '0.0000',
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        DB::table('profit_share_agents')->insert([
            'shareholder_category_id' => $categoryId,
            'agent_id' => $child->id,
            'direct_percentage' => 100,
            'team_percentage' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        $this->createAgentPersonalSetting($child, self::PERSONAL_SHARE_POINT, '50');

        if ($capAmount !== null) {
            $this->createAgentPersonalSetting($child, AgentPersonalSettingKeyEnum::PROFIT_SHARE_WALLET_CAP_AMOUNT, $capAmount);
        }

        DB::table('agent_wallets')->insert([
            'agent_id' => $child->id,
            'wallet_type_id' => 3,
            'balance' => $walletBalance,
            'is_unlimited' => 0,
            'status' => 1,
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        $payoutId = $this->insertPayoutLog(agentGroupId: $groupId, summaryDate: $summaryDate);

        return [
            'ga_id' => $ga->id,
            'child_id' => $child->id,
            'payout_id' => $payoutId,
            'start_time' => $startTime,
        ];
    }

    private function seedMinimalCustomizedSettings(Agent $generalAgent, bool $shareholderOn): void
    {
        $groupId = (int) $generalAgent->customized_setting_group_id;
        DB::table('agent_customized_settings')
            ->where('agent_group_id', $groupId)
            ->whereIn('setting_key', [
                self::SETTING_SHAREHOLDER_ON,
                self::SETTING_SHAREHOLDER_GENERAL_AGENT,
                self::SETTING_GENERAL_AGENT_SHARE_VALUE,
                self::SETTING_AGENCY_GAP,
                self::SETTING_COMPANY_PROFIT_SHARE_JSON,
            ])
            ->delete();

        $json = json_encode([
            'setting' => [[
                'max_sp' => 100,
                'min_sp' => 0,
                'p1' => 10,
                'p2' => 60,
                'p3' => 30,
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_ON, $shareholderOn ? '1' : '0');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_GENERAL_AGENT, (string) $generalAgent->username);
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_GENERAL_AGENT_SHARE_VALUE, '100');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_AGENCY_GAP, '0');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_COMPANY_PROFIT_SHARE_JSON, $json);
    }

    private function insertAgentCustomizedSetting(int $agentGroupId, int $settingKey, string $value): void
    {
        DB::table('agent_customized_settings')->insert([
            'agent_group_id' => $agentGroupId,
            'setting_key' => $settingKey,
            'sub_category' => '1',
            'value' => $value,
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPayoutLog(int $agentGroupId, string $summaryDate): int
    {
        $startTime = $this->profitShareStartTime($summaryDate);

        return (int) DB::table('profit_share_payout_logs')->insertGetId([
            'summary_time' => $startTime,
            'agent_group_id' => $agentGroupId,
            'status' => 0,
            'memo' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function profitShareStartTime(string $summaryDateYmd): string
    {
        $row = DB::selectOne(
            'SELECT DATE_SUB(CAST(? AS DATE), INTERVAL 8 HOUR) AS t',
            [$summaryDateYmd]
        );

        return (string) $row->t;
    }
}
