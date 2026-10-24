<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotSW;

/**
 * 驗證 SexySlotSW inquiry 端點：交易存在、交易不存在、依 roundId 查詢。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotSW/InquiryTest.php --compact
 */
class InquiryTest extends SexySlotSWTestCase
{
    public function test_inquiry_existing_transaction_returns_success(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseUrl}/fund", $this->fundPayload('debit', 'txn-iq1', 'round-iq1', 50.00));

        $creditPayload = $this->fundPayload('credit', 'txn-iq1-win', 'round-iq1', 80.00);
        $creditPayload['fundtransferrequest']['fundinfo']['endround'] = true;
        $this->postJson("{$this->baseUrl}/fund", $creditPayload);

        $response = $this->postJson("{$this->baseUrl}/inquiry", [
            'access_token' => $this->clientAccessToken,
            'hashkey' => $this->validHashkey(),
            'client_player_id' => $this->username,
            'transactionId' => 'txn-iq1',
            'roundId' => 'round-iq1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 200)
            ->assertJsonPath('status', 'TRANSACTION_SUCCESS')
            ->assertJsonPath('data.total_win', 80);
    }

    public function test_inquiry_by_round_id_only(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseUrl}/fund", $this->fundPayload('debit', 'txn-iq2', 'round-iq2', 30.00));

        $response = $this->postJson("{$this->baseUrl}/inquiry", [
            'access_token' => $this->clientAccessToken,
            'hashkey' => $this->validHashkey(),
            'client_player_id' => $this->username,
            'transactionId' => '',
            'roundId' => 'round-iq2',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 200)
            ->assertJsonPath('status', 'TRANSACTION_SUCCESS')
            ->assertJsonPath('data.total_win', 0);
    }

    public function test_inquiry_not_found_returns_404(): void
    {
        $response = $this->postJson("{$this->baseUrl}/inquiry", [
            'access_token' => $this->clientAccessToken,
            'hashkey' => $this->validHashkey(),
            'client_player_id' => $this->username,
            'transactionId' => 'nonexistent',
            'roundId' => 'nonexistent-round',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 404)
            ->assertJsonPath('status', 'TRANSACTION_NOT_FOUND');
    }

    public function test_inquiry_invalid_hashkey_returns_not_found(): void
    {
        $response = $this->postJson("{$this->baseUrl}/inquiry", [
            'access_token' => $this->clientAccessToken,
            'hashkey' => 'invalidhash',
            'client_player_id' => $this->username,
            'transactionId' => 'txn-x',
            'roundId' => 'round-x',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 404);
    }
}
