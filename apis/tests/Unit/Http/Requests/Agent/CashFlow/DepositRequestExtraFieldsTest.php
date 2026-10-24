<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Agent\CashFlow;

use App\Http\Requests\Agent\CashFlow\DepositRequest;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 代理端存款 DepositRequest::getExtraFields() target/tickets#4433。
 *
 * 規則：建單本身用的 return_url／order_type_id／amount，以及新代理後台的分流參數 action／cash_flow_id
 * 不算動態欄位；其餘全部保留原鍵原值，交給 OrderService::createOrder 寫進 orders.extra_content。
 *
 * php artisan test tests/Unit/Http/Requests/Agent/CashFlow/DepositRequestExtraFieldsTest.php
 */
class DepositRequestExtraFieldsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extraFieldsOf(array $payload): array
    {
        $request = DepositRequest::create('/agent/api/agent/deposit', 'POST', $payload);

        return $request->getExtraFields();
    }

    #[TestDox('TngPin 送單：pin 進 extra_content，基本欄位與分流參數不進')]
    public function testPinIsKeptAndBaseFieldsAreDropped(): void
    {
        $extra = $this->extraFieldsOf([
            'action' => 'agent_self_deposit_create_order_fast',
            'return_url' => 'https://agent.example.test/agent_self_deposit/create_fast',
            'order_type_id' => 362,
            'cash_flow_id' => 58,
            'pin' => '1234567890123456',
        ]);

        $this->assertSame(['pin' => '1234567890123456'], $extra);
    }

    #[TestDox('沒有動態欄位時回空陣列，createOrder 會把 extra_content 存成 null')]
    public function testNoExtraFieldsGivesEmptyArray(): void
    {
        $extra = $this->extraFieldsOf([
            'return_url' => 'https://agent.example.test/',
            'order_type_id' => 10,
            'amount' => '100.00',
        ]);

        $this->assertSame([], $extra);
    }

    #[TestDox('first_name、last_name、bank_number 這類選填欄位照會員端規則也算動態欄位')]
    public function testOptionalNamedFieldsAreTreatedAsExtra(): void
    {
        $extra = $this->extraFieldsOf([
            'return_url' => 'https://agent.example.test/',
            'order_type_id' => 10,
            'amount' => '100.00',
            'first_name' => 'Ali',
            'bank_number' => '1234567890',
        ]);

        $this->assertSame(['first_name' => 'Ali', 'bank_number' => '1234567890'], $extra);
    }
}
