<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecordRecovery;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\TotalBetRecord;
use App\Models\TotalBetRecordTransactionLog;
use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\External\SingleWallets\KissH5SW\KissH5TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/BetRecordRecovery/RevertTest.php --compact
 */
class RevertTest extends KissH5TestCase
{
    private function seedRecoveredBetRecord(string $balanceDiff = '200.0000'): array
    {
        $record = KissH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => '999000001',
            'bet_id' => '999000002',
            'reference_no' => 'REF-RECOVERED',
            'username' => $this->acctId,
            'game_code' => '10000',
            'bet' => '100.0000',
            'valid_bet' => '100.0000',
            'win' => '300.0000',
            'win_lose' => '200.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2026-04-30 12:00:00',
            'payout_time' => '2026-04-30 12:05:00',
            'status' => 1,
        ]);

        $totalBetRecord = TotalBetRecord::create([
            'summary_time' => '2026-04-30 12:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $record->id,
            'game_id' => 0,
            'reference_no' => $record->bet_id,
            'bet' => '100.0000',
            'valid_bet' => '100.0000',
            'win_lose' => '200.0000',
            'rolling_chip' => '0.0000',
            'jackpot' => '0.0000',
            'bonus' => '0.0000',
            'valid_bet_rate' => 1,
            'commission' => 0,
            'bet_time' => '2026-04-30 12:00:00',
            'payout_time' => '2026-04-30 12:05:00',
            'admin' => 0,
        ]);

        $log = BetRecordRecoveryLog::create([
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $record->id,
            'bet_id' => (string) $record->bet_id,
            'member_id' => $this->member->id,
            'type' => BetRecordRecoveryTypeEnum::RECOVER->value,
            'performed_at' => now(),
            'performed_by' => 'kissh5sw:recover-orphans',
            'reason' => '測試補帳',
            'source' => 'test.csv:row#1',
            'before' => [
                'status' => 0,
                'reference_no' => '',
                'bet' => '100.0000',
                'valid_bet' => '100.0000',
                'win' => '0.0000',
                'win_lose' => '-100.0000',
                'payout_time' => null,
            ],
            'after' => [
                'status' => 1,
                'reference_no' => 'REF-RECOVERED',
                'bet' => '100.0000',
                'valid_bet' => '100.0000',
                'win' => '300.0000',
                'win_lose' => '200.0000',
                'payout_time' => '2026-04-30 12:05:00',
                '_meta' => [
                    'transaction_log_id' => 9999,
                    'total_bet_record_id' => $totalBetRecord->id,
                    'is_multiple_wallet' => false,
                ],
            ],
            'balance_diff' => $balanceDiff,
            'parent_log_id' => null,
        ]);

        return ['record' => $record, 'log' => $log, 'totalBetRecord' => $totalBetRecord];
    }

    public function test_revert_execute_reverses_state_and_writes_revert_log(): void
    {
        $this->wallet->update(['balance' => '1000.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'test 測試',
        ]);

        $this->assertSame(0, $exitCode);

        $seed['record']->refresh();
        $this->assertSame(0, (int) $seed['record']->status);
        $this->assertSame('0.0000', (string) $seed['record']->win);
        $this->assertSame('-100.0000', (string) $seed['record']->win_lose);

        $this->wallet->refresh();
        $this->assertSame('800.0000', (string) $this->wallet->balance);

        $revertLog = BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)
            ->where('type', BetRecordRecoveryTypeEnum::REVERT->value)
            ->first();
        $this->assertNotNull($revertLog);
        $this->assertSame('-200.0000', (string) $revertLog->balance_diff);

        $this->assertNull(TotalBetRecord::find($seed['totalBetRecord']->id));
        $this->assertSame(
            0,
            TotalBetRecordTransactionLog::where('total_bet_record_id', $seed['totalBetRecord']->id)->count()
        );
    }

    public function test_revert_dry_run_does_not_modify_state(): void
    {
        $this->wallet->update(['balance' => '1000.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
        ]);

        $this->assertSame(0, $exitCode);

        $seed['record']->refresh();
        $this->assertSame(1, (int) $seed['record']->status);

        $this->wallet->refresh();
        $this->assertSame('1000.0000', (string) $this->wallet->balance);

        $this->assertSame(
            0,
            BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)->count()
        );
    }

    public function test_revert_refuses_when_drift_detected(): void
    {
        $this->wallet->update(['balance' => '1000.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $seed['record']->win = '999.9999';
        $seed['record']->save();

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'drift test',
        ]);

        $this->assertSame(0, $exitCode);

        $this->wallet->refresh();
        $this->assertSame('1000.0000', (string) $this->wallet->balance);

        $this->assertSame(
            0,
            BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)->count()
        );
    }

    public function test_revert_with_force_proceeds_despite_drift(): void
    {
        $this->wallet->update(['balance' => '1000.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $seed['record']->win = '999.9999';
        $seed['record']->save();

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'force drift',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->wallet->refresh();
        $this->assertSame('800.0000', (string) $this->wallet->balance);

        $this->assertSame(
            1,
            BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)->count()
        );
    }

    public function test_revert_refuses_to_double_revert_same_recover(): void
    {
        $this->wallet->update(['balance' => '1000.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'first',
        ]);

        $this->wallet->refresh();
        $this->assertSame('800.0000', (string) $this->wallet->balance);

        Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'second',
        ]);

        $this->wallet->refresh();
        $this->assertSame('800.0000', (string) $this->wallet->balance);

        $this->assertSame(
            1,
            BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)
                ->where('type', BetRecordRecoveryTypeEnum::REVERT->value)
                ->count()
        );
    }

    public function test_revert_allows_negative_balance_and_reports_bad_debt(): void
    {
        $this->wallet->update(['balance' => '50.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'bad-debt scenario',
        ]);

        $this->assertSame(0, $exitCode);

        $this->wallet->refresh();
        $this->assertSame('-150.0000', (string) $this->wallet->balance);

        $this->assertSame(-1, Common::numCompare((string) $this->wallet->balance, '0'));
    }

    public function test_revert_aborts_when_max_bad_debt_exceeded(): void
    {
        $this->wallet->update(['balance' => '50.0000']);
        $seed = $this->seedRecoveredBetRecord('200.0000');

        $exitCode = Artisan::call('bet-record-recovery:revert', [
            '--log-id' => $seed['log']->id,
            '--execute' => true,
            '--reason' => 'guard test',
            '--max-bad-debt' => '100',
        ]);

        $this->assertSame(1, $exitCode);

        $this->wallet->refresh();
        $this->assertSame('50.0000', (string) $this->wallet->balance);

        $this->assertSame(
            0,
            BetRecordRecoveryLog::where('parent_log_id', $seed['log']->id)->count()
        );
    }
}
