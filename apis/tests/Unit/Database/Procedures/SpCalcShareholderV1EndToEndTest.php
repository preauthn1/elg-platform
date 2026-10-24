<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use App\Enums\Agent\RoleEnum;
use App\Enums\Game\GameTypeEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Vendor;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1 代分桶流程綜合測試：依 {@see docs/分桶與佔成/分桶流程.md} 之 1 代 SP 鏈路執行 temp → calc → payout。
 *
 * 分類比例 **bet_ratio=100、commission_ratio=0**，理論上等同 2 代 SP 在「投注分紅 100%、業績分紅 0%」時，
 * 全池依營業額佔比分配（2 代僅 `direct_nett_profit` 有值、`direct_win_nett_profit`=0）。
 *
 * 試算表預期值：{@see SpCalcShareholderV1EndToEndTest-reference.md}
 * 結算後依代理試算表：{@see dumpAgentShareholderSpreadsheetSummary()}（寫入 STDERR）
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/SpCalcShareholderV1EndToEndTest.php
 * ```
 */
class SpCalcShareholderV1EndToEndTest extends TestCase
{
    private const float EXPECTED_DELTA = 0.1;

    /** 1 代等價於 2 代 bet_ratio=100、commission_ratio=0 */
    private const int SHAREHOLDER_CATEGORY_BET_RATIO = 100;

    private const int SHAREHOLDER_CATEGORY_COMMISSION_RATIO = 0;

    /** v1 SP 多處硬編碼 shareholder_category_id = 1 */
    private const int SHAREHOLDER_CATEGORY_ID = 1;

    private const int SETTING_SHAREHOLDER_ON = 6100;

    private const int SETTING_SHAREHOLDER_GENERAL_AGENT = 6101;

    private const int SETTING_SHAREHOLDER_TYPE = 6102;

    private const int SETTING_GENERAL_AGENT_SHARE_VALUE = 8001;

    private const int SETTING_AGENCY_GAP = 8004;

    private const int SETTING_SHARE_LIMIT_JSON = 8006;

    private const int SETTING_COMPANY_PROFIT_SHARE_JSON = 8201;

    private const int PERSONAL_SHARE_POINT = 95001;

    private const int TRANSACTION_TYPE_SHAREHOLDER = 5;

    private const string GENERAL_AGENT_MAIN_BALANCE = '1000000.0000';

    private const string SETTLEMENT_GAME_PROFIT = '-1000.0000';

    /**
     * @var array{name: string, code: string, game_type_id: int}
     */
    private const array SLOT_CATEGORY_DEFINITION = [
        'name' => 'sh_v1_e2e_slot',
        'code' => 'slot',
        'game_type_id' => GameTypeEnum::SLOTS->value,
    ];

    /** @var array<string, string> */
    private const array AGENT_DISPLAY_NAMES = [
        'agent_a' => 'A代理',
        'agent_b' => 'B代理',
        'agent_c' => 'C代理',
    ];

    /**
     * 29,000 × 營業額佔比 × (100/130)，TRUNCATE(…,4)。
     *
     * @var array<string, array{turnover: string, direct_rate: string, direct_nett_profit: string}>
     */
    private const array CHILD_AGENT_SLOT_EXPECTATIONS = [
        'agent_a' => [
            'turnover' => '600000.0000',
            'direct_rate' => '0.6000',
            'direct_nett_profit' => '13384.6154',
        ],
        'agent_b' => [
            'turnover' => '300000.0000',
            'direct_rate' => '0.3000',
            'direct_nett_profit' => '6692.3077',
        ],
        'agent_c' => [
            'turnover' => '100000.0000',
            'direct_rate' => '0.1000',
            'direct_nett_profit' => '2230.7692',
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $scenario = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        foreach ([
            'COM_Send_Error_Message',
            'ADMIN_get_customized_setting',
            'SP_calc_shareholder_winlose',
            'SP_calc_shareholder_temp',
            'SP_calc_shareholder',
            'SP_shareholder_payout',
        ] as $routine) {
            $this->ensureStoredProcedureExists($routine);
        }
    }

    #[Test]
    public function runs_v1_shareholder_pipeline_matching_bet_only_v2_equivalent(): void
    {
        $this->scenario = $this->seedShareholderScenario();

        $balancesBefore = $this->captureAgentBalances();

        $this->runShareholderPipeline();

        $this->assertCompanySlotTemps();
        $this->assertChildAgentSlotShareholders();
        $this->assertShareholderPayoutIncreasesChildBalances($balancesBefore);
        $this->assertTotalPayoutMatchesV2BetOnlyEquivalent();

        $this->dumpAgentShareholderSpreadsheetSummary($balancesBefore);
    }

    private function runShareholderPipeline(): void
    {
        $summaryDate = $this->scenario['summary_date'];
        $gaId = $this->scenario['ga_id'];
        $payoutId = $this->scenario['payout_id'];

        $this->callStoredProcedure('SP_calc_shareholder_temp', [$summaryDate, $gaId, $payoutId]);
        $this->callStoredProcedure('SP_calc_shareholder', [$summaryDate, $gaId, $payoutId]);
        $this->callStoredProcedure('SP_shareholder_payout', [$summaryDate, $gaId, $payoutId]);
    }

    /**
     * @param  list<int|string>  $parameters
     */
    private function callStoredProcedure(string $procedureName, array $parameters): void
    {
        $placeholders = implode(', ', array_fill(0, count($parameters), '?'));
        $statement = DB::connection()->getPdo()->prepare("CALL {$procedureName}({$placeholders})");
        $statement->execute($parameters);

        do {
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['p_TGMessage']) && str_contains((string) $row['p_TGMessage'], '異常')) {
                    $this->fail("{$procedureName} 失敗：{$row['p_TGMessage']}");
                }
            }
        } while ($statement->nextRowset());
    }

    private function assertCompanySlotTemps(): void
    {
        $row = DB::table('summary_shareholder_temps')
            ->where('summary_time', $this->scenario['start_time'])
            ->where('agent_group_id', $this->scenario['agent_group_id'])
            ->where('shareholder_category_id', self::SHAREHOLDER_CATEGORY_ID)
            ->first();

        $this->assertNotNull($row, 'summary_shareholder_temps 應有 SLOT 公司彙總列');

        $this->assertEqualsWithDelta(1_000_000.0, (float) $row->turnover, self::EXPECTED_DELTA);
        $this->assertEqualsWithDelta(29_000.0, (float) $row->nett_profit, self::EXPECTED_DELTA);
        $this->assertSame(130, (int) $row->share_point);
    }

    private function assertChildAgentSlotShareholders(): void
    {
        $startTime = $this->scenario['start_time'];

        foreach (self::CHILD_AGENT_SLOT_EXPECTATIONS as $agentKey => $expected) {
            $agentId = $this->scenario['agent_ids'][$agentKey];

            $row = DB::table('summary_shareholders')
                ->where('summary_time', $startTime)
                ->where('agent_id', $agentId)
                ->where('shareholder_category_id', self::SHAREHOLDER_CATEGORY_ID)
                ->first();

            $this->assertNotNull($row, "summary_shareholders 應有 {$agentKey} SLOT 列");

            $this->assertEqualsWithDelta((float) $expected['turnover'], (float) $row->direct_turnover, self::EXPECTED_DELTA);
            $this->assertEqualsWithDelta((float) $expected['direct_rate'], (float) $row->direct_rate, self::EXPECTED_DELTA);
            $this->assertEqualsWithDelta((float) $expected['direct_nett_profit'], (float) $row->direct_nett_profit, self::EXPECTED_DELTA);

            if ($this->columnExists('summary_shareholders', 'direct_win_nett_profit')) {
                $this->assertEqualsWithDelta(0.0, (float) ($row->direct_win_nett_profit ?? 0), self::EXPECTED_DELTA);
            }
        }
    }

    /**
     * @param  array<int, string>  $balancesBefore
     */
    private function assertShareholderPayoutIncreasesChildBalances(array $balancesBefore): void
    {
        $this->assertSame(
            1,
            (int) DB::table('shareholder_payout_logs')->where('id', $this->scenario['payout_id'])->value('status'),
            '派彩完成後 shareholder_payout_logs.status 應為 1（已派獎）'
        );

        foreach (self::CHILD_AGENT_SLOT_EXPECTATIONS as $agentKey => $expected) {
            $agentId = $this->scenario['agent_ids'][$agentKey];
            $expectedPayout = (float) $expected['direct_nett_profit'];
            $balanceBefore = (float) ($balancesBefore[$agentId] ?? 0);
            $balanceAfter = (float) DB::table('agents')->where('id', $agentId)->value('balance');

            $this->assertEqualsWithDelta(
                $balanceBefore + $expectedPayout,
                $balanceAfter,
                self::EXPECTED_DELTA,
                "{$agentKey} 主錢包應增加 direct_nett_profit"
            );

            $this->assertGreaterThan(
                0,
                DB::table('transaction_log_agents')
                    ->where('to_agent_id', $agentId)
                    ->where('type', self::TRANSACTION_TYPE_SHAREHOLDER)
                    ->count(),
                "{$agentKey} 應有分桶派彩 transaction_log_agents（type=5）"
            );
        }
    }

    private function assertTotalPayoutMatchesV2BetOnlyEquivalent(): void
    {
        $startTime = $this->scenario['start_time'];
        $total = (float) DB::table('summary_shareholders')
            ->where('summary_time', $startTime)
            ->where('shareholder_category_id', self::SHAREHOLDER_CATEGORY_ID)
            ->whereIn('agent_id', array_values($this->scenario['agent_ids']))
            ->sum('direct_nett_profit');

        $this->assertEqualsWithDelta(22_307.7, $total, self::EXPECTED_DELTA);
    }

    /**
     * 依試算表格式印出各代理分桶明細（寫入 STDERR，跑測試時可直接看）。
     *
     * @param  array<int, string>  $balancesBefore
     */
    protected function dumpAgentShareholderSpreadsheetSummary(array $balancesBefore): void
    {
        $this->assertNotNull($this->scenario, '請先執行 seedShareholderScenario()');

        $startTime = $this->scenario['start_time'];
        $gaSharePoint = (int) $this->scenario['ga_share_point'];
        $categoryId = $this->scenario['shareholder_category_id'];
        $companyTotals = $this->loadCompanyShareholderTotals($startTime);
        $gaCost = $this->loadGaSettlementCost($startTime, (int) $this->scenario['ga_id']);
        $categoryRatios = $this->loadShareholderCategoryRatios($categoryId);
        $betPct = (int) round($categoryRatios['bet_pool_ratio'] * 100);
        $winPct = (int) round($categoryRatios['commission_pool_ratio'] * 100);

        fwrite(STDERR, PHP_EOL . '========== 分桶結算｜各代理試算表（1代 · 100/0 · SLOT）==========' . PHP_EOL);
        fwrite(STDERR, sprintf(
            'summary_date=%s  start_time=%s  總代=%d  payout_log=%d  總代股份=%d',
            $this->scenario['summary_date'],
            $startTime,
            $this->scenario['ga_id'],
            $this->scenario['payout_id'],
            $gaSharePoint
        ) . PHP_EOL);

        $tableRows = [];
        $grandTurnover = 0.0;
        $grandTurnoverCost = 0.0;
        $grandPerformanceCost = 0.0;
        $grandNetProfit = 0.0;
        $grandNetWin = 0.0;
        $grandBetShare = 0.0;
        $grandWinShare = 0.0;
        $grandTotal = 0.0;

        foreach (self::AGENT_DISPLAY_NAMES as $agentKey => $agentLabel) {
            $agentId = $this->scenario['agent_ids'][$agentKey];
            $sharePoint = (int) DB::table('agent_personal_settings')
                ->where('agent_id', $agentId)
                ->where('setting_id', self::PERSONAL_SHARE_POINT)
                ->value('value');
            $row = $this->loadAgentShareholderSpreadsheetRow(
                startTime: $startTime,
                agentId: $agentId,
                categoryId: $categoryId,
                gaCost: $gaCost,
                agentSharePoint: $sharePoint,
                gaSharePoint: $gaSharePoint,
                betPoolRatio: $categoryRatios['bet_pool_ratio'],
                commissionPoolRatio: $categoryRatios['commission_pool_ratio'],
            );

            $grandTurnover += $row['turnover'];
            $grandTurnoverCost += $row['turnover_cost'];
            $grandPerformanceCost += $row['performance_cost'];
            $grandNetProfit += $row['net_profit'];
            $grandNetWin += $row['net_win'];
            $grandBetShare += $row['bet_share'];
            $grandWinShare += $row['win_share'];
            $grandTotal += $row['row_total'];

            $tableRows[] = $this->buildShareholderSpreadsheetTableRow(
                agentLabel: $agentLabel,
                sharePointLabel: sprintf('%d/%d', $sharePoint, $gaSharePoint),
                row: $row,
            );
        }

        $tableRows[] = $this->buildShareholderSpreadsheetTableRow(
            agentLabel: 'subtotal',
            sharePointLabel: '—',
            row: [
                'turnover' => $grandTurnover,
                'turnover_cost' => $grandTurnoverCost,
                'performance_cost' => $grandPerformanceCost,
                'turnover_rate' => $companyTotals['turnover'] > 0 ? ($grandTurnover / $companyTotals['turnover']) : 0.0,
                'net_profit' => $grandNetProfit,
                'net_profit_rate' => $companyTotals['net_win'] > 0 ? ($grandNetWin / $companyTotals['net_win']) : 0.0,
                'bet_share' => $grandBetShare,
                'win_share' => $grandWinShare,
            ],
        );

        $this->table(
            [
                '代理',
                '股份',
                '营业额',
                '营成本',
                '绩成本',
                '营%',
                '净利润',
                '绩%',
                "营分{$betPct}%",
                "绩分{$winPct}%",
                '总收益',
            ],
            $tableRows,
        );

        $lines = [''];
        foreach (self::AGENT_DISPLAY_NAMES as $agentKey => $agentLabel) {
            $agentId = $this->scenario['agent_ids'][$agentKey];
            $balanceBefore = (float) ($balancesBefore[$agentId] ?? 0);
            $balanceAfter = (float) DB::table('agents')->where('id', $agentId)->value('balance');
            $lines[] = sprintf(
                '  %s 派彩後主錢包: %.2f → %.2f（Δ %+.2f）',
                $agentLabel,
                $balanceBefore,
                $balanceAfter,
                $balanceAfter - $balanceBefore
            );
        }

        $lines[] = '================================================';
        $lines[] = '';

        fwrite(STDERR, implode(PHP_EOL, $lines));
    }

    /**
     * @return array{
     *     turnover: float,
     *     turnover_cost: float,
     *     performance_cost: float,
     *     turnover_rate: float,
     *     net_profit: float,
     *     net_profit_rate: float,
     *     net_win: float,
     *     bet_share: float,
     *     win_share: float,
     *     row_total: float
     * }
     */
    private function loadAgentShareholderSpreadsheetRow(
        string $startTime,
        int $agentId,
        int $categoryId,
        float $gaCost,
        int $agentSharePoint,
        int $gaSharePoint,
        float $betPoolRatio,
        float $commissionPoolRatio,
    ): array {
        $shareRow = DB::table('summary_shareholders')
            ->where('summary_time', $startTime)
            ->where('agent_id', $agentId)
            ->where('shareholder_category_id', $categoryId)
            ->first();

        $winloseRow = DB::table('summary_shareholder_winloses as ssw')
            ->join('shareholder_category_settings as scs', function ($join) use ($categoryId): void {
                $join->on('scs.vendor_id', '=', 'ssw.vendor_id')
                    ->where('scs.shareholder_category_id', '=', $categoryId)
                    ->where('scs.status', '=', 1)
                    ->where(function ($query): void {
                        $query->whereColumn('scs.game_type_id', 'ssw.game_type_id')
                            ->orWhere('scs.game_type_id', '=', 0);
                    });
            })
            ->where('ssw.summary_time', $startTime)
            ->where('ssw.agent_id', $agentId)
            ->where('ssw.vendor_id', '>', 0)
            ->selectRaw('ROUND(SUM(ssw.game_profit), 4) AS game_profit, ROUND(SUM(ssw.game_spending), 4) AS game_spending')
            ->first();

        $betShare = (float) ($shareRow->direct_nett_profit ?? 0);
        $winShare = $this->columnExists('summary_shareholders', 'direct_win_nett_profit')
            ? (float) ($shareRow->direct_win_nett_profit ?? 0)
            : 0.0;
        $turnoverRate = (float) ($shareRow->direct_rate ?? 0);
        $performanceRate = $this->columnExists('summary_shareholders', 'direct_win_rate')
            ? (float) ($shareRow->direct_win_rate ?? 0)
            : (float) ($shareRow->direct_rate ?? 0);
        $costs = $this->calculateAgentShareholderCosts(
            gaCost: $gaCost,
            agentSharePoint: $agentSharePoint,
            gaSharePoint: $gaSharePoint,
            betPoolRatio: $betPoolRatio,
            commissionPoolRatio: $commissionPoolRatio,
            turnoverRate: $turnoverRate,
            performanceRate: $performanceRate,
        );

        return [
            'turnover' => (float) ($shareRow->direct_turnover ?? 0),
            'turnover_cost' => $costs['turnover_cost'],
            'performance_cost' => $costs['performance_cost'],
            'turnover_rate' => $turnoverRate,
            'net_profit' => (float) ($winloseRow->game_profit ?? 0),
            'net_profit_rate' => $performanceRate,
            'net_win' => $this->columnExists('summary_shareholders', 'direct_win')
                ? (float) ($shareRow->direct_win ?? 0)
                : 0.0,
            'bet_share' => $betShare,
            'win_share' => $winShare,
            'row_total' => $betShare + $winShare,
        ];
    }

    private function loadGaSettlementCost(string $startTime, int $gaId): float
    {
        return (float) (DB::table('summary_shareholder_winloses')
            ->where('summary_time', $startTime)
            ->where('agent_id', $gaId)
            ->where('memo', 'settlement-cost')
            ->value('game_profit') ?? 0);
    }

    /**
     * @return array{bet_pool_ratio: float, commission_pool_ratio: float}
     */
    private function loadShareholderCategoryRatios(int $categoryId): array
    {
        $row = DB::table('shareholder_categories')
            ->where('id', $categoryId)
            ->first(['bet_ratio', 'commission_ratio']);

        $betRatio = (int) ($row->bet_ratio ?? 0);
        $commissionRatio = (int) ($row->commission_ratio ?? 0);
        $ratioSum = $betRatio + $commissionRatio;

        return [
            'bet_pool_ratio' => $ratioSum > 0 ? $betRatio / $ratioSum : 1.0,
            'commission_pool_ratio' => $ratioSum > 0 ? $commissionRatio / $ratioSum : 0.0,
        ];
    }

    /**
     * 代理營業額成本 = 總代成本 × 代理股份/總代股份 × 總代营业额分润% × 代理营业额占比
     * 代理業績成本 = 總代成本 × 代理股份/總代股份 × 總代業績分润% × 代理業績占比
     *
     * @return array{turnover_cost: float, performance_cost: float}
     */
    private function calculateAgentShareholderCosts(
        float $gaCost,
        int $agentSharePoint,
        int $gaSharePoint,
        float $betPoolRatio,
        float $commissionPoolRatio,
        float $turnoverRate,
        float $performanceRate,
    ): array {
        $shareFactor = $gaSharePoint > 0 ? $agentSharePoint / $gaSharePoint : 0.0;

        return [
            'turnover_cost' => $gaCost * $shareFactor * $betPoolRatio * $turnoverRate,
            'performance_cost' => $gaCost * $shareFactor * $commissionPoolRatio * $performanceRate,
        ];
    }

    /**
     * @return array{turnover: float, net_win: float}
     */
    private function loadCompanyShareholderTotals(string $startTime): array
    {
        $select = ['turnover', 'nett_profit'];
        if ($this->columnExists('summary_shareholder_temps', 'net_win')) {
            $select[] = 'net_win';
        }

        $row = DB::table('summary_shareholder_temps')
            ->where('summary_time', $startTime)
            ->where('agent_group_id', $this->scenario['agent_group_id'])
            ->where('shareholder_category_id', $this->scenario['shareholder_category_id'])
            ->first($select);

        return [
            'turnover' => (float) ($row->turnover ?? 0),
            'net_win' => (float) ($row->net_win ?? $row->nett_profit ?? 0),
        ];
    }

    /**
     * @param  array{
     *     turnover: float,
     *     turnover_cost: float,
     *     performance_cost: float,
     *     turnover_rate: float,
     *     net_profit: float,
     *     net_profit_rate: float,
     *     bet_share: float,
     *     win_share: float,
     *     row_total?: float
     * }  $row
     * @return array<int, string>
     */
    private function buildShareholderSpreadsheetTableRow(
        string $agentLabel,
        string $sharePointLabel,
        array $row,
    ): array {
        $rowTotal = $row['row_total'] ?? ($row['bet_share'] + $row['win_share']);

        return [
            $agentLabel,
            $sharePointLabel,
            $this->formatSpreadsheetAmount($row['turnover']),
            $this->formatSpreadsheetAmount($row['turnover_cost']),
            $this->formatSpreadsheetAmount($row['performance_cost']),
            $this->formatSpreadsheetPercent($row['turnover_rate']) . '%',
            $this->formatSpreadsheetAmount($row['net_profit']),
            $this->formatSpreadsheetPercent($row['net_profit_rate']) . '%',
            $this->formatSpreadsheetAmount($row['bet_share']),
            $this->formatSpreadsheetAmount($row['win_share']),
            $this->formatSpreadsheetAmount($rowTotal),
        ];
    }

    private function formatSpreadsheetAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function formatSpreadsheetPercent(float $rate): string
    {
        return number_format($rate * 100, 2, '.', '');
    }

    /**
     * @return array<int, string>
     */
    private function captureAgentBalances(): array
    {
        $rows = DB::table('agents')
            ->whereIn('id', array_values($this->scenario['agent_ids']))
            ->pluck('balance', 'id');

        return array_map(static fn ($balance): string => (string) $balance, $rows->all());
    }

    /**
     * @return array{
     *     summary_date: string,
     *     ga_id: int,
     *     agent_group_id: int,
     *     payout_id: int,
     *     start_time: string,
     *     shareholder_category_id: int,
     *     agent_ids: array<string, int>,
     *     vendor_id: int,
     *     ga_share_point: int
     * }
     */
    private function seedShareholderScenario(): array
    {
        $summaryDate = '2026-05-21';

        $ga = $this->createAgentAndDomain([
            'parent' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
            'balance' => self::GENERAL_AGENT_MAIN_BALANCE,
            'is_balance_unlimited' => 0,
        ]);

        $groupId = $ga->id;
        DB::table('agents')->where('id', $ga->id)->update([
            'customized_setting_group_id' => $groupId,
            'hierarchy_path' => "/1/{$ga->id}",
            'hierarchy' => "[-1,1,{$ga->id},-2]",
        ]);
        $ga->refresh();

        $this->seedShareholderCustomizedSettings($ga);
        $this->createAgentPersonalSetting($ga, self::PERSONAL_SHARE_POINT, '130');
        $this->ensureRootAgentSharePoint();

        $vendor = Vendor::factory()->create();
        $definition = self::SLOT_CATEGORY_DEFINITION;

        $vendor->games()->save(new Game([
            'type_id' => $definition['game_type_id'],
            'name' => 'shareholder-v1-e2e-slot',
            'game_type' => 'SLOT',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid/game.png',
            'admin' => 0,
        ]));

        $this->ensureAgentGroupShareholderCategory(agentGroupId: $groupId, vendorId: $vendor->id, gameTypeId: $definition['game_type_id']);
        $this->ensureGlobalShareholderCategoryTemplate(gameTypeId: $definition['game_type_id']);

        $agentIds = [];
        $childConfigs = [
            'agent_a' => ['turnover' => '600000.0000', 'game_profit' => '15000.0000', 'game_spending' => '582000.0000'],
            'agent_b' => ['turnover' => '300000.0000', 'game_profit' => '25000.0000', 'game_spending' => '291000.0000'],
            'agent_c' => ['turnover' => '100000.0000', 'game_profit' => '-10000.0000', 'game_spending' => '97000.0000'],
        ];

        foreach ($childConfigs as $agentKey => $metrics) {
            $child = Agent::factory()->create([
                'parent' => $ga->id,
                'role_id' => RoleEnum::AGENT->value,
                'customized_setting_group_id' => $groupId,
                'cash_flow_group_id' => $ga->cash_flow_group_id,
                'organization_id' => $ga->organization_id,
                'host_id' => 0,
                'admin' => 1,
                'balance' => '0.0000',
                'is_balance_unlimited' => 0,
            ]);

            $child->hierarchy_path = "/1/{$ga->id}/{$child->id}";
            $child->hierarchy = "[-1,1,{$ga->id},{$child->id},-2]";
            $child->save();

            $this->createAgentPersonalSetting($child, self::PERSONAL_SHARE_POINT, '100');
            $agentIds[$agentKey] = $child->id;

            $this->insertShareholderWinlose(
                startTime: $this->shareholderStartTime($summaryDate),
                agentId: $child->id,
                vendorId: $vendor->id,
                gameTypeId: self::SLOT_CATEGORY_DEFINITION['game_type_id'],
                turnover: $metrics['turnover'],
                gameSpending: $metrics['game_spending'],
                gameProfit: $metrics['game_profit'],
            );
        }

        $startTime = $this->shareholderStartTime($summaryDate);

        $this->insertGeneralAgentTemplateWinlose(
            startTime: $startTime,
            generalAgentId: $ga->id,
            gameTypeId: self::SLOT_CATEGORY_DEFINITION['game_type_id'],
        );

        $this->insertShareholderSettlementCost(
            startTime: $startTime,
            generalAgentId: $ga->id,
            vendorId: $vendor->id,
            gameTypeId: self::SLOT_CATEGORY_DEFINITION['game_type_id'],
            gameProfit: self::SETTLEMENT_GAME_PROFIT,
        );

        $payoutId = (int) DB::table('shareholder_payout_logs')->insertGetId([
            'summary_time' => $startTime,
            'agent_group_id' => $groupId,
            'status' => 0,
            'memo' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        return [
            'summary_date' => $summaryDate,
            'ga_id' => $ga->id,
            'agent_group_id' => $groupId,
            'payout_id' => $payoutId,
            'start_time' => $startTime,
            'shareholder_category_id' => self::SHAREHOLDER_CATEGORY_ID,
            'agent_ids' => $agentIds,
            'vendor_id' => $vendor->id,
            'ga_share_point' => 130,
        ];
    }

    private function ensureAgentGroupShareholderCategory(int $agentGroupId, int $vendorId, int $gameTypeId): void
    {
        DB::table('shareholder_category_settings')
            ->where('shareholder_category_id', self::SHAREHOLDER_CATEGORY_ID)
            ->delete();

        DB::table('shareholder_categories')
            ->where('id', self::SHAREHOLDER_CATEGORY_ID)
            ->delete();

        $definition = self::SLOT_CATEGORY_DEFINITION;

        DB::table('shareholder_categories')->insert([
            'id' => self::SHAREHOLDER_CATEGORY_ID,
            'agent_group_id' => $agentGroupId,
            'name' => $definition['name'],
            'code' => $definition['code'],
            'bet_ratio' => self::SHAREHOLDER_CATEGORY_BET_RATIO,
            'commission_ratio' => self::SHAREHOLDER_CATEGORY_COMMISSION_RATIO,
            'sort' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        DB::table('shareholder_category_settings')->insert([
            'shareholder_category_id' => self::SHAREHOLDER_CATEGORY_ID,
            'vendor_id' => $vendorId,
            'game_type_id' => $gameTypeId,
            'calc_bet' => 1,
            'calc_win_lose' => 1,
            'memo' => '',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function ensureRootAgentSharePoint(): void
    {
        $exists = DB::table('agent_personal_settings')
            ->where('agent_id', 1)
            ->where('setting_id', self::PERSONAL_SHARE_POINT)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('agent_personal_settings')->insert([
            'agent_id' => 1,
            'setting_id' => self::PERSONAL_SHARE_POINT,
            'sub_category' => '',
            'value' => '130',
            'created_by' => 0,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureGlobalShareholderCategoryTemplate(int $gameTypeId): void
    {
        $exists = DB::table('shareholder_category_settings as scs')
            ->join('shareholder_categories as sc', 'sc.id', '=', 'scs.shareholder_category_id')
            ->where('sc.agent_group_id', 0)
            ->where('scs.vendor_id', 0)
            ->where('scs.game_type_id', $gameTypeId)
            ->exists();

        if ($exists) {
            return;
        }

        $templateCategoryId = (int) DB::table('shareholder_categories')->insertGetId([
            'agent_group_id' => 0,
            'name' => 'sh_v1_global_tpl_' . $gameTypeId,
            'code' => 'sh_v1_global_tpl_' . $gameTypeId,
            'bet_ratio' => self::SHAREHOLDER_CATEGORY_BET_RATIO,
            'commission_ratio' => self::SHAREHOLDER_CATEGORY_COMMISSION_RATIO,
            'sort' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        DB::table('shareholder_category_settings')->insert([
            'shareholder_category_id' => $templateCategoryId,
            'vendor_id' => 0,
            'game_type_id' => $gameTypeId,
            'calc_bet' => 1,
            'calc_win_lose' => 1,
            'memo' => '',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function insertGeneralAgentTemplateWinlose(string $startTime, int $generalAgentId, int $gameTypeId): void
    {
        DB::table('summary_shareholder_winloses')->insert([
            'summary_time' => $startTime,
            'agent_id' => $generalAgentId,
            'vendor_id' => 0,
            'game_type_id' => $gameTypeId,
            'turnover' => '0.0000',
            'winlose' => '0.0000',
            'game_spending' => '0.0000',
            'game_profit' => '0.0000',
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function insertShareholderSettlementCost(
        string $startTime,
        int $generalAgentId,
        int $vendorId,
        int $gameTypeId,
        string $gameProfit,
    ): void {
        DB::table('summary_shareholder_winloses')->insert([
            'summary_time' => $startTime,
            'agent_id' => $generalAgentId,
            'vendor_id' => $vendorId,
            'game_type_id' => $gameTypeId,
            'turnover' => '0.0000',
            'winlose' => '0.0000',
            'game_spending' => '0.0000',
            'game_profit' => $gameProfit,
            'memo' => 'settlement-cost',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function insertShareholderWinlose(
        string $startTime,
        int $agentId,
        int $vendorId,
        int $gameTypeId,
        string $turnover,
        string $gameSpending,
        string $gameProfit,
    ): void {
        DB::table('summary_shareholder_winloses')->insert([
            'summary_time' => $startTime,
            'agent_id' => $agentId,
            'vendor_id' => $vendorId,
            'game_type_id' => $gameTypeId,
            'turnover' => $turnover,
            'winlose' => '0.0000',
            'game_spending' => $gameSpending,
            'game_profit' => $gameProfit,
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function seedShareholderCustomizedSettings(Agent $generalAgent): void
    {
        $groupId = (int) $generalAgent->customized_setting_group_id;

        DB::table('agent_customized_settings')
            ->where('agent_group_id', $groupId)
            ->whereIn('setting_key', [
                self::SETTING_SHAREHOLDER_ON,
                self::SETTING_SHAREHOLDER_GENERAL_AGENT,
                self::SETTING_SHAREHOLDER_TYPE,
                self::SETTING_GENERAL_AGENT_SHARE_VALUE,
                self::SETTING_AGENCY_GAP,
                self::SETTING_SHARE_LIMIT_JSON,
                self::SETTING_COMPANY_PROFIT_SHARE_JSON,
            ])
            ->delete();

        $shareLimitJson = json_encode([
            ['max' => 130, 'min' => 90, 'gap' => 5],
            ['max' => 89, 'min' => 0, 'gap' => 1],
        ], JSON_THROW_ON_ERROR);

        $companyProfitShareJson = json_encode([
            'setting' => [
                ['max_sp' => 130, 'min_sp' => 129, 'p1' => 100, 'p2' => 0, 'p3' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_ON, '1');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_GENERAL_AGENT, (string) $generalAgent->username);
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_TYPE, '1');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_GENERAL_AGENT_SHARE_VALUE, '130');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_AGENCY_GAP, '0');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHARE_LIMIT_JSON, $shareLimitJson);
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_COMPANY_PROFIT_SHARE_JSON, $companyProfitShareJson);
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

    private function shareholderStartTime(string $summaryDateYmd): string
    {
        $row = DB::selectOne(
            'SELECT DATE_SUB(CAST(? AS DATE), INTERVAL 8 HOUR) AS t',
            [$summaryDateYmd]
        );

        return (string) $row->t;
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }
}
