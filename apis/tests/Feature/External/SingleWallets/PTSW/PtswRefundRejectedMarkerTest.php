<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW settlement 拒絕 REFUND 時，留下的 deal record 必須帶 `_rejected` 標記。
 *
 * 被拒的請求在 runDealAction 的交易內已由 createDealRecord 落地、不會回滾（同 requestId／transactionCode
 * 重送要走冪等早退），於是 ptsw_deal_records 會留下一筆 type=gameRoundResult、bet_amount<0、applied_at=NULL
 * 的 REFUND 形狀事件，與真正生效的退款沖銷事件長得一樣。沒有標記時：
 * - playtech:reconcile-bet-accumulation 把它算進 SUM(bet_amount)，該局 expected_bet 被沖成 0，真正漏記的注單漏列；
 * - isBetAlreadyRefunded() 把它當成「已退過」，語意上錯（沒有任何錢動過）。
 * 標記寫在 raw 的 `_rejected`（比照 refund-unsettled 命令的 `_recovery`），記錯誤碼與原因。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswRefundRejectedMarkerTest.php --compact
 */
class PtswRefundRejectedMarkerTest extends PtswTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 對齊事故現場：TPower 的 PTSW vendor_info 未開禮碼（rolling_chip_status=0），無禮碼錢包
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $this->member->update(['balance' => '40.0000']);
    }

    #[TestDox('settlement：REFUND 缺 relatedTransactionCode 被拒時，deal record raw 補 _rejected（code／reason），原始 payload 保留')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_refund_rejected_for_missing_related_code_marks_deal_record(): void
    {
        $gameRoundCode = 'live_rej_norel_1';

        $this->bet(requestId: 'req-rej-norel-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-norel-bet', amount: '10.0', transactionDate: '2026-09-05T16:00:00Z')
            ->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('30.0000', $this->memberBalance());

        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-rej-norel-refund',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-rej-norel-refund',
                'transactionDate' => '2026-09-05T16:00:30Z',
                'amount' => '10.0',
                'type' => 'REFUND',
            ],
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('30.0000', $this->memberBalance(), '被拒的退款不得入帳');

        $event = $this->refundEvent($gameRoundCode, 'tc-rej-norel-refund');
        $this->assertRejectedMarker($event);
        $this->assertSame('REFUND', $event->raw['pay']['type'] ?? null, '標記是補在原始 payload 之上，不得覆蓋 raw');
    }

    #[TestDox('settlement：REFUND 對應下注未扣款被拒時，deal record raw 補 _rejected')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_refund_rejected_for_unapplied_bet_marks_deal_record(): void
    {
        $this->member->update(['balance' => '10.0000']);
        $gameRoundCode = 'live_rej_unapplied_1';

        $this->bet(requestId: 'req-rej-unapp-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-unapp-bet', amount: '30.0', transactionDate: '2026-09-05T16:10:00Z')
            ->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $this->refund(
            requestId: 'req-rej-unapp-refund',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-unapp-refund',
            relatedTransactionCode: 'tc-rej-unapp-bet',
            amount: '30.0',
            transactionDate: '2026-09-05T16:10:30Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('10.0000', $this->memberBalance());
        $this->assertRejectedMarker($this->refundEvent($gameRoundCode, 'tc-rej-unapp-refund'));
    }

    #[TestDox('settlement：同一筆下注重複 REFUND 被拒時只有被拒那筆帶 _rejected，生效的退款事件不帶')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_duplicate_refund_marks_only_the_rejected_event(): void
    {
        $gameRoundCode = 'live_rej_dup_1';

        $this->bet(requestId: 'req-rej-dup-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-dup-bet', amount: '10.0', transactionDate: '2026-09-05T16:20:00Z')
            ->assertStatus(200)->assertJsonMissingPath('error');

        $this->refund(
            requestId: 'req-rej-dup-refund-a',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-dup-refund-a',
            relatedTransactionCode: 'tc-rej-dup-bet',
            amount: '10.0',
            transactionDate: '2026-09-05T16:20:30Z',
        )->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('40.0000', $this->memberBalance());

        $this->refund(
            requestId: 'req-rej-dup-refund-b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-dup-refund-b',
            relatedTransactionCode: 'tc-rej-dup-bet',
            amount: '10.0',
            transactionDate: '2026-09-05T16:25:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');
        $this->assertSame('40.0000', $this->memberBalance());

        $this->assertRejectedMarker($this->refundEvent($gameRoundCode, 'tc-rej-dup-refund-b'));
        $this->assertArrayNotHasKey('_rejected', $this->refundEvent($gameRoundCode, 'tc-rej-dup-refund-a')->raw, '生效的退款事件不得被標記');
    }

    #[TestDox('settlement：REFUND 找不到注單被拒時，deal record raw 同樣補 _rejected')]
    #[Group('rules/vendor-ptsw#R-24')]
    public function test_refund_rejected_for_missing_bet_record_marks_deal_record(): void
    {
        $gameRoundCode = 'live_rej_nobr_1';

        $this->bet(requestId: 'req-rej-nobr-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-nobr-bet', amount: '10.0', transactionDate: '2026-09-05T16:30:00Z')
            ->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('30.0000', $this->memberBalance());

        // 下注 deal record 已 applied、但注單列不存在：退款走到「bet record not found」那道 ERR_NO_BET
        PtswBetRecord::query()->where('bet_id', $gameRoundCode)->delete();

        $this->refund(
            requestId: 'req-rej-nobr-refund',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-nobr-refund',
            relatedTransactionCode: 'tc-rej-nobr-bet',
            amount: '10.0',
            transactionDate: '2026-09-05T16:30:30Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->assertSame('30.0000', $this->memberBalance());
        $event = $this->refundEvent($gameRoundCode, 'tc-rej-nobr-refund');
        $this->assertRejectedMarker($event);
        // resolveSettlementOutcome() 會把 REFUND 的 win_amount 改成正數退款額（僅供成功路徑的 win_lose 回滾用）；
        // 被拒事件 save() 時不得把這個髒值一起落地，退款事件 win_amount 恆為 0 是既有不變式
        $this->assertSame('0.0000', (string) $event->win_amount, '被拒事件的 win_amount 必須維持 0，與其他三道分支及生效的沖銷事件同形');
    }

    #[TestDox('settlement：三方 payload 自帶 _rejected 不得讓生效的退款事件被當成被拒（閘門 fail-open）')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_vendor_payload_cannot_forge_rejected_marker(): void
    {
        $gameRoundCode = 'live_rej_forge_1';

        $this->bet(requestId: 'req-rej-forge-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-forge-bet', amount: '10.0', transactionDate: '2026-09-05T16:50:00Z')
            ->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('30.0000', $this->memberBalance());

        // 生效的退款，但 payload 夾帶保留鍵
        $forged = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-rej-forge-refund-a',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-rej-forge-ref-a',
                'transactionDate' => '2026-09-05T16:50:30Z',
                'amount' => '10.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-rej-forge-bet',
            ],
            '_rejected' => ['code' => 'ERR_NO_BET', 'reason' => 'forged by vendor'],
            '_recovery' => ['command' => 'forged by vendor'],
        ]);
        $forged->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('40.0000', $this->memberBalance());

        $effective = $this->refundEvent($gameRoundCode, 'tc-rej-forge-ref-a');
        $this->assertArrayNotHasKey('_rejected', $effective->raw, '三方送來的保留鍵不得落進 raw');
        $this->assertArrayNotHasKey('_recovery', $effective->raw, '三方送來的保留鍵不得落進 raw');

        // 換新 transactionCode 重送同一筆下注的退款：閘門必須仍看得到上面那筆生效事件
        $this->refund(
            requestId: 'req-rej-forge-refund-b',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-forge-ref-b',
            relatedTransactionCode: 'tc-rej-forge-bet',
            amount: '10.0',
            transactionDate: '2026-09-05T16:55:00Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');
        $this->assertSame('40.0000', $this->memberBalance(), '偽造的 _rejected 不得讓同一筆下注退第二次');
    }

    #[TestDox('settlement：被拒後同 requestId／同 transactionCode 重送走冪等早退回 200，錢包不動、_rejected 仍在')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_rejected_refund_replay_keeps_marker_and_returns_success(): void
    {
        $this->member->update(['balance' => '10.0000']);
        $gameRoundCode = 'live_rej_replay_1';

        $this->bet(requestId: 'req-rej-replay-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-replay-bet', amount: '30.0', transactionDate: '2026-09-05T17:00:00Z')
            ->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $this->refund(
            requestId: 'req-rej-replay-refund',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-replay-refund',
            relatedTransactionCode: 'tc-rej-replay-bet',
            amount: '30.0',
            transactionDate: '2026-09-05T17:00:30Z',
        )->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        // 同 requestId 重放，與不同 requestId 但同 transactionCode 重放，都走 createDealRecord 的冪等早退
        $this->refund(
            requestId: 'req-rej-replay-refund',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-replay-refund',
            relatedTransactionCode: 'tc-rej-replay-bet',
            amount: '30.0',
            transactionDate: '2026-09-05T17:00:30Z',
        )->assertStatus(200)->assertJsonMissingPath('error');
        $this->refund(
            requestId: 'req-rej-replay-refund-2',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-replay-refund',
            relatedTransactionCode: 'tc-rej-replay-bet',
            amount: '30.0',
            transactionDate: '2026-09-05T17:00:30Z',
        )->assertStatus(200)->assertJsonMissingPath('error');

        $this->assertSame('10.0000', $this->memberBalance(), '重放不得讓錢包產生任何變動');
        $this->assertRejectedMarker($this->refundEvent($gameRoundCode, 'tc-rej-replay-refund'));
        $this->assertSame(1, PtswDealRecord::query()->where('bet_id', $gameRoundCode)->where('type', 'gameRoundResult')->count(), '重放不得再建事件');
    }

    #[TestDox('settlement：帶 _rejected 的退款事件不算已退款，同一筆下注之後合法的 REFUND 仍放行')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_rejected_refund_event_does_not_count_as_refunded_in_duplicate_guard(): void
    {
        $gameRoundCode = 'live_rej_guard_1';

        $this->bet(requestId: 'req-rej-guard-bet', gameRoundCode: $gameRoundCode, transactionCode: 'tc-rej-guard-bet', amount: '10.0', transactionDate: '2026-09-05T16:40:00Z')
            ->assertStatus(200)->assertJsonMissingPath('error');
        $this->assertSame('30.0000', $this->memberBalance());

        // 直接種一筆被拒的退款事件（形狀與 settlement 落地的一致：REFUND 指向同一筆下注、bet_amount 為負、applied_at NULL）
        PtswDealRecord::create([
            'request_id' => 'req-rej-guard-seeded',
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $gameRoundCode,
            'transaction_code' => 'tc-rej-guard-seeded',
            'type' => 'gameRoundResult',
            'bet_amount' => '-10.0000',
            'win_amount' => '0',
            'jackpot' => '0',
            'external_transaction_code' => 'ext-rej-guard-seeded',
            'external_transaction_date' => Carbon::parse('2026-09-05T16:40:20Z')->format('Y-m-d H:i:s.v'),
            'raw' => [
                'requestId' => 'req-rej-guard-seeded',
                'gameRoundCode' => $gameRoundCode,
                'pay' => [
                    'transactionCode' => 'tc-rej-guard-seeded',
                    'type' => 'REFUND',
                    'relatedTransactionCode' => 'tc-rej-guard-bet',
                    'amount' => '10.0',
                ],
                '_rejected' => ['code' => 'ERR_NO_BET', 'reason' => 'seeded for test'],
            ],
        ]);

        $this->refund(
            requestId: 'req-rej-guard-refund',
            gameRoundCode: $gameRoundCode,
            transactionCode: 'tc-rej-guard-refund',
            relatedTransactionCode: 'tc-rej-guard-bet',
            amount: '10.0',
            transactionDate: '2026-09-05T16:41:00Z',
        )->assertStatus(200)->assertJsonMissingPath('error');

        $this->assertSame('40.0000', $this->memberBalance(), '被拒事件沒動過錢，不得擋掉這筆下注第一次真正的退款');
    }

    private function assertRejectedMarker(PtswDealRecord $event): void
    {
        $this->assertIsArray($event->raw['_rejected'] ?? null, '被拒的退款事件 raw 必須帶 _rejected');
        $this->assertSame('ERR_NO_BET', $event->raw['_rejected']['code'] ?? null);
        $this->assertNotSame('', (string) ($event->raw['_rejected']['reason'] ?? ''), '_rejected 必須記拒絕原因');
        $this->assertNull($event->applied_at);
    }

    private function refundEvent(string $gameRoundCode, string $transactionCode): PtswDealRecord
    {
        return PtswDealRecord::query()
            ->where('bet_id', $gameRoundCode)
            ->where('type', 'gameRoundResult')
            ->where('transaction_code', $transactionCode)
            ->firstOrFail();
    }

    private function bet(string $requestId, string $gameRoundCode, string $transactionCode, string $amount, string $transactionDate): TestResponse
    {
        return $this->postJson("{$this->baseurl}/bet", [
            'requestId' => $requestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => $transactionCode,
            'amount' => $amount,
            'transactionDate' => $transactionDate,
        ]);
    }

    private function refund(
        string $requestId,
        string $gameRoundCode,
        string $transactionCode,
        string $relatedTransactionCode,
        string $amount,
        string $transactionDate,
    ): TestResponse {
        return $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => $requestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => $transactionCode,
                'transactionDate' => $transactionDate,
                'amount' => $amount,
                'type' => 'REFUND',
                'relatedTransactionCode' => $relatedTransactionCode,
            ],
        ]);
    }

    private function memberBalance(): string
    {
        return (string) $this->member->refresh()->balance;
    }
}
