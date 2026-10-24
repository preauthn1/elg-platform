<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\Frontend\Withdrawal\BindBankAccountRequest;
use App\Http\Requests\Frontend\Withdrawal\CreateOrderRequest;
use Tests\TestCase;

/**
 * 驗證綁卡 / 建單入口的 prepareForValidation 會 trim 掉 account_no 頭尾空白
 * （OP #2243 源頭根治：避免帶空格帳號流入 withdrawal_orders 與三方代付）。
 *
 * php artisan test tests/Unit/Http/Requests/Frontend/Withdrawal/AccountNoTrimTest.php
 */
class AccountNoTrimTest extends TestCase
{
    public function test_create_order_request_trims_account_no(): void
    {
        $request = CreateOrderRequest::create('/withdrawal/create-order', 'POST', [
            'amount' => '100',
            'account_name' => 'Tester',
            'account_no' => '  4979405811  ',
            'bank_id' => 1,
            'bank_name' => 'Test Bank',
            'bank_code' => 'TB',
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('4979405811', $request->input('account_no'));
    }

    public function test_bind_bank_account_request_trims_account_no(): void
    {
        $request = BindBankAccountRequest::create('/withdrawal/bind-bank-account', 'POST', [
            'account' => [
                ['bank_id' => 1, 'account_name' => 'Tester', 'account_no' => '  4979405811  '],
            ],
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame('4979405811', $request->input('account.0.account_no'));
    }
}
