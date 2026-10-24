<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor;

use App\Enums\Vendor\VendorIdEnum;
use App\Jobs\FetchVendorBetRecord;
use App\Models\Game;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use App\Services\BetRecordFetchService;
use App\Services\VendorService;
use App\Vendors\Models\EvoAccount;
use App\Vendors\Models\EvoBetRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * fetch_bet_record --direct（同步跑完整個窗口）以 Evo 為儲存端的端對端行為：
 * 寫入 {vendor}_bet_records 後由 BetRecordFetchService 轉入 total_bet_records。
 *
 * 對照 SP_transfer_bet_record_evo：member_id / vendor_id / game_id / valid_bet_rate
 * 的解析規則與過濾條件必須一致。
 *
 * php artisan test tests/Feature/Console/Commands/Vendor/FetchBetRecordCommandTest.php
 */
class FetchBetRecordCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const string API_URL = 'https://evo-api.test';

    private const int VENDOR_ID = VendorIdEnum::TITAN_EVO->value;

    private VendorInfo $vendorInfo;

    private Game $game;

    /** @var array<int, array<string, mixed>> 三方本次要回的注單，可於同一測試中改寫後再跑一次 */
    private array $thirdRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $vendor = Vendor::find(self::VENDOR_ID)
            ?? Vendor::factory()->create(['id' => self::VENDOR_ID]);
        // Titan EVO 是轉帳錢包：factory 預設 is_single_wallet = 1，會被「單一錢包只放行乾跑」擋下
        $vendor->forceFill(['name' => 'TITAN EVO', 'status' => 1, 'valid_bet_rate' => 0.5, 'is_single_wallet' => 0])->save();

        $this->vendorInfo = $this->createVendorInfo();

        // 用 closure 讀 $this->thirdRecords：同一測試重跑時只換內容，不重複註冊 stub
        Http::fake([
            self::API_URL . '/Report/TransactionDetail' => fn () => Http::response([
                'code' => 1,
                'totalPage' => 1,
                'data' => $this->thirdRecords,
            ]),
        ]);

        $this->game = Game::factory()->create([
            'vendor_id' => self::VENDOR_ID,
            'game_type' => 'baccarat',
            'valid_bet_rate' => 0.8,
        ]);

        RateLimiter::clear('fetch_bet_record:' . self::VENDOR_ID);
    }

    private function createVendorInfo(string $agentId = 'test-agent'): VendorInfo
    {
        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => self::VENDOR_ID,
            'status' => 1,
            'rate' => 1,
        ]);
        // info 有 array cast（寫入時自行 json_encode），存明文 JSON 即可被 accessor 解析
        $vendorInfo->forceFill(['info' => [
            'API_URL' => self::API_URL,
            'AGENT_ID' => $agentId,
            // 非大廳型 provider：game_code 取三方 gameCode（大廳型會改用 PROVIDER，另見 DriverTest）
            'PROVIDER' => '13',
        ]])->save();

        return $vendorInfo;
    }

    private function createAccount(string $username, int $memberId): EvoAccount
    {
        return EvoAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'password' => 'x',
            'member_id' => $memberId,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function fakeThirdParty(array $records): void
    {
        $this->thirdRecords = $records;
    }

    private function runCommand(): void
    {
        Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
            '--direct' => true,
        ]);
    }

    #[TestDox('注單寫入 evo_bet_records 並轉入 total_bet_records（member/game/rate 解析與 SP 一致）')]
    #[Group('rules/bet-record-pull#R-16')]
    public function test_bet_record_and_total_bet_record_are_written(): void
    {
        $account = $this->createAccount('Y2WEVO00000001', 123456);

        $this->fakeThirdParty([[
            'id' => 'BET-1',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 100.5,
            'turnover' => 80.25,
            'winlose' => 5.5,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        $this->runCommand();

        $betRecord = EvoBetRecord::where('bet_id', 'BET-1')->firstOrFail();
        $this->assertSame('80.2500', $betRecord->bet);
        $this->assertSame('-5.5000', $betRecord->win_lose);
        $this->assertSame('2026-08-20 00:30:00', $betRecord->bet_time);

        $total = TotalBetRecord::where('vendor_id', self::VENDOR_ID)
            ->where('bet_record_id', $betRecord->id)
            ->firstOrFail();

        $this->assertSame(123456, $total->member_id);
        $this->assertSame($this->game->id, $total->game_id);
        $this->assertSame('BET-1', $total->reference_no);
        $this->assertSame('80.2500', $total->bet);
        $this->assertSame('80.2500', $total->valid_bet);
        $this->assertSame('-5.5000', $total->win_lose);
        // summary_time 取投注時間整點，payout_time 比照 SP 用投注時間
        $this->assertSame('2026-08-20 00:00:00', $total->summary_time);
        $this->assertSame('2026-08-20 00:30:00', $total->bet_time);
        $this->assertSame('2026-08-20 00:30:00', $total->payout_time);
        // valid_bet_rate：無 vendor_info_games 時取 games
        $this->assertSame('0.8000', $total->valid_bet_rate);
    }

    #[TestDox('valid_bet_rate 以 vendor_info_games 優先於 games')]
    #[Group('rules/bet-record-pull#R-16')]
    public function test_vendor_info_game_rate_wins_over_game_rate(): void
    {
        $account = $this->createAccount('Y2WEVO00000002', 123457);

        VendorInfoGame::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'game_id' => $this->game->id,
            'valid_bet_rate' => 0.3,
            'status' => 1,
        ]);

        $this->fakeThirdParty([[
            'id' => 'BET-2',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        $this->runCommand();

        $this->assertSame(
            '0.3000',
            TotalBetRecord::where('reference_no', 'BET-2')->value('valid_bet_rate'),
        );
    }

    #[TestDox('查無 game 時 game_id 記 0 並 fallback 至 vendor 的 valid_bet_rate')]
    #[Group('rules/bet-record-pull#R-16')]
    public function test_unknown_game_code_falls_back_to_vendor_rate_and_zero_game_id(): void
    {
        $account = $this->createAccount('Y2WEVO00000003', 123458);

        $this->fakeThirdParty([[
            'id' => 'BET-3',
            'loginId' => $account->username,
            'gameCode' => 'not-in-games',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        $this->runCommand();

        $total = TotalBetRecord::where('reference_no', 'BET-3')->firstOrFail();
        $this->assertSame(0, $total->game_id);
        $this->assertSame('0.5000', $total->valid_bet_rate);
    }

    #[TestDox('未綁定會員的注單不轉入 TBR（member_id > 0），type = 0 活動單照常轉入')]
    #[Group('rules/bet-record-pull#R-15')]
    public function test_unbound_account_is_kept_out_but_zero_type_is_kept(): void
    {
        // member_id = 0：SP 的 JOIN 條件是 member_id > 0
        $unbound = $this->createAccount('Y2WEVO00000004', 0);
        $bound = $this->createAccount('Y2WEVO00000005', 123459);

        $this->fakeThirdParty([
            [
                'id' => 'BET-4',
                'loginId' => $unbound->username,
                'gameCode' => 'baccarat',
                'bet' => 10,
                'turnover' => 10,
                'winlose' => 0,
                'type' => 3,
                'endDate' => '2026-08-20T08:30:00+08:00',
            ],
            [
                'id' => 'BET-5',
                'loginId' => $bound->username,
                'gameCode' => 'baccarat',
                'bet' => 10,
                'turnover' => 10,
                'winlose' => 0,
                'type' => 0,
                'endDate' => '2026-08-20T08:30:00+08:00',
            ],
        ]);

        $this->runCommand();

        // 兩筆注單都要進 evo_bet_records
        $this->assertSame(2, EvoBetRecord::whereIn('bet_id', ['BET-4', 'BET-5'])->count());
        // 未綁定會員的不進 TBR（SP 的 member_id > 0 條件）
        $this->assertSame(0, TotalBetRecord::where('reference_no', 'BET-4')->count());
        // type = 0 的活動單照常進 TBR（SP 會排除，這裡刻意保留）
        $this->assertSame(1, TotalBetRecord::where('reference_no', 'BET-5')->count());
    }

    #[TestDox('重跑同窗口不重複寫入也不覆寫既有列（唯一鍵去重後純新增）')]
    #[Group('rules/bet-record-pull#R-12')]
    public function test_rerun_deduplicates_and_keeps_original_row(): void
    {
        $account = $this->createAccount('Y2WEVO00000006', 123460);

        $record = [
            'id' => 'BET-6',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ];

        $this->fakeThirdParty([$record]);
        $this->runCommand();

        // 三方後來改了輸贏：拉單採唯一鍵去重後純新增，既有列不覆寫，
        // 事後修正走補帳（repair）流程而非重拉
        $this->fakeThirdParty([[...$record, 'winlose' => 7]]);
        $this->runCommand();

        $this->assertSame(1, EvoBetRecord::where('bet_id', 'BET-6')->count());
        $this->assertSame(1, TotalBetRecord::where('reference_no', 'BET-6')->count());
        $this->assertSame('0.0000', TotalBetRecord::where('reference_no', 'BET-6')->value('win_lose'));
    }

    #[TestDox('憑證鍵相同（同 AGENT_ID + PROVIDER）的多個 vendor_info 同片只打一次三方 API')]
    #[Group('rules/bet-record-pull#R-8')]
    public function test_vendor_infos_sharing_credentials_are_fetched_once(): void
    {
        // 第二個 vendor_info 與 setUp 建的共用同組 AGENT_ID + PROVIDER（各代理組共用商戶憑證）
        $this->createVendorInfo();

        $this->fakeThirdParty([]);
        $this->runCommand();

        Http::assertSentCount(1);
    }

    #[TestDox('乾跑模式（--dry-run）照常打三方並回報統計，但不寫入任何資料')]
    #[Group('rules/bet-record-pull#R-20')]
    public function test_dry_run_reports_but_persists_nothing(): void
    {
        $account = $this->createAccount('Y2WEVO00000007', 123461);

        $this->fakeThirdParty([[
            'id' => 'BET-7',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
            '--dry-run' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('乾跑模式', $output);
        $this->assertStringContainsString('新增 1 筆', $output);
        // 乾跑不寫入：注單與 TBR 都不落地
        $this->assertSame(0, EvoBetRecord::where('bet_id', 'BET-7')->count());
        $this->assertSame(0, TotalBetRecord::where('reference_no', 'BET-7')->count());
    }

    #[TestDox('乾跑列出將新增的注單，搭配 --update 列出既有列的金額差異；全部不落地')]
    #[Group('rules/bet-record-pull#R-20')]
    public function test_dry_run_lists_new_records_and_amount_diffs(): void
    {
        $account = $this->createAccount('Y2WEVO00000008', 123462);

        $existing = [
            'id' => 'BET-8',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ];
        $this->fakeThirdParty([$existing]);
        $this->runCommand();

        // 三方改了 BET-8 的輸贏，並多了一筆 BET-9
        $this->fakeThirdParty([
            [...$existing, 'winlose' => 7],
            [...$existing, 'id' => 'BET-9'],
        ]);

        Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
            '--dry-run' => true,
            '--update' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('新增 1 筆', $output);
        $this->assertStringContainsString('BET-9', $output);
        // Evo 的 win_lose 存玩家輸贏取負：三方 winlose 7 → -7.0000
        $this->assertStringContainsString('BET-8  win_lose: 0.0000 → -7.0000', $output);

        $this->assertSame('0.0000', EvoBetRecord::where('bet_id', 'BET-8')->value('win_lose'));
        $this->assertSame(0, EvoBetRecord::where('bet_id', 'BET-9')->count());
    }

    #[TestDox('--use-cache 寫進去的是壓縮後的內容：這份快取與 session、業務快取共用同一台 1GB Redis')]
    public function test_raw_cache_is_stored_compressed(): void
    {
        $account = $this->createAccount('Y2WEVO00000010', 123464);

        $this->fakeThirdParty([[
            'id' => 'BET-11',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
            '--dry-run' => true,
            '--use-cache' => true,
        ]);

        $driver = app(VendorService::class)->getDriver(self::VENDOR_ID);
        $cached = Cache::get(BetRecordFetchService::rawCacheKey(
            self::VENDOR_ID,
            $driver->betRecordCredentialKey($this->vendorInfo->info),
            Carbon::parse('2026-08-20 00:00:00', 'UTC'),
            Carbon::parse('2026-08-20 01:00:00', 'UTC'),
        ));

        // 存陣列（未壓縮）會讓這條斷言紅：壓縮是事故後的決策，不是實作細節
        $this->assertIsString($cached);
        $this->assertIsArray(unserialize(gzuncompress($cached), ['allowed_classes' => false]));
    }

    #[TestDox('--use-cache：乾跑拉回的三方回應在正式模式沿用，不再打三方')]
    #[Group('rules/bet-record-pull#R-23')]
    public function test_use_cache_reuses_dry_run_response_for_real_run(): void
    {
        $account = $this->createAccount('Y2WEVO00000009', 123463);

        $this->fakeThirdParty([[
            'id' => 'BET-10',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        $window = ['--vendor' => (string) self::VENDOR_ID, '--start' => '2026-08-20@00', '--end' => '2026-08-20@01'];

        Artisan::call('fetch_bet_record', [...$window, '--dry-run' => true, '--use-cache' => true]);
        $this->assertSame(0, EvoBetRecord::where('bet_id', 'BET-10')->count());

        $exitCode = Artisan::call('fetch_bet_record', [...$window, '--direct' => true, '--use-cache' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());

        Http::assertSentCount(1);
        $this->assertSame(1, EvoBetRecord::where('bet_id', 'BET-10')->count());
        $this->assertSame(1, TotalBetRecord::where('reference_no', 'BET-10')->count());
    }

    #[TestDox('維護中的遊戲商照常拉單（維護只擋玩家下注，已結算注單仍需拉取）')]
    #[Group('rules/bet-record-pull#R-5')]
    public function test_vendor_under_maintenance_is_still_fetched(): void
    {
        $account = $this->createAccount('Y2WEVO00000008', 123462);

        DB::table('vendor_maintenances')->insert([
            'vendor_id' => self::VENDOR_ID,
            'status' => 1,
            'type' => 1,
            'start_time' => now()->subHour()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeThirdParty([[
            'id' => 'BET-8',
            'loginId' => $account->username,
            'gameCode' => 'baccarat',
            'bet' => 10,
            'turnover' => 10,
            'winlose' => 0,
            'type' => 3,
            'endDate' => '2026-08-20T08:30:00+08:00',
        ]]);

        $this->runCommand();

        $this->assertSame(1, EvoBetRecord::where('bet_id', 'BET-8')->count());
    }

    #[TestDox('三方拉取失敗時逐商隔離回報錯誤，命令以 FAILURE 作收（Olympus 操作紀錄標失敗）')]
    #[Group('rules/bet-record-pull#R-18')]
    public function test_third_party_failure_reports_error_and_exits_failure(): void
    {
        // 換掉 API host 指向回 500 的 stub（setUp 的成功 stub 先註冊、同 URL 會先命中）
        $this->vendorInfo->forceFill(['info' => [
            'API_URL' => 'https://evo-broken.test',
            'AGENT_ID' => 'test-agent',
            'PROVIDER' => '13',
        ]])->save();
        Http::fake(['evo-broken.test/*' => Http::response('', 500)]);

        $exitCode = Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
            '--direct' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('⚠', Artisan::output());
    }

    #[TestDox('排程模式（無 flag）逐商派發 FetchVendorBetRecord Job，不直接打三方')]
    #[Group('rules/bet-record-pull#R-2')]
    public function test_default_mode_dispatches_queue_job(): void
    {
        Queue::fake();

        Artisan::call('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@01',
        ]);

        Queue::assertPushed(
            FetchVendorBetRecord::class,
            fn (FetchVendorBetRecord $job) => $job->vendorId === self::VENDOR_ID
                && $job->startTime === '2026-08-20 00:00:00'
                && $job->endTime === '2026-08-20 01:00:00',
        );
        Http::assertNothingSent();
    }

    #[TestDox('同步模式逐片印出進度：整個窗口跑完才有結果，中途要看得出還在跑')]
    #[Group('rules/bet-record-pull#R-24')]
    public function test_direct_mode_prints_chunk_progress(): void
    {
        $this->fakeThirdParty([]);

        // Evo 一片 60 分鐘，兩小時窗口 ＝ 2 片；末片帶總片數，看得出還剩多少
        $this->artisan('fetch_bet_record', [
            '--vendor' => (string) self::VENDOR_ID,
            '--start' => '2026-08-20@00',
            '--end' => '2026-08-20@02',
            '--direct' => true,
        ])
            ->expectsOutputToContain('[2/2]')
            ->assertExitCode(0);
    }

    #[Group('rules/bet-record-pull#R-6')]
    public function test_vendor_info_option_requires_vendor(): void
    {
        $this->artisan('fetch_bet_record', ['--vendor-info' => 123])
            ->expectsOutputToContain('--vendor-info 需搭配 --vendor 使用')
            ->assertExitCode(1);
    }
}
