<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor\KissH5SW;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\BetRecordRecoveryLog;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\WalletService;
use App\Vendors\Models\KissH5SWAccount;
use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\External\SingleWallets\KissH5SW\KissH5TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/Vendor/KissH5SW/RepairOrphansTest.php --compact
 */
class RepairOrphansTest extends KissH5TestCase
{
    private string $pBetId = '70000003735205777';

    private string $oBetId = '70000003735205902';

    private string $pTrueRef = '70000003735205778';

    private string $oTrueRef = '70000003735205903';

    private string $pureBetId = '70000003735300001';

    private string $pureTrueRef = '70000003735300002';

    private Member $playerO;

    private Member|MemberWallet $playerOWallet;

    private string $playerOUsername;

    protected function setUp(): void
    {
        parent::setUp();

        $this->playerOUsername = '233_KISSH5_99999999';

        $u = bin2hex(random_bytes(6));
        $this->playerO = $this->createMember($this->agent, [
            'username' => 'pO' . $u,
            'nickname' => 'pO' . $u,
        ]);
        KissH5SWAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerOUsername,
            'admin' => 1,
            'member_id' => $this->playerO->id,
            'auth_token' => null,
        ]);

        $oWalletDto = (new WalletService)->getMemberLockedWallets($this->playerO, $this->vendorInfo);
        $this->playerOWallet = $oWalletDto->wallet;
    }

    private function makeCsv(string $path, bool $includePair = true, bool $includePure = false): void
    {
        $rows = [
            ['No', 'TranID', 'BetTranID', 'TranDateTime', 'PId', 'PlayerId', 'GameId', 'GameName', 'GameProviderId', 'OpId', 'OpCode', 'BrandCode', 'CurrencyCode', 'ValidTurnover', 'Turnover', 'Payout', 'CurrentBalance', 'ExchangeRate', 'TranType', 'TranDateInt', 'GameServerRefId', 'RoundType'],
        ];
        if ($includePair) {
            // P 真實：bet 5、payout 50
            $rows[] = ['1', $this->pTrueRef, $this->pBetId, '2026-05-01 10:00:00.000', '99001', $this->acctId, '260030', 'TestSlot', '1', '1801', 'KS5', 'KISSH5', 'MYR', '-5.00', '-5.00', '50.00', '95.00', '1.00', '2', '20260501', '111', '0'];
            // O 真實：bet 10、payout 30
            $rows[] = ['2', $this->oTrueRef, $this->oBetId, '2026-05-01 10:00:01.000', '99002', $this->playerOUsername, '260030', 'TestSlot', '1', '1801', 'KS5', 'KISSH5', 'MYR', '-10.00', '-10.00', '30.00', '20.00', '1.00', '2', '20260501', '112', '0'];
        }
        if ($includePure) {
            // 純孤兒：bet 8、payout 20
            $rows[] = ['3', $this->pureTrueRef, $this->pureBetId, '2026-05-01 11:00:00.000', '99001', $this->acctId, '260030', 'TestSlot', '1', '1801', 'KS5', 'KISSH5', 'MYR', '-8.00', '-8.00', '20.00', '88.00', '1.00', '2', '20260501', '113', '0'];
        }

        $fp = fopen($path, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

    /**
     * @return array{pollution: KissH5SWBetRecord, orphan: KissH5SWBetRecord}
     */
    private function makePair(): array
    {
        $pollution = KissH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => $this->pBetId,
            'bet_id' => $this->pBetId,
            'reference_no' => 'WRONG-REF-FROM-O',
            'username' => $this->acctId,
            'game_code' => '260030',
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win' => '30.0000',
            'win_lose' => '20.0000',
            'rolling_chip' => '0.0000',
            'info' => json_encode([
                ['BetId' => $this->pBetId, 'BetAmount' => 5.0],
                ['BetId' => $this->oBetId, 'Payout' => 30.0, 'ResultId' => 'WRONG-REF-FROM-O'],
            ]),
            'bet_time' => '2026-05-01 09:59:00',
            'payout_time' => '2026-05-01 10:00:01',
            'status' => 1,
        ]);

        $orphan = KissH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->playerO->id,
            'type' => 'N',
            'round_id' => $this->oBetId,
            'bet_id' => $this->oBetId,
            'reference_no' => 'PENDING-PAIR-' . bin2hex(random_bytes(4)),
            'username' => $this->playerOUsername,
            'game_code' => '260030',
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win' => '0.0000',
            'win_lose' => '-10.0000',
            'rolling_chip' => '0.0000',
            'info' => json_encode([
                ['BetId' => $this->oBetId, 'BetAmount' => 10.0],
            ]),
            'bet_time' => '2026-05-01 09:59:30',
            'payout_time' => null,
            'status' => 0,
        ]);

        return ['pollution' => $pollution, 'orphan' => $orphan];
    }

    private function makePureOrphan(): KissH5SWBetRecord
    {
        return KissH5SWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'type' => 'N',
            'round_id' => $this->pureBetId,
            'bet_id' => $this->pureBetId,
            'reference_no' => 'PENDING-PURE-' . bin2hex(random_bytes(4)),
            'username' => $this->acctId,
            'game_code' => '260030',
            'bet' => '8.0000',
            'valid_bet' => '8.0000',
            'win' => '0.0000',
            'win_lose' => '-8.0000',
            'rolling_chip' => '0.0000',
            'info' => json_encode([
                ['BetId' => $this->pureBetId, 'BetAmount' => 8.0],
            ]),
            'bet_time' => '2026-05-01 10:59:00',
            'payout_time' => null,
            'status' => 0,
        ]);
    }

    public function test_dry_run_does_not_modify_state(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_dryrun.csv';
        $this->makeCsv($csvPath, includePair: true, includePure: true);
        $pair = $this->makePair();
        $pure = $this->makePureOrphan();
        $this->wallet->update(['balance' => '100.0000']);
        $this->playerOWallet->update(['balance' => '50.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
        ]);

        $this->assertSame(0, $exit);

        $pair['pollution']->refresh();
        $pair['orphan']->refresh();
        $pure->refresh();
        $this->assertSame('30.0000', (string) $pair['pollution']->win);
        $this->assertSame(0, (int) $pair['orphan']->status);
        $this->assertSame(0, (int) $pure->status);

        $this->wallet->refresh();
        $this->playerOWallet->refresh();
        $this->assertSame('100.0000', (string) $this->wallet->balance);
        $this->assertSame('50.0000', (string) $this->playerOWallet->balance);

        $this->assertSame(0, BetRecordRecoveryLog::count());

        @unlink($csvPath);
    }

    public function test_execute_repairs_pair(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_pair.csv';
        $this->makeCsv($csvPath, includePair: true);
        $seed = $this->makePair();
        $this->wallet->update(['balance' => '100.0000']);
        $this->playerOWallet->update(['balance' => '50.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
            '--reason' => 'pair test',
        ]);

        $this->assertSame(0, $exit);

        $seed['pollution']->refresh();
        $this->assertSame('5.0000', (string) $seed['pollution']->bet);
        $this->assertSame('50.0000', (string) $seed['pollution']->win);
        $this->assertSame('45.0000', (string) $seed['pollution']->win_lose);
        $this->assertSame($this->pTrueRef, (string) $seed['pollution']->reference_no);

        $seed['orphan']->refresh();
        $this->assertSame(1, (int) $seed['orphan']->status);
        $this->assertSame('30.0000', (string) $seed['orphan']->win);
        $this->assertSame('20.0000', (string) $seed['orphan']->win_lose);
        $this->assertSame($this->oTrueRef, (string) $seed['orphan']->reference_no);

        // P 錢包：100 + (50 - 30) = 120
        $this->wallet->refresh();
        $this->assertSame('120.0000', (string) $this->wallet->balance);

        // O 錢包：50 + 30 = 80
        $this->playerOWallet->refresh();
        $this->assertSame('80.0000', (string) $this->playerOWallet->balance);

        $this->assertSame(2, BetRecordRecoveryLog::count());
        $pLog = BetRecordRecoveryLog::where('bet_record_id', $seed['pollution']->id)->first();
        $oLog = BetRecordRecoveryLog::where('bet_record_id', $seed['orphan']->id)->first();
        $this->assertSame('pollution', $pLog->after['_meta']['pair_role']);
        $this->assertSame((int) $seed['orphan']->id, (int) $pLog->after['_meta']['paired_bet_record_id']);
        $this->assertSame('paired_orphan', $oLog->after['_meta']['pair_role']);
        $this->assertSame((int) $seed['pollution']->id, (int) $oLog->after['_meta']['paired_bet_record_id']);

        @unlink($csvPath);
    }

    public function test_execute_repairs_pure_orphan(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_pure.csv';
        $this->makeCsv($csvPath, includePair: false, includePure: true);
        $pure = $this->makePureOrphan();
        $this->wallet->update(['balance' => '100.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
            '--reason' => 'pure orphan test',
        ]);

        $this->assertSame(0, $exit);

        $pure->refresh();
        $this->assertSame(1, (int) $pure->status);
        $this->assertSame('8.0000', (string) $pure->bet);
        $this->assertSame('20.0000', (string) $pure->win);
        $this->assertSame('12.0000', (string) $pure->win_lose);
        $this->assertSame($this->pureTrueRef, (string) $pure->reference_no);

        $this->wallet->refresh();
        $this->assertSame('120.0000', (string) $this->wallet->balance);

        $this->assertSame(1, BetRecordRecoveryLog::count());
        $log = BetRecordRecoveryLog::where('bet_record_id', $pure->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('pure_orphan', $log->after['_meta']['pair_role']);
        $this->assertNull($log->after['_meta']['paired_bet_record_id']);
        $this->assertSame('20.0000', (string) $log->balance_diff);

        @unlink($csvPath);
    }

    public function test_execute_handles_pair_and_pure_in_one_run(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_mixed.csv';
        $this->makeCsv($csvPath, includePair: true, includePure: true);
        $seed = $this->makePair();
        $pure = $this->makePureOrphan();
        $this->wallet->update(['balance' => '100.0000']);
        $this->playerOWallet->update(['balance' => '50.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
            '--reason' => 'mixed test',
        ]);

        $this->assertSame(0, $exit);

        $seed['pollution']->refresh();
        $seed['orphan']->refresh();
        $pure->refresh();
        $this->assertSame('5.0000', (string) $seed['pollution']->bet);
        $this->assertSame(1, (int) $seed['orphan']->status);
        $this->assertSame(1, (int) $pure->status);

        // P 錢包：100 + 20（pair） + 20（pure：8 bet 早扣過、補 20 win）= 140
        $this->wallet->refresh();
        $this->assertSame('140.0000', (string) $this->wallet->balance);

        $this->playerOWallet->refresh();
        $this->assertSame('80.0000', (string) $this->playerOWallet->balance);

        // 3 筆 log：pair 兩筆 + pure 一筆
        $this->assertSame(3, BetRecordRecoveryLog::count());

        @unlink($csvPath);
    }

    public function test_skips_pair_when_csv_missing_pollution(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_missing_p.csv';
        // CSV 只有 O 真實，沒 P
        $fp = fopen($csvPath, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['No', 'TranID', 'BetTranID', 'TranDateTime', 'PId', 'PlayerId', 'GameId', 'GameName', 'GameProviderId', 'OpId', 'OpCode', 'BrandCode', 'CurrencyCode', 'ValidTurnover', 'Turnover', 'Payout', 'CurrentBalance', 'ExchangeRate', 'TranType', 'TranDateInt', 'GameServerRefId', 'RoundType']);
        fputcsv($fp, ['1', $this->oTrueRef, $this->oBetId, '2026-05-01 10:00:01.000', '99002', $this->playerOUsername, '260030', 'TestSlot', '1', '1801', 'KS5', 'KISSH5', 'MYR', '-10.00', '-10.00', '30.00', '20.00', '1.00', '2', '20260501', '112', '0']);
        fclose($fp);

        $seed = $this->makePair();
        $this->wallet->update(['balance' => '100.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
            '--reason' => 'missing P test',
        ]);

        $this->assertSame(0, $exit);

        $seed['pollution']->refresh();
        $this->assertSame('30.0000', (string) $seed['pollution']->win);
        $this->wallet->refresh();
        $this->assertSame('100.0000', (string) $this->wallet->balance);
        $this->assertSame(0, BetRecordRecoveryLog::count());

        @unlink($csvPath);
    }

    public function test_skips_already_recovered_orphan(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_recovered.csv';
        $this->makeCsv($csvPath, includePair: false, includePure: true);
        $pure = $this->makePureOrphan();

        BetRecordRecoveryLog::create([
            'vendor_id' => VendorIdEnum::KISSH5SW->value,
            'bet_record_id' => $pure->id,
            'bet_id' => (string) $pure->bet_id,
            'member_id' => $pure->member_id,
            'type' => BetRecordRecoveryTypeEnum::RECOVER->value,
            'performed_at' => now(),
            'performed_by' => 'test',
            'reason' => 'pre-existing',
            'before' => [],
            'after' => [],
            'balance_diff' => '0',
            'parent_log_id' => null,
        ]);

        $this->wallet->update(['balance' => '100.0000']);

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
            '--reason' => 'idempotency test',
        ]);

        $this->assertSame(0, $exit);

        $pure->refresh();
        $this->assertSame(0, (int) $pure->status);
        $this->wallet->refresh();
        $this->assertSame('100.0000', (string) $this->wallet->balance);
        $this->assertSame(1, BetRecordRecoveryLog::count(), '應只剩 setup 那筆 log');

        @unlink($csvPath);
    }

    public function test_execute_requires_reason(): void
    {
        $csvPath = sys_get_temp_dir() . '/repair_test_no_reason.csv';
        $this->makeCsv($csvPath, includePair: false, includePure: true);
        $this->makePureOrphan();

        $exit = Artisan::call('kissh5sw:repair-orphans', [
            'csv' => [$csvPath],
            '--execute' => true,
        ]);

        $this->assertSame(1, $exit);

        @unlink($csvPath);
    }
}
