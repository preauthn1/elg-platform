<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\BetRecordStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\FumaSW\Enums\BetTypeEnum;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/BetRecordRecovery/RefundUnsettledTest.php --compact
 *
 * 情境來源：2026-09-05 TPower DDoS，FumaSW action 2 已扣款但三方判 timeout 失敗、永不派彩。
 */
class RefundUnsettledTest extends TestCase
{
    private const string CMD = 'bet-record-recovery:refund-unsettled';

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private FumaswAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::FUMASW->value);
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'FUMATEST_';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = ['DC' => 'T01', 'KEY' => 'k', 'IV' => 'i', 'API_URL' => '', 'PARENT' => 'p'];
        $this->vendorInfo->save();

        $this->account = FumaswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'FUMATEST_00000001',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->member->update(['balance' => '100.0000']);
    }

    private function seedUnsettledBet(string $betId = '998090523480201195', string $roundId = '27200617', string $bet = '1.0000', array $override = []): FumaswBetRecord
    {
        return FumaswBetRecord::create(array_merge([
            'bet_id' => $betId,
            'round_id' => $roundId,
            'username' => $this->account->username,
            'game_code' => '7',
            'type' => BetTypeEnum::BET,
            'status' => 0,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => Common::numMul($bet, -1, 4),
            'rolling_chips' => '0.0000',
            'infos' => json_encode(['action' => 2, 'transferId' => $betId, 'roundId' => (int) $roundId]),
            'bet_time' => '2026-09-05 15:48:02',
            'payout_time' => '2026-09-05 15:48:02',
        ], $override));
    }

    private function memberBalance(): string
    {
        return (string) $this->member->refresh()->balance;
    }

    #[Group('rules/single-wallet-unsettled-refund#R-2')]
    public function test_dry_run_reports_refundable_bet_without_writing(): void
    {
        $record = $this->seedUnsettledBet();

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('would_refund', Artisan::output());
        $this->assertSame('100.0000', $this->memberBalance());
        $this->assertSame(0, (int) $record->refresh()->status);
        $this->assertSame(0, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(0, TransactionLog::where('member_id', $this->member->id)->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-11')]
    #[Group('rules/single-wallet-unsettled-refund#R-13')]
    public function test_execute_refunds_wallet_marks_record_and_writes_audit_log(): void
    {
        $record = $this->seedUnsettledBet(bet: '2.5000');

        $exitCode = Artisan::call(self::CMD, [
            '--vendor' => 'fumasw',
            '--bet-id' => $record->bet_id,
            '--execute' => true,
            '--reason' => '2026-09-05 TPower DDoS，FUMA 判 timeout 投注失敗',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('102.5000', $this->memberBalance());

        $record->refresh();
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $record->status);
        $this->assertSame('2.5000', (string) $record->bet, '原下注金額保留供稽核');
        $this->assertNotSame('2026-09-05 15:48:02', (string) $record->payout_time, 'payout_time 應改寫為取消時間');
        $this->assertStringContainsString('_refund', (string) $record->infos);

        $tx = TransactionLog::where('member_id', $this->member->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame(TransactionEnum::SEAMLESS_WALLET_CANCEL->value, (int) $tx->type);
        $this->assertSame(VendorIdEnum::FUMASW->value, (int) $tx->vendor_id);
        $this->assertSame('2.5000', (string) $tx->point);
        $this->assertSame('100.0000', (string) $tx->before_balance);
        $this->assertSame('102.5000', (string) $tx->balance);

        $log = BetRecordRecoveryLog::where('bet_record_id', $record->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(BetRecordRecoveryTypeEnum::REFUND, $log->type);
        $this->assertSame(VendorIdEnum::FUMASW->value, (int) $log->vendor_id);
        $this->assertSame($record->bet_id, (string) $log->bet_id);
        $this->assertSame($this->member->id, (int) $log->member_id);
        $this->assertSame('2.5000', (string) $log->balance_diff);
        $this->assertSame(0, (int) $log->before['status']);
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $log->after['status']);
        $this->assertSame($tx->id, (int) $log->after['_meta']['transaction_log_id']);
        $this->assertNull($log->parent_log_id);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-3')]
    public function test_execute_without_reason_is_rejected(): void
    {
        $record = $this->seedUnsettledBet();

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id, '--execute' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('100.0000', $this->memberBalance());
        $this->assertSame(0, (int) $record->refresh()->status);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-7')]
    public function test_skips_bet_that_already_has_settle_row(): void
    {
        $record = $this->seedUnsettledBet();
        $this->seedUnsettledBet(betId: '998090523480201196', roundId: $record->round_id, override: [
            'type' => BetTypeEnum::SETTLE, 'bet' => '0.0000', 'valid_bet' => '0.0000', 'win_lose' => '3.0000',
        ]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('has_settle', Artisan::output());
        $this->assertSame('100.0000', $this->memberBalance());
        $this->assertSame(0, (int) $record->refresh()->status);
        $this->assertSame(0, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-10')]
    #[Group('rules/single-wallet-unsettled-refund#R-7')]
    public function test_skips_settled_bet_and_never_refunds_twice(): void
    {
        $settled = $this->seedUnsettledBet(betId: '998090523480201197', roundId: '27200618', override: ['status' => 1]);
        $record = $this->seedUnsettledBet();

        $args = ['--vendor' => 'fumasw', '--bet-ids' => $settled->bet_id . ',' . $record->bet_id, '--execute' => true, '--reason' => 't'];
        Artisan::call(self::CMD, $args);
        $this->assertStringContainsString('not_unsettled', Artisan::output());
        $this->assertSame('101.0000', $this->memberBalance());
        $this->assertSame(1, (int) $settled->refresh()->status);

        Artisan::call(self::CMD, $args);

        $this->assertSame('101.0000', $this->memberBalance(), '第二次執行不得重複退款');
        $this->assertSame(1, BetRecordRecoveryLog::where('bet_record_id', $record->id)->count());
        $this->assertSame(1, TransactionLog::where('member_id', $this->member->id)->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-8')]
    public function test_refuses_bet_paid_with_rolling_chips(): void
    {
        $record = $this->seedUnsettledBet(override: ['rolling_chips' => '0.5000']);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('rolling_chip_used', Artisan::output());
        $this->assertSame('100.0000', $this->memberBalance());
        $this->assertSame(0, (int) $record->refresh()->status);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-7')]
    public function test_skips_bet_already_aggregated_into_total_bet_records(): void
    {
        $record = $this->seedUnsettledBet();
        TotalBetRecord::create([
            'summary_time' => '2026-09-05 15:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => VendorIdEnum::FUMASW->value,
            'bet_record_id' => $record->id,
            'game_id' => 0,
            'reference_no' => $record->bet_id,
            'bet' => '1.0000',
            'valid_bet' => '1.0000',
            'win_lose' => '-1.0000',
            'rolling_chip' => '0.0000',
            'jackpot' => '0.0000',
            'bonus' => '0.0000',
            'valid_bet_rate' => 1,
            'commission' => 0,
            'bet_time' => '2026-09-05 15:48:02',
            'payout_time' => '2026-09-05 15:48:02',
            'admin' => 0,
        ]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('has_tbr', Artisan::output());
        $this->assertSame('100.0000', $this->memberBalance());
        $this->assertSame(0, (int) $record->refresh()->status);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-5')]
    public function test_reads_bet_ids_from_file_one_per_line(): void
    {
        $a = $this->seedUnsettledBet(betId: '998090523480201195', roundId: '27200617', bet: '1.0000');
        $b = $this->seedUnsettledBet(betId: '104090523515101850', roundId: '27200627', bet: '2.0000');
        $path = tempnam(sys_get_temp_dir(), 'fuma_ids_');
        file_put_contents($path, "bet_id\n{$a->bet_id}\n\n{$b->bet_id}\n");

        try {
            $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id-file' => $path, '--execute' => true, '--reason' => 't']);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame('103.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $a->refresh()->status);
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $b->refresh()->status);
        $this->assertSame(2, BetRecordRecoveryLog::where('member_id', $this->member->id)->count());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-5')]
    public function test_headered_multi_column_file_still_refunds_fumasw_without_meta_requirement(): void
    {
        // ptsw 引入的表頭 meta 解析對 fumasw 必須是零行為變更：多欄表頭只當附註，仍照 bet_id 退
        $a = $this->seedUnsettledBet(betId: '998090523480201196', roundId: '27200618', bet: '1.0000');
        $path = tempnam(sys_get_temp_dir(), 'fuma_ids_');
        file_put_contents($path, "bet_id,note,member_id\n{$a->bet_id},dd0s,{$this->member->id}\n");

        try {
            $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id-file' => $path, '--execute' => true, '--reason' => 't']);
            $output = Artisan::output();
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('refunded', $output);
        $this->assertSame('101.0000', $this->memberBalance());
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $a->refresh()->status);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-4')]
    public function test_execute_aborts_when_write_connection_is_read_only(): void
    {
        $record = $this->seedUnsettledBet();

        // 模擬 write 連線落在 read_only=1 的從庫（本機 dbc 從庫＋CONNECTION_ADMIN 會繞過 read_only 直接寫壞複寫）
        DB::shouldReceive('selectFromWriteConnection')
            ->once()
            ->with('SELECT @@read_only AS ro')
            ->andReturn([(object) ['ro' => 1]]);

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('read_only', Artisan::output());
    }

    #[Group('rules/single-wallet-unsettled-refund#R-4')]
    public function test_dry_run_does_not_probe_write_connection(): void
    {
        $record = $this->seedUnsettledBet();

        DB::shouldReceive('selectFromWriteConnection')->never();

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'fumasw', '--bet-id' => $record->bet_id]);

        $this->assertSame(0, $exitCode);
    }

    #[Group('rules/single-wallet-unsettled-refund#R-1')]
    public function test_unknown_vendor_fails_before_touching_anything(): void
    {
        $record = $this->seedUnsettledBet();

        $exitCode = Artisan::call(self::CMD, ['--vendor' => 'nosuchvendor', '--bet-id' => $record->bet_id, '--execute' => true, '--reason' => 't']);

        $this->assertSame(1, $exitCode);
        $this->assertSame('100.0000', $this->memberBalance());
    }
}
