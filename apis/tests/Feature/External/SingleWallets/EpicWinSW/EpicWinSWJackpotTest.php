<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Helpers\Common;
use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWJackpotTest extends EpicWinSWTestCase
{
    private string $fn = 'Jackpot';

    private function jackpotParams(array $overrides = []): array
    {
        $post = $this->baseParams(array_merge([
            'TranId' => $this->uniqueId(),
            'JackpotId' => 'JACKPOT_1',
            'Payout' => 300,
            'ExchangeRate' => 1,
            'Result' => 'win',
            'TranDateTime' => $this->requestDateTime,
        ], $overrides));
        $this->regenerateSignature($this->fn, $post);

        return $post;
    }

    #[TestDox('Jackpot：派彩入帳並建立注單與 total_bet_record')]
    #[Group('rules/vendor-epicwin#R-14')]
    public function test_jackpot_credits_balance_and_creates_records(): void
    {
        // Arrange
        $balance = 500;
        $this->member->update(['balance' => $balance]);

        $payout = 300;
        $tranId = $this->uniqueId();
        $post = $this->jackpotParams(['TranId' => $tranId, 'Payout' => $payout]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert：彩金 Payout/rate 進真金錢包
        $platformWin = Common::numDiv($payout, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balance, $platformWin, 4);

        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'NewBalance' => (float) Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($expectedBalance, $this->member->balance);

        $this->assertDatabaseHas('epic_sw_bet_records', [
            'bet_id' => $tranId,
            'type' => 'J',
            'status' => 1,
        ]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    #[TestDox('Jackpot：TranId 重複時回 DUPLICATE_TRANSACTION 且不派彩')]
    #[Group('rules/vendor-epicwin#R-14')]
    public function test_jackpot_duplicate_transaction(): void
    {
        // Arrange
        $this->member->update(['balance' => 500]);
        $tranId = $this->uniqueId();
        $this->createBetRecord($tranId, 0, status: 1, type: 'J');

        $post = $this->jackpotParams(['TranId' => $tranId]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value]);

        $this->assertEquals(500, $this->member->balance);
    }

    #[TestDox('Jackpot：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_jackpot_invalid_signature(): void
    {
        // Arrange
        $post = $this->jackpotParams();
        $post['Signature'] = 'invalidSignature';

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }
}
