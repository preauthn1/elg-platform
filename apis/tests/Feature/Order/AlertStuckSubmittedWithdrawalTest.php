<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\Payment\PayoutQueryOutcome;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\BotNotifyService;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use App\Services\Payment\Data\PayoutQueryResult;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * withdrawal:alert-stuck-submitted — 停留代付請求中(21)超時掃描告警（target/tickets#4137 E 項）
 *
 * pod 被終止時程式已消失、發不出即時告警；本命令補上「怎麼發現」的缺口。
 * 去重採「送出成功才標記」：BotNotify 失敗時 key 不寫，下輪重試，不靜默吞單。
 * 告警前先走三方查單自動查證（SupportsPayoutQuery 擴充點）：有單→補單號轉 22；
 * 查無／未知／不支援→照舊告警。
 *
 * php artisan test tests/Feature/Order/AlertStuckSubmittedWithdrawalTest.php
 */
class AlertStuckSubmittedWithdrawalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_alerts_stuck_submitted_order_once(): void
    {
        BotNotify::spy();
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();
        // 同單重複掃描不得重複轟炸（6 小時去重）
        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        BotNotify::shouldHaveReceived('send')->once();
        // 告警不改單：發現交人工，定案走按鈕／命令
        $this->assertSame(21, (int) $order->refresh()->status);
    }

    public function test_notify_failure_leaves_order_unmarked_for_retry(): void
    {
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);

        // 第一輪：通知失敗 → 去重 key 不得寫入
        BotNotify::shouldReceive('send')->once()->andThrow(new \RuntimeException('notify down'));
        $this->artisan('withdrawal:alert-stuck-submitted')->assertFailed();

        // 第二輪：通知恢復 → 同一單必須被重新告警
        // 偏離計劃：BotNotify::spy() 在 facade 已是 mock（shouldReceive 建立）時為 no-op
        // （Illuminate\Support\Facades\Facade::spy() 內部 isMock() 短路，見框架原始碼），
        // 沿用舊 mock 會使第一輪 ->once() 期望在本輪第二次呼叫時立即拋 Mockery 例外，
        // 被 command 的 catch(\Throwable) 吞掉誤判為送出失敗。改用 swap() 明確換新 spy。
        BotNotify::swap(Mockery::spy(BotNotifyService::class));
        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();
        BotNotify::shouldHaveReceived('send')->once();
        $this->assertSame(21, (int) $order->refresh()->status);
    }

    public function test_fresh_submitted_order_is_not_alerted(): void
    {
        BotNotify::spy();
        $this->makeSubmittedOrder(stuckMinutes: 2);

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        // 正常在途的 21（HTTP 尚未回來）不告警
        BotNotify::shouldNotHaveReceived('send');
    }

    public function test_provider_query_found_auto_resolves_and_skips_alert(): void
    {
        BotNotify::spy();
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);
        $record = $this->makeRecord($order);
        // provider 查單回「有單」＝硬事實：自動補單號轉 22、不進停留告警清單
        $this->mockQueryProvider(new PayoutQueryResult(PayoutQueryOutcome::FOUND_ACCEPTED, 'QUERY-3RD-001'));

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        $order->refresh();
        $this->assertSame(22, (int) $order->status);
        $this->assertSame('QUERY-3RD-001', $order->third_party_order_no);
        // 雙欄回填：三家雙條件對單商靠 record 的三方單號對回調
        $this->assertSame('QUERY-3RD-001', $record->refresh()->third_party_transaction_no);
        // 只發「自動查證定案」通知，不發停留告警
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_provider_query_found_without_order_no_still_alerts(): void
    {
        BotNotify::spy();
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);
        $this->makeRecord($order);
        // 有單但查單未附三方單號＝資訊不足：不自動轉 22（缺單號的 22 會脫離掃描成孤兒），照舊告警
        $this->mockQueryProvider(new PayoutQueryResult(PayoutQueryOutcome::FOUND_ACCEPTED, ''));

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        $this->assertSame(21, (int) $order->refresh()->status);
        BotNotify::shouldHaveReceived('send')->once(); // 停留告警
    }

    public function test_provider_query_yields_when_order_rewritten_after_record(): void
    {
        BotNotify::spy();
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);
        $record = $this->makeRecord($order);
        // 模擬 ABA 窗口（狀態 21→29→重新發起又回到 21，值相同、實為兩次發起）：
        // record 建檔後訂單的 21 又被重寫（重新發起、新發起尚未建檔）——record.created_at
        // 早於 order.updated_at，本查單結果屬前一次發起，必須讓路
        WithdrawalPayoutRecord::query()->whereKey($record->id)->update([
            'created_at' => now()->subMinutes(60)->toDateTimeString(),
        ]);
        $this->mockQueryProvider(new PayoutQueryResult(PayoutQueryOutcome::FOUND_ACCEPTED, 'STALE-3RD-001'));

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        $order->refresh();
        // 舊 attempt 的查單結果不得寫入：訂單維持 21（照舊告警）、單號不被沾染
        $this->assertSame(21, (int) $order->status);
        $this->assertNotSame('STALE-3RD-001', $order->third_party_order_no);
        BotNotify::shouldHaveReceived('send')->once(); // 停留告警
    }

    public function test_provider_query_not_found_still_alerts_and_keeps_order(): void
    {
        BotNotify::spy();
        $order = $this->makeSubmittedOrder(stuckMinutes: 30);
        $this->makeRecord($order);
        // 查無不自動轉 29（可能只是三方入帳延遲，誤判＝自動開重複出款窗口）：照舊告警交人工
        $this->mockQueryProvider(new PayoutQueryResult(PayoutQueryOutcome::NOT_FOUND));

        $this->artisan('withdrawal:alert-stuck-submitted')->assertSuccessful();

        $this->assertSame(21, (int) $order->refresh()->status);
        BotNotify::shouldHaveReceived('send')->once(); // 停留告警
    }

    private function makeSubmittedOrder(int $stuckMinutes): WithdrawalOrder
    {
        $order = WithdrawalOrder::factory()->create(['status' => 21]);

        // Eloquent update 會 touch updated_at，改用 query builder 硬寫停留時間
        WithdrawalOrder::query()->whereKey($order->id)->update([
            'updated_at' => now()->subMinutes($stuckMinutes)->toDateTimeString(),
        ]);

        return $order;
    }

    private function makeRecord(WithdrawalOrder $order): WithdrawalPayoutRecord
    {
        $agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'admin' => 0,
            'info' => json_encode([]),
        ]);

        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => 'TXN-QUERY-' . $order->id,
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '',
            'result_amount' => $order->amount,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => 0,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
        ]);
    }

    private function mockQueryProvider(PayoutQueryResult $result): void
    {
        $provider = new class($result) implements SupportsPayoutQuery
        {
            public function __construct(private readonly PayoutQueryResult $result) {}

            public function queryPayout(WithdrawalPayoutRecord $record): PayoutQueryResult
            {
                return $this->result;
            }
        };

        $this->mock(PaymentService::class, function ($mock) use ($provider): void {
            $mock->shouldReceive('getProvider')->andReturn($provider);
        });
    }
}
