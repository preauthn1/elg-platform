<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * PTSW settlement 在「close round + 無 pay 欄位」情境下的處理測試。
 *
 * Vendor 對「玩家輸光、無資金支付」的 round close 不會送 pay.transactionCode，
 * `createDealRecord('gameRoundResult', ...)` 必須把 transaction_code 寫成 NULL；
 * 若寫成空字串，第二筆同類 callback 會撞 ptsw_deal_records_transaction_code_unique 拋 1062，
 * settlement transaction rollback 導致 BR 永遠卡 IN_PROGRESS（2026-05 Alfawin77 incident root cause）。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswSettlementNoPayTest.php --compact
 */
class PtswSettlementNoPayTest extends PtswTestCase
{
    /**
     * 兩個不同 gameRoundCode 都以 close + 無 pay 結算，
     * 必須都能寫入 transaction_code=NULL 且推進 BR.status 到 SETTLE、建立 TBR。
     */
    #[Group('rules/vendor-ptsw#R-17')]
    public function test_settlement_without_pay_writes_null_transaction_code_and_does_not_collide(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $this->settleRoundClosedWithNoPayout(
            gameRoundCode: 'live_no_pay_round_1',
            betTxCode: 'tc-bet-no-pay-1',
            betRequestId: 'req-bet-no-pay-1',
            settleRequestId: 'req-settle-no-pay-1',
            betAmount: '30.0',
            roundCloseDate: '2026-05-17 14:51:24.000',
        );

        $this->settleRoundClosedWithNoPayout(
            gameRoundCode: 'live_no_pay_round_2',
            betTxCode: 'tc-bet-no-pay-2',
            betRequestId: 'req-bet-no-pay-2',
            settleRequestId: 'req-settle-no-pay-2',
            betAmount: '50.0',
            roundCloseDate: '2026-05-17 15:10:00.000',
        );

        $settledRounds = PtswBetRecord::query()
            ->where('status', BetRecordStatusEnum::SETTLE->value)
            ->get();
        $this->assertCount(2, $settledRounds, '兩個 round 都應推進到 SETTLE');
        foreach ($settledRounds as $br) {
            $this->assertNotNull($br->payout_time, 'payout_time 應由 gameRoundClose.date 補上');
        }

        $dealRecords = PtswDealRecord::query()
            ->where('type', 'gameRoundResult')
            ->get();
        $this->assertCount(2, $dealRecords);
        foreach ($dealRecords as $dr) {
            $this->assertNull(
                $dr->transaction_code,
                'gameRoundResult 無 pay 時 transaction_code 必須為 NULL（撞空字串會 1062 拋錯）'
            );
        }
        $this->assertSame(
            0,
            PtswDealRecord::query()->where('transaction_code', '')->count(),
            'transaction_code 不可寫入空字串',
        );

        $this->assertSame(2, TotalBetRecord::query()->where('vendor_id', $this->vendor->id)->count());
    }

    private function settleRoundClosedWithNoPayout(
        string $gameRoundCode,
        string $betTxCode,
        string $betRequestId,
        string $settleRequestId,
        string $betAmount,
        string $roundCloseDate,
    ): void {
        $betResponse = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => $betRequestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => $betTxCode,
            'amount' => $betAmount,
            'transactionDate' => '2026-05-17T14:51:23Z',
        ]);
        $betResponse->assertStatus(200);
        $betResponse->assertJsonMissing(['error']);

        $settleResponse = $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => $settleRequestId,
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'slion',
            'jackpot' => [
                'contributionAmount' => '2',
                'winAmount' => '0',
                'jackpotId' => 'slion_70081_3',
                'jackpotContributions' => [
                    [
                        'amount' => '2',
                        'jackpotId' => 'slion_70081_3',
                        'subJackpotInfo' => [
                            ['amount' => '2', 'jackpotId' => 'slion_70081_3'],
                        ],
                    ],
                ],
            ],
            'gameRoundClose' => [
                'date' => $roundCloseDate,
                'rngGeneratorId' => 'SecureRandom',
                'rngSoftwareId' => 'Casino CaGS 0.0.0.0',
            ],
        ]);
        $settleResponse->assertStatus(200);
        $settleResponse->assertJsonMissing(['error']);
    }
}
