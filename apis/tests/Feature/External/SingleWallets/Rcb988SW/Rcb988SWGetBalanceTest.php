<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Helpers\Common;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class Rcb988SWGetBalanceTest extends Rcb988SWTestCase
{
    private function makeParams(string $userId = '', string $key = ''): array
    {
        return [
            'key' => $key ?: $this->clientSecret,
            'message' => $this->buildMessage('getBalance', [
                'userId' => $userId ?: $this->username,
            ]),
        ];
    }

    /**
     * 正常查詢餘額：應回傳 SUCCESS 及換算後的餘額。
     */
    #[Group('rules/vendor-rcb988#R-1')]
    #[Group('rules/vendor-rcb988#R-10')]
    public function test_get_balance_ok(): void
    {
        // Arrange
        $balance = 10000;
        $this->member->update(['balance' => $balance]);

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($balance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeParams());

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'Success',
                'userId' => $this->username,
                'balance' => $expectedBalance,
            ]);
    }

    /**
     * 金鑰錯誤：應回傳 INVALID_CERTIFICATE。
     */
    #[Group('rules/vendor-rcb988#R-6')]
    public function test_get_balance_invalid_key(): void
    {
        // Act
        $response = $this->postEntrance($this->makeParams(key: 'wrong-secret-key'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::INVALID_CERTIFICATE->value,
            ]);
    }

    /**
     * 使用者不存在：應回傳 ACCOUNT_NOT_EXISTS。
     */
    #[Group('rules/vendor-rcb988#R-4')]
    public function test_get_balance_user_not_found(): void
    {
        // Act
        $response = $this->postEntrance($this->makeParams(userId: 'nonexistent_user'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::ACCOUNT_NOT_EXISTS->value,
            ]);
    }

    /**
     * 不支援的 action：應回傳 INVALID_PARAMETERS。
     */
    #[Group('rules/vendor-rcb988#R-7')]
    public function test_get_balance_invalid_action(): void
    {
        // Arrange — 非 getBalance 的 action 透過 txns 傳 userId，否則 getUsername() 先拋 INVALID_USER_ID
        $params = [
            'key' => $this->clientSecret,
            'message' => json_encode([
                'action' => 'invalidAction',
                'txns' => [['userId' => $this->username]],
            ]),
        ];

        // Act
        $response = $this->postEntrance($params);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::INVALID_PARAMETERS->value,
            ]);
    }
}
