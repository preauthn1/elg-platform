<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Services\Database\TableBackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * cash-flow:set-cost：預演不寫入、執行只改 cost、不合格整批零寫入、備份失敗即中止。
 *
 * 連錯庫防護自查：TableBackupService 一律以 mock 取代，本測試不執行任何 DDL（真的備份是 CREATE TABLE，
 * 會隱式 commit 掉 fixture）；資料列只由 DatabaseTransactions 回滾，不 truncate、不無條件 delete。
 */
class SetAgentCashFlowCostCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CF_DEPOSIT = 99781;

    private const CF_WITHDRAW = 99782;

    private const STAMP = '20990101000000';

    private function seedAcf(int $cashFlowId, int $type, string $cost = '0'): AgentCashFlow
    {
        CashFlow::create([
            'id' => $cashFlowId, 'name' => 'Sentinel ' . $cashFlowId, 'type' => $type, 'status' => 1, 'info' => '', 'admin' => 0,
        ]);

        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => '(MYR) Sentinel ' . $cashFlowId, 'status' => 1, 'admin' => 0,
            'info' => [], 'cost' => $cost,
        ]);
    }

    /** 預期備份被呼叫一次，且範圍正好是 $ids */
    private function expectBackupOf(array $ids): void
    {
        $this->mock(TableBackupService::class, function (MockInterface $mock) use ($ids): void {
            $mock->shouldReceive('capture')
                ->once()
                ->withArgs(fn (array $full, array $scoped): bool => $full === []
                    && ($scoped['agent_cash_flows']['column'] ?? null) === 'id'
                    && ($scoped['agent_cash_flows']['ids'] ?? null) === $ids)
                ->andReturn([
                    'stamp' => self::STAMP,
                    'tables' => ['agent_cash_flows' => [
                        'backup_table' => 'agent_cash_flows_bak_' . self::STAMP, 'scope' => 'id in ids', 'rows' => count($ids),
                    ]],
                ]);
        });
    }

    private function expectNoBackup(): void
    {
        $this->mock(TableBackupService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('capture');
        });
    }

    #[Group('rules/payment-channel-management#R-13')]
    public function test_dry_run_reports_plan_without_writing(): void
    {
        $acf = $this->seedAcf(self::CF_DEPOSIT, 1);
        $this->expectNoBackup();

        $this->artisan('cash-flow:set-cost', ['--pair' => [$acf->id . ':0.005'], '--reason' => 'test'])
            ->expectsOutputToContain('預演完成：1 個商戶可設定')
            ->assertExitCode(0);

        $this->assertSame('0.0000', (string) $acf->fresh()->cost);
    }

    #[Group('rules/payment-channel-management#R-13')]
    #[Group('rules/payment-channel-management#R-17')]
    public function test_execute_backs_up_then_updates_only_cost(): void
    {
        $deposit = $this->seedAcf(self::CF_DEPOSIT, 1);
        $withdraw = $this->seedAcf(self::CF_WITHDRAW, 3);
        $this->expectBackupOf([$deposit->id, $withdraw->id]);

        $before = $deposit->fresh()->only(['name', 'rate', 'status', 'tax', 'tax_percentage', 'agent_group_id']);

        $this->artisan('cash-flow:set-cost', [
            '--pair' => [$deposit->id . ':0.005', $withdraw->id . ':0.01'],
            '--reason' => 'test', '--execute' => true, '--force' => true,
        ])
            ->expectsOutputToContain('設定完成：2 個商戶')
            ->expectsOutputToContain(sprintf('db:backup-rollback --stamp=%s --table=agent_cash_flows', self::STAMP))
            ->assertExitCode(0);

        $this->assertSame('0.0050', (string) $deposit->fresh()->cost);
        $this->assertSame('0.0100', (string) $withdraw->fresh()->cost);
        $this->assertSame($before, $deposit->fresh()->only(array_keys($before)));
    }

    #[Group('rules/payment-channel-management#R-16')]
    public function test_row_already_at_target_is_skipped_and_not_backed_up(): void
    {
        $same = $this->seedAcf(self::CF_DEPOSIT, 1, '0.005');
        $other = $this->seedAcf(self::CF_WITHDRAW, 3);
        $this->expectBackupOf([$other->id]);

        $this->artisan('cash-flow:set-cost', [
            '--pair' => [$same->id . ':0.0050', $other->id . ':0.008'],
            '--reason' => 'test', '--execute' => true, '--force' => true,
        ])
            ->expectsOutputToContain('設定完成：1 個商戶')
            ->assertExitCode(0);

        $this->assertSame('0.0050', (string) $same->fresh()->cost);
        $this->assertSame('0.0080', (string) $other->fresh()->cost);
    }

    #[Group('rules/payment-channel-management#R-15')]
    public function test_missing_row_blocks_whole_batch(): void
    {
        $acf = $this->seedAcf(self::CF_DEPOSIT, 1);
        $missingId = (int) AgentCashFlow::withTrashed()->max('id') + 1000;
        $this->expectNoBackup();

        $this->artisan('cash-flow:set-cost', [
            '--pair' => [$acf->id . ':0.005', $missingId . ':0.005'],
            '--reason' => 'test', '--execute' => true, '--force' => true,
        ])
            ->expectsOutputToContain(sprintf('id=%d 不存在', $missingId))
            ->assertExitCode(1);

        $this->assertSame('0.0000', (string) $acf->fresh()->cost);
    }

    #[Group('rules/payment-channel-management#R-14')]
    public function test_high_cost_requires_explicit_flag(): void
    {
        $acf = $this->seedAcf(self::CF_DEPOSIT, 1);
        $this->expectNoBackup();

        $this->artisan('cash-flow:set-cost', [
            '--pair' => [$acf->id . ':0.25'], '--reason' => 'test', '--execute' => true, '--force' => true,
        ])
            ->expectsOutputToContain('--allow-high-cost')
            ->assertExitCode(1);

        $this->assertSame('0.0000', (string) $acf->fresh()->cost);
    }

    #[Group('rules/payment-channel-management#R-17')]
    public function test_backup_failure_aborts_without_writing(): void
    {
        $acf = $this->seedAcf(self::CF_DEPOSIT, 1);

        $this->mock(TableBackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('capture')->once()->andThrow(new RuntimeException('disk full'));
        });

        $this->artisan('cash-flow:set-cost', [
            '--pair' => [$acf->id . ':0.005'], '--reason' => 'test', '--execute' => true, '--force' => true,
        ])
            ->expectsOutputToContain('備份失敗，未寫入任何資料')
            ->assertExitCode(1);

        $this->assertSame('0.0000', (string) $acf->fresh()->cost);
    }
}
