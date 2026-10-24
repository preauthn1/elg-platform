<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use App\Enums\Agent\RoleEnum;
use App\Enums\Game\GameTypeEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 佔成流程綜合測試：自 {@see summary_bet_records} 起依序執行佔成相關 SP，最後驗證子代理 {@see agents.balance} 增加。
 *
 * 參考 {@see docs/分桶與佔成/佔成流程.md}
 *
 * 各層代理／會員／佔成錢包設定：{@see AGENT_LAYER_CONFIG_FROM_LOWEST}（索引 0=L7 葉節 … 6=L1 總代）。
 * 遊戲分類 Live / Slot：{@see GAME_CATEGORY_DEFINITIONS}；每層每類別各一筆 {@see summary_bet_records}。
 * 調整單層輸贏：{@see setMemberWinLose()} / {@see setMemberBetCost()}；佔成比例見 {@see setProfitShareAgentPercentage()}。
 * 結算後驗證各層 Live/Slot「代理实际输赢」：{@see assertExpectedAgentActualProfitByLayer()}（誤差 ±0.1）。
 * 試算表完整欄位：{@see SpProfitShareEndToEndTest-reference.md}（同目錄）。
 *
 * 佔成比例：{@see setProfitShareAgentPercentage()} / {@see setProfitShareAgentPercentagesByLayer()}
 * / {@see setProfitShareAgentPercentageForAgent()}；seed 後可呼叫 {@see updateProfitShareAgentPercentages()} 改 DB。
 *
 * 派彩後檢視各代理實收：{@see dumpAgentProfitSharePayoutSummary()}（需先 {@see captureAgentBalances()}）
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/SpProfitShareEndToEndTest.php
 * ```
 */
class SpProfitShareEndToEndTest extends TestCase
{
    private const int AGENT_LAYER_COUNT = 7;

    private const float EXPECTED_ACTUAL_PROFIT_DELTA = 0.1;

    private const int PROFIT_SHARE_WALLET_TYPE_ID = 3;

    private const string DEFAULT_MEMBER_COST = '0.0000';

    private const string GENERAL_AGENT_MAIN_BALANCE = '1000000.0000';

    /** @var list<string> */
    private const array GAME_CATEGORY_KEYS = ['live', 'slot'];

    /**
     * 佔成分類（shareholder_categories + game.type_id）。
     *
     * @var array<string, array{name: string, code: string, game_type_id: int}>
     */
    private const array GAME_CATEGORY_DEFINITIONS = [
        'live' => [
            'name' => 'ps_e2e_live',
            'code' => 'live',
            'game_type_id' => GameTypeEnum::LIVE_CASINO->value,
        ],
        'slot' => [
            'name' => 'ps_e2e_slot',
            'code' => 'slot',
            'game_type_id' => GameTypeEnum::SLOTS->value,
        ],
    ];

    /**
     * 代理層級設定（由最低層到最高層：索引 0 = L7 葉節 … 6 = L1 總代）。
     *
     * categories.live / categories.slot：
     * - win_lose_amount：顧客輸贏 100%（正值＝會員輸、寫入 win_lose 為負；負值＝會員贏）
     * - direct_percentage / team_percentage：該分類 profit_share_agents（代理自身占成／吃下級占成）
     * - bet：未填時為 abs(win_lose_amount) × 2
     *
     * @var list<array{
     *     share_point: int,
     *     profit_share_wallet_balance: string,
     *     profit_share_wallet_unlimited: bool,
     *     categories: array<string, array{
     *         win_lose_amount: string,
     *         direct_percentage: int,
     *         team_percentage: int,
     *         expected_actual_profit: string
     *     }>
     * }>
     *
     * expected_actual_profit：{@see summary_profit_shares.profit} 預期值（±{@see EXPECTED_ACTUAL_PROFIT_DELTA}）。
     * 註解為試算表「代理实际输赢」對照；L7 與試算表一致。
     *
     * **L2／L3 與 reference 數字不同**：試算表「下級實際輸贏 5%」算法與 SP 不一致。
     * - **SP**（{@see SP_calc_profit_share}）：下級 5% 輸贏以**所有下級**（整棵子樹）為基礎計算。
     * - **Reference**（試算表 P2／P3）：P2 用**第一層代理**直屬會員輸贏算 5%；P3 用**下級的下級**會員輸贏另算 5%（分層拆開，非整棵子樹一次加總）。
     * 故 L2、L3 的 {@see expected_actual_profit} 以 SP 實際結果為準；欄位旁仍保留試算表對照值供 diff。
     */
    private const array AGENT_LAYER_CONFIG_FROM_LOWEST = [
        [
            'share_point' => 75,
            'profit_share_wallet_balance' => '50000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '100000.00', 'direct_percentage' => 70, 'team_percentage' => 40, 'expected_actual_profit' => '18421.05'],
                'slot' => ['win_lose_amount' => '150000.00', 'direct_percentage' => 80, 'team_percentage' => 30, 'expected_actual_profit' => '31578.95'],
            ],
        ],
        [
            'share_point' => 80,
            'profit_share_wallet_balance' => '50000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '-50000.00', 'direct_percentage' => 90, 'team_percentage' => 20, 'expected_actual_profit' => '-15260.08'], // 試算表 -15260.08
                'slot' => ['win_lose_amount' => '80000.00', 'direct_percentage' => 70, 'team_percentage' => 70, 'expected_actual_profit' => '65260.08'], // 試算表 65260.08
            ],
        ],
        [
            'share_point' => 85,
            'profit_share_wallet_balance' => '50000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '-50000.00', 'direct_percentage' => 60, 'team_percentage' => 10, 'expected_actual_profit' => '-17816.10'], // 試算表 -17816.10
                'slot' => ['win_lose_amount' => '60000.00', 'direct_percentage' => 50, 'team_percentage' => 60, 'expected_actual_profit' => '59496.58'], // 試算表 59496.58
            ],
        ],
        [
            'share_point' => 90,
            'profit_share_wallet_balance' => '50000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '100000.00', 'direct_percentage' => 100, 'team_percentage' => 70, 'expected_actual_profit' => '31328.96'], // 試算表 31328.96
                'slot' => ['win_lose_amount' => '30000.00', 'direct_percentage' => 50, 'team_percentage' => 80, 'expected_actual_profit' => '18671.04'], // 試算表 18671.04
            ],
        ],
        // L3（share_point 95）：下級 5% 輸贏 — SP 計所有下級；試算表 P3 只算下級的下級會員輸贏 → profit 與 reference 不同。
        [
            'share_point' => 95,
            'profit_share_wallet_balance' => '30000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '100000.00', 'direct_percentage' => 50, 'team_percentage' => 50, 'expected_actual_profit' => '18113.37'], // reference 19386.36
                'slot' => ['win_lose_amount' => '50000.00', 'direct_percentage' => 30, 'team_percentage' => 50, 'expected_actual_profit' => '11886.63'], // reference 10613.64
            ],
        ],
        // L2（share_point 100）：下級 5% 輸贏 — SP 計所有下級；試算表 P2 只算第一層代理會員輸贏 → profit 與 reference 不同。
        [
            'share_point' => 100,
            'profit_share_wallet_balance' => '200000.0000',
            'profit_share_wallet_unlimited' => false,
            'categories' => [
                'live' => ['win_lose_amount' => '100000.00', 'direct_percentage' => 50, 'team_percentage' => 50, 'expected_actual_profit' => '102606.40'], // reference 101969.90
                'slot' => ['win_lose_amount' => '30000.00', 'direct_percentage' => 100, 'team_percentage' => 50, 'expected_actual_profit' => '78553.36'], // reference 64689.85
            ],
        ],
        [
            'share_point' => 130,
            'profit_share_wallet_balance' => '1000000000.0000',
            'profit_share_wallet_unlimited' => true,
            'categories' => [
                'live' => ['win_lose_amount' => '0', 'direct_percentage' => 100, 'team_percentage' => 100, 'expected_actual_profit' => '0'],
                'slot' => ['win_lose_amount' => '0', 'direct_percentage' => 100, 'team_percentage' => 100, 'expected_actual_profit' => '0'],
            ],
        ],
    ];

    private const int SHAREHOLDER_CATEGORY_BET_RATIO = 60;

    private const int SHAREHOLDER_CATEGORY_COMMISSION_RATIO = 40;

    private const int SETTING_SHAREHOLDER_ON = 6100;

    private const int SETTING_SHAREHOLDER_GENERAL_AGENT = 6101;

    private const int SETTING_SHAREHOLDER_TYPE = 6102;

    private const int SETTING_GENERAL_AGENT_SHARE_VALUE = 8001;

    private const int SETTING_AGENCY_GAP = 8004;

    private const int SETTING_SHARE_LIMIT_JSON = 8006;

    private const int SETTING_COMPANY_PROFIT_SHARE_JSON = 8201;

    private const int PERSONAL_SHARE_POINT = 95001;

    /** @var array<string, mixed>|null */
    private ?array $scenario = null;

    private string $summaryDate = '';

    /**
     * 依層級（0=總代）覆寫 profit_share_agents；null 表示用 {@see defaultProfitShareAgentPercentagesByLayer()}。
     *
     * @var list<array<string, array{direct_percentage: int, team_percentage: int}>>|null
     */
    private ?array $profitShareAgentPercentagesByLayer = null;

    /**
     * 依 agent_id 覆寫（優先於層級設定）；可只指定單一分類 key。
     *
     * @var array<int, array<string, array{direct_percentage: int, team_percentage: int}>>
     */
    private array $profitShareAgentPercentagesByAgentId = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        foreach ([
            'COM_Send_Error_Message',
            'ADMIN_get_customized_setting',
            'SP_calc_profit_share_winlose',
            'SP_calc_profit_share_event_cost',
            'SP_calc_profit_share_payment_cost',
            'SP_calc_profit_share_temp',
            'SP_calc_profit_share',
            'SP_payout_profit_share',
        ] as $routine) {
            $this->ensureStoredProcedureExists($routine);
        }
    }

    protected function tearDown(): void
    {
        $this->purgeScenario();

        parent::tearDown();
    }

    /**
     * 刪掉本情境建立的夾具與 SP 為它產生的列。
     *
     * SP_payout_profit_share 內部有 START TRANSACTION／COMMIT，會把外層測試交易一併提交，
     * DatabaseTransactions 回滾不掉：7 層代理鏈 ＋ 7 個會員（ps_e2e_m0～m6）每跑一次就永久留在測試庫，
     * 汙染做全表統計的測試（AuditOrphanParentCommandTest 因此在 CI 隨機紅，本地測試庫實測殘留 273 個會員）。
     * 依 php.md「DDL／SP 測試視為不可回滾」：只刪自己建立、以 id 定位的列，不做無條件 delete。
     */
    private function purgeScenario(): void
    {
        if ($this->scenario === null) {
            return;
        }

        $agentIds = $this->scenario['agent_ids'];
        $memberIds = $this->scenario['member_ids'];
        $groupId = $this->scenario['agent_group_id'];
        $categoryIds = array_values($this->scenario['shareholder_category_ids']);

        // SP 產物
        foreach ([
            'summary_profit_shares',
            'summary_profit_share_winloses',
            'summary_profit_share_winlose_temps',
            'summary_profit_share_event_costs',
            'summary_profit_share_payment_costs',
            'wallet_transaction_log_agents',
        ] as $table) {
            DB::table($table)->whereIn('agent_id', $agentIds)->delete();
        }
        DB::table('transaction_log_agents')
            ->where(fn ($q) => $q->whereIn('from_agent_id', $agentIds)->orWhereIn('to_agent_id', $agentIds))
            ->delete();
        DB::table('summary_profit_share_temps')->where('agent_group_id', $groupId)->delete();
        DB::table('profit_share_settings')->where('agent_group_id', $groupId)->delete();
        DB::table('agent_temps')->where('general_agent_id', $this->scenario['ga_id'])->delete();
        DB::table('member_type_histories')->whereIn('member_id', $memberIds)->delete();
        DB::table('profit_share_payout_logs')->where('id', $this->scenario['payout_id'])->delete();

        // 自建夾具，先子後父
        DB::table('summary_bet_records')->whereIn('member_id', $memberIds)->delete();
        DB::table('members')->whereIn('id', $memberIds)->delete();
        foreach (['profit_share_agents', 'agent_wallets', 'agent_personal_settings', 'agent_domains'] as $table) {
            DB::table($table)->whereIn('agent_id', $agentIds)->delete();
        }
        DB::table('shareholder_category_settings')->whereIn('shareholder_category_id', $categoryIds)->delete();
        DB::table('shareholder_categories')->whereIn('id', $categoryIds)->delete();
        DB::table('games')->whereIn('id', $this->scenario['game_ids'])->delete();
        DB::table('vendors')->where('id', $this->scenario['vendor_id'])->delete();
        DB::table('agent_customized_settings')->where('agent_group_id', $groupId)->delete();
        DB::table('agents')->whereIn('id', $agentIds)->delete();

        $this->scenario = null;
    }

    #[Test]
    public function runs_all_profit_share_sps_from_summary_bet_records_and_increases_sub_agent_balance(): void
    {
        $this->scenario = $this->seedProfitShareScenario();

        $leafAgentId = $this->scenario['leaf_agent_id'];
        $balancesBefore = $this->captureAgentBalances();
        $balanceBefore = $balancesBefore[$leafAgentId] ?? '0.0000';

        $this->runProfitSharePipeline();

        $this->assertExpectedAgentActualProfitByLayer();

        $this->dumpAgentProfitSharePayoutSummary($balancesBefore);

        $leafProfitRow = DB::table('summary_profit_shares')
            ->where('summary_time', $this->scenario['start_time'])
            ->where('agent_id', $leafAgentId)
            ->first();

        $this->assertNotNull($leafProfitRow, 'summary_profit_shares 應有最下層代理列');
        $this->assertNotEquals(
            0,
            (float) $leafProfitRow->profit,
            sprintf(
                '最下層 profit 應非 0（direct=%s team=%s profit=%s）',
                $leafProfitRow->direct_profit,
                $leafProfitRow->team_profit,
                $leafProfitRow->profit
            )
        );

        $balanceAfter = (string) DB::table('agents')->where('id', $leafAgentId)->value('balance');

        $this->assertGreaterThan(
            (float) $balanceBefore,
            (float) $balanceAfter,
            '派彩後最下層代理 balance 應增加（可透過 setMemberWinLose 調整輸贏重測）'
        );

        $this->assertSame(
            1,
            (int) DB::table('profit_share_payout_logs')->where('id', $this->scenario['payout_id'])->value('status'),
            '派彩完成後 profit_share_payout_logs.status 應為 1（已派發）'
        );

        $this->assertGreaterThan(
            0,
            DB::table('transaction_log_agents')
                ->where('to_agent_id', $leafAgentId)
                ->where('type', 9)
                ->count(),
            'TotalProfit >= 0 時應寫入 transaction_log_agents'
        );
    }

    /**
     * 派彩前快照各代理主錢包 balance（agent_id => balance）。
     *
     * @return array<int, string>
     */
    protected function captureAgentBalances(): array
    {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        $rows = DB::table('agents')
            ->whereIn('id', $this->scenario['agent_ids'])
            ->pluck('balance', 'id');

        return array_map(static fn ($balance): string => (string) $balance, $rows->all());
    }

    /**
     * 印出全部代理佔成結算明細與派彩實收（寫入 STDERR，跑測試時可直接看）。
     *
     * 欄位說明：
     * - member_win_lose：該代理直屬會員 {@see summary_bet_records} 輸贏加總
     * - 下級剩餘：{@see summary_profit_shares.team_win_lose}（下級結算後剩餘往上流）
     * - direct% / team%：{@see profit_share_agents} 設定；總表多分類時以 Live/Slot 順序以 / 分隔
     * - 總輸贏：{@see summary_profit_shares} direct_profit + team_profit（權重前）
     * - 權重%：{@see summary_profit_shares.weighting}（封頂後 profit = 總輸贏 × 權重）
     * - direct / team / profit：{@see summary_profit_shares} 結算值
     * - 實發：{@see SP_payout_profit_share} 實際撥款欄位（profit 欄；負值走佔成錢包）
     * - 主錢包Δ / 佔成錢包Δ：派彩前後餘額差
     *
     * @param  array<int, string>  $balanceBeforeByAgent
     */
    protected function dumpAgentProfitSharePayoutSummary(array $balanceBeforeByAgent): void
    {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        $startTime = $this->scenario['start_time'];
        $gaId = $this->scenario['ga_id'];
        $agentIds = $this->scenario['agent_ids'];

        $agents = DB::table('agents as a')
            ->leftJoin('agent_personal_settings as aps', function ($join): void {
                $join->on('aps.agent_id', '=', 'a.id')
                    ->where('aps.setting_id', '=', self::PERSONAL_SHARE_POINT);
            })
            ->whereIn('a.id', $agentIds)
            ->orderBy('a.hierarchy_path')
            ->get([
                'a.id',
                'a.hierarchy_path',
                'a.balance',
                'aps.value as share_point',
            ])
            ->keyBy('id');

        $shareRows = DB::table('summary_profit_shares')
            ->where('summary_time', $startTime)
            ->whereIn('agent_id', $agentIds)
            ->selectRaw('agent_id')
            ->selectRaw('ROUND(SUM(team_win_lose), 4) AS team_win_lose')
            ->selectRaw('ROUND(SUM(direct_profit), 4) AS direct_profit')
            ->selectRaw('ROUND(SUM(team_profit), 4) AS team_profit')
            ->selectRaw('ROUND(SUM(direct_profit + team_profit), 4) AS gross_profit')
            ->selectRaw('ROUND(MAX(weighting), 8) AS weighting')
            ->selectRaw('ROUND(SUM(profit), 4) AS profit')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $shareRowsByCategory = DB::table('summary_profit_shares as sps')
            ->join('shareholder_categories as sc', 'sc.id', '=', 'sps.shareholder_category_id')
            ->where('sps.summary_time', $startTime)
            ->whereIn('sps.agent_id', $agentIds)
            ->selectRaw('sps.agent_id')
            ->selectRaw('sc.code AS category_code')
            ->selectRaw('ROUND(sps.team_win_lose, 4) AS team_win_lose')
            ->selectRaw('ROUND(sps.direct_rate * 100, 2) AS direct_percentage')
            ->selectRaw('ROUND(sps.team_rate * 100, 2) AS team_percentage')
            ->selectRaw('ROUND(sps.direct_profit, 4) AS direct_profit')
            ->selectRaw('ROUND(sps.team_profit, 4) AS team_profit')
            ->selectRaw('ROUND(sps.direct_profit + sps.team_profit, 4) AS gross_profit')
            ->selectRaw('ROUND(sps.weighting, 8) AS weighting')
            ->selectRaw('ROUND(sps.profit, 4) AS profit')
            ->get()
            ->groupBy('agent_id');

        $profitShareAgentRows = DB::table('profit_share_agents as psa')
            ->join('shareholder_categories as sc', 'sc.id', '=', 'psa.shareholder_category_id')
            ->whereIn('psa.agent_id', $agentIds)
            ->whereIn('psa.shareholder_category_id', array_values($this->scenario['shareholder_category_ids']))
            ->get(['psa.agent_id', 'sc.code AS category_code', 'psa.direct_percentage', 'psa.team_percentage'])
            ->groupBy('agent_id');

        $memberBetRows = DB::table('summary_bet_records as sbr')
            ->join('members as m', 'm.id', '=', 'sbr.member_id')
            ->join('games as g', 'g.id', '=', 'sbr.game_id')
            ->whereIn('m.parent', $agentIds)
            ->where('sbr.summary_time', $startTime)
            ->groupBy('m.parent')
            ->selectRaw('m.parent AS agent_id')
            ->selectRaw('ROUND(SUM(sbr.win_lose), 4) AS member_win_lose')
            ->get()
            ->keyBy('agent_id');

        $memberBetRowsByCategory = DB::table('summary_bet_records as sbr')
            ->join('members as m', 'm.id', '=', 'sbr.member_id')
            ->join('games as g', 'g.id', '=', 'sbr.game_id')
            ->whereIn('m.parent', $agentIds)
            ->where('sbr.summary_time', $startTime)
            ->groupBy('m.parent', 'g.type_id')
            ->selectRaw('m.parent AS agent_id')
            ->selectRaw('g.type_id AS game_type_id')
            ->selectRaw('ROUND(SUM(sbr.win_lose), 4) AS member_win_lose')
            ->get()
            ->groupBy('agent_id');

        $walletBalancesAfter = DB::table('agent_wallets')
            ->whereIn('agent_id', $agentIds)
            ->where('wallet_type_id', self::PROFIT_SHARE_WALLET_TYPE_ID)
            ->pluck('balance', 'agent_id');

        $profitWalletDeltaByAgent = [];
        foreach ($agentIds as $agentId) {
            $walletBefore = (float) ($this->scenario['wallet_balance_before'][$agentId] ?? 0);
            $walletAfter = (float) ($walletBalancesAfter[$agentId] ?? $walletBefore);
            $profitWalletDeltaByAgent[$agentId] = round($walletAfter - $walletBefore, 4);
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '========== 佔成派彩｜各代理結算與實收 ==========';
        $lines[] = sprintf(
            'summary_date=%s  start_time=%s  總代=%d  payout_log=%d',
            $this->summaryDate,
            $startTime,
            $gaId,
            $this->scenario['payout_id']
        );
        $lines[] = str_repeat('-', 168);
        $lines[] = sprintf(
            '%-4s %6s %12s %12s %8s %8s %12s %12s %12s %8s %12s %12s %12s %s',
            '層級',
            '佔成點',
            '會員輸贏',
            '下級剩餘',
            'direct%',
            'team%',
            'direct',
            'team',
            '總輸贏',
            '權重%',
            'profit',
            '主錢包Δ',
            '佔成錢包Δ',
            '備註'
        );
        $lines[] = str_repeat('-', 168);

        $totalPaidToSubs = 0.0;
        $totalMemberWinLose = 0.0;
        $totalTeamWinLose = 0.0;

        foreach ($agentIds as $layer => $agentId) {
            $agent = $agents[$agentId] ?? null;
            $share = $shareRows[$agentId] ?? null;
            $memberBet = $memberBetRows[$agentId] ?? null;

            $memberWinLose = (float) ($memberBet->member_win_lose ?? 0);
            $totalMemberWinLose += $memberWinLose;

            $teamWinLose = (float) ($share->team_win_lose ?? 0);
            $totalTeamWinLose += $teamWinLose;

            $direct = (float) ($share->direct_profit ?? 0);
            $team = (float) ($share->team_profit ?? 0);
            $grossProfit = (float) ($share->gross_profit ?? 0);
            $weighting = (float) ($share->weighting ?? 0);
            $profit = (float) ($share->profit ?? 0);
            $payoutAmount = $profit;

            $balanceBefore = (float) ($balanceBeforeByAgent[$agentId] ?? 0);
            $balanceAfter = (float) ($agent->balance ?? 0);
            $mainWalletDelta = round($balanceAfter - $balanceBefore, 4);

            $profitWalletDelta = $profitWalletDeltaByAgent[$agentId] ?? 0.0;

            $note = '';
            if ($agentId === $gaId) {
                $note = '總代（派彩 SP 排除，不撥款）';
                $payoutAmount = 0.0;
            } elseif ($payoutAmount > 0) {
                $note = '主錢包入帳';
                $totalPaidToSubs += $payoutAmount;
            } elseif ($payoutAmount < 0) {
                $note = '佔成錢包扣款';
            } else {
                $note = '無撥款';
            }

            $lines[] = sprintf(
                '%-4s %6s %12.4f %12.4f %8s %8s %12.4f %12.4f %12.4f %8s %12.4f %12.4f %12.4f %s',
                'L' . ($layer + 1),
                (string) ($agent->share_point ?? '-'),
                $memberWinLose,
                $teamWinLose,
                $this->formatProfitSharePercentagesByCategory($profitShareAgentRows, $agentId, 'direct_percentage'),
                $this->formatProfitSharePercentagesByCategory($profitShareAgentRows, $agentId, 'team_percentage'),
                $direct,
                $team,
                $grossProfit,
                $this->formatWeightingPercent($weighting),
                $agentId === $gaId ? 0.0 : $payoutAmount,
                $mainWalletDelta,
                $profitWalletDelta,
                $note
            );
        }

        $lines[] = str_repeat('-', 168);
        $lines[] = sprintf(
            '會員輸贏合計: %.4f  下級剩餘合計: %.4f',
            $totalMemberWinLose,
            $totalTeamWinLose
        );
        $lines[] = sprintf('子代理實發合計（profit 欄加總，不含總代）: %.4f', $totalPaidToSubs);
        $lines[] = '';
        $lines[] = '---------- 依遊戲分類（Live / Slot）----------';

        foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
            $gameTypeId = self::GAME_CATEGORY_DEFINITIONS[$categoryKey]['game_type_id'];
            $lines[] = sprintf('【%s】 game_type_id=%d', strtoupper($categoryKey), $gameTypeId);
            $lines[] = sprintf(
                '%-4s %12s %12s %8s %8s %12s %12s %12s %8s %12s',
                '層級',
                '會員輸贏',
                '下級剩餘',
                'direct%',
                'team%',
                'direct',
                'team',
                '總輸贏',
                '權重%',
                'profit'
            );

            foreach ($agentIds as $layer => $agentId) {
                $categoryShare = ($shareRowsByCategory[$agentId] ?? collect())
                    ->firstWhere('category_code', $categoryKey);
                $categoryMemberBet = ($memberBetRowsByCategory[$agentId] ?? collect())
                    ->firstWhere('game_type_id', $gameTypeId);

                $lines[] = sprintf(
                    '%-4s %12.4f %12.4f %8s %8s %12.4f %12.4f %12.4f %8s %12.4f',
                    'L' . ($layer + 1),
                    (float) ($categoryMemberBet->member_win_lose ?? 0),
                    (float) ($categoryShare->team_win_lose ?? 0),
                    $this->formatPercentageValue($categoryShare->direct_percentage ?? null),
                    $this->formatPercentageValue($categoryShare->team_percentage ?? null),
                    (float) ($categoryShare->direct_profit ?? 0),
                    (float) ($categoryShare->team_profit ?? 0),
                    (float) ($categoryShare->gross_profit ?? 0),
                    $this->formatWeightingPercent((float) ($categoryShare->weighting ?? 0)),
                    (float) ($categoryShare->profit ?? 0)
                );
            }

            $lines[] = '';
        }

        $lines[] = '================================================';
        $lines[] = '';

        fwrite(STDERR, implode(PHP_EOL, $lines));
    }

    /**
     * 一次設定全部層級的 direct / team 百分比（L1 總代 … L7 葉節）；未指定分類時兩類別同值。
     *
     * @param  list<array<string, array{direct_percentage: int, team_percentage: int}|array{direct: int, team: int}>>  $percentagesByLayer
     */
    protected function setProfitShareAgentPercentagesByLayer(array $percentagesByLayer): void
    {
        $this->profitShareAgentPercentagesByLayer = array_map(
            function (array $layerRow): array {
                $normalized = [];
                foreach ($layerRow as $categoryKey => $row) {
                    if (!is_string($categoryKey)) {
                        continue;
                    }
                    $normalized[$categoryKey] = $this->normalizeProfitShareAgentPercentageRow($row);
                }

                return $normalized;
            },
            $percentagesByLayer
        );
    }

    /**
     * 依層級設定單一代理的 profit_share_agents 百分比（0=總代）；未指定分類時 Live、Slot 同值。
     */
    protected function setProfitShareAgentPercentage(
        int $layerIndex,
        int $directPercentage,
        int $teamPercentage,
        ?string $categoryKey = null,
    ): void {
        if ($this->profitShareAgentPercentagesByLayer === null) {
            $this->profitShareAgentPercentagesByLayer = $this->defaultProfitShareAgentPercentagesByLayer();
        }

        $categoryKeys = $categoryKey !== null ? [$categoryKey] : self::GAME_CATEGORY_KEYS;

        foreach ($categoryKeys as $key) {
            $this->profitShareAgentPercentagesByLayer[$layerIndex][$key] = [
                'direct_percentage' => $directPercentage,
                'team_percentage' => $teamPercentage,
            ];
        }
    }

    /**
     * 依 agent_id 設定（優先於層級；需已知 agent id，通常 seed 後設定）。
     */
    protected function setProfitShareAgentPercentageForAgent(
        int $agentId,
        int $directPercentage,
        int $teamPercentage,
        ?string $categoryKey = null,
    ): void {
        $categoryKeys = $categoryKey !== null ? [$categoryKey] : self::GAME_CATEGORY_KEYS;

        foreach ($categoryKeys as $key) {
            $this->profitShareAgentPercentagesByAgentId[$agentId][$key] = [
                'direct_percentage' => $directPercentage,
                'team_percentage' => $teamPercentage,
            ];
        }
    }

    /**
     * seed 後更新 profit_share_agents 並重置結算中繼（便於調整比例後重跑 SP）。
     */
    protected function updateProfitShareAgentPercentages(
        int $directPercentage,
        int $teamPercentage,
        ?int $agentId = null,
        ?int $layerIndex = null,
        ?string $categoryKey = null,
    ): void {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        if ($agentId !== null) {
            $this->setProfitShareAgentPercentageForAgent($agentId, $directPercentage, $teamPercentage, $categoryKey);
            $targetAgentIds = [$agentId];
        } elseif ($layerIndex !== null) {
            $this->setProfitShareAgentPercentage($layerIndex, $directPercentage, $teamPercentage, $categoryKey);
            $targetAgentIds = [$this->scenario['agent_ids'][$layerIndex] ?? 0];
        } else {
            throw new \InvalidArgumentException('updateProfitShareAgentPercentages 需指定 agentId 或 layerIndex');
        }

        $categoryIds = $categoryKey !== null
            ? [(int) ($this->scenario['shareholder_category_ids'][$categoryKey] ?? 0)]
            : array_values($this->scenario['shareholder_category_ids']);

        foreach ($targetAgentIds as $targetAgentId) {
            if ($targetAgentId === 0) {
                continue;
            }

            foreach ($categoryIds as $categoryId) {
                if ($categoryId === 0) {
                    continue;
                }

                DB::table('profit_share_agents')
                    ->where('shareholder_category_id', $categoryId)
                    ->where('agent_id', $targetAgentId)
                    ->update([
                        'direct_percentage' => $directPercentage,
                        'team_percentage' => $teamPercentage,
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->resetProfitShareSettlementState();
    }

    /**
     * 動態調整葉節會員 {@see summary_bet_records.win_lose}（測試內或子類別可呼叫）。
     */
    protected function setMemberWinLose(string $winLose, string $categoryKey = 'live'): void
    {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        $recordId = $this->summaryBetRecordIdForLeafCategory($categoryKey);

        DB::table('summary_bet_records')
            ->where('id', $recordId)
            ->update(['win_lose' => $winLose, 'updated_at' => now()]);

        $this->resetProfitShareSettlementState();
    }

    /**
     * 動態調整葉節會員 {@see summary_bet_records.cost}（盤費／成本）。
     */
    protected function setMemberBetCost(string $cost, string $categoryKey = 'live'): void
    {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        $recordId = $this->summaryBetRecordIdForLeafCategory($categoryKey);

        DB::table('summary_bet_records')
            ->where('id', $recordId)
            ->update(['cost' => $cost, 'updated_at' => now()]);
    }

    private function summaryBetRecordIdForLeafCategory(string $categoryKey): int
    {
        $leafLayerIndex = self::AGENT_LAYER_COUNT - 1;
        $recordId = $this->scenario['summary_bet_record_ids_by_layer_and_category'][$leafLayerIndex][$categoryKey] ?? 0;

        $this->assertNotSame(0, $recordId, "找不到葉節 {$categoryKey} 的 summary_bet_records");

        return $recordId;
    }

    private function runProfitSharePipeline(): void
    {
        $date = $this->summaryDate;
        $gaId = $this->scenario['ga_id'];
        $payoutId = $this->scenario['payout_id'];

        DB::statement('CALL SP_calc_profit_share_winlose(?)', [$date]);

        $this->assertGreaterThan(
            0,
            DB::table('summary_profit_share_winloses')
                ->where('summary_time', $this->scenario['start_time'])
                ->where('agent_id', $this->scenario['leaf_agent_id'])
                ->count(),
            'SP_calc_profit_share_winlose 應寫入葉節代理業績'
        );

        DB::statement('CALL SP_calc_profit_share_event_cost(?)', [$date]);
        DB::statement('CALL SP_calc_profit_share_payment_cost(?)', [$date]);

        $payoutId = (int) DB::table('profit_share_payout_logs')
            ->where('agent_group_id', $this->scenario['agent_group_id'])
            ->where('summary_time', $this->scenario['start_time'])
            ->value('id');

        $this->scenario['payout_id'] = $payoutId;

        DB::statement('CALL SP_calc_profit_share_temp(?, ?, ?)', [$date, $gaId, $payoutId]);
        DB::statement('CALL SP_calc_profit_share(?, ?, ?)', [$date, $gaId, $payoutId]);

        $this->assertSame(
            3,
            (int) DB::table('profit_share_payout_logs')->where('id', $payoutId)->value('status'),
            'SP_calc_profit_share 結束後 status 應為 3（已結算）'
        );

        DB::table('profit_share_payout_logs')
            ->where('id', $payoutId)
            ->update(['status' => 4, 'updated_at' => now()]);

        DB::statement('CALL SP_payout_profit_share(?, ?, ?)', [$date, $gaId, $payoutId]);
    }

    /**
     * @return array{
     *     ga_id: int,
     *     leaf_agent_id: int,
     *     agent_group_id: int,
     *     payout_id: int,
     *     start_time: string,
     *     summary_bet_record_id: int,
     *     summary_bet_record_ids_by_layer: array<int, int>,
     *     leaf_member_id: int,
     *     agent_ids: list<int>,
     *     wallet_balance_before: array<int, string>,
     *     shareholder_category_ids: array<string, int>,
     *     shareholder_category_id: int
     * }
     */
    private function seedProfitShareScenario(): array
    {
        $this->summaryDate = sprintf('2026-05-%02d', random_int(16, 27));

        $ga = $this->createAgentAndDomain([
            'parent' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
            'balance' => self::GENERAL_AGENT_MAIN_BALANCE,
        ]);

        $groupId = $ga->id;
        DB::table('agents')->where('id', $ga->id)->update(['customized_setting_group_id' => $groupId]);
        $ga->refresh();

        $this->seedRequiredCustomizedSettings($ga);

        $agents = [$ga];
        $parent = $ga;
        $path = "/{$ga->id}";
        $hierarchy = "[-1,{$ga->id}";

        for ($i = 1; $i < self::AGENT_LAYER_COUNT; $i++) {
            $child = Agent::factory()->create([
                'parent' => $parent->id,
                'role_id' => RoleEnum::AGENT->value,
                'customized_setting_group_id' => $groupId,
                'cash_flow_group_id' => $ga->cash_flow_group_id,
                'organization_id' => $ga->organization_id,
                'host_id' => 0,
                'admin' => 1,
                'balance' => '0.0000',
                'is_balance_unlimited' => 0,
            ]);

            $path .= "/{$child->id}";
            $hierarchy .= ",{$child->id}";
            $child->hierarchy_path = $path;
            $child->hierarchy = $hierarchy . ',-2]';
            $child->save();

            $agents[] = $child;
            $parent = $child;
        }

        foreach ($agents as $layerIndex => $agent) {
            $this->createAgentPersonalSetting(
                $agent,
                self::PERSONAL_SHARE_POINT,
                (string) $this->layerConfigRow($layerIndex)['share_point']
            );
        }

        $vendor = Vendor::factory()->create();
        DB::table('vendors')->where('id', $vendor->id)->update(['calc_on_profit_share' => 1]);

        $gamesByCategory = [];
        $shareholderCategoryIds = [];
        $sort = 0;

        foreach (self::GAME_CATEGORY_DEFINITIONS as $categoryKey => $definition) {
            $gamesByCategory[$categoryKey] = $vendor->games()->save(new Game([
                'type_id' => $definition['game_type_id'],
                'name' => 'profit-share-e2e-' . $categoryKey,
                'game_type' => strtoupper($categoryKey),
                'status' => 1,
                'open_window' => 0,
                'image' => 'https://example.invalid/game.png',
                'admin' => 0,
            ]));

            $categoryId = (int) DB::table('shareholder_categories')->insertGetId([
                'agent_group_id' => $groupId,
                'name' => $definition['name'],
                'code' => $definition['code'],
                'bet_ratio' => self::SHAREHOLDER_CATEGORY_BET_RATIO,
                'commission_ratio' => self::SHAREHOLDER_CATEGORY_COMMISSION_RATIO,
                'sort' => $sort,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'admin' => 0,
            ]);
            $sort++;

            $shareholderCategoryIds[$categoryKey] = $categoryId;

            DB::table('shareholder_category_settings')->insert([
                'shareholder_category_id' => $categoryId,
                'vendor_id' => $vendor->id,
                'game_type_id' => $definition['game_type_id'],
                'calc_bet' => 1,
                'calc_win_lose' => 1,
                'memo' => '',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'admin' => 0,
            ]);
        }

        foreach ($agents as $layerIndex => $agent) {
            foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
                $percentages = $this->resolveProfitShareAgentPercentages(
                    agentId: $agent->id,
                    layerIndex: $layerIndex,
                    categoryKey: $categoryKey,
                );

                DB::table('profit_share_agents')->insert([
                    'shareholder_category_id' => $shareholderCategoryIds[$categoryKey],
                    'agent_id' => $agent->id,
                    'direct_percentage' => $percentages['direct_percentage'],
                    'team_percentage' => $percentages['team_percentage'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'admin' => 0,
                ]);
            }

            $wallet = $this->profitShareWalletForLayer($layerIndex);

            DB::table('agent_wallets')->insert([
                'agent_id' => $agent->id,
                'wallet_type_id' => self::PROFIT_SHARE_WALLET_TYPE_ID,
                'balance' => $wallet['balance'],
                'is_unlimited' => $wallet['is_unlimited'] ? 1 : 0,
                'status' => 1,
                'memo' => '',
                'created_at' => now(),
                'updated_at' => now(),
                'admin' => 0,
            ]);
        }

        $startTime = $this->profitShareStartTime($this->summaryDate);
        $leafAgent = $parent;
        $leafMember = null;
        $summaryBetRecordId = 0;
        $summaryBetRecordIdsByLayer = [];
        $summaryBetRecordIdsByLayerAndCategory = [];
        $memberIds = [];

        foreach ($agents as $layerIndex => $agent) {
            $unique = bin2hex(random_bytes(4));
            $member = $this->createMember($agent, [
                'previous_day_type' => 2,
                'username' => "ps_e2e_m{$layerIndex}_{$unique}",
                'nickname' => "ps_e2e_m{$layerIndex}",
            ]);
            $memberIds[] = $member->id;

            foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
                $winLose = $this->memberWinLoseForLayer($layerIndex, $categoryKey);
                $bet = $this->memberBetForLayer($layerIndex, $categoryKey);
                $game = $gamesByCategory[$categoryKey];

                $recordId = (int) DB::table('summary_bet_records')->insertGetId([
                    'summary_time' => $startTime,
                    'data_time' => $startTime,
                    'member_id' => $member->id,
                    'vendor_id' => $vendor->id,
                    'game_id' => $game->id,
                    'bet' => $bet,
                    'win_lose' => $winLose,
                    'rolling_chip' => '0.0000',
                    'commission' => '0.0000',
                    'jackpot' => '0.0000',
                    'record_count' => 1,
                    'cost' => self::DEFAULT_MEMBER_COST,
                    'correction' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'admin' => 0,
                ]);

                $summaryBetRecordIdsByLayerAndCategory[$layerIndex][$categoryKey] = $recordId;
                $summaryBetRecordIdsByLayer[$layerIndex] = $recordId;
            }

            if ($agent->id === $leafAgent->id) {
                $leafMember = $member;
                $summaryBetRecordId = $summaryBetRecordIdsByLayerAndCategory[$layerIndex]['live'];
            }
        }

        $payoutId = (int) DB::table('profit_share_payout_logs')->insertGetId([
            'summary_time' => $startTime,
            'agent_group_id' => $groupId,
            'status' => 0,
            'memo' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        $agentIds = array_map(static fn (Agent $a): int => $a->id, $agents);
        $walletBalanceBefore = DB::table('agent_wallets')
            ->whereIn('agent_id', $agentIds)
            ->where('wallet_type_id', self::PROFIT_SHARE_WALLET_TYPE_ID)
            ->pluck('balance', 'agent_id')
            ->all();

        return [
            'ga_id' => $ga->id,
            'leaf_agent_id' => $leafAgent->id,
            'agent_group_id' => $groupId,
            'payout_id' => $payoutId,
            'start_time' => $startTime,
            'summary_bet_record_id' => $summaryBetRecordId,
            'summary_bet_record_ids_by_layer' => $summaryBetRecordIdsByLayer,
            'summary_bet_record_ids_by_layer_and_category' => $summaryBetRecordIdsByLayerAndCategory,
            'leaf_member_id' => $leafMember instanceof Member ? $leafMember->id : 0,
            'member_ids' => $memberIds,
            'vendor_id' => $vendor->id,
            'game_ids' => array_map(static fn (Game $g): int => $g->id, array_values($gamesByCategory)),
            'agent_ids' => $agentIds,
            'wallet_balance_before' => array_map(static fn ($b): string => (string) $b, $walletBalanceBefore),
            'shareholder_category_ids' => $shareholderCategoryIds,
            'shareholder_category_id' => $shareholderCategoryIds['live'],
        ];
    }

    /**
     * @return list<array<string, array{direct_percentage: int, team_percentage: int}>>
     */
    private function defaultProfitShareAgentPercentagesByLayer(): array
    {
        $layers = [];
        foreach (self::AGENT_LAYER_CONFIG_FROM_LOWEST as $lowestOrder => $row) {
            $layerIndex = $this->layerIndexFromLowestOrder($lowestOrder);
            $layers[$layerIndex] = [];
            foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
                $categoryRow = $row['categories'][$categoryKey];
                $layers[$layerIndex][$categoryKey] = [
                    'direct_percentage' => $categoryRow['direct_percentage'],
                    'team_percentage' => $categoryRow['team_percentage'],
                ];
            }
        }

        return $layers;
    }

    private function layerIndexFromLowestOrder(int $lowestOrderIndex): int
    {
        return self::AGENT_LAYER_COUNT - 1 - $lowestOrderIndex;
    }

    /**
     * @return array{
     *     share_point: int,
     *     profit_share_wallet_balance: string,
     *     profit_share_wallet_unlimited: bool,
     *     categories: array<string, array{
     *         win_lose_amount: string,
     *         direct_percentage: int,
     *         team_percentage: int
     *     }>
     * }
     */
    private function layerConfigRow(int $layerIndex): array
    {
        $lowestOrder = self::AGENT_LAYER_COUNT - 1 - $layerIndex;

        return self::AGENT_LAYER_CONFIG_FROM_LOWEST[$lowestOrder];
    }

    /**
     * @return array{
     *     win_lose_amount: string,
     *     direct_percentage: int,
     *     team_percentage: int,
     *     expected_actual_profit: string
     * }
     */
    private function categoryConfigRow(int $layerIndex, string $categoryKey): array
    {
        return $this->layerConfigRow($layerIndex)['categories'][$categoryKey];
    }

    /**
     * @return array{balance: string, is_unlimited: bool}
     */
    private function profitShareWalletForLayer(int $layerIndex): array
    {
        $row = $this->layerConfigRow($layerIndex);

        return [
            'balance' => $row['profit_share_wallet_balance'],
            'is_unlimited' => $row['profit_share_wallet_unlimited'],
        ];
    }

    private function memberWinLoseForLayer(int $layerIndex, string $categoryKey): string
    {
        $amount = (float) $this->categoryConfigRow($layerIndex, $categoryKey)['win_lose_amount'];
        if ($amount == 0.0) {
            return '0.0000';
        }

        if ($amount > 0) {
            return sprintf('-%.4f', $amount);
        }

        return sprintf('%.4f', abs($amount));
    }

    private function memberBetForLayer(int $layerIndex, string $categoryKey): string
    {
        $amount = abs((float) $this->categoryConfigRow($layerIndex, $categoryKey)['win_lose_amount']);
        if ($amount == 0.0) {
            return '0.0000';
        }

        return sprintf('%.4f', $amount * 2);
    }

    /**
     * @return array{direct_percentage: int, team_percentage: int}
     */
    private function resolveProfitShareAgentPercentages(int $agentId, int $layerIndex, string $categoryKey): array
    {
        if (isset($this->profitShareAgentPercentagesByAgentId[$agentId][$categoryKey])) {
            return $this->profitShareAgentPercentagesByAgentId[$agentId][$categoryKey];
        }

        $byLayer = $this->profitShareAgentPercentagesByLayer ?? $this->defaultProfitShareAgentPercentagesByLayer();

        return $byLayer[$layerIndex][$categoryKey] ?? [
            'direct_percentage' => 0,
            'team_percentage' => 0,
        ];
    }

    /**
     * @param  array{direct_percentage?: int, team_percentage?: int, direct?: int, team?: int}  $row
     * @return array{direct_percentage: int, team_percentage: int}
     */
    private function normalizeProfitShareAgentPercentageRow(array $row): array
    {
        return [
            'direct_percentage' => (int) ($row['direct_percentage'] ?? $row['direct'] ?? 0),
            'team_percentage' => (int) ($row['team_percentage'] ?? $row['team'] ?? 0),
        ];
    }

    private function resetProfitShareSettlementState(): void
    {
        // 只清本情境的 SP 產物：SP 的 COMMIT 會讓這裡的刪除永久生效，無條件 delete() 會清掉別人的資料
        if ($this->scenario === null) {
            return;
        }

        $agentIds = $this->scenario['agent_ids'];
        DB::table('summary_profit_share_winloses')->whereIn('agent_id', $agentIds)->delete();
        DB::table('summary_profit_shares')->whereIn('agent_id', $agentIds)->delete();
        DB::table('summary_profit_share_winlose_temps')->whereIn('agent_id', $agentIds)->delete();
        DB::table('agent_temps')->where('general_agent_id', $this->scenario['ga_id'])->delete();

        DB::table('profit_share_payout_logs')
            ->where('id', $this->scenario['payout_id'])
            ->update(['status' => 0, 'updated_at' => now()]);
    }

    /**
     * 依 {@see AGENT_LAYER_CONFIG_FROM_LOWEST} 的 expected_actual_profit 驗證各層 Live/Slot 代理实际输赢（summary_profit_shares.profit）。
     *
     * L2／L3 預期值對齊 SP 而非試算表：下級 5% 輸贏算法差異見該常數 docblock（SP 全下級 vs reference P2／P3 分層會員輸贏）。
     */
    protected function assertExpectedAgentActualProfitByLayer(): void
    {
        $this->assertNotNull($this->scenario, '請先執行 seedProfitShareScenario()');

        $startTime = $this->scenario['start_time'];
        $categoryIds = $this->scenario['shareholder_category_ids'];

        foreach ($this->scenario['agent_ids'] as $layerIndex => $agentId) {
            if ($layerIndex === 0) {
                continue;
            }

            $layerLabel = 'L' . ($layerIndex + 1);
            $layerTotalExpected = 0.0;
            $layerTotalActual = 0.0;

            foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
                $expected = (float) $this->categoryConfigRow($layerIndex, $categoryKey)['expected_actual_profit'];
                $categoryId = (int) $categoryIds[$categoryKey];

                $row = DB::table('summary_profit_shares')
                    ->where('summary_time', $startTime)
                    ->where('agent_id', $agentId)
                    ->where('shareholder_category_id', $categoryId)
                    ->first(['profit']);

                $this->assertNotNull(
                    $row,
                    sprintf('%s %s 應有 summary_profit_shares 列', $layerLabel, $categoryKey)
                );

                $actual = (float) $row->profit;
                $layerTotalExpected += $expected;
                $layerTotalActual += $actual;

                $this->assertEqualsWithDelta(
                    $expected,
                    $actual,
                    self::EXPECTED_ACTUAL_PROFIT_DELTA,
                    sprintf(
                        '%s %s 代理实际输赢不符（expected=%.4f actual=%.4f delta=%.4f）',
                        $layerLabel,
                        $categoryKey,
                        $expected,
                        $actual,
                        abs($actual - $expected)
                    )
                );
            }

            $this->assertEqualsWithDelta(
                $layerTotalExpected,
                $layerTotalActual,
                self::EXPECTED_ACTUAL_PROFIT_DELTA,
                sprintf(
                    '%s 代理实际输赢合計不符（expected=%.4f actual=%.4f delta=%.4f）',
                    $layerLabel,
                    $layerTotalExpected,
                    $layerTotalActual,
                    abs($layerTotalActual - $layerTotalExpected)
                )
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, \stdClass>>  $profitShareAgentRows
     */
    private function formatProfitSharePercentagesByCategory(
        \Illuminate\Support\Collection $profitShareAgentRows,
        int $agentId,
        string $field,
    ): string {
        $rowsByCode = collect($profitShareAgentRows->get($agentId, []))->keyBy('category_code');
        $parts = [];

        foreach (self::GAME_CATEGORY_KEYS as $categoryKey) {
            $row = $rowsByCode[$categoryKey] ?? null;
            $parts[] = $row !== null ? (string) (int) $row->{$field} : '-';
        }

        $unique = array_unique($parts);

        return count($unique) === 1 ? $parts[0] : implode('/', $parts);
    }

    private function formatPercentageValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $numeric = (float) $value;

        return rtrim(rtrim(sprintf('%.2f', $numeric), '0'), '.') . '%';
    }

    private function formatWeightingPercent(float $weighting): string
    {
        if ($weighting <= 0) {
            return '0%';
        }

        if ($weighting >= 1) {
            return '100%';
        }

        return rtrim(rtrim(sprintf('%.2f', $weighting * 100), '0'), '.') . '%';
    }

    private function seedRequiredCustomizedSettings(Agent $generalAgent): void
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
                ['max_sp' => 100, 'min_sp' => 96, 'p1' => 70, 'p2' => 5, 'p3' => 5],
                ['max_sp' => 95, 'min_sp' => 91, 'p1' => 70, 'p2' => 5, 'p3' => 0],
                ['max_sp' => 90, 'min_sp' => 0, 'p1' => 70, 'p2' => 0, 'p3' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_ON, '1');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_GENERAL_AGENT, (string) $generalAgent->username);
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_SHAREHOLDER_TYPE, '1');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_GENERAL_AGENT_SHARE_VALUE, '130');
        $this->insertAgentCustomizedSetting($groupId, self::SETTING_AGENCY_GAP, '5');
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

    private function profitShareStartTime(string $summaryDateYmd): string
    {
        $row = DB::selectOne(
            'SELECT DATE_SUB(CAST(? AS DATE), INTERVAL 8 HOUR) AS t',
            [$summaryDateYmd]
        );

        return (string) $row->t;
    }
}
