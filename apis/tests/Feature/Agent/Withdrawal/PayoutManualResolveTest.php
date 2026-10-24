<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Withdrawal;

use App\Facades\BotNotify;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentOperateLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代付停留單後台人工定案兩支接口（target/tickets#4137，承接自 API_Agent 後台按鈕）：
 *  - fill-third-party-no：人工查到支付商有單 → 21 → 22，訂單與最新請求紀錄雙欄回填
 *  - mark-failed：人工確認支付商查無此單 → 21/22 → 29，可重新發起
 *
 * 守衛：操作者階層 scope、狀態集合、21 的 in-flight 保護窗（3 分鐘）、無 record 拒絕補單。
 *
 * php artisan test tests/Feature/Agent/Withdrawal/PayoutManualResolveTest.php
 */
class PayoutManualResolveTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        // 全新測試庫的第一位代理可能拿到 id=1（admin bypass 保留值），會短路 scope 測試：燒掉重建
        if ((int) $this->agent->id === 1) {
            $this->agent = $this->createAgentAndDomain();
        }

        // scope 守衛以 hierarchy_path 前綴判定；測試代理自成子樹根，
        // 並確保不命中 admin bypass（host_id=1 會無條件放行、短路 scope 測試）
        $this->agent->update(['hierarchy_path' => '/' . $this->agent->id, 'host_id' => 0]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_fill_third_party_no_moves_submitted_order_to_processing(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        $record = $this->makeRecord($order, 'TXN-FILL-1');

        $response = $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-001',
        ]);

        $order->refresh();
        // 21 → 22 並回填訂單三方單號
        $this->assertSame(22, (int) $order->status, 'response: ' . $response->getContent());
        $this->assertSame('MANUAL-3RD-001', $order->third_party_order_no);
        // 必須同步回填最新請求紀錄：GMPay/GffPay/MajesticPay 以「三方單號＋我方交易號」對單
        $this->assertSame('MANUAL-3RD-001', $record->refresh()->third_party_transaction_no);
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_fill_third_party_no_backfills_only_latest_record(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        $staleRecord = $this->makeRecord($order, 'TXN-FILL-STALE');
        $latestRecord = $this->makeRecord($order, 'TXN-FILL-LATEST');

        $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-002',
        ]);

        // 只回填最新一筆；stale record 是「前次換渠道」的殘影，不得沾染
        $this->assertSame('MANUAL-3RD-002', $latestRecord->refresh()->third_party_transaction_no);
        $this->assertSame('', $staleRecord->refresh()->third_party_transaction_no);
    }

    public function test_fill_third_party_no_rejects_order_without_record(): void
    {
        $order = $this->makeOrder(21);

        $response = $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-003',
        ]);

        $order->refresh();
        // 無 record＝HTTP 未送出＝支付商必然查無此單：拒絕補單，正確出口是 mark-failed；
        // 且三家雙條件對單商沒有 record 可回填，轉 22 只會永遠等不到回調
        $this->assertSame(21, (int) $order->status);
        $this->assertSame('', (string) $order->third_party_order_no);
        $this->assertStringContainsString('record not found', $response->getContent());
    }

    public function test_fill_third_party_no_rejects_processing_order(): void
    {
        $order = $this->makeOrder(22);
        $this->makeRecord($order, 'TXN-FILL-22');

        $response = $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-004',
        ]);

        // 22 已有三方單號，不得經本接口覆寫（重複操作同樣落在這裡被擋）
        $this->assertSame(22, (int) $order->refresh()->status);
        $this->assertNotSame('MANUAL-3RD-004', $order->third_party_order_no);
        $this->assertStringContainsString('not allowed', $response->getContent());
    }

    public function test_fill_third_party_no_rejects_in_flight_submitted_order(): void
    {
        // 剛寫入 21（updated_at＝現在）：原 HTTP 可能仍在途，3 分鐘保護窗內拒絕人工操作
        $order = $this->makeOrder(21, stuckMinutes: 0);
        $this->makeRecord($order, 'TXN-FILL-FRESH');

        $response = $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-005',
        ]);

        $this->assertSame(21, (int) $order->refresh()->status);
        $this->assertStringContainsString('in flight', $response->getContent());
    }

    public function test_fill_third_party_no_appends_remark_to_memo(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        // Eloquent builder update 會自動 touch updated_at，須顯式帶回倒退值以免誤觸 in-flight 窗
        WithdrawalOrder::query()->whereKey($order->id)->update([
            'memo' => '既有備註',
            'updated_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);
        $this->makeRecord($order, 'TXN-FILL-MEMO');

        $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-006',
            'remark' => '已於支付商後台查到單',
        ]);

        $order->refresh();
        $this->assertSame(22, (int) $order->status);
        // 備註比照舊 reviewOrder 慣例：既有 memo 換行附加
        $this->assertSame('既有備註' . PHP_EOL . '已於支付商後台查到單', $order->memo);
    }

    public function test_mark_failed_writes_remark_to_empty_memo(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        // 同上：顯式帶回倒退的 updated_at 以免誤觸 in-flight 窗
        WithdrawalOrder::query()->whereKey($order->id)->update([
            'memo' => '',
            'updated_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);

        $this->requestApi('agent/api/withdrawal/mark-failed', [
            'order_id' => $order->id,
            'remark' => '支付商後台查無此單',
        ]);

        $order->refresh();
        $this->assertSame(29, (int) $order->status);
        $this->assertSame('支付商後台查無此單', $order->memo);
    }

    public function test_mark_failed_resolves_submitted_order(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);

        $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        // 人工確認查無此單：21 → 29 可重新發起
        $this->assertSame(29, (int) $order->refresh()->status);
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_mark_failed_rejects_in_flight_submitted_order(): void
    {
        $order = $this->makeOrder(21, stuckMinutes: 0);

        $response = $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        // 3 分鐘保護窗：原 HTTP 仍可能抵達支付商並受理，此刻轉 29 就是開重複出款窗口
        $this->assertSame(21, (int) $order->refresh()->status);
        $this->assertStringContainsString('in flight', $response->getContent());
    }

    public function test_mark_failed_resolves_processing_order(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(22);

        $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        $this->assertSame(29, (int) $order->refresh()->status);
    }

    public function test_mark_failed_rejects_terminal_order(): void
    {
        $order = $this->makeOrder(24);

        $response = $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        // 終態 24 不得被人工標記失敗（已出款單解鎖重送＝重複出款）
        $this->assertSame(24, (int) $order->refresh()->status);
        $this->assertStringContainsString('not allowed', $response->getContent());
    }

    public function test_fill_third_party_no_writes_operate_log_with_remark(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(21);
        $this->makeRecord($order, 'TXN-FILL-LOG');

        $this->requestApi('agent/api/withdrawal/fill-third-party-no', [
            'order_id' => $order->id,
            'third_party_order_no' => 'MANUAL-3RD-LOG',
            'remark' => '已於支付商後台查到單',
        ]);

        $log = $this->latestOperateLog($order);
        // 寫入約定已貼在 target/tickets#4439 留言，API_Agent 依此讀取，類型代碼與格式不得改
        $this->assertSame('withdrawal_fill_third_party_no', $log->type);
        $this->assertSame('withdrawal_order', $log->reference_type);
        $this->assertSame(
            ['old' => ['status' => 21], 'attributes' => ['status' => 22], 'remark' => '已於支付商後台查到單'],
            json_decode($log->debug_info, true),
        );
    }

    public function test_mark_failed_writes_operate_log_without_remark_key(): void
    {
        BotNotify::spy();
        $order = $this->makeOrder(22);

        $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        $log = $this->latestOperateLog($order);
        $this->assertSame('withdrawal_mark_failed', $log->type);
        $this->assertSame('withdrawal_order', $log->reference_type);
        // 操作員沒填備註時不帶 remark 鍵
        $this->assertSame(
            ['old' => ['status' => 22], 'attributes' => ['status' => 29]],
            json_decode($log->debug_info, true),
        );
    }

    public function test_rejects_sibling_with_similar_path_prefix(): void
    {
        // 前置守衛：scope 測試的操作者不得命中 admin bypass（id=1／host_id=1 無條件放行），
        // 否則測試根本沒走到邊界判定路徑、形同沒測
        $this->assertNotSame(1, (int) $this->agent->id, 'scope 測試不得使用 admin 帳號');
        $this->assertNotSame(1, (int) $this->agent->host_id, 'scope 測試不得使用 admin 子帳號');

        // 路徑邊界：操作者 /N 不得放行 /N0（裸前綴比對的經典繞過，如 /5/12 誤放 /5/120）
        $foreignAgent = Agent::factory()->create(['hierarchy_path' => '/' . $this->agent->id . '0']);
        $order = WithdrawalOrder::factory()->create([
            'agent_id' => $foreignAgent->id,
            'agent_group_id' => $foreignAgent->cash_flow_group_id,
            'member_id' => 0,
            'status' => 21,
            'third_party_order_no' => '',
        ]);
        WithdrawalOrder::query()->whereKey($order->id)->update([
            'updated_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);

        $response = $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        $this->assertSame(21, (int) $order->refresh()->status);
        // 範疇改由 WithdrawalController::isOrderInScope() 把關（target/tickets#4140 稽核 #9），回 403／9003
        $response->assertStatus(403);
        $response->assertJsonPath('message', __('error_message.9003'));
    }

    public function test_rejects_order_outside_operator_scope(): void
    {
        // 前置守衛：不得命中 admin bypass（同上，防測試短路）
        $this->assertNotSame(1, (int) $this->agent->id, 'scope 測試不得使用 admin 帳號');
        $this->assertNotSame(1, (int) $this->agent->host_id, 'scope 測試不得使用 admin 子帳號');

        // 他人子樹的訂單：跨租戶補單號／標記失敗＝直接的重複出款入口，必須擋
        $foreignAgent = Agent::factory()->create(['hierarchy_path' => '/999999']);
        $order = WithdrawalOrder::factory()->create([
            'agent_id' => $foreignAgent->id,
            'agent_group_id' => $foreignAgent->cash_flow_group_id,
            'member_id' => 0,
            'status' => 21,
            'third_party_order_no' => '',
        ]);
        WithdrawalOrder::query()->whereKey($order->id)->update([
            'updated_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);

        $response = $this->requestApi('agent/api/withdrawal/mark-failed', ['order_id' => $order->id]);

        $this->assertSame(21, (int) $order->refresh()->status);
        // 範疇改由 WithdrawalController::isOrderInScope() 把關（target/tickets#4140 稽核 #9），回 403／9003
        $response->assertStatus(403);
        $response->assertJsonPath('message', __('error_message.9003'));
    }

    // ─── fixtures ──────────────────────────────────────────────────────

    private function makeOrder(int $status, int $stuckMinutes = 10): WithdrawalOrder
    {
        $order = WithdrawalOrder::factory()->create([
            'agent_id' => $this->agent->id,
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'member_id' => 0,
            'status' => $status,
            'third_party_order_no' => '',
        ]);

        // 預設倒退 updated_at 越過 in-flight 保護窗（3 分鐘）；stuckMinutes=0 模擬剛寫入
        if ($stuckMinutes > 0) {
            WithdrawalOrder::query()->whereKey($order->id)->update([
                'updated_at' => now()->subMinutes($stuckMinutes)->toDateTimeString(),
            ]);
        }

        return $order;
    }

    private function latestOperateLog(WithdrawalOrder $order): AgentOperateLog
    {
        return AgentOperateLog::query()
            ->where('agent_id', $this->agent->id)
            ->where('reference_id', (string) $order->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function makeRecord(WithdrawalOrder $order, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => 1,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
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
}
