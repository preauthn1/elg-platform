<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\VipContent;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Database\Seeders\WithdrawalTypeSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/BanksTest.php
 */
class BanksTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

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
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent);
        $this->createBanks();
    }

    public function test_success()
    {
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/banks',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    [
                        'bank_name' => 'MayBank',
                        'bank_icon' => null,
                        'bank_info' => null,
                        'bank_code' => 'MayBank',
                        'bank_type' => 1,
                        'rate' => '1.000000000',
                        'currency_id' => 1,
                        'currency_name' => 'Malaysia Dollar',
                        'withdrawal_type_id' => 1,
                        'withdrawal_type_name' => 'Bank',
                        'min_amount' => '50',
                        'max_amount' => '1000',
                        'tax_type' => 0,
                        'tax' => '0.00000',
                    ],
                    [
                        'bank_name' => 'AustraliaBank',
                        'bank_icon' => null,
                        'bank_info' => null,
                        'bank_code' => 'AustraliaBank',
                        'bank_type' => 6,
                        'rate' => '2.00000',
                        'currency_id' => 1,
                        'currency_name' => 'Malaysia Dollar',
                        'withdrawal_type_id' => 6,
                        'withdrawal_type_name' => 'Australia Bank',
                        'min_amount' => '300.000',
                        'max_amount' => '3000.0000',
                        'tax_type' => 0,
                        'tax' => '0.00000',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    '*' => [
                        'bank_id',
                        'bank_name',
                        'bank_icon',
                        'bank_info',
                        'bank_code',
                        'bank_type',
                        'rate',
                        'currency_id',
                        'currency_name',
                        'withdrawal_type_id',
                        'withdrawal_type_name',
                        'min_amount',
                        'max_amount',
                        'tax_type',
                        'tax',
                    ],
                ],
            ]);
    }

    // 2025-05-22 Neil 在 API_Frontend 註解掉 VIP 功能
    // public function test_vip()
    // {
    //     $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::VIP_VISIBLE, 1);
    //     $vipContent = $this->createVipContent();
    //     $this->createMemberVip($vipContent);

    //     // Act
    //     $response = $this->json(
    //         method: 'POST',
    //         uri: 'frontend/api/withdrawal/banks',
    //         data: [],
    //         headers: [
    //             'Accept-Language' => 'zh-TW',
    //             'Authorization' => 'Bearer ' . $this->token,
    //         ]
    //     );

    //     // Assert JSON 結構
    //     $response->assertStatus(200)
    //         ->assertJson([
    //             'code' => 200,
    //             'data' => [
    //                 [
    //                     'bank_name' => 'AustraliaBank',
    //                     'bank_icon' => null,
    //                     'bank_info' => null,
    //                     'bank_code' => 'AustraliaBank',
    //                     'bank_type' => 6,
    //                     'rate' => '2.00000',
    //                     'currency_id' => 1,
    //                     'currency_name' => 'Malaysia Dollar',
    //                     'withdrawal_type_id' => 6,
    //                     'withdrawal_type_name' => 'Australia Bank',
    //                     'min_amount' => '300.0000',
    //                     'max_amount' => '2500.0000',
    //                     'tax_type' => 0,
    //                     'tax' => '0.00000',
    //                 ],
    //             ],
    //         ]);
    // }

    protected function createBanks()
    {
        Bank::query()->insert([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::BANK->value,
            'name' => 'MayBank',
            'code' => 'MayBank',
            'third_party_code' => null,
            'currency_id' => CurrencyEnum::MYR->value,
            'info' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 99,
            'image_url' => null,
            'status' => 1,
            'remark' => 'MY',
            'admin' => 0,
        ]);
        Bank::query()->insert([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::AUSTRALIA_BANK->value,
            'name' => 'AustraliaBank',
            'code' => 'AustraliaBank',
            'third_party_code' => null,
            'currency_id' => CurrencyEnum::MYR->value,
            'info' => null,
            'rate' => 2,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 300,
            'max_amount' => 3000,
            'sort' => 98,
            'image_url' => null,
            'status' => 1,
            'remark' => 'MY',
            'admin' => 0,
        ]);
    }

    protected function createVipContent(): VipContent
    {
        $vipContent = new VipContent;
        $vipContent->agent_id = $this->agent->id;
        $vipContent->level = 0;
        $vipContent->name = 'VIP0';
        $vipContent->target_amount = 500;
        $vipContent->keep_amount = 300;
        $vipContent->withdrawal_times_limit = 103;
        $vipContent->tax = 1;
        $vipContent->image = '';
        $vipContent->withdrawal_amount_limit = 5000;
        $vipContent->vip_channel = 0;
        $vipContent->is_downgrade = 1;
        $vipContent->admin = 1;
        $vipContent->save();

        $vipContent->withdrawalTypes()->create([
            'withdrawal_type_id' => WithdrawalTypeEnum::AUSTRALIA_BANK->value,
            'status' => 1,
            'admin' => 1,
        ]);

        return $vipContent;
    }

    protected function createMemberVip(VipContent $vipContent): MemberVip
    {
        $memberVip = new MemberVip;
        $memberVip->member_id = $this->member->id;
        $memberVip->vip_content_id = $vipContent->id;
        $memberVip->deposit_amount = 0;
        $memberVip->keep_amount = 0;
        $memberVip->admin = 1;
        $memberVip->save();

        return $memberVip;
    }
}
