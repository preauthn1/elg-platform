<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotSW;

use App\Vendors\Models\SexyslotswBetRecord;
use App\Vendors\Models\SexyslotswDealRecord;

/**
 * 驗證 SexySlotSW fund 端點 debit（投注）：正常投注、餘額不足、冪等性、multi-bet 累加。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotSW/FundDebitTest.php --compact
 */
class FundDebitTest extends SexySlotSWTestCase
{
    public function test_debit_deducts_balance_and_creates_records(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = $this->fundPayload('debit', 'txn-001', 'round-001', 50.00);

        $response = $this->postJson("{$this->baseUrl}/fund", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 200)
            ->assertJsonPath('fundtransferresponse.status.status', 'SUCCESS');

        $this->assertEquals(1, SexyslotswBetRecord::count());
        $this->assertEquals(1, SexyslotswDealRecord::count());

        $br = SexyslotswBetRecord::first();
        $this->assertEquals('round-001', $br->bet_id);
        $this->assertEquals('50.0000', $br->bet);
        $this->assertEquals('50.0000', $br->valid_bet);
        $this->assertEquals('-50.0000', $br->win_lose);

        $dr = SexyslotswDealRecord::first();
        $this->assertEquals('txn-001', $dr->reference_no);
        $this->assertEquals('debit', $dr->action);

        $this->member->refresh();
        $this->assertEquals('950.0000', $this->member->balance);
    }

    public function test_debit_insufficient_balance_returns_error(): void
    {
        $this->member->update(['balance' => '10.0000']);

        $payload = $this->fundPayload('debit', 'txn-insuf', 'round-insuf', 50.00);

        $response = $this->postJson("{$this->baseUrl}/fund", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 402)
            ->assertJsonPath('fundtransferresponse.status.status', 'INSUFFICIENT_BALANCE');

        $this->assertEquals(0, SexyslotswBetRecord::count());
        $this->assertEquals(0, SexyslotswDealRecord::count());
    }

    public function test_debit_duplicate_transaction_is_idempotent(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = $this->fundPayload('debit', 'txn-idem', 'round-idem', 30.00);

        $this->postJson("{$this->baseUrl}/fund", $payload)->assertStatus(200);
        $this->postJson("{$this->baseUrl}/fund", $payload)->assertStatus(200);

        $this->assertEquals(1, SexyslotswBetRecord::count());
        $this->assertEquals(1, SexyslotswDealRecord::count());

        $this->member->refresh();
        $this->assertEquals('970.0000', $this->member->balance);
    }

    public function test_debit_multi_bet_same_round_accumulates(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload1 = $this->fundPayload('debit', 'txn-mb-1', 'round-mb', 20.00);
        $payload2 = $this->fundPayload('debit', 'txn-mb-2', 'round-mb', 30.00);

        $this->postJson("{$this->baseUrl}/fund", $payload1)->assertStatus(200);
        $this->postJson("{$this->baseUrl}/fund", $payload2)->assertStatus(200);

        $this->assertEquals(1, SexyslotswBetRecord::count());
        $this->assertEquals(2, SexyslotswDealRecord::count());

        $br = SexyslotswBetRecord::first();
        $this->assertEquals('50.0000', $br->bet);
        $this->assertEquals('50.0000', $br->valid_bet);
        $this->assertEquals('-50.0000', $br->win_lose);

        $this->member->refresh();
        $this->assertEquals('950.0000', $this->member->balance);
    }

    public function test_debit_invalid_hashkey_returns_error(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = $this->fundPayload('debit', 'txn-hash', 'round-hash', 50.00);
        $payload['hashkey'] = 'invalidhash';

        $response = $this->postJson("{$this->baseUrl}/fund", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 402);

        $this->assertEquals(0, SexyslotswBetRecord::count());
    }
}
