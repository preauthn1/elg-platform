<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class UUSlotSWJackpotTest extends UUSlotSWTestCase
{
    private $functionName = 'Jackpot';

    #[Group('rules/vendor-uuslotsw#R-23')]
    public function test_post_jackpot_ok()
    {
        // Arrange
        $this->member->update([
            'balance' => 0,
        ]);
        $payoutAmount = 100;

        $postData = [
            'TranId' => '20000016089',
            'BonusId' => 'UUSA',
            'BonusName' => 'Christmas 2021',
            'Result' => 'Test reward',
            'Currency' => 'MYR',
            'ExchangeRate' => 100,
            'Payout' => $payoutAmount,
            'PlayerId' => $this->acctId,
            'TranDateTime' => '2024-08-20 12:24:41',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:41.888+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:41',
            'Signature' => 'e70da664c4c8324e6863c6018e0161ad',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        $platformPayoutAmount = Common::numDiv($payoutAmount, $this->vendorInfo->rate, 4);
        $platformWinLoseAmount = Common::numDiv($payoutAmount, $this->vendorInfo->rate, 4);

        $oldTotalBalance = 0;
        // Should use payout instead of win_lose, since we sub the bet amount first.
        $newTotalBalance = Common::numAdd($oldTotalBalance, $platformPayoutAmount, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $totalBetRecord = TotalBetRecord::first();
        $transactionLog = TransactionLog::first();
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul($newTotalBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($newTotalBalance, $this->member->balance);
        $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(1, TotalBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($postData['TranId'], $betRecord->bet_id);
        $this->assertEquals($postData['TranId'], $betRecord->reference_bet_id);
        $this->assertEquals(0, $betRecord->bet);
        $this->assertEquals($platformPayoutAmount, $betRecord->payout);
        $this->assertEquals($platformWinLoseAmount, $betRecord->win_lose);
        $this->assertEquals('Jackpot', $betRecord->game_code);
        $this->assertEquals(BetRecordTypeEnum::JACKPOT, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->bet_time);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        $this->assertNotEmpty($transactionLog);
        // 單錢包投注 point 需為 bet: 正數, payout: 負數
        $this->assertEquals(Common::numMul($platformWinLoseAmount, -1, 4), $transactionLog->point);
        $this->assertEquals(0, $transactionLog->before_balance);
        $this->assertEquals($platformWinLoseAmount, $transactionLog->balance);

        $this->assertNotEmpty($totalBetRecord);
        $this->assertEquals($postData['TranId'], $totalBetRecord->reference_no);
        $this->assertEquals(0, $totalBetRecord->bet);
        $this->assertEquals(0, $totalBetRecord->valid_bet);
        $this->assertEquals($platformWinLoseAmount, $totalBetRecord->win_lose);
        $this->assertEquals($platformPayoutAmount, $totalBetRecord->jackpot);
        $this->assertEquals(0, $totalBetRecord->bonus);
        $this->assertEquals(0, $totalBetRecord->rolling_chip);
        $this->assertEquals($betRecord->bet_time, $totalBetRecord->bet_time);
        $this->assertEquals($betRecord->payout_time, $totalBetRecord->payout_time);
    }

    #[Group('rules/vendor-uuslotsw#R-24')]
    public function test_post_jackpot_failed_duplicate_bet_case()
    {
        // Arrange
        $payoutAmount = 100;
        $postData = [
            'TranId' => '20000016089',
            'BonusId' => 'UUSA',
            'BonusName' => 'Christmas 2021',
            'Result' => 'Test reward',
            'Currency' => 'MYR',
            'ExchangeRate' => 100,
            'Payout' => $payoutAmount,
            'PlayerId' => $this->acctId,
            'TranDateTime' => '2024-08-20 12:24:41',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:41.888+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:41',
            'Signature' => 'e70da664c4c8324e6863c6018e0161ad',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                'Description' => 'Jackpot bet_id or reference_bet_id duplicate, bet_id: 20000016089 reference_bet_id: 20000016089',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-6')]
    public function test_post_jackpot_failed_invalid_signature()
    {
        // Arrange
        $postData = [
            'TranId' => '20000016089',
            'BonusId' => 'UUSA',
            'BonusName' => 'Christmas 2021',
            'Result' => 'Test reward',
            'Currency' => 'MYR',
            'ExchangeRate' => 100,
            'Payout' => 100,
            'PlayerId' => $this->acctId,
            'TranDateTime' => '2024-08-20 12:24:41',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:41.888+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:41',
            'Signature' => 'invalid',
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_SIGNATURE,
                'Description' => 'Invalid Signature',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }
}
