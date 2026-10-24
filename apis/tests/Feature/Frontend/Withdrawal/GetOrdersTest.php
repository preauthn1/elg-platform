<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\CurrencyEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Member;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Database\Seeders\WithdrawalTypeSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/GetOrdersTest.php
 */
class GetOrdersTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Bank $bank;

    protected AgentCashFlow $agentCashFlow;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(WithdrawalTypeSeeder::class);
        $this->seed(CashFlowSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->bank = $this->createBank();
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent);
    }

    public function test_success()
    {
        // 增加餘額方便提款測試
        $this->member->balance = 100;
        $this->member->save();

        $postData = [
            'amount' => 50,
            'account_name' => 'tim',
            'account_no' => '123546789',
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'bank_code' => $this->bank->code,
        ];

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/create-order',
            data: $postData,
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/orders',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );
        // {
        //     "code": 200,
        //     "message": "成功",
        //     "data": {
        //         "items": [
        //             {
        //                 "id": 360,
        //                 "created_at": "2025-07-31 17:24:38",
        //                 "amount": "50.0000",
        //                 "status": "待審核",
        //                 "icon_status": "pending",
        //                 "bank_icon": null,
        //                 "bank_name": "MayBank",
        //                 "remark": "AutoWithdrawal"
        //             }
        //         ],
        //         "pagination": {
        //             "per_page": 15,
        //             "total": 1,
        //             "pages": 1,
        //             "previous": "",
        //             "current": 1,
        //             "next": ""
        //         }
        //     }
        // }
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['data']['items']);
        $item = $data['data']['items'][0];
        $this->assertIsInt($item['id']);
        $this->assertIsString($item['created_at']);
        $this->assertEquals('50.0000', $item['amount']);
        $this->assertEquals('待審核', $item['status']);
        $this->assertEquals('pending', $item['icon_status']);
        $this->assertNull($item['bank_icon']);
        $this->assertEquals('MayBank', $item['bank_name']);
        $this->assertEquals('AutoWithdrawal', $item['remark']);
    }

    protected function createBank(): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::BANK->value,
            'name' => 'MayBank',
            'code' => 'MayBank',
            'third_party_code' => null,
            'currency_id' => CurrencyEnum::MYR->value,
            'info' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0.1,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 99,
            'image_url' => null,
            'status' => 1,
            'remark' => 'MY',
            'admin' => 0,
        ]);
    }
}
