<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\BetRecordRecoveryLog;
use App\Models\Game;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\AFBSW\BetRecordStatusEnum;
use App\Vendors\Models\AfbswAccount;
use App\Vendors\Models\AfbswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 已退款注單的投注量歸零（target/op_tickets#3067）。
 *
 * 情境來源：2026-09-05 TPower 事故期間 AFB 送 refund 取消投注，但當時的 refund 回調沒有把
 * bet／valid_bet 歸零，退款注單照樣以原始投注額寫進 total_bet_records、聚合進
 * summary_bet_records，於是 SR11／SR12、三層代理返利與提款流水門檻全把它當有效投注。
 *
 * php artisan test tests/Feature/Console/Commands/BetRecordRecovery/ZeroRefundedTurnoverTest.php --compact
 */
class ZeroRefundedTurnoverTest extends TestCase
{
    private const string CMD = 'bet-record-recovery:zero-refunded-turnover';

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private AfbswAccount $account;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::AFBSW->value);
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = ['API_URL' => '', 'SITE_ID' => 'TESTSITE', 'CURRENCY' => 'MYR'];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();

        $this->account = AfbswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'afbsw_00000001',
            'member_id' => $this->member->id,
            'status' => 1,
        ]);
    }

    /**
     * 建立一筆「已退款但投注量還留著」的注單，以及它落地的 total_bet_records。
     */
    private function seedRefundedBet(
        string $betId = '1788622749122374051',
        string $bet = '6.0000',
        int $status = BetRecordStatusEnum::REFOUND->value,
        string $betTime = '2026-09-05 15:39:09',
    ): AfbswBetRecord {
        $record = AfbswBetRecord::create([
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'bet_id' => $betId,
            'game_code' => 'fishingLegend-skg',
            'bet' => $bet,
            'valid_bet' => $bet,
            'payout' => $bet,
            'win_lose' => '0.0000',
            'rolling_chips' => '0.0000',
            'bet_time' => $betTime,
            'payout_time' => '2026-09-05 17:39:21',
            'status' => $status,
            'info' => ['method' => 'refund'],
        ]);

        TotalBetRecord::create([
            'summary_time' => '2026-09-05 17:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => VendorIdEnum::AFBSW->value,
            'bet_record_id' => $record->id,
            'game_id' => $this->game->id,
            'reference_no' => $betId,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => '0.0000',
            'rolling_chip' => '0.0000',
            'bet_time' => $betTime,
            'payout_time' => '2026-09-05 17:39:21',
        ]);

        return $record;
    }

    private function totalBetRecordOf(AfbswBetRecord $record): TotalBetRecord
    {
        return TotalBetRecord::query()
            ->where('vendor_id', VendorIdEnum::AFBSW->value)
            ->where('bet_record_id', $record->id)
            ->firstOrFail();
    }

    /**
     * dry-run 只報告不寫入：注單、TBR、審計 log 三者都不得變動。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-21')]
    public function test_dry_run_reports_target_without_writing(): void
    {
        $record = $this->seedRefundedBet();

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'afbsw', '--bet-id' => $record->bet_id]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('would_zero', Artisan::output());

        $record->refresh();
        $this->assertSame('6.0000', Common::numAdd('0', (string) $record->bet, 4));
        $this->assertSame('6.0000', Common::numAdd('0', (string) $record->valid_bet, 4));
        $this->assertSame('6.0000', Common::numAdd('0', (string) $this->totalBetRecordOf($record)->valid_bet, 4));
        $this->assertSame(0, BetRecordRecoveryLog::query()->where('bet_record_id', $record->id)->count());
    }

    /**
     * execute：注單與 TBR 的投注量欄位全歸零，並留下可追查的審計 log 與 info 標記。
     * 錢包不在本命令的守備範圍——退款金額當初已正確退回，這裡只改報表口徑。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-22')]
    public function test_execute_zeroes_bet_record_and_total_bet_record(): void
    {
        $record = $this->seedRefundedBet();
        $balanceBefore = (string) $this->member->refresh()->balance;

        $exitCode = Artisan::call(self::CMD, [
            '--vendor' => 'afbsw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
            '--reason' => 'target/op_tickets#3067 退款注單投注量回補',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('zeroed', Artisan::output());

        $record->refresh();
        $this->assertSame('0.0000', Common::numAdd('0', (string) $record->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $record->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $record->payout, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $record->win_lose, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $record->rolling_chips, 4));
        $this->assertSame(BetRecordStatusEnum::REFOUND->value, (int) $record->status);
        $this->assertArrayHasKey('_zero_turnover', $record->info);

        $totalBetRecord = $this->totalBetRecordOf($record);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->win_lose, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $totalBetRecord->rolling_chip, 4));

        $log = BetRecordRecoveryLog::query()->where('bet_record_id', $record->id)->sole();
        $this->assertSame(BetRecordRecoveryTypeEnum::ZERO_TURNOVER, $log->type);
        $this->assertSame(VendorIdEnum::AFBSW->value, (int) $log->vendor_id);
        $this->assertSame('0.0000', Common::numAdd('0', (string) $log->balance_diff, 4));
        $this->assertSame('6.0000', Common::numAdd('0', (string) $log->before['valid_bet'], 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $log->after['valid_bet'], 4));

        // 錢包不動：退款金額當初已正確退回玩家
        $this->assertSame($balanceBefore, (string) $this->member->refresh()->balance);
    }

    /**
     * execute 模式沒帶 --reason 直接拒絕，不留下任何寫入。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-21')]
    public function test_execute_requires_reason(): void
    {
        $record = $this->seedRefundedBet();

        $exitCode = Artisan::call(self::CMD, [
            '--vendor' => 'afbsw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('6.0000', Common::numAdd('0', (string) $record->refresh()->valid_bet, 4));
        $this->assertSame(0, BetRecordRecoveryLog::query()->where('bet_record_id', $record->id)->count());
    }

    /**
     * 只處理已退款的注單：已結算注單的投注量是真實流水，絕不能被歸零。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-20')]
    public function test_skips_bet_record_that_is_not_refunded(): void
    {
        $record = $this->seedRefundedBet(status: BetRecordStatusEnum::PAID->value);

        $exitCode = Artisan::call(self::CMD, [
            '--vendor' => 'afbsw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
            '--reason' => 'target/op_tickets#3067 退款注單投注量回補',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('not_refunded', Artisan::output());
        $this->assertSame('6.0000', Common::numAdd('0', (string) $record->refresh()->valid_bet, 4));
        $this->assertSame('6.0000', Common::numAdd('0', (string) $this->totalBetRecordOf($record)->valid_bet, 4));
        $this->assertSame(0, BetRecordRecoveryLog::query()->where('bet_record_id', $record->id)->count());
    }

    /**
     * 冪等：投注量已是 0 的注單重跑不再寫入，也不重複記一筆審計 log。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-24')]
    public function test_is_idempotent_when_turnover_already_zero(): void
    {
        $record = $this->seedRefundedBet();
        $args = [
            '--vendor' => 'afbsw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
            '--reason' => 'target/op_tickets#3067 退款注單投注量回補',
        ];

        $this->assertSame(0, Artisan::call(self::CMD, $args));
        $this->assertSame(0, Artisan::call(self::CMD, $args));

        $this->assertStringContainsString('already_zero', Artisan::output());
        $this->assertSame(1, BetRecordRecoveryLog::query()->where('bet_record_id', $record->id)->count());
    }

    /**
     * 歸零後 summary_bet_records 不會自己跟著變，必須補跑彙總。
     * AFBSW 以 bet_time 分桶（config/summary_bet_record.php），所以提示的小時要取 bet_time
     * 那一格（15:00 UTC），不是 payout_time 的 17:00——取錯桶會補到不存在的資料上。
     */
    #[Group('rules/single-wallet-unsettled-refund#R-26')]
    public function test_reports_summary_bet_record_rerun_hint(): void
    {
        $record = $this->seedRefundedBet();

        Artisan::call(self::CMD, [
            '--vendor' => 'afbsw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
            '--reason' => 'target/op_tickets#3067 退款注單投注量回補',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString(
            'summary_bet_record --date=2026-09-05 --hour=15 --vendor=' . VendorIdEnum::AFBSW->value . ' --timezone=UTC',
            $output
        );
    }
}
