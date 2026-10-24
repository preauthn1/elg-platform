<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Helpers\Common;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class UUSlotSWGetBalanceTest extends UUSlotSWTestCase
{
    private $functionName = 'GetBalance';

    #[Group('rules/vendor-uuslotsw#R-8')]
    public function test_post_get_balance_ok()
    {
        // Arrange
        $postData = [
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'AuthToken' => '',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:35',
            'Signature' => 'c8e236b6bda29fe6d8c9a22782ddbfe8',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // $balance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $balance = 10000;
        $this->member->update([
            'balance' => $balance,
        ]);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'Balance' => Common::numMul($balance, $this->vendorInfo->rate, 4),
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-3')]
    public function test_post_get_balance_failed_wrong_player()
    {
        // Arrange
        $postData = [
            'PlayerId' => 'wrongPlayer',
            'Currency' => 'MYR',
            'AuthToken' => '',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:35',
            'Signature' => 'c8e236b6bda29fe6d8c9a22782ddbfe8',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_PLAYER,
                'Description' => 'Account not found',
                'Balance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-3')]
    public function test_post_get_balance_failed_inactive_player()
    {
        // Arrange
        $postData = [
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'AuthToken' => '',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:35',
            'Signature' => 'c8e236b6bda29fe6d8c9a22782ddbfe8',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        $this->member->delete();

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_PLAYER,
                'Description' => 'Account inactive',
                'Balance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-6')]
    public function test_post_get_balance_failed_invalid_signature()
    {
        // Arrange
        $postData = [
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'AuthToken' => '',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:35',
            'Signature' => 'invalidSignature',
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_SIGNATURE,
                'Description' => 'Invalid Signature',
                'Balance' => 0,
            ]);
    }
}
