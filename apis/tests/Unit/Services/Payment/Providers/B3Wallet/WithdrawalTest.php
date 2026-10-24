<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\B3Wallet;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\B3Wallet\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B3 Wallet 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/B3Wallet/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::firstOrCreate(['code' => 'SGD'], [
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://www.judy88.xin',
            'PAYMENT_KEY' => 'test_payment_key',
            'PAYMENT_PASS' => 'test_payment_pass',
            'available_banks' => [
                ['BankCode_ELG' => 'DBS', 'BankCode_Third' => 'DBS', 'BankName' => 'DBS Bank'],
                // 鏈上出金：本地銀行碼對到的三方碼就是 Chain
                ['BankCode_ELG' => 'USDT_TRC20', 'BankCode_Third' => 'TRON', 'BankName' => 'USDT - TRC20'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'name' => 'B3 Wallet',
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createWithdrawalOrder(Bank $bank, string $accountNo, string $accountName, float $amount = 88.8): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'bank_name' => $bank->name,
            'account_no' => $accountNo,
            'account_name' => $accountName,
            'amount' => $amount,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 銀行轉帳代付：正常提交，建立 PayoutRecord（維持 PENDING 待回調確認）
     *
     * 響應取自 TPower 2026-10-22 QA 實測（紀錄 1884243）：受理回 HTTP 201、`Error=null`，
     * 同步響應就帶 FeeAmount／TotalDeductAmount，但 `Paid=false`、沒有 Status，終態只能等回調查單。
     */
    public function test_bank_transfer_success_creates_payout_record(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = $this->createWithdrawalOrder($bank, '12345678', 'Alice');

        Http::fake([
            '*' => Http::response([
                'PayoutMethod' => 'BANK_TRANSFER',
                'PayNowProxyType' => null,
                'PayNowProxy' => null,
                'Amount' => 50,
                'RecipientAmount' => 50,
                'FeeRate' => 0.016,
                'FeeAmount' => 0.8,
                'TotalDeductAmount' => 50.8,
                'Date' => '2026-10-22T03:17:10.718Z',
                'Error' => null,
                'Paid' => false,
                'PaymentMethod' => null,
                'PayoutId' => '3d9cc0a9-0249-4a1f-9099-cbeb1018e316',
                'RecipientAccount' => '12345678',
                'RecipientBank' => 'DBS',
                'Currency' => 'SGD',
            ], 201),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from(['order' => $withdrawalOrder]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertSame('3d9cc0a9-0249-4a1f-9099-cbeb1018e316', $result->providerOrderNo);

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('3d9cc0a9-0249-4a1f-9099-cbeb1018e316', $record->third_party_transaction_no);

        Http::assertSent(function ($request) {
            return $request['PayoutMethod'] === 'BANK_TRANSFER'
                && $request['RecipientBank'] === 'DBS'
                && $request['RecipientAccount'] === '12345678';
        });
    }

    /**
     * PayNow 代付（電子錢包 Bank.type=2）：正常提交，帶 PayNowProxyType/PayNowProxy
     */
    public function test_paynow_success_creates_payout_record(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'B3_PAYNOW_MOBILE'], [
            'name' => 'PayNow (Mobile)',
            'type' => 2,
            'status' => 1,
            'admin' => 0,
            'info' => ['proxy_type' => 'MOBILE'],
        ]);

        $withdrawalOrder = $this->createWithdrawalOrder($bank, '+6591234567', 'Alice');

        Http::fake([
            '*' => Http::response([
                'PayoutId' => 'payout-2',
                'PayoutMethod' => 'PAYNOW',
                'PayNowProxyType' => 'MOBILE',
                'PayNowProxy' => '+6591234567',
                'Amount' => 88.8,
                'FeeAmount' => 1.0,
                'TotalDeductAmount' => 89.8,
                'Date' => now()->toIso8601String(),
                'Error' => null,
                'Paid' => false,
                'RecipientAccount' => '+6591234567',
                'RecipientBank' => 'PAYNOW',
                'Currency' => 'SGD',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from(['order' => $withdrawalOrder]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);

        Http::assertSent(function ($request) {
            return $request['PayoutMethod'] === 'PAYNOW'
                && $request['PayNowProxyType'] === 'MOBILE'
                && $request['PayNowProxy'] === '+6591234567'
                && !isset($request['RecipientBank'])
                && !isset($request['RecipientAccount']);
        });
    }

    /**
     * 鏈上出金：鏈別取自銀行的三方代碼，收款地址取會員填的帳號，且不得帶銀行轉帳／PayNow 欄位
     */
    public function test_crypto_success_sends_chain_and_address(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'USDT_TRC20'], [
            'name' => 'USDT - TRC20',
            'type' => 3,
            'status' => 1,
            'admin' => 0,
            'info' => [],
        ]);

        $address = 'TXYZabcdefghijklmnopqrstuvwxyz1234';
        $withdrawalOrder = $this->createWithdrawalOrder($bank, $address, 'Alice');

        Http::fake([
            '*' => Http::response([
                'PayoutId' => 'payout-crypto-1',
                'PayoutMethod' => 'CRYPTO',
                'Amount' => 88.8,
                'FeeAmount' => 1.0,
                'TotalDeductAmount' => 89.8,
                'Date' => now()->toIso8601String(),
                'Error' => null,
                'Paid' => false,
                'RecipientAccount' => $address,
                'RecipientBank' => 'CRYPTO',
                'Currency' => 'USDT',
            ], 200),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))
            ->handle(WithdrawalRequestData::from(['order' => $withdrawalOrder]));

        $this->assertTrue($result->isSuccess);

        Http::assertSent(function ($request) use ($address) {
            return $request['PayoutMethod'] === 'CRYPTO'
                && $request['Chain'] === 'TRON'
                && $request['ToAddress'] === $address
                && !isset($request['RecipientBank'])
                && !isset($request['RecipientAccount'])
                && !isset($request['PayNowProxy']);
        });
    }

    /**
     * 第三方拒絕（業務層 4xx，扁平 Error 字串，如文件所載成功結構之拒絕變體），正確處理並回傳失敗響應
     */
    public function test_third_party_rejection_returns_failure_response(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = $this->createWithdrawalOrder($bank, '12345678', 'Alice');

        Http::fake([
            '*' => Http::response(['Error' => 'INSUFFICIENT_BALANCE'], 422),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from(['order' => $withdrawalOrder]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertSame('INSUFFICIENT_BALANCE', $result->errorMessage);
    }

    /**
     * 第三方拒絕（業務層 4xx，巢狀小寫 error:{code,message}，實測 www.judy88.xin 實際回傳格式），
     * 需帶出三方真實錯誤訊息而非 Unknown error
     */
    public function test_third_party_rejection_with_nested_error_returns_provider_message(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = $this->createWithdrawalOrder($bank, '12345678', 'Alice');

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 'INSUFFICIENT_BALANCE',
                    'message' => 'Insufficient merchant wallet balance',
                ],
            ], 422),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle(WithdrawalRequestData::from(['order' => $withdrawalOrder]));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertSame('Insufficient merchant wallet balance', $result->errorMessage);
    }
}
