<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\OnebxSW;

use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\OnebxswBetRecord;
use App\Vendors\OnebxSW\Enums\BetStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OneBX 回調（target/tickets#4375）：改繼承 BaseController、邏輯集中進 controller 後的行為釘樁。
 * 這家改版前沒有任何測試，整組都是新寫的；回應碼與 HTTP 狀態碼對照沿用改版前。
 *
 * fixture 形狀取自真實請求：TPower 線上 2026-09-17 一天（balance 117／bet 9／rollback 4／settle 8，走舊系統
 * API_External 的 log）＋ Dev 2026-09-15～18（settle／undo）。cancel 與 step/* 在線上一天與 Dev 七天內都沒有流量，
 * 那幾支的 payload 只能照 FormRequest 規則編。
 */
class OnebxSWCallbackTest extends OnebxSWTestCase
{
    #[TestDox('下注成功扣真金、建立未結算注單、不建 TBR，回傳乘過 rate 的餘額')]
    #[Group('rules/vendor-onebxsw#R-8')]
    public function test_bet_deducts_wallet_and_creates_pending_record(): void
    {
        $this->placeBet('BET-001', '100')->assertOk()->assertJson(['status' => 'success', 'balance' => 900.0]);

        $this->assertSame('900.0000', $this->balance());
        $record = OnebxswBetRecord::where('bet_id', 'BET-001')->firstOrFail();
        $this->assertSame(BetStatusEnum::BET->value, $record->status);
        $this->assertEquals('100.0000', $record->bet);
        $this->assertEquals('100.0000', $record->valid_bet);
        $this->assertEquals('-100.0000', $record->win_lose);
        $this->assertSame(0, TotalBetRecord::count());
        $this->assertSame(1, TransactionLog::count());
    }

    #[TestDox('同一 bettranstion_id 重送下注只扣一次，第二次回成功與當前餘額')]
    #[Group('rules/vendor-onebxsw#R-10')]
    public function test_duplicate_bet_is_idempotent(): void
    {
        $this->placeBet('BET-001', '100')->assertOk();
        $this->placeBet('BET-001', '100')->assertOk()->assertJson(['status' => 'success', 'balance' => 900.0]);

        $this->assertSame('900.0000', $this->balance());
        $this->assertSame(1, OnebxswBetRecord::count());
    }

    #[TestDox('餘額不足回 C_00 / HTTP 400，不建注單')]
    #[Group('rules/vendor-onebxsw#R-11')]
    public function test_bet_rejected_when_balance_not_enough(): void
    {
        $this->placeBet('BET-001', '5000')->assertStatus(400)->assertJson(['status' => 'C_00']);

        $this->assertSame('1000.0000', $this->balance());
        $this->assertSame(0, OnebxswBetRecord::count());
    }

    #[TestDox('會員被系統鎖定時下注被擋，回 B_00 / HTTP 401')]
    #[Group('rules/vendor-onebxsw#R-12')]
    public function test_bet_rejected_when_member_locked(): void
    {
        $this->member->update(['sys_lock' => 1]);

        $this->placeBet('BET-001', '100')->assertStatus(401)->assertJson(['status' => 'B_00']);
        $this->assertSame('1000.0000', $this->balance());
    }

    #[TestDox('遊戲被標為不可投注（vendor_info_games.bettable=0）時下注被擋，回 B_00')]
    #[Group('rules/vendor-onebxsw#R-13')]
    public function test_bet_rejected_when_game_not_bettable(): void
    {
        $this->vendorInfo->vendorInfoGames()->where('game_id', $this->game->id)->update(['bettable' => 0]);

        $this->placeBet('BET-001', '100')->assertStatus(401)->assertJson(['status' => 'B_00']);
        $this->assertSame(0, OnebxswBetRecord::count());
    }

    #[TestDox('下注資格檢查必須排在會員上鎖之後，否則快照定在等鎖前讀不到最新狀態')]
    #[Group('rules/vendor-onebxsw#R-12')]
    public function test_bettability_is_verified_after_member_lock(): void
    {
        DB::enableQueryLog();
        $this->placeBet('BET-001', '100')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        $lockIndex = $queries->search(fn (string $q) => str_contains($q, 'from `members`') && str_contains($q, 'for update'));
        $gameIndex = $queries->search(fn (string $q) => str_contains($q, 'from `games`') && str_contains($q, '`game_type`'));

        $this->assertNotFalse($lockIndex);
        $this->assertNotFalse($gameIndex);
        $this->assertGreaterThan($lockIndex, $gameIndex);
    }

    #[TestDox('簽名錯誤回 M_00 / HTTP 401')]
    #[Group('rules/vendor-onebxsw#R-4')]
    public function test_invalid_signature(): void
    {
        $payload = $this->betPayload('BET-001', '100');
        $payload['signature'] = 'tampered';

        $this->postJson("{$this->baseUrl}/bet", $payload)->assertStatus(401)->assertJson(['status' => 'M_00']);
    }

    #[TestDox('帳號不存在回 C_01 / HTTP 401')]
    #[Group('rules/vendor-onebxsw#R-5')]
    public function test_unknown_account(): void
    {
        $this->postJson("{$this->baseUrl}/bet", $this->betPayload('BET-001', '100', ['username' => 'nobody']))
            ->assertStatus(401)->assertJson(['status' => 'C_01']);
    }

    #[TestDox('查餘額回乘過 rate 的總餘額')]
    #[Group('rules/vendor-onebxsw#R-6')]
    public function test_balance(): void
    {
        $this->postJson("{$this->baseUrl}/balance", $this->sign(['username' => $this->username, 'timestamp' => $this->timestamp]))
            ->assertOk()->assertJson(['status' => 'success', 'balance' => 1000.0]);
    }

    #[TestDox('結算派彩加真金、注單轉已結算、建立 TBR')]
    #[Group('rules/vendor-onebxsw#R-15')]
    public function test_settle_pays_out_and_creates_total_bet_record(): void
    {
        $this->placeBet('BET-001', '100');

        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))
            ->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('1150.0000', $this->balance());
        $record = OnebxswBetRecord::where('bet_id', 'BET-001')->firstOrFail();
        $this->assertSame(BetStatusEnum::SETTLED->value, $record->status);
        $this->assertEquals('150.0000', $record->win_lose);
        $this->assertSame(1, TotalBetRecord::count());
        $this->assertEquals('150.0000', TotalBetRecord::first()->win_lose);
    }

    #[TestDox('結算重送不重複派彩')]
    #[Group('rules/vendor-onebxsw#R-16')]
    public function test_settle_is_idempotent(): void
    {
        $this->placeBet('BET-001', '100');
        $payload = $this->itemPayload([$this->item('BET-001', '250')]);

        $this->postJson("{$this->baseUrl}/settle", $payload)->assertOk();
        $this->postJson("{$this->baseUrl}/settle", $payload)->assertOk();

        $this->assertSame('1150.0000', $this->balance());
        $this->assertSame(1, TotalBetRecord::count());
    }

    #[TestDox('會員被系統鎖定時結算仍照常派彩，不因鎖定而中斷')]
    #[Group('rules/vendor-onebxsw#R-14')]
    public function test_settle_still_pays_when_member_locked(): void
    {
        $this->placeBet('BET-001', '100');
        $this->member->update(['sys_lock' => 1]);

        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))->assertOk();
        $this->assertSame('1150.0000', $this->balance());
    }

    #[TestDox('結算金額為負回 C_00 / HTTP 400，該走 undo')]
    #[Group('rules/vendor-onebxsw#R-17')]
    public function test_settle_rejects_negative_amount(): void
    {
        $this->placeBet('BET-001', '100');

        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '-10')]))
            ->assertStatus(400)->assertJson(['status' => 'C_00']);
        $this->assertSame('900.0000', $this->balance());
    }

    #[TestDox('取消下注（真實取消的 credit_amount 等於 bet）退回本金、注單轉取消、win_lose 歸零，回傳餘額')]
    #[Group('rules/vendor-onebxsw#R-23')]
    public function test_rollback_refunds_bet(): void
    {
        $this->placeBet('BET-001', '100');

        $this->postJson("{$this->baseUrl}/rollback", $this->rollbackPayload('BET-001', 100))
            ->assertOk()->assertJson(['status' => 'success', 'balance' => 1000.0]);

        $this->assertSame('1000.0000', $this->balance());
        $record = OnebxswBetRecord::where('bet_id', 'BET-001')->firstOrFail();
        $this->assertSame(BetStatusEnum::CANCELED->value, $record->status);
        $this->assertEquals('0.0000', $record->win_lose);
        $this->assertEquals('100.0000', $record->bet, '改版前就保留原下注額供對帳');
    }

    #[TestDox('真實 void 型 rollback（credit_amount=0、price/tor_side 為 "null"）打到我方沒有的注單，視為已處理回成功與餘額、不動錢')]
    #[Group('rules/vendor-onebxsw#R-26')]
    public function test_rollback_unknown_bet_is_noop(): void
    {
        $this->postJson("{$this->baseUrl}/rollback", $this->rollbackPayload('162442577', 0))
            ->assertOk()->assertJson(['status' => 'success', 'balance' => 1000.0]);

        $this->assertSame('1000.0000', $this->balance());
        $this->assertSame(0, OnebxswBetRecord::count());
    }

    #[TestDox('credit_amount=0 的 rollback 卻對到存在的未結算注單，回 E_00 讓三方重送，不可默默標成取消而錢沒退')]
    #[Group('rules/vendor-onebxsw#R-27')]
    public function test_rollback_zero_amount_on_existing_bet_is_rejected(): void
    {
        $this->placeBet('BET-001', '100');

        $this->postJson("{$this->baseUrl}/rollback", $this->rollbackPayload('BET-001', 0))
            ->assertStatus(500)->assertJson(['status' => 'E_00']);

        $this->assertSame('900.0000', $this->balance());
        $this->assertSame(BetStatusEnum::BET->value, OnebxswBetRecord::where('bet_id', 'BET-001')->value('status'));
    }

    #[TestDox('MY 盤負賠率時 deduct_amount 小於 amount_bet：扣款以 deduct_amount、有效投注記 amount_bet（TPower 真實下注 122/89.06）')]
    #[Group('rules/vendor-onebxsw#R-9')]
    public function test_bet_negative_price_deducts_less_than_stake(): void
    {
        $this->postJson("{$this->baseUrl}/bet", $this->betPayload('162506793', 122, ['deduct_amount' => 89.06, 'price' => '-0.73']))
            ->assertOk()->assertJson(['status' => 'success', 'balance' => 910.94]);

        $record = OnebxswBetRecord::where('bet_id', '162506793')->firstOrFail();
        $this->assertEquals('89.0600', $record->bet);
        $this->assertEquals('122.0000', $record->valid_bet);
        $this->assertEquals('-89.0600', $record->win_lose);
    }

    #[TestDox('cancel 批次可一次取消多筆未結算注單')]
    #[Group('rules/vendor-onebxsw#R-23')]
    public function test_cancel_batch_refunds_each_item(): void
    {
        $this->placeBet('BET-001', '100');
        $this->placeBet('BET-002', '50');

        $this->postJson("{$this->baseUrl}/cancel", $this->itemPayload([$this->item('BET-001', '100'), $this->item('BET-002', '50')]))
            ->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('1000.0000', $this->balance());
        $this->assertSame(2, OnebxswBetRecord::where('status', BetStatusEnum::CANCELED->value)->count());
    }

    #[TestDox('取消結算以注單已結算派彩收回（真實 undo 的 credit_amount 恆為 0）、注單退回未結算、刪掉 TBR')]
    #[Group('rules/vendor-onebxsw#R-20')]
    public function test_undo_reverts_settlement(): void
    {
        $this->placeBet('BET-001', '100');
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))->assertOk();

        $this->postJson("{$this->baseUrl}/undo", $this->itemPayload([$this->undoItem('BET-001')], 'undo'))
            ->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('900.0000', $this->balance());
        $record = OnebxswBetRecord::where('bet_id', 'BET-001')->firstOrFail();
        $this->assertSame(BetStatusEnum::BET->value, $record->status);
        $this->assertEquals('-100.0000', $record->win_lose);
        $this->assertSame(0, TotalBetRecord::count());
    }

    #[TestDox('取消結算時真金不足以收回派彩，回 C_00 / HTTP 400 且不動錢')]
    #[Group('rules/vendor-onebxsw#R-21')]
    public function test_undo_rejected_when_wallet_cannot_cover(): void
    {
        $this->placeBet('BET-001', '100');
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))->assertOk();
        $this->member->update(['balance' => '10.0000']);

        $this->postJson("{$this->baseUrl}/undo", $this->itemPayload([$this->undoItem('BET-001')], 'undo'))
            ->assertStatus(400)->assertJson(['status' => 'C_00']);
        $this->assertSame('10.0000', $this->balance());
    }

    #[TestDox('輸的注結算派彩 0 後 undo 再結算為贏，只派一次；undo 不動錢、不留 TBR')]
    #[Group('rules/vendor-onebxsw#R-20')]
    public function test_undo_then_resettle_pays_exactly_once(): void
    {
        $this->placeBet('BET-001', '100');
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', 0)]))->assertOk();
        $this->assertSame('900.0000', $this->balance());

        $this->postJson("{$this->baseUrl}/undo", $this->itemPayload([$this->undoItem('BET-001')], 'undo'))->assertOk();
        $this->assertSame('900.0000', $this->balance());
        $this->assertSame(0, TotalBetRecord::count());

        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))->assertOk();
        $this->assertSame('1150.0000', $this->balance());
        $this->assertSame(1, TotalBetRecord::count());
        $this->assertEquals('150.0000', OnebxswBetRecord::where('bet_id', 'BET-001')->value('win_lose'));
    }

    #[TestDox('item 超過 30 筆：cancel 回 E_00 / HTTP 500；settle 同樣 500 但 body 不得是 E_00（文件：settle 收到 E_00 整批永久不付）')]
    #[Group('rules/vendor-onebxsw#R-18')]
    #[Group('rules/vendor-onebxsw#R-28')]
    public function test_too_many_items(): void
    {
        $items = array_map(fn (int $i) => $this->item("BET-{$i}", '1'), range(1, 31));

        $this->postJson("{$this->baseUrl}/cancel", $this->itemPayload($items))->assertStatus(500)->assertJson(['status' => 'E_00']);
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload($items))->assertStatus(500)->assertJson(['status' => 'RETRY']);
    }

    #[TestDox('settle 查不到注單回 HTTP 500 讓三方重送，但 body 不得是 E_00')]
    #[Group('rules/vendor-onebxsw#R-18')]
    #[Group('rules/vendor-onebxsw#R-3')]
    public function test_settle_unknown_bet_is_retryable_not_permanent_failure(): void
    {
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('NOPE', '250')]))
            ->assertStatus(500)->assertJson(['status' => 'RETRY']);
    }

    #[TestDox('已結算的注單收到同一 call_transaction_id 的 settle 是重送，靜默不動錢；不同 call_transaction_id 是沒經 undo 的重新結算，留 warning 也不動錢')]
    #[Group('rules/vendor-onebxsw#R-16')]
    public function test_settle_idempotency_keys_on_call_transaction_id_in_infos(): void
    {
        Log::spy();
        $this->placeBet('BET-001', '100');
        $first = $this->item('BET-001', '250');
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$first]))->assertOk();

        // 同 ctid 重送
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$first]))->assertOk();
        $this->assertSame('1150.0000', $this->balance());
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'settle_new_call_transaction_id'));

        // 不同 ctid（新結算版本）
        $second = $this->item('BET-001', '300');
        $second['call_transaction_id'] = 'BET-001-S2';
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$second]))->assertOk();
        $this->assertSame('1150.0000', $this->balance());
        $this->assertSame(1, TotalBetRecord::count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'settle_new_call_transaction_id')
            && $context['incoming_call_transaction_id'] === 'BET-001-S2'
            && $context['member_id'] === $this->member->id)->once();
    }

    #[TestDox('cancel 打到已結算的注單：退回本金並收回派彩、注單轉取消、刪掉 TBR（文件：settle result must be reversed as part of the cancel）')]
    #[Group('rules/vendor-onebxsw#R-25')]
    public function test_cancel_on_settled_bet_reverses_payout_and_refunds_stake(): void
    {
        $this->placeBet('BET-001', '100');
        $this->postJson("{$this->baseUrl}/settle", $this->itemPayload([$this->item('BET-001', '250')]))->assertOk();
        $this->assertSame('1150.0000', $this->balance());

        $this->postJson("{$this->baseUrl}/cancel", $this->itemPayload([$this->item('BET-001', '100')]))->assertOk();

        $this->assertSame('1000.0000', $this->balance());
        $record = OnebxswBetRecord::where('bet_id', 'BET-001')->firstOrFail();
        $this->assertSame(BetStatusEnum::CANCELED->value, $record->status);
        $this->assertEquals('0.0000', $record->win_lose);
        $this->assertSame(0, TotalBetRecord::count());
    }

    #[TestDox('文件形狀的 cancel body 沒有頂層 timestamp、每筆 item 自帶，必須被接受')]
    #[Group('rules/vendor-onebxsw#R-28')]
    public function test_cancel_without_top_level_timestamp_is_accepted(): void
    {
        $this->placeBet('BET-001', '100');
        $entry = $this->item('BET-001', '100') + ['timestamp' => $this->timestamp];
        unset($entry['call_transaction_id'], $entry['settled_at'], $entry['comm_amount']);
        $payload = $this->sign(['item' => json_encode(['win_side' => null, 'data' => json_encode([$entry])])]);

        $this->postJson("{$this->baseUrl}/cancel", $payload)->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame('1000.0000', $this->balance());
    }

    #[TestDox('step/bonus 與 step/refund-bonus 尚未實作：把 payload 記進 log 後回非 200，不可回 200（回 200 等於承認已入帳，玩家的 promotion 會消失）')]
    #[Group('rules/vendor-onebxsw#R-29')]
    public function test_unimplemented_promotion_endpoints_log_payload_and_refuse(): void
    {
        Log::spy();
        $entry = [
            'id' => 1,
            'promotion_id' => '11',
            'promotion_name' => 'Promotion 11',
            'username' => $this->username,
            'bill_id' => 'BILL-001',
            'transaction_id' => 'TX-1001',
            'amount' => '100.50',
        ];

        foreach (['bonus' => 'promotion', 'refund-bonus' => 'refund'] as $path => $type) {
            $payload = $this->sign([
                'item' => json_encode(['type' => $type, 'data' => json_encode([$entry])]),
                'timestamp' => $this->timestamp,
            ]);

            $this->postJson("{$this->baseUrl}/step/{$path}", $payload)
                ->assertStatus(500)
                ->assertJson(['status' => 'E_00']);
        }

        $this->assertSame('1000.0000', $this->balance(), '尚未實作，一毛都不該動');

        foreach (['step/bonus', 'step/refund-bonus'] as $endpoint) {
            Log::shouldHaveReceived('warning')->withArgs(
                fn (string $message, array $context) => str_contains($message, 'endpoint_not_implemented')
                    && $context['endpoint'] === $endpoint
                    && ($context['items'][0]['transaction_id'] ?? null) === 'TX-1001'
                    && ($context['items'][0]['amount'] ?? null) === '100.50'
            )->once();
        }
    }

    #[TestDox('step 端點（Dev 30 天無真實流量，payload 照 FormRequest 編）：stepBet 扣 deduct_amount、stepSettle 以 amount_bet 派彩、stepRollback 以 deduct_amount 退款')]
    public function test_step_endpoints_use_their_own_amount_fields(): void
    {
        $step = fn (string $betId) => $this->sign([
            'bettranstion_id' => $betId,
            'username' => $this->username,
            'match_play' => ['home' => 'A'],
            'choose_bet' => 'home',
            'price' => '1.9',
            'amount_bet' => '100',
            'deduct_amount' => '60',
            'match_tor_side' => 'home',
            'round' => 'FT',
            'sport_id' => '1',
            'sport_name' => '足球',
            'sport_name_en' => 'Football',
            'timestamp' => $this->timestamp,
        ]);

        $this->postJson("{$this->baseUrl}/step/bet", $step('STEP-001'))->assertOk()->assertJson(['balance' => 940.0]);
        $this->postJson("{$this->baseUrl}/step/bet", $step('STEP-002'))->assertOk()->assertJson(['balance' => 880.0]);

        $this->postJson("{$this->baseUrl}/step/settle", $this->itemPayload([
            ['bettranstion_id' => 'STEP-001', 'username' => $this->username, 'amount_bet' => '150'],
        ]))->assertOk();
        $this->assertSame('1030.0000', $this->balance());

        $this->postJson("{$this->baseUrl}/step/rollback", $step('STEP-002'))->assertOk()->assertJson(['balance' => 1090.0]);
        $this->assertSame('1090.0000', $this->balance());
    }
}
