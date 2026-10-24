<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\SP\SP_summary_bet_record_team;
use App\Console\Commands\SummaryBetRecord\SP\SP_summary_event_record_by_rolling_chip;
use App\Console\Commands\SummaryBetRecord\SP\SP_summary_rolling_chip_record;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\Member;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SP → PHP 忠實移植的等值測試（target/tickets#4257）
 *
 * 舊系統 RD-Studio-01 每小時仍呼叫原 SP 對同一格刪除重建，PHP 移植版與 SP
 * 產出若有任何差異，雙軌期間兩邊會互相覆蓋造成數值閃爍——故以「同一 fixture
 * 下先跑 SP、清空產出、再跑 PHP，逐列比對」驗證等值；另錨定關鍵列的預期值，
 * 防兩邊同錯的空殼等值。
 *
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/SummaryProcedurePortEquivalenceTest.php
 */
class SummaryProcedurePortEquivalenceTest extends TestCase
{
    use DatabaseTransactions;

    /** 彙總 hour bucket（UTC）；+8h = 台北 2026-06-03 15:00 → 台北日 06-03 */
    private const string START = '2026-06-03 07:00:00';

    private const string END = '2026-06-03 08:00:00';

    /** 前一小時 bucket：鋪在此格的髒列必須存活，鎖住刪除足跡不得越窗 */
    private const string PREV_HOUR = '2026-06-03 06:00:00';

    /** 918 系整日格：DATE(START +8h) */
    private const string DAILY_BUCKET = '2026-06-03 00:00:00';

    /** 台北日（member_unlimit_cancels.date） */
    private const string SETTLEMENT_DATE = '2026-06-03';

    /** 第 1 層會員（有直屬注單，且當日 member_unlimit_cancels status=1 → bet 歸零） */
    private const int LEVEL1_MEMBER = 920001;

    /** 第 2 層會員（topper 指向 LEVEL1_MEMBER，注單滾算到上層） */
    private const int LEVEL2_MEMBER = 920002;

    /** 第 3 層會員（topper 指向 LEVEL2_MEMBER，讓 LEVEL1 的 bet_team／win_lose_team 非零） */
    private const int LEVEL3_MEMBER = 920003;

    /** 髒列專用會員：只出現在預鋪的產出列，驗證 DELETE 足跡（無需真實 member） */
    private const int STALE_MEMBER = 920099;

    private const int GAME_ID = 9;

    private const int DAILY_GAME_ID = 7;

    private Agent $agent;

    #[Test]
    public function test_team_summary_php_port_matches_stored_procedure(): void
    {
        $this->seedTeamFixture();
        $dirtyRows = $this->seedTeamDirtyRows();

        DB::statement('call SP_summary_bet_record_team(?)', [self::START]);
        $spRows = $this->teamRows();
        $spControl = $this->latestControlLogWindow();

        $this->assertCount(8, $spRows, 'SP 應產出 3 列小時格＋2 列整日格＋3 列存活髒列（空產出＝fixture 失效或 SP 靜默失敗）');

        // 錨定 DELETE 足跡：本格髒列（小時格非 918、整日格 918 系）必須被刪，
        // 窗外（前一小時）與跨格（小時格的 918 系、整日格的非 918）必須存活——
        // 刪錯範疇＝雙軌期兩邊互洗相鄰格資料
        $staleRows = collect($spRows)->filter(fn (array $row): bool => (int) $row['member_id'] === self::STALE_MEMBER);
        $this->assertCount(3, $staleRows, '髒列應剩 3 列存活（2 列本格髒列被刪除重建範疇涵蓋）');
        $this->assertNull(
            $staleRows->first(fn (array $row): bool => $row['summary_time'] === self::START && (int) $row['vendor_id'] === VendorIdEnum::PTSW->value),
            '小時格既有髒列（非 918 系）必須被刪除重建',
        );
        $this->assertNull(
            $staleRows->first(fn (array $row): bool => $row['summary_time'] === self::DAILY_BUCKET && (int) $row['vendor_id'] === VendorIdEnum::SCR->value),
            '整日格既有髒列（918 系）必須被刪除重建',
        );

        // 還原產出表到 SP 執行前的狀態（重鋪髒列），讓 PHP 版從同一起點執行，
        // 同時比對 INSERT 產物與 DELETE 足跡
        DB::table('summary_bet_records_team')
            ->whereIn('summary_time', [self::PREV_HOUR, self::START, self::DAILY_BUCKET])
            ->delete();
        DB::table('summary_bet_records_team')->insert($dirtyRows);

        (new SP_summary_bet_record_team)->write(self::START);

        $this->assertSame($spRows, $this->teamRows(), 'PHP 移植版與 SP 產出（含刪除足跡）必須逐列一致');
        $this->assertSame($spControl, $this->latestControlLogWindow(), '控制表統計窗口必須一致');

        // 錨定語意（防空殼等值）：
        // LEVEL1 會員列 = 自身直屬（MUC status=1 → bet 歸零、win_lose 保留）
        //               ＋ 下層 LEVEL2 的注單滾算進 direct 欄位
        //               ＋ 第三層 LEVEL3 經 LEVEL2 的 direct 滾算進 team 欄位
        $level1Row = collect($this->teamRows())->first(
            fn (array $row): bool => (int) $row['member_id'] === self::LEVEL1_MEMBER
                && $row['summary_time'] === self::START,
        );
        $this->assertNotNull($level1Row);
        $this->assertSame('0.0000', $level1Row['bet'], 'member_unlimit_cancels status=1 當日直屬 bet 必須歸零');
        $this->assertSame('5.0000', $level1Row['win_lose'], 'MUC 歸零僅及 bet，win_lose 保留');
        $this->assertSame('100.0000', $level1Row['bet_direct'], '下層會員注單必須滾算進上層 bet_direct');
        $this->assertSame('-10.0000', $level1Row['win_lose_direct']);
        $this->assertSame('200.0000', $level1Row['bet_team'], '第三層注單必須經 direct 滾算進最上層 bet_team');
        $this->assertSame('-20.0000', $level1Row['win_lose_team']);
    }

    #[Test]
    public function test_rolling_chip_php_port_matches_stored_procedure(): void
    {
        $this->seedRollingChipFixture();
        $dirtyRows = $this->seedRollingChipDirtyRows();

        DB::statement('call SP_summary_rolling_chip_record(?)', [self::START]);
        $spChips = $this->chipRows();
        $spEvents = $this->eventRows();
        $spAgentEvents = $this->agentEventRows();

        $this->assertNotEmpty($spChips, 'SP 應有滾碼彙總產出');
        $this->assertNotEmpty($spEvents, 'SP 應鏈式產出獎勵彙總（member 面）');
        $this->assertNotEmpty($spAgentEvents, 'SP 應鏈式產出獎勵彙總（agent 面）');

        // 錨定 DELETE 足跡：本格髒列必須被刪、前一小時髒列必須存活
        $staleChips = collect($spChips)->filter(fn (array $row): bool => (int) $row['member_id'] === self::STALE_MEMBER);
        $this->assertCount(1, $staleChips, '滾碼髒列應只剩前一小時那列存活');
        $this->assertSame(self::PREV_HOUR, $staleChips->first()['summary_time'], '刪除足跡不得越窗到前一小時');
        $this->assertNull(
            collect($spEvents)->first(fn (array $row): bool => (int) $row['member_id'] === self::STALE_MEMBER && $row['summary_time'] === self::START),
            '獎勵彙總本格髒列必須被刪除重建',
        );

        // 還原產出表到 SP 執行前的狀態（重鋪髒列），讓 PHP 版從同一起點執行
        $this->cleanRollingChipOutputs();
        DB::table('summary_rolling_chip_records')->insert($dirtyRows['chips']);
        DB::table('summary_event_records')->insert($dirtyRows['events']);

        (new SP_summary_rolling_chip_record)->write(self::START);

        $this->assertSame($spChips, $this->chipRows(), '滾碼彙總（含刪除足跡）必須逐列一致');
        $this->assertSame($spEvents, $this->eventRows(), '獎勵彙總（member 面）必須逐列一致');
        $this->assertSame($spAgentEvents, $this->agentEventRows(), '獎勵彙總（agent 面）必須逐列一致');

        // 錨定：LEVEL1 會員 pay = 三層代 2.25 ＋ 活動 20 ＋ VIP 7 ＋ 禮碼發放 4 = 33.25
        $level1Chip = collect($this->chipRows())->first(
            fn (array $row): bool => (int) $row['member_id'] === self::LEVEL1_MEMBER,
        );
        $this->assertNotNull($level1Chip);
        $this->assertSame('33.2500', $level1Chip['pay_rolling_chip']);

        // 錨定 UNION 去重（SP 刻意用 UNION 非 UNION ALL）：LEVEL2 的活動獎勵 20 與
        // VIP 獎勵 20 產出相同 tuple，只計一次 → pay = 20（誤改 UNION ALL 會變 40）
        $level2Chip = collect($this->chipRows())->first(
            fn (array $row): bool => (int) $row['member_id'] === self::LEVEL2_MEMBER,
        );
        $this->assertNotNull($level2Chip);
        $this->assertSame('20.0000', $level2Chip['pay_rolling_chip']);
        $this->assertSame('3.0000', $level2Chip['use_rolling_chip']);
    }

    #[Test]
    public function test_event_writer_floors_window_like_stored_procedure(): void
    {
        $this->seedMembersAndAgent();

        // 直接鋪滾碼彙總列：07:00 在窗內、06:00 在窗外、pay=0 不得入列
        DB::table('summary_rolling_chip_records')->insert([
            ['summary_time' => self::START, 'member_id' => self::LEVEL1_MEMBER, 'pay_rolling_chip' => 5, 'use_rolling_chip' => 0],
            ['summary_time' => self::START, 'member_id' => self::LEVEL2_MEMBER, 'pay_rolling_chip' => 0, 'use_rolling_chip' => 2],
            ['summary_time' => '2026-06-03 06:00:00', 'member_id' => self::LEVEL1_MEMBER, 'pay_rolling_chip' => 9, 'use_rolling_chip' => 0],
        ]);

        // SP 會把非整點輸入截斷到整點：07:30:10 ～ 08:45:00 → 07:00 ～ 08:00
        DB::statement('call SP_summary_event_record_by_rolling_chip(?, ?)', ['2026-06-03 07:30:10', '2026-06-03 08:45:00']);
        $spEvents = $this->eventRows();
        $spAgentEvents = $this->agentEventRows();

        $this->assertCount(1, $spEvents, '只有窗內且 pay > 0 的列入帳');
        $this->assertCount(1, $spAgentEvents, 'agent 面必須同步入帳（空集合＝兩邊同錯的空殼等值）');

        $this->cleanEventOutputs();

        (new SP_summary_event_record_by_rolling_chip)->write('2026-06-03 07:30:10', '2026-06-03 08:45:00');

        $this->assertSame($spEvents, $this->eventRows());
        $this->assertSame($spAgentEvents, $this->agentEventRows());
    }

    /**
     * 三層代理鏈：agent（開啟全民代理模式 7001=1）
     * ├─ LEVEL1_MEMBER（agent_level 1，當日 MUC status=1）
     * ├─ LEVEL2_MEMBER（agent_level 2，topper → LEVEL1_MEMBER）
     * └─ LEVEL3_MEMBER（agent_level 3，topper → LEVEL2_MEMBER；
     *    讓 LEVEL1 的 bet_team／win_lose_team 非零，鎖住第二段 UNION 的跨層滾算）
     */
    private function seedTeamFixture(): void
    {
        $this->seedMembersAndAgent();

        DB::table('member_agent')->insert([
            [
                'member_id' => self::LEVEL1_MEMBER,
                'parent_id' => $this->agent->id,
                'topper_member_id' => 0,
                'agent_level' => 1,
            ],
            [
                'member_id' => self::LEVEL2_MEMBER,
                'parent_id' => $this->agent->id,
                'topper_member_id' => self::LEVEL1_MEMBER,
                'agent_level' => 2,
            ],
            [
                'member_id' => self::LEVEL3_MEMBER,
                'parent_id' => $this->agent->id,
                'topper_member_id' => self::LEVEL2_MEMBER,
                'agent_level' => 3,
            ],
        ]);

        DB::table('member_unlimit_cancels')->insert([
            'date' => self::SETTLEMENT_DATE,
            'member_id' => self::LEVEL1_MEMBER,
            'status' => 1,
        ]);

        DB::table('summary_bet_records')->insert([
            $this->betRecord(self::LEVEL3_MEMBER, VendorIdEnum::PTSW->value, self::GAME_ID, self::START, '200', '-20'),
            $this->betRecord(self::LEVEL2_MEMBER, VendorIdEnum::PTSW->value, self::GAME_ID, self::START, '100', '-10'),
            $this->betRecord(self::LEVEL1_MEMBER, VendorIdEnum::PTSW->value, self::GAME_ID, self::START, '50', '5'),
            // 918 系整日格：SBR 落在 DATE(start+8h)
            $this->betRecord(self::LEVEL2_MEMBER, VendorIdEnum::SCR->value, self::DAILY_GAME_ID, self::DAILY_BUCKET, '30', '-3'),
        ]);
    }

    /**
     * 於產出表預鋪髒列，驗證兩邊刪除足跡一致（多刪＝互洗相鄰格、少刪＝殘留舊值）。
     *
     * @return array<int, array<string, mixed>> 鋪入的髒列（供 PHP phase 前還原）
     */
    private function seedTeamDirtyRows(): array
    {
        $rows = [
            // 本格髒列：必須被刪除重建
            $this->teamRow(self::START, VendorIdEnum::PTSW->value, self::GAME_ID),
            $this->teamRow(self::DAILY_BUCKET, VendorIdEnum::SCR->value, self::DAILY_GAME_ID),
            // 窗外／跨格髒列：必須存活
            $this->teamRow(self::PREV_HOUR, VendorIdEnum::PTSW->value, self::GAME_ID),
            $this->teamRow(self::START, VendorIdEnum::SCR->value, self::DAILY_GAME_ID),
            $this->teamRow(self::DAILY_BUCKET, VendorIdEnum::PTSW->value, self::GAME_ID),
        ];

        DB::table('summary_bet_records_team')->insert($rows);

        return $rows;
    }

    /**
     * @return array{chips: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
     */
    private function seedRollingChipDirtyRows(): array
    {
        $rows = [
            'chips' => [
                // 本格髒列（必須被刪）＋前一小時髒列（必須存活）
                ['summary_time' => self::START, 'member_id' => self::STALE_MEMBER, 'pay_rolling_chip' => 99, 'use_rolling_chip' => 0],
                ['summary_time' => self::PREV_HOUR, 'member_id' => self::STALE_MEMBER, 'pay_rolling_chip' => 88, 'use_rolling_chip' => 0],
            ],
            'events' => [
                ['summary_time' => self::START, 'member_id' => self::STALE_MEMBER, 'event_id' => 6, 'point' => 77],
            ],
        ];

        DB::table('summary_rolling_chip_records')->insert($rows['chips']);
        DB::table('summary_event_records')->insert($rows['events']);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function teamRow(string $summaryTime, int $vendorId, int $gameId): array
    {
        return [
            'summary_time' => $summaryTime,
            'member_id' => self::STALE_MEMBER,
            'vendor_id' => $vendorId,
            'game_id' => $gameId,
            'bet' => '111',
            'bet_direct' => '0',
            'bet_team' => '0',
            'win_lose' => '11',
            'win_lose_direct' => '0',
            'win_lose_team' => '0',
            'admin' => 0,
        ];
    }

    private function seedRollingChipFixture(): void
    {
        $this->seedMembersAndAgent();

        // 三層代獎勵（reward_type=2、agent_id=0）：2.25
        DB::table('three_hierarchy_bonus_report')->insert([
            'summary_time' => self::START,
            'reward_type' => 2,
            'agent_id' => 0,
            'member_id' => self::LEVEL1_MEMBER,
            'rebate' => '1.5',
            'down_rebate' => '0.5',
            'down2_rebate' => '0.25',
            'created_at' => self::START,
        ]);

        // 活動獎勵（prize_contents.point_type=2、prize_members.status=1）：20
        $contentId = DB::table('prize_contents')->insertGetId([
            'prize_id' => 1,
            'name' => 'equivalence-test',
            'number' => 1,
            'percentage' => 100,
            'point_type' => 2,
            'point' => 20,
        ]);
        DB::table('prize_members')->insert([
            'member_id' => self::LEVEL1_MEMBER,
            'prize_id' => '1',
            'content_id' => (string) $contentId,
            'status' => 1,
            'admin' => 0,
            'updated_at' => self::START,
        ]);

        // 投注消耗禮碼（SBR rolling_chip > 0）：LEVEL2 用 3
        DB::table('summary_bet_records')->insert([
            $this->betRecord(self::LEVEL2_MEMBER, VendorIdEnum::PTSW->value, self::GAME_ID, self::START, '10', '1', '3'),
        ]);

        // VIP 獎勵（point_type=2、status=1）：LEVEL1 = 7；
        // LEVEL2 = 20 與其活動獎勵 20 產出相同 tuple → 鎖 UNION 去重（見測試錨定）
        DB::table('member_vip_prizes')->insert([
            [
                'member_id' => self::LEVEL1_MEMBER,
                'point_type' => 2,
                'point' => 7,
                'status' => 1,
                'updated_at' => self::START,
            ],
            [
                'member_id' => self::LEVEL2_MEMBER,
                'point_type' => 2,
                'point' => 20,
                'status' => 1,
                'updated_at' => self::START,
            ],
        ]);

        // LEVEL2 活動獎勵 20：與上方 VIP 獎勵同額，兩來源 tuple 相同
        DB::table('prize_members')->insert([
            'member_id' => self::LEVEL2_MEMBER,
            'prize_id' => '1',
            'content_id' => (string) DB::table('prize_contents')->where('name', 'equivalence-test')->value('id'),
            'status' => 1,
            'admin' => 0,
            'updated_at' => self::START,
        ]);

        // 註冊/後台贈送/優惠碼禮碼發放（type 5/11/23）：4
        DB::table('rolling_chip_logs')->insert([
            'member_id' => self::LEVEL1_MEMBER,
            'type' => 5,
            'amount' => '4',
            'created_at' => self::START,
        ]);
    }

    private function seedMembersAndAgent(): void
    {
        $this->agent = Agent::factory()->create(['customized_setting_group_id' => 8801]);

        AgentCustomizedSetting::create([
            'agent_group_id' => 8801,
            'setting_key' => AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING,
            'value' => '1',
            'sub_category' => 1,
            'memo' => 'equivalence-test',
        ]);

        Member::factory()->create(['id' => self::LEVEL1_MEMBER, 'parent' => $this->agent->id, 'username' => 'sbr_eq_l1']);
        Member::factory()->create(['id' => self::LEVEL2_MEMBER, 'parent' => $this->agent->id, 'username' => 'sbr_eq_l2']);
        Member::factory()->create(['id' => self::LEVEL3_MEMBER, 'parent' => $this->agent->id, 'username' => 'sbr_eq_l3']);
    }

    /**
     * @return array<string, mixed>
     */
    private function betRecord(int $memberId, int $vendorId, int $gameId, string $summaryTime, string $bet, string $winLose, string $rollingChip = '0'): array
    {
        return [
            'summary_time' => $summaryTime,
            'data_time' => $summaryTime,
            'member_id' => $memberId,
            'vendor_id' => $vendorId,
            'game_id' => $gameId,
            'bet' => $bet,
            'win_lose' => $winLose,
            'rolling_chip' => $rollingChip,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamRows(): array
    {
        return DB::table('summary_bet_records_team')
            ->whereIn('summary_time', [self::PREV_HOUR, self::START, self::DAILY_BUCKET])
            ->orderBy('summary_time')->orderBy('member_id')->orderBy('vendor_id')->orderBy('game_id')
            ->get(['summary_time', 'member_id', 'vendor_id', 'game_id', 'bet', 'bet_direct', 'bet_team', 'win_lose', 'win_lose_direct', 'win_lose_team', 'admin'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chipRows(): array
    {
        return DB::table('summary_rolling_chip_records')
            ->whereIn('summary_time', [self::PREV_HOUR, self::START])
            ->orderBy('summary_time')->orderBy('member_id')
            ->get(['summary_time', 'member_id', 'pay_rolling_chip', 'use_rolling_chip'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventRows(): array
    {
        return DB::table('summary_event_records')
            ->where('event_id', 6)
            ->where('summary_time', '>=', self::START)->where('summary_time', '<', self::END)
            ->orderBy('member_id')
            ->get(['summary_time', 'member_id', 'event_id', 'point'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agentEventRows(): array
    {
        return DB::table('summary_event_record_agents')
            ->where('event_id', 6)
            ->where('summary_time', '>=', self::START)->where('summary_time', '<', self::END)
            ->orderBy('agent_id')
            ->get(['summary_time', 'agent_id', 'event_id', 'point'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function latestControlLogWindow(): array
    {
        return (array) DB::table('summary_control_logs')
            ->where('vendor_id', 10006)
            ->orderByDesc('id')
            ->first(['stat_start_time', 'stat_end_time', 'params']);
    }

    private function cleanRollingChipOutputs(): void
    {
        DB::table('summary_rolling_chip_records')->whereIn('summary_time', [self::PREV_HOUR, self::START])->delete();
        $this->cleanEventOutputs();
    }

    private function cleanEventOutputs(): void
    {
        DB::table('summary_event_records')
            ->where('event_id', 6)
            ->where('summary_time', '>=', self::START)->where('summary_time', '<', self::END)
            ->delete();
        DB::table('summary_event_record_agents')
            ->where('event_id', 6)
            ->where('summary_time', '>=', self::START)->where('summary_time', '<', self::END)
            ->delete();
    }
}
