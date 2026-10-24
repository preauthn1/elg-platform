<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW settlement（派彩 / 退款 / 回合關閉）端點基本行為測試。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswSettlementTest.php --compact
 */
class PtswSettlementTest extends PtswTestCase
{
    #[TestDox('settlement：帶 pay(WIN) 且 gameRoundClose 時派彩並推進到 SETTLE')]
    #[Group('rules/vendor-ptsw#R-17')]
    public function test_settlement_with_pay_and_round_close_settles_and_pays_out(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'settle_round_win_1';
        $betAmount = '30.0';
        $winAmount = '50.00';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-settle-win-bet',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-settle-win-bet',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-01T10:00:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $settleResponse = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-settle-win-1',
            'username' => $this->username,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-settle-win-pay',
                'transactionDate' => '2026-06-01T10:05:00Z',
                'amount' => $winAmount,
                'type' => 'WIN',
            ],
            'gameRoundClose' => [
                'date' => '2026-06-01 10:05:01.000',
                'rngGeneratorId' => 'SecureRandom',
                'rngSoftwareId' => 'Casino CaGS 0.0.0.0',
            ],
        ]);

        $settleResponse->assertStatus(200);
        $settleResponse->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count());
        $betRecord = PtswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);

        $this->assertSame(1, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());

        $this->member->refresh();
        $expectedBalance = Common::numAdd(
            Common::numSub($initialBalance, $betAmount, 4),
            $winAmount,
            4,
        );
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    #[TestDox('settlement：pay.type=REFUND 時退款，狀態變更為 REFUND 且餘額回復')]
    #[Group('rules/vendor-ptsw#R-20')]
    public function test_settlement_with_refund_reverts_bet_and_sets_refund_status(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'settle_round_refund_1';
        $betAmount = '30.0';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-settle-refund-bet',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-settle-refund-bet',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-01T11:00:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $settleResponse = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-settle-refund-1',
            'username' => $this->username,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-settle-refund-pay',
                'transactionDate' => '2026-06-01T11:05:00Z',
                'amount' => $betAmount,
                'type' => 'REFUND',
                'relatedTransactionCode' => 'tc-settle-refund-bet',
            ],
        ]);

        $settleResponse->assertStatus(200);
        $settleResponse->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count());
        $betRecord = PtswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::REFUND->value, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);

        $this->assertSame(
            0,
            TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count(),
            '退款未關局時不應建立 TotalBetRecord',
        );

        $this->member->refresh();
        $this->assertEquals(
            $initialBalance,
            $this->member->balance,
            '退款後餘額應回復到投注前',
        );
    }

    #[TestDox('settlement：找不到對應的 bet record 時回 INTERNAL_ERROR')]
    #[Group('rules/vendor-ptsw#R-18')]
    public function test_settlement_without_existing_bet_record_returns_internal_error(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-settle-no-bet-1',
            'username' => $this->username,
            'gameRoundCode' => 'settle_round_never_bet',
            'gameCodeName' => 'ubal',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-settle-no-bet-1',
            'error' => ['code' => 'INTERNAL_ERROR'],
        ]);

        $this->assertEquals(0, PtswBetRecord::count());
        $this->assertSame(0, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    #[TestDox('settlement：相同 requestId 重送為冪等，不重複派彩也不重複建立 TotalBetRecord')]
    #[Group('rules/vendor-ptsw#R-19')]
    public function test_settlement_duplicate_request_id_is_idempotent(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'settle_round_idem_1';
        $betAmount = '30.0';
        $winAmount = '50.00';

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-settle-idem-bet',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-settle-idem-bet',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-01T12:00:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $settlePayload = [
            'requestId' => 'req-settle-idem-1',
            'username' => $this->username,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'pay' => [
                'transactionCode' => 'tc-settle-idem-pay',
                'transactionDate' => '2026-06-01T12:05:00Z',
                'amount' => $winAmount,
                'type' => 'WIN',
            ],
            'gameRoundClose' => [
                'date' => '2026-06-01 12:05:01.000',
                'rngGeneratorId' => 'SecureRandom',
                'rngSoftwareId' => 'Casino CaGS 0.0.0.0',
            ],
        ];

        $this->postJson("{$this->baseurl}/settlement", $settlePayload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->postJson("{$this->baseurl}/settlement", $settlePayload)
            ->assertStatus(200)
            ->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count(), '相同 requestId 重送不應重複建立 PtswBetRecord');
        $this->assertSame(
            1,
            TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count(),
            '相同 requestId 重送不應重複建立 TotalBetRecord',
        );

        $this->member->refresh();
        $expectedBalance = Common::numAdd(
            Common::numSub($initialBalance, $betAmount, 4),
            $winAmount,
            4,
        );
        $this->assertEquals($expectedBalance, $this->member->balance, '重送不應重複派彩');
    }
}
