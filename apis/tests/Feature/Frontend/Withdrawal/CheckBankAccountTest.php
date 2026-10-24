<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\General\HttpCode;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\WithdrawalOrder;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/CheckBankAccountTest.php
 */
class CheckBankAccountTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected Bank $normalBank;

    protected Bank $mobileBank;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(CashFlowSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];

        $this->setupMember();
        $this->createBanks();
        $this->createMemberBankAccount();
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT, 10);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE, 1);
    }

    public function setupMember()
    {
        $this->member->mobile_validate = 1;
        $this->member->mobile = '1234567890';
        $this->member->first_name = '';
        $this->member->save();
    }

    public function test_success()
    {
        $this->createWithdrawalOrder(3);
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/check-bank-account',
            data: [],
            headers: [
                'Accept-Language' => 'en',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'has_bank_card_setting' => 1,
                    'has_bank_card' => 1,
                    'bank_card_amount' => 1,
                    'country_code' => '',
                    'mobile' => '1234567890',
                    'account_name' => '',
                    'account' => [
                        [
                            'bank_id' => $this->normalBank->id,
                            'bank_name' => 'MayBank',
                            'bank_code' => 'MayBank',
                            'bank_type' => 1,
                            'bank_rate' => '1.000000000',
                            'min_amount' => '50',
                            'max_amount' => '1000',
                            'tax_type' => 0,
                            'bank_tax' => '0.00000',
                            'account_name' => '',
                            'account_no' => '1234567890',
                        ],
                    ],
                    'min_amount' => '50',
                    'max_amount' => '1000',
                    'is_first' => 1,
                    'account_name_modify' => 1,
                    'has_login_event_reward' => 0,
                    'withdrawal_times' => 3,
                    'withdrawal_times_limit' => 10,
                    'over_times' => 0,
                    'vip_tax' => '0',
                ],
            ]);
    }

    /**
     * 組別開了「提款需綁手機」且收銀組別有啟用中的菲律賓披索電子錢包（type=2、幣別 PHP）時，
     * 未綁手機的會員要被導去綁手機（324）。
     *
     * 幣別看 PHP(22) 而不是 GCASH(12)：GCASH 是付款方式不是幣別，決議㉛ 會把它從幣別主檔清掉；
     * 這段的原意是「菲律賓的電子錢包（GCash）提款要綁手機」，改看 PHP 才留得住這個語意。
     */
    public function test_redirects_to_bind_mobile_when_group_has_php_ewallet_bank(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE, 1);
        $this->mobileBank->update(['currency_id' => CurrencyEnum::PHP->value]);
        $this->member->update(['mobile_validate' => 0, 'mobile' => '']);

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/check-bank-account',
            data: [],
            headers: [
                'Accept-Language' => 'en',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(HttpCode::HTTP_REDIRECT_SET_MOBILE->value)
            ->assertJson([
                'code' => HttpCode::HTTP_REDIRECT_SET_MOBILE->value,
                'data' => ['member_id' => $this->member->id],
            ]);
    }

    /**
     * 同樣開了設定、會員未綁手機，但電子錢包銀行的幣別不是 PHP（例如印尼盾、馬幣的電子錢包），
     * 不觸發綁手機——維持改動前的行為（改動前這些幣別也比對不到 GCASH）。
     * Alfawin77 有 15 條啟用中的印尼盾電子錢包，這條測試守的就是那些站不會突然被要求綁手機。
     */
    public function test_does_not_redirect_when_ewallet_bank_is_not_php(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE, 1);
        $this->mobileBank->update(['currency_id' => CurrencyEnum::IDR->value]);
        $this->member->update(['mobile_validate' => 0, 'mobile' => '']);

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/check-bank-account',
            data: [],
            headers: [
                'Accept-Language' => 'en',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)->assertJson(['code' => 200]);
    }

    public function test_exceed_withdrawal_times_limit()
    {
        $this->createWithdrawalOrder(10);
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/check-bank-account',
            data: [],
            headers: [
                'Accept-Language' => 'en',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert 應該轉跳會員中心
        $response->assertStatus(321);
    }

    protected function createMemberBankAccount()
    {
        MemberBankAccount::create([
            'member_id' => $this->member->id,
            'bank_id' => $this->normalBank->id,
            'account_name' => $this->member->first_name,
            'account_no' => $this->member->mobile,
            'sort' => 0,
            'status' => 1,
            'operator_cs' => 0,
        ]);
    }

    protected function createWithdrawalOrder(int $count = 1)
    {
        WithdrawalOrder::factory()->count($count)->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
        ]);
    }

    protected function createBanks()
    {
        $this->normalBank = Bank::query()->create([
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

        $this->mobileBank = Bank::query()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::GCASH->value,
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
}
