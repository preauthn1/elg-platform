<?php

declare(strict_types=1);

namespace Tests\Feature\External\TransferWallets;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Vendors\Models\Sky1388Account;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Sky1388 Confirm Transfer Callback（防偽造上下分）
 *
 * php artisan test tests/Feature/External/TransferWallets/Sky1388ConfirmTransferTest.php
 */
class Sky1388ConfirmTransferTest extends TestCase
{
    private const string BASE_URL = 'external/api/tw/sky1388/confirm_transfer';

    private Agent $agent;

    private Member $member;

    private Sky1388Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->account = Sky1388Account::create([
            'vendor_info_id' => 0,
            'username' => 'wtsk00000001',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    #[Group('rules/vendor-sky1388#R-3')]
    public function test_returns_9999_when_account_not_found(): void
    {
        $response = $this->act([
            'kiosk' => 'no_such_account',
            'order' => 'order-1',
            'amount' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => '9999',
                'err_message' => 'Account not found',
            ]);
    }

    #[Group('rules/vendor-sky1388#R-4')]
    public function test_returns_9999_when_transaction_not_found(): void
    {
        $response = $this->act([
            'kiosk' => $this->account->username,
            'order' => 'no-such-order',
            'amount' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => '9999',
                'err_message' => 'Transaction not found',
            ]);
    }

    #[Group('rules/vendor-sky1388#R-5')]
    public function test_returns_0000_when_transaction_log_matches(): void
    {
        $this->createTransactionLog('order-in-transaction-log');

        $response = $this->act([
            'kiosk' => $this->account->username,
            'order' => 'order-in-transaction-log',
            'amount' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => '0000',
                'err_message' => '',
            ]);
    }

    #[Group('rules/vendor-sky1388#R-4')]
    #[Group('rules/vendor-sky1388#R-5')]
    public function test_returns_0000_when_only_wallet_transaction_log_matches(): void
    {
        $this->createWalletTransactionLog('order-in-wallet-transaction-log');

        $response = $this->act([
            'kiosk' => $this->account->username,
            'order' => 'order-in-wallet-transaction-log',
            'amount' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => '0000',
                'err_message' => '',
            ]);
    }

    private function act(array $params): TestResponse
    {
        return $this->postJson(self::BASE_URL, $params);
    }

    private function createTransactionLog(string $orderNo): TransactionLog
    {
        return TransactionLog::create([
            'transaction_no' => $orderNo,
            'member_id' => $this->member->id,
            'agent_id' => $this->agent->id,
            'vendor_id' => VendorIdEnum::SKY1388->value,
            'type' => TransactionEnum::TO_VENDOR->value,
            'point' => 10,
            'vendor_point' => 10,
            'rate' => 1,
            'before_balance' => 10,
            'balance' => 0,
            'vendor_balance' => 10,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => 0,
            'remark' => '',
            'admin' => 0,
        ]);
    }

    private function createWalletTransactionLog(string $orderNo): WalletTransactionLog
    {
        return WalletTransactionLog::create([
            'transaction_no' => $orderNo,
            'wallet_type_id' => WalletEnum::CODE_PC->value,
            'member_id' => $this->member->id,
            'agent_id' => $this->agent->id,
            'vendor_id' => VendorIdEnum::SKY1388->value,
            'order_id' => 0,
            'type' => WalletTransactionEnum::GAME_WALLET_TO_VENDOR->value,
            'point' => 10,
            'vendor_point' => 10,
            'rate' => 1,
            'before_balance' => 10,
            'balance' => 0,
            'vendor_balance' => 10,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => 0,
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'admin' => 0,
        ]);
    }
}
