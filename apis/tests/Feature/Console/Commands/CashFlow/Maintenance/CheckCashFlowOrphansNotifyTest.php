<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/CheckCashFlowOrphansNotifyTest.php
 *
 * --notify：排程安全網模式。掃全 APIS 範疇的 4-layer 孤兒，發現即 BotNotify。
 *
 * 刻意設計：
 * - 唯讀。只報告不動資料（下架仍走 fix:cashflow-orphans 或 Observer 即時連動）。
 * - --notify 一律回 SUCCESS。孤兒存在不是「命令失敗」，否則排程每天紅、告警與 BotNotify 重複。
 */
class CheckCashFlowOrphansNotifyTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_notify_sends_bot_message_when_orphans_found(): void
    {
        $acfot = $this->makeL3Orphan();

        BotNotify::shouldReceive('send')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, '孤立')
                    && ($context['total'] ?? 0) >= 1;
            });

        // 指定 --cashflow：預設 scope=apis 只掃 cf > 62，而測試庫的 cash_flows 自增 id 起點不固定
        // （並行測試每個 process 各自一個乾淨的庫，id 可能 <= 62），不指定就會掃不到自己造的孤兒
        $this->artisan('check:cashflow-orphans', ['--notify' => true, '--cashflow' => (string) $acfot->cash_flow_id])
            ->assertExitCode(0);

        $this->assertSame(1, (int) $acfot->refresh()->status, '--notify 為唯讀，不得動資料');
    }

    public function test_notify_returns_success_even_with_orphans(): void
    {
        $acfot = $this->makeL3Orphan();

        BotNotify::shouldReceive('send')->once();

        // 對照：不帶 --notify 時，既有行為是有孤兒回 FAILURE(1)
        $this->artisan('check:cashflow-orphans', ['--notify' => true, '--cashflow' => (string) $acfot->cash_flow_id])
            ->assertExitCode(0);
    }

    public function test_notify_stays_silent_when_no_orphans(): void
    {
        BotNotify::shouldReceive('send')->never();

        // 指定一個不存在孤兒的 cash_flow，避免受測試庫既有資料影響
        $cashFlow = CashFlow::create([
            'name' => 'Test Clean ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $this->artisan('check:cashflow-orphans', ['--notify' => true, '--cashflow' => (string) $cashFlow->id])
            ->assertExitCode(0);
    }

    /**
     * 造一筆 L3_ACF_DISABLED 孤兒：ACF 已停用（直接建 status=0，不經 update 以免觸發連動 Observer）
     * 但旗下 ACFOT 仍 status=1。
     */
    private function makeL3Orphan(): AgentCashFlowOrderType
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test Orphan ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $acf = AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) Orphan Test',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => 0,
            'admin' => 0,
        ]);

        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => 'Orphan OT',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => 'ORPHAN_' . uniqid(),
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);

        return AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $acf->id,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $cashFlow->id,
            'min_amount' => 0,
            'max_amount' => 0,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => 1,
        ]);
    }
}
