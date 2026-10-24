<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\AgentVendor;
use App\Models\SummaryBetRecord;
use App\Models\TotalBetRecord;
use App\Models\VendorInfo;
use App\Services\BetRecordCorrectionQueryService;
use App\Vendors\Models\Ace333Account;
use App\Vendors\Models\Ace333BetRecord;
use App\Vendors\Models\EvoAccount;
use App\Vendors\Models\EvoBetRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 補帳查詢的四份對照數據。
 *
 * php artisan test tests/Feature/Services/BetRecordCorrectionQueryServiceTest.php
 */
class BetRecordCorrectionQueryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const int VENDOR_ID = VendorIdEnum::TITAN_EVO->value;

    /** 台北 2026-06-08 整日 = UTC 06-07 16:00 ～ 06-08 16:00 */
    private const string DATE = '2026-06-08';

    public function test_query_returns_range_and_four_datasets(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        $vendorInfoId = (int) AgentVendor::query()
            ->where('agent_id', $agent->id)
            ->where('vendor_id', self::VENDOR_ID)
            ->value('vendor_info_id');

        $member = $this->createMember($agent);

        EvoAccount::create([
            'vendor_info_id' => $vendorInfoId,
            'username' => 'TESTEVO00000001',
            'password' => 'x',
            'member_id' => $member->id,
        ]);

        // 區間內兩筆、區間外一筆（差 1 小時）
        $this->createBetRecord('BET-IN-1', '2026-06-08 02:00:00', '100', '-20');
        $this->createBetRecord('BET-IN-2', '2026-06-07 16:00:00', '50', '10');
        $this->createBetRecord('BET-OUT', '2026-06-07 15:00:00', '999', '999');

        // 調帳列記在關帳整點（台北 06-08 00:00 = UTC 06-07 16:00），資料日為 06-05
        SummaryBetRecord::create([
            'summary_time' => '2026-06-07 16:00:00',
            'data_time' => '2026-06-05 03:00:00',
            'member_id' => $member->id,
            'vendor_id' => self::VENDOR_ID,
            'game_id' => 0,
            'bet' => '30.5000',
            'win_lose' => '-5.2500',
            'jackpot' => '1.0000',
            'correction' => 1,
            'record_count' => 2,
        ]);
        // 非調帳列：不該出現在第 1 塊，但要計入第 2 塊
        SummaryBetRecord::create([
            'summary_time' => '2026-06-08 05:00:00',
            'data_time' => '2026-06-08 05:00:00',
            'member_id' => $member->id,
            'vendor_id' => self::VENDOR_ID,
            'game_id' => 0,
            'bet' => '10.0000',
            'win_lose' => '2.0000',
            'jackpot' => '0.0000',
            'correction' => 0,
            'record_count' => 1,
        ]);

        TotalBetRecord::create([
            'summary_time' => '2026-06-08 02:00:00',
            'member_id' => $member->id,
            'vendor_id' => self::VENDOR_ID,
            'bet_record_id' => 999001,
            'game_id' => 0,
            'reference_no' => 'BET-IN-1',
            'bet' => '100.0000',
            'valid_bet' => '90.0000',
            'win_lose' => '-20.0000',
            'jackpot' => '0.0000',
            'bet_time' => '2026-06-08 02:00:00',
            'payout_time' => '2026-06-08 02:00:00',
        ]);

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        // 日界依 calc_time_zone = 8
        $this->assertSame(8, $result['offset']);
        $this->assertSame('2026-06-07 16:00:00', $result['start']);
        $this->assertSame('2026-06-08 16:00:00', $result['end']);
        // Titan 在 config/summary_bet_record.php 是 bet_time
        $this->assertSame('bet_time', $result['time_field']);

        // ① 只取 correction = 1，依資料日分組並附說明字串
        $this->assertCount(1, $result['corrections']);
        $this->assertSame('2026-06-05', $result['corrections'][0]['data_date']);
        $this->assertSame('2026-06-08', $result['corrections'][0]['summary_date']);
        $this->assertSame('補6/5 b:30.5 wl:-5.25', $result['corrections'][0]['description']);

        // ② SBR 區間合計（按 summary_time）：調帳列與非調帳列都在區間內
        $this->assertSame('40.5000', $result['summary']['summary_time']['bet']);
        $this->assertSame('-3.2500', $result['summary']['summary_time']['win_lose']);
        $this->assertSame('2', $result['summary']['summary_time']['record_count']);

        // ② SBR 區間合計（按 data_time）：調帳列的原始資料日是 06-05，落在區間外
        $this->assertSame('10.0000', $result['summary']['data_time']['bet']);
        $this->assertSame('2.0000', $result['summary']['data_time']['win_lose']);
        $this->assertSame('1', $result['summary']['data_time']['record_count']);

        // ③ TBR 區間合計
        $this->assertSame('100.0000', $result['total']['bet']);
        $this->assertSame('90.0000', $result['total']['valid_bet']);
        $this->assertSame('1', $result['total']['record_count']);

        // ④ 遊戲商注單合計：只算區間內兩筆，經 accessor 取值
        $this->assertSame('150.0000', $result['bet_record']['bet']);
        $this->assertSame('150.0000', $result['bet_record']['valid_bet']);
        $this->assertSame('-10.0000', $result['bet_record']['win_lose']);
        $this->assertSame('2', $result['bet_record']['record_count']);
    }

    /**
     * 總代自己的 agent_vendors 全停用時該商戶不列入（VendorService 的規則：
     * status=1，或 status=0 但 self_status=1 且是自己那筆才算有效）。
     * VWorld 的 vworldmy 對 BWG 就是 0/0，所以第 4 區塊撈不到商戶。
     */
    /**
     * 注單表與帳號表的玩家欄位名不一定相同（ACE333 是 bet.playerID = acc.player_id），
     * join 兩側必須各取各的 Model 宣告；帳號側寫死 username 會一筆都撈不到。
     */
    public function test_bet_record_join_uses_each_models_username_column(): void
    {
        $vendorId = VendorIdEnum::ACE333->value;

        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, $vendorId);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        $vendorInfoId = (int) AgentVendor::query()
            ->where('agent_id', $agent->id)
            ->where('vendor_id', $vendorId)
            ->value('vendor_info_id');

        $member = $this->createMember($agent);

        // 帳號表的玩家欄位是 player_id，username 是我方內部帳號
        Ace333Account::create([
            'vendor_info_id' => $vendorInfoId,
            'username' => 'QAM00000001',
            'player_id' => '01329493029',
            'member_id' => $member->id,
            'admin' => 0,
        ]);

        Ace333BetRecord::create([
            'playerID' => '01329493029',
            'extPlayerID' => 'QAM00000001',
            'gameID' => 'slot-a',
            'transactionType' => 'BR',
            'referenceID' => 'REF-JOIN-1',
            'status' => 'C',
            'created' => '2026-06-08 02:00:00',
            'updated' => '2026-06-08 02:00:00',
            'betAmount' => '100.0000',
            'winAmount' => '150.0000',
            'jackpotModule' => '',
            'jackpotContributionAmt' => '0.0000',
            'currency' => 'MYR',
            'resultUrl' => '',
            'roundDetails' => '',
            'platform' => 'WEB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertSame('1', $result['bet_record']['record_count']);
        $this->assertSame('100.0000', $result['bet_record']['bet']);
    }

    public function test_vendor_without_enabled_vendor_info_reports_note(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        VendorInfo::query()->where('vendor_id', self::VENDOR_ID)->update(['status' => 0]);

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertNull($result['bet_record']['record_count']);
        $this->assertSame('該遊戲商沒有啟用中的商戶', $result['bet_record']['note']);
    }

    /**
     * 指定商戶時，SBR 合計與注單合計都只算該商戶：會員範圍由帳號表界定，
     * 另一商戶的會員與注單不得混入。
     */
    public function test_specific_vendor_info_scopes_members_and_bet_records(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        $vendorInfoA = VendorInfo::query()->where('vendor_id', self::VENDOR_ID)->firstOrFail();
        $vendorInfoB = VendorInfo::factory()->create(['vendor_id' => self::VENDOR_ID, 'status' => 1]);

        $memberA = $this->createMember($agent, ['username' => 'corrtest_a', 'nickname' => 'corrtest_a']);
        $memberB = $this->createMember($agent, ['username' => 'corrtest_b', 'nickname' => 'corrtest_b']);

        EvoAccount::create([
            'vendor_info_id' => $vendorInfoA->id,
            'username' => 'TESTEVOA0000001',
            'password' => 'x',
            'member_id' => $memberA->id,
        ]);
        EvoAccount::create([
            'vendor_info_id' => $vendorInfoB->id,
            'username' => 'TESTEVOB0000001',
            'password' => 'x',
            'member_id' => $memberB->id,
        ]);

        foreach ([[$memberA, '10.0000'], [$memberB, '70.0000']] as [$member, $bet]) {
            SummaryBetRecord::create([
                'summary_time' => '2026-06-08 05:00:00',
                'data_time' => '2026-06-08 05:00:00',
                'member_id' => $member->id,
                'vendor_id' => self::VENDOR_ID,
                'game_id' => 0,
                'bet' => $bet,
                'win_lose' => '0.0000',
                'jackpot' => '0.0000',
                'correction' => 0,
                'record_count' => 1,
            ]);
        }

        $this->createBetRecord('BET-A', '2026-06-08 02:00:00', '10', '0', 'TESTEVOA0000001');
        $this->createBetRecord('BET-B', '2026-06-08 02:00:00', '70', '0', 'TESTEVOB0000001');

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, $vendorInfoA, self::DATE);

        // SBR 只含商戶 A 的會員
        $this->assertSame('10.0000', $result['summary']['summary_time']['bet']);
        $this->assertSame('1', $result['summary']['summary_time']['record_count']);
        // 注單合計只含商戶 A 的帳號
        $this->assertSame('10.0000', $result['bet_record']['bet']);
        $this->assertSame('1', $result['bet_record']['record_count']);
    }

    /**
     * 注單表的時間欄位：config 有設就照它（Titan 是 bet_time），
     * 沒設才自己挑有索引的——pgssw_bet_records 的 payout_time 沒索引，
     * 直接掃會卡死（實測執行計畫要掃 167 萬列）。
     */
    public function test_bet_record_time_column_follows_config_then_index(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        // Titan 在 config 裡是 bet_time，evo_bet_records 也有該欄位
        $this->assertSame('bet_time', config('summary_bet_record.' . self::VENDOR_ID . '.time_field'));

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertStringContainsString('時間欄位 bet_time', (string) $result['bet_record']['note']);
        $this->assertStringContainsString('config/summary_bet_record.php', (string) $result['bet_record']['note']);
    }

    /**
     * config 指定的時間欄位沒索引時直接不跑——不能靜默換成別的欄位（那是偷換語意），
     * 也不能硬掃（pgssw_bet_records 這類表會拖垮 DB）。
     */
    public function test_refuses_to_query_when_the_time_column_has_no_index(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        // 用 win_lose 模擬「config 指到無索引欄位」：evo_bet_records 與
        // total_bet_records 都有這欄位（TBR 那份查詢才不會直接 SQL error），
        // 且 evo_bet_records 沒有為它建索引
        Config::set('summary_bet_record.' . self::VENDOR_ID . '.time_field', 'win_lose');

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertNull($result['bet_record']['record_count']);
        $this->assertStringContainsString('沒有索引', (string) $result['bet_record']['note']);
        $this->assertStringContainsString('不執行查詢', (string) $result['bet_record']['note']);
    }

    /**
     * 資料日在查詢日、關帳卻落在別天的調帳：從關帳日那頭（第一份）看不到，
     * 只有從資料日這頭（第二份）才查得出「這天的帳補到哪去了」。
     */
    public function test_corrections_by_data_time_finds_records_summarised_on_another_day(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        $member = $this->createMember($agent);

        // 資料日台北 06-08，關帳整點卻是台北 06-11 00:00（= UTC 06-10 16:00）
        SummaryBetRecord::create([
            'summary_time' => '2026-06-10 16:00:00',
            'data_time' => '2026-06-08 03:00:00',
            'member_id' => $member->id,
            'vendor_id' => self::VENDOR_ID,
            'game_id' => 0,
            'bet' => '30.5000',
            'win_lose' => '-5.2500',
            'jackpot' => '0.0000',
            'correction' => 1,
            'record_count' => 2,
        ]);

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertSame([], $result['corrections']);
        $this->assertCount(1, $result['corrections_by_data_time']);
        $this->assertSame('2026-06-08', $result['corrections_by_data_time'][0]['data_date']);
        $this->assertSame('2026-06-11', $result['corrections_by_data_time'][0]['summary_date']);
        $this->assertSame('補6/8 b:30.5 wl:-5.25', $result['corrections_by_data_time'][0]['description']);
    }

    /**
     * 調帳列的 summary_time 記在補帳當下所用時區的關帳整點（多為台北 +8），
     * 用其他時區（如 UTC+0）查詢時不會等於日界整點：只要落在查詢日 0～24 點
     * 內就要列出，不能用整點 exact match（實測 yes2win PP 用 UTC 查就漏）。
     */
    public function test_corrections_include_rows_recorded_at_non_boundary_hours(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 0])->save();

        $member = $this->createMember($agent);

        // 台北關帳整點 06-09 00:00 = UTC 06-08 16:00，落在 UTC 查詢日內但非 00:00
        SummaryBetRecord::create([
            'summary_time' => '2026-06-08 16:00:00',
            'data_time' => '2026-06-05 03:00:00',
            'member_id' => $member->id,
            'vendor_id' => self::VENDOR_ID,
            'game_id' => 0,
            'bet' => '0.0000',
            'win_lose' => '18.0000',
            'jackpot' => '0.0000',
            'correction' => 1,
            'record_count' => 0,
        ]);

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertCount(1, $result['corrections']);
        $this->assertSame('2026-06-05', $result['corrections'][0]['data_date']);
        $this->assertSame('2026-06-08', $result['corrections'][0]['summary_date']);
        $this->assertSame('補6/5 b:0 wl:18', $result['corrections'][0]['description']);
    }

    public function test_calc_time_zone_shifts_the_range(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 0])->save();

        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE);

        $this->assertSame(0, $result['offset']);
        $this->assertSame(0, $result['vendor_offset']);
        $this->assertSame('2026-06-08 00:00:00', $result['start']);
        $this->assertSame('2026-06-09 00:00:00', $result['end']);
    }

    public function test_explicit_offset_overrides_the_vendor_setting(): void
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, self::VENDOR_ID);
        $vendor->forceFill(['status' => 1, 'calc_time_zone' => 8])->save();

        // 遊戲商設定 +8，改以 -4 重算日界
        $result = app(BetRecordCorrectionQueryService::class)->query($vendor, null, self::DATE, -4);

        $this->assertSame(-4, $result['offset']);
        $this->assertSame(8, $result['vendor_offset']);
        $this->assertSame('2026-06-08 04:00:00', $result['start']);
        $this->assertSame('2026-06-09 04:00:00', $result['end']);
    }

    private function createBetRecord(string $betId, string $betTime, string $bet, string $winLose, string $username = 'TESTEVO00000001'): void
    {
        EvoBetRecord::create([
            'bet_id' => $betId,
            'username' => $username,
            'game_code' => 'TEST',
            'bet_time' => $betTime,
            'bet' => $bet,
            'win_lose' => $winLose,
            'info' => '{}',
        ]);
    }
}
