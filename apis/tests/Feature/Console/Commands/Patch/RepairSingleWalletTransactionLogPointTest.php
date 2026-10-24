<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Patch;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TransactionLog;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * patch:repair-single-wallet-transaction-log-point 的行為測試。
 *
 * 夾具全部由本測試建立、DatabaseTransactions 回滾；斷言只看自己建的列，不受測試庫殘留影響。
 *
 * php artisan test tests/Feature/Console/Commands/Patch/RepairSingleWalletTransactionLogPointTest.php
 */
class RepairSingleWalletTransactionLogPointTest extends TestCase
{
    private const string COMMAND = 'patch:repair-single-wallet-transaction-log-point';

    private Agent $agent;

    private Member $member;

    private Carbon $sessionStart;

    private Carbon $sessionEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->sessionStart = Carbon::now()->subDays(10)->startOfHour();
        $this->sessionEnd = $this->sessionStart->copy()->addMinutes(30);
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        $drifted = $this->makeGamingLog(before: '1000.4803', balance: '0.4803', point: '1200.0000');

        $exit = $this->artisan(self::COMMAND, ['--months' => 3, '--vendor' => VendorIdEnum::ASTAR->value])->run();

        $this->assertSame(SymfonyCommand::SUCCESS, $exit);
        $this->assertSame('1200.0000', $drifted->fresh()->point);
    }

    public function test_execute_requires_reason(): void
    {
        $exit = $this->artisan(self::COMMAND, ['--months' => 3, '--execute' => true])->run();

        $this->assertSame(SymfonyCommand::FAILURE, $exit);
    }

    public function test_repairs_drifted_point_and_vendor_point_from_balances(): void
    {
        $drifted = $this->makeGamingLog(before: '1000.4803', balance: '0.4803', point: '1200.0000', rate: '2.0000');

        $this->runExecute();

        $drifted->refresh();
        $this->assertSame('1000.0000', $drifted->point);
        $this->assertSame('2000.0000', $drifted->vendor_point);
    }

    public function test_repairs_negative_drift_where_player_won(): void
    {
        // 玩家贏：錢包 3000.87 → 13700.87，正確 point 為 −10700，現值 −10200
        $drifted = $this->makeGamingLog(before: '3000.8728', balance: '13700.8728', point: '-10200.0000');

        $this->runExecute();

        $this->assertSame('-10700.0000', $drifted->fresh()->point);
    }

    public function test_leaves_consistent_row_untouched(): void
    {
        $ok = $this->makeGamingLog(before: '500.0000', balance: '100.0000', point: '400.0000');
        $updatedAt = $ok->fresh()->updated_at;

        $this->runExecute();

        $ok->refresh();
        $this->assertSame('400.0000', $ok->point);
        $this->assertTrue($updatedAt->equalTo($ok->updated_at));
    }

    public function test_leaves_in_progress_and_non_gaming_rows_untouched(): void
    {
        $gaming = $this->makeGamingLog(before: '1000.0000', balance: '0.0000', point: '1200.0000', status: TransactionStatusEnum::STATUS_GAMING->value);
        $deposit = $this->makeLog(type: 3, before: '0.0000', balance: '1000.0000', point: '999.0000', createdAt: $this->sessionStart->copy()->subMinute());

        $this->runExecute();

        $this->assertSame('1200.0000', $gaming->fresh()->point);
        $this->assertSame('999.0000', $deposit->fresh()->point);
    }

    public function test_skips_row_when_deposit_landed_before_a_later_merge(): void
    {
        // session 中途儲值 2000（錢包 0.48 → 2000.48），之後又有派彩併入 → balance 含儲值金額，不可機械回補
        $drifted = $this->makeGamingLog(before: '1000.4803', balance: '2100.4803', point: '900.0000');
        $this->makeLog(type: 3, before: '0.4803', balance: '2000.4803', point: '2000.0000', createdAt: $this->sessionStart->copy()->addMinutes(10));

        $this->runExecute();

        $this->assertSame('900.0000', $drifted->fresh()->point);
    }

    public function test_repairs_row_when_deposit_landed_after_the_last_merge(): void
    {
        // 儲值發生在最後一次合併之後（儲值的 before_balance == 本列 balance），balance 未被污染，可回補
        $drifted = $this->makeGamingLog(before: '1000.4803', balance: '0.4803', point: '1200.0000');
        $this->makeLog(type: 3, before: '0.4803', balance: '2000.4803', point: '2000.0000', createdAt: $this->sessionStart->copy()->addMinutes(10));

        $this->runExecute();

        $this->assertSame('1000.0000', $drifted->fresh()->point);
    }

    public function test_vendor_filter_limits_scope(): void
    {
        $astar = $this->makeGamingLog(before: '1000.0000', balance: '0.0000', point: '1200.0000', vendorId: VendorIdEnum::ASTAR->value);
        $kiss = $this->makeGamingLog(before: '100.0000', balance: '0.0000', point: '90.0000', vendorId: VendorIdEnum::KISSH5SW->value);

        $this->runExecute(['--vendor' => VendorIdEnum::ASTAR->value]);

        $this->assertSame('1000.0000', $astar->fresh()->point);
        $this->assertSame('90.0000', $kiss->fresh()->point);
    }

    public function test_rows_older_than_window_are_not_touched(): void
    {
        $old = $this->makeGamingLog(
            before: '1000.0000',
            balance: '0.0000',
            point: '1200.0000',
            createdAt: Carbon::now()->subMonths(4),
        );

        $this->runExecute(['--months' => 3]);

        $this->assertSame('1200.0000', $old->fresh()->point);
    }

    // ---------- 夾具 ----------

    private function runExecute(array $options = []): void
    {
        $exit = $this->artisan(self::COMMAND, ['--months' => 3, '--execute' => true, '--reason' => 'test'] + $options)->run();

        $this->assertSame(SymfonyCommand::SUCCESS, $exit);
    }

    private function makeGamingLog(
        string $before,
        string $balance,
        string $point,
        string $rate = '1.0000',
        int $status = TransactionStatusEnum::STATUS_SUCCESS->value,
        int $vendorId = VendorIdEnum::ASTAR->value,
        ?Carbon $createdAt = null,
    ): TransactionLog {
        return $this->makeLog(
            type: TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value,
            before: $before,
            balance: $balance,
            point: $point,
            createdAt: $createdAt ?? $this->sessionStart,
            updatedAt: ($createdAt ?? $this->sessionStart)->copy()->addMinutes(30),
            rate: $rate,
            status: $status,
            vendorId: $vendorId,
        );
    }

    private function makeLog(
        int $type,
        string $before,
        string $balance,
        string $point,
        Carbon $createdAt,
        ?Carbon $updatedAt = null,
        string $rate = '1.0000',
        int $status = TransactionStatusEnum::STATUS_SUCCESS->value,
        int $vendorId = 0,
    ): TransactionLog {
        $log = new TransactionLog([
            'transaction_no' => 'T' . uniqid(),
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
            'type' => $type,
            'point' => $point,
            'vendor_point' => $point,
            'rate' => $rate,
            'before_balance' => $before,
            'balance' => $balance,
            'status' => $status,
            'operator' => 'test',
            'admin' => 0,
        ]);
        $log->created_at = $createdAt;
        $log->updated_at = $updatedAt ?? $createdAt;
        $log->save();

        return $log;
    }
}
