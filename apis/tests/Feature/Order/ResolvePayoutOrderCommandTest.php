<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\Transaction\TransactionEnum;
use App\Facades\BotNotify;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * withdrawal:resolve-payout — 代付停留單（21/22）人工兜底定案命令
 *
 * target/tickets#4137：事務邊界拆分後，代付請求結果未明的訂單停留在 21（代付請求中），
 * 已受理但回調始終未達的訂單停留在 22（代付處理中）。原「三方查證中（23）」已廢除並移除。
 * 回調一直沒來時由人工到三方後台核對實際出款結果，再以本命令定案：
 *  - 查無此單 → --outcome=resend：解鎖為 29，重新開放重送
 *  - 已出款 → --outcome=success：定案 24，鏡射回調成功側效（payment_time＋TransactionLog 1000）
 *
 * 舊名 withdrawal:resolve-verifying 以 alias 保留。
 *
 * php artisan test tests/Feature/Order/ResolvePayoutOrderCommandTest.php
 */
class ResolvePayoutOrderCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rejects_in_flight_submitted_order(): void
    {
        // 剛寫入 21（updated_at＝現在）：原 HTTP 可能仍在途，3 分鐘保護窗內拒絕 resend
        // （窗內解鎖為 29 後原請求仍可能被支付商受理＝重複出款）
        $order = $this->makeOrder(21, stuckMinutes: 0);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'resend',
        ])->assertFailed();

        $this->assertSame(21, (int) $order->refresh()->status);
    }

    #[Group('rules/payout-submit-and-status#R-17')]
    public function test_resolves_submitted_to_resend(): void
    {
        $order = $this->makeOrder(21);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'resend',
        ])
            ->expectsConfirmation('確認執行定案？', 'yes')
            ->assertSuccessful();

        // 人工確認支付商查無此單 → 21 解鎖為 29 可重新發起
        $this->assertSame(29, (int) $order->refresh()->status);
    }

    #[Group('rules/payout-submit-and-status#R-17')]
    public function test_resolves_processing_to_success_with_side_effects(): void
    {
        $order = $this->makeOrder(22);
        $transactionLog = $this->makeTransactionLog($order);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'success',
        ])
            ->expectsConfirmation('確認執行定案？', 'yes')
            ->assertSuccessful();

        $order->refresh();
        // 支付商已出款、回調始終未達 → 定案 24 並鏡射回調成功全部側效
        $this->assertSame(24, (int) $order->status);
        $this->assertNotNull($order->payment_time);
        $this->assertSame(1000, (int) $transactionLog->refresh()->status);
    }

    public function test_success_without_transaction_log_still_resolves_and_alerts(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        // 不建 TransactionLog：資料異常情境

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'success',
        ])
            ->expectsConfirmation('確認執行定案？', 'yes')
            ->assertSuccessful();

        // 出款是既成事實：訂單仍定案 24，但交易日誌缺失必須額外告警交人工補帳
        $this->assertSame(24, (int) $order->refresh()->status);
        BotNotify::shouldHaveReceived('send')->twice(); // 缺日誌告警 ＋ 例行定案通知
    }

    #[Group('rules/payout-submit-and-status#R-17')]
    public function test_rejects_retired_verifying_status(): void
    {
        $order = $this->makeOrder(23);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'resend',
        ])->assertFailed();

        // 23（原「三方查證中」）已隨 target/tickets#4137 收尾移除：本命令不再定案，
        // 線上若再出現 23 代表有未知寫入者，須先查來源而非直接改狀態
        $this->assertSame(23, (int) $order->refresh()->status);
    }

    #[Group('rules/payout-submit-and-status#R-17')]
    public function test_rejects_terminal_order(): void
    {
        $order = $this->makeOrder(24);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'resend',
        ])->assertFailed();

        // 終態 24 不得被本命令改動
        $this->assertSame(24, (int) $order->refresh()->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $order = $this->makeOrder(21);

        $this->artisan('withdrawal:resolve-payout', [
            'order_id' => $order->id,
            '--outcome' => 'resend',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(21, (int) $order->refresh()->status);
    }

    // ─── fixtures ──────────────────────────────────────────────────────

    private function makeOrder(int $status, int $stuckMinutes = 10): WithdrawalOrder
    {
        $order = WithdrawalOrder::factory()->create([
            'status' => $status,
            'payment_time' => null,
        ]);

        // 預設倒退 updated_at 越過 21 的 in-flight 保護窗（3 分鐘）；stuckMinutes=0 模擬剛寫入。
        // Eloquent update 會 touch updated_at，改用 query builder 硬寫
        if ($stuckMinutes > 0) {
            WithdrawalOrder::query()->whereKey($order->id)->update([
                'updated_at' => now()->subMinutes($stuckMinutes)->toDateTimeString(),
            ]);
            $order->refresh();
        }

        return $order;
    }

    private function makeTransactionLog(WithdrawalOrder $order): TransactionLog
    {
        return TransactionLog::create([
            'transaction_no' => (string) Str::uuid(),
            'member_id' => $order->member_id,
            'agent_id' => $order->agent_id,
            'vendor_id' => 0,
            'order_id' => $order->id,
            'type' => TransactionEnum::WITHDRAWAL->value,
            'point' => '1.0000',
            'tax' => '0.0000',
            'vendor_point' => '0.0000',
            'rate' => '1.0000',
            'before_balance' => '0.0000',
            'balance' => '1.0000',
            'vendor_balance' => '0.0000',
            'status' => 0,
            'operator' => '0',
            'remark' => '',
            'admin' => 0,
        ]);
    }
}
