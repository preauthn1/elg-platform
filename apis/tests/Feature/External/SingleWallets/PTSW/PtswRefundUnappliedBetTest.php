<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW settlement REFUND 對「從未扣款的下注」不得入帳。
 *
 * 2026-09-05 TPower 事故：下注在餘額檢查階段被拒（BR 已建、deal record 未 applied），
 * PT 端因逾時判投注失敗送 REFUND，settlement 只查 BR 存在就走 changeBalance，
 * before（bet 0）→ after（bet −X）算出 +X 入帳，5 筆多付玩家 253.00（target/op_tickets#3079）。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswRefundUnappliedBetTest.php --compact
 */
class PtswRefundUnappliedBetTest extends PtswTestCase
{
    #[TestDox('settlement：REFUND 對應的下注從未扣款（餘額不足被拒）時不入帳、回 ERR_NO_BET')]
    #[Group('rules/vendor-ptsw#R-22')]
    public function test_refund_for_unapplied_bet_does_not_credit_wallet(): void
    {
        // 對齊事故現場：TPower 的 PTSW vendor_info 未開禮碼（rolling_chip_status=0），無禮碼錢包
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $initialBalance = '10.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'live_refund_unapplied_1';
        $betTxCode = 'tc-unapplied-bet-1';

        $betResponse = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-unapplied-bet-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => $betTxCode,
            'amount' => '30.0',
            'transactionDate' => '2026-09-05T16:27:18Z',
        ]);
        $betResponse->assertStatus(200);
        $betResponse->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        // 前置狀態與事故一致：BR 已建（bet 0）、bet deal record 未 applied
        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->first();
        $this->assertNotNull($betRecord, '被拒的下注仍會留下 BR');
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $betDeal = PtswDealRecord::query()->where('bet_id', $gameRoundCode)->where('type', 'bet')->first();
        $this->assertNotNull($betDeal);
        $this->assertNull($betDeal->applied_at, '被拒的下注 deal record 不會 applied');

        $settleResponse = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-unapplied-refund-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-unapp-refund-1',
                'transactionDate' => '2026-09-05T16:27:49Z',
                'amount' => '30.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => $betTxCode,
            ],
        ]);
        $settleResponse->assertStatus(200);

        $this->member->refresh();
        $this->assertSame($initialBalance, (string) $this->member->balance, '未扣款的下注退款不得入帳');
        $settleResponse->assertJsonPath('error.code', 'ERR_NO_BET');

        $betRecord->refresh();
        $this->assertSame('0.0000', (string) $betRecord->bet, 'BR.bet 不得被沖成負值');
        $this->assertSame('0.0000', (string) $betRecord->win_lose);
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：REFUND 未帶 relatedTransactionCode 時一律拒絕（該局無已扣款下注）')]
    #[Group('rules/vendor-ptsw#R-21')]
    public function test_refund_without_related_code_and_no_applied_bet_does_not_credit_wallet(): void
    {
        // 對齊事故現場：TPower 的 PTSW vendor_info 未開禮碼（rolling_chip_status=0），無禮碼錢包
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $initialBalance = '10.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'live_refund_unapplied_2';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-unapplied-bet-2',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-unapplied-bet-2',
            'amount' => '30.0',
            'transactionDate' => '2026-09-05T16:37:28Z',
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $settleResponse = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-unapplied-refund-2',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-unapp-refund-2',
                'transactionDate' => '2026-09-05T16:37:37Z',
                'amount' => '30.0',
                'type' => 'REFUND',
            ],
        ]);
        $settleResponse->assertStatus(200);

        $this->member->refresh();
        $this->assertSame($initialBalance, (string) $this->member->balance, '未扣款的下注退款不得入帳');
        $settleResponse->assertJsonPath('error.code', 'ERR_NO_BET');
    }

    #[TestDox('settlement：multi-bet 局部扣款時，REFUND 只對已扣款的那筆生效，被拒那筆回 ERR_NO_BET')]
    #[Group('rules/vendor-ptsw#R-22')]
    public function test_refund_in_multi_bet_round_only_refunds_applied_bet(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $this->member->update(['balance' => '40.0000']);

        $gameRoundCode = 'live_refund_multi_1';

        // 下注 A：10，成功扣款 → 餘額 30
        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-multi-bet-a',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-multi-bet-a',
            'amount' => '10.0',
            'transactionDate' => '2026-09-05T16:40:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        // 下注 B：35，餘額不足被拒（deal record 未 applied）
        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-multi-bet-b',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-multi-bet-b',
            'amount' => '35.0',
            'transactionDate' => '2026-09-05T16:40:05Z',
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $this->member->refresh();
        $this->assertSame('30.0000', (string) $this->member->balance);

        // 對 B 退款：必須拒、錢包不動
        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-multi-refund-b',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-multi-refund-b',
                'transactionDate' => '2026-09-05T16:40:30Z',
                'amount' => '35.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-multi-bet-b',
            ],
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->member->refresh();
        $this->assertSame('30.0000', (string) $this->member->balance, '被拒下注的退款不得入帳');

        // 對 A 退款：必須成功、只退 A 的 10
        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-multi-refund-a',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-multi-refund-a',
                'transactionDate' => '2026-09-05T16:40:31Z',
                'amount' => '10.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-multi-bet-a',
            ],
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $this->member->refresh();
        $this->assertSame('40.0000', (string) $this->member->balance, '已扣款的下注退款後餘額回到投注前');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->first();
        $this->assertSame('0.0000', (string) $betRecord->bet, 'BR.bet 只回退已扣款的 10，不得沖成負值');
        $this->assertSame(BetRecordStatusEnum::REFUND->value, $betRecord->status);
    }

    #[TestDox('settlement：帶 gameRoundClose 的 REFUND 打在未扣款注單上同樣被拒，注單留 IN_PROGRESS、不建 TBR')]
    #[Group('rules/vendor-ptsw#R-22')]
    public function test_refund_with_round_close_on_unapplied_bet_is_rejected(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $initialBalance = '10.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'live_refund_unapplied_3';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-unapplied-bet-3',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-unapplied-bet-3',
            'amount' => '30.0',
            'transactionDate' => '2026-09-05T15:44:32Z',
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-unapplied-refund-3',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-unapp-refund-3',
                'transactionDate' => '2026-09-05T15:44:37Z',
                'amount' => '30.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-unapplied-bet-3',
            ],
            'gameRoundClose' => [
                'date' => '2026-09-05 15:44:37.000',
                'rngGeneratorId' => '1.0.3',
                'rngSoftwareId' => '1.0.56',
            ],
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->member->refresh();
        $this->assertSame($initialBalance, (string) $this->member->balance);

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->first();
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, $betRecord->status, '未扣款注單不以 0 關局結算');
        $this->assertNull($betRecord->payout_time);
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：被拒的 REFUND 重放（同 requestId／同 pay.transactionCode）錢包都不變')]
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_rejected_refund_replay_does_not_change_wallet(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $initialBalance = '10.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'live_refund_unapplied_4';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-unapplied-bet-4',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-unapplied-bet-4',
            'amount' => '30.0',
            'transactionDate' => '2026-09-05T16:27:18Z',
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_INSUFFICIENT_FUNDS');

        $refundPayload = [
            'requestId' => 'req-unapplied-refund-4',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-unapp-refund-4',
                'transactionDate' => '2026-09-05T16:27:49Z',
                'amount' => '30.0',
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-unapplied-bet-4',
            ],
        ];

        $this->postJson("{$this->baseurl}/settlement", $refundPayload)
            ->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        // 同 requestId 重放：deal record 冪等早退，回 200 + 餘額
        $this->postJson("{$this->baseurl}/settlement", $refundPayload)
            ->assertStatus(200)->assertJsonMissing(['error']);

        // 不同 requestId、同 pay.transactionCode 重放：bet_id + tc 冪等早退
        $this->postJson("{$this->baseurl}/settlement", ['requestId' => 'req-unapplied-refund-4b'] + $refundPayload)
            ->assertStatus(200)->assertJsonMissing(['error']);

        $this->member->refresh();
        $this->assertSame($initialBalance, (string) $this->member->balance, '重放不得讓錢包產生任何變動');
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：REFUND 未帶 relatedTransactionCode 即使該局有已扣款下注也一律拒絕、錢包不動')]
    #[Group('rules/vendor-ptsw#R-21')]
    public function test_refund_without_related_code_is_rejected_even_if_bet_applied(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => 0]);
        $this->member->update(['balance' => '40.0000']);

        $gameRoundCode = 'live_refund_norel_applied';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-norel-bet',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-norel-bet',
            'amount' => '10.0',
            'transactionDate' => '2026-09-05T16:50:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $this->member->refresh();
        $this->assertSame('30.0000', (string) $this->member->balance);

        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-norel-refund',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-norel-refund',
                'transactionDate' => '2026-09-05T16:50:30Z',
                'amount' => '10.0',
                'type' => 'REFUND',
            ],
        ])->assertStatus(200)->assertJsonPath('error.code', 'ERR_NO_BET');

        $this->member->refresh();
        $this->assertSame('30.0000', (string) $this->member->balance, '無 relatedTransactionCode 的退款不得放行');

        $betRecord = PtswBetRecord::query()->where('bet_id', $gameRoundCode)->first();
        $this->assertSame('10.0000', (string) $betRecord->bet);
        $this->assertSame(BetRecordStatusEnum::IN_PROGRESS->value, $betRecord->status);
    }
}
