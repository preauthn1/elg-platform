<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotSW;

use App\Vendors\Models\SexyslotswBetRecord;
use App\Vendors\Models\SexyslotswDealRecord;

/**
 * 驗證 SexySlotSW fund 端點 credit（派彩）與 refund（退款）。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotSW/FundCreditTest.php --compact
 */
class FundCreditTest extends SexySlotSWTestCase
{
    public function test_credit_adds_win_to_balance(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseUrl}/fund", $this->fundPayload('debit', 'txn-d1', 'round-c1', 50.00))
            ->assertJsonPath('fundtransferresponse.status.code', 200);

        $creditPayload = $this->fundPayload('credit', 'txn-c1', 'round-c1', 120.00);
        $creditPayload['fundtransferrequest']['fundinfo']['endround'] = true;

        $response = $this->postJson("{$this->baseUrl}/fund", $creditPayload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 200);

        $br = SexyslotswBetRecord::first();
        $this->assertEquals('50.0000', $br->bet);
        $this->assertEquals('120.0000', $br->win);
        $this->assertEquals('70.0000', $br->win_lose);
        $this->assertEquals(1, $br->status);

        $this->member->refresh();
        $this->assertEquals('1070.0000', $this->member->balance);
    }

    public function test_credit_round_not_found_returns_error(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = $this->fundPayload('credit', 'txn-cnf', 'round-nonexist', 100.00);

        $response = $this->postJson("{$this->baseUrl}/fund", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 402)
            ->assertJsonPath('fundtransferresponse.status.status', 'ROUND_NOT_FOUND');
    }

    public function test_credit_duplicate_is_idempotent(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseUrl}/fund", $this->fundPayload('debit', 'txn-di', 'round-ci', 50.00));

        $creditPayload = $this->fundPayload('credit', 'txn-ci', 'round-ci', 80.00);

        $this->postJson("{$this->baseUrl}/fund", $creditPayload)->assertStatus(200);
        $this->postJson("{$this->baseUrl}/fund", $creditPayload)->assertStatus(200);

        $this->assertEquals(1, SexyslotswBetRecord::count());
        $this->assertEquals(2, SexyslotswDealRecord::count());

        $this->member->refresh();
        $this->assertEquals('1030.0000', $this->member->balance);
    }

    public function test_refund_returns_bet_amount_to_balance(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseUrl}/fund", $this->fundPayload('debit', 'txn-dr', 'round-r1', 60.00))
            ->assertJsonPath('fundtransferresponse.status.code', 200);

        $this->member->refresh();
        $this->assertEquals('940.0000', $this->member->balance);

        $refundPayload = $this->fundPayload('credit', 'txn-r1', 'round-r1', 60.00);
        $refundPayload['fundtransferrequest']['fundinfo']['rollback'] = true;

        $response = $this->postJson("{$this->baseUrl}/fund", $refundPayload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 200);

        $br = SexyslotswBetRecord::first();
        $this->assertEquals('0.0000', $br->bet);
        $this->assertEquals(2, $br->status);

        $this->member->refresh();
        $this->assertEquals('1000.0000', $this->member->balance);
    }

    public function test_refund_no_matching_round_returns_round_not_found(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $refundPayload = $this->fundPayload('credit', 'txn-rnf', 'round-nonexist', 50.00);
        $refundPayload['fundtransferrequest']['fundinfo']['rollback'] = true;

        $response = $this->postJson("{$this->baseUrl}/fund", $refundPayload);

        $response->assertStatus(200)
            ->assertJsonPath('fundtransferresponse.status.code', 402)
            ->assertJsonPath('fundtransferresponse.status.status', 'ROUND_NOT_FOUND');

        $this->member->refresh();
        $this->assertEquals('1000.0000', $this->member->balance);
    }
}
