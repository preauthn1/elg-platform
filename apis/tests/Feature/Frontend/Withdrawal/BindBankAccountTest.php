<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberBankAccount;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class BindBankAccountTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Bank $bank;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(CashFlowSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];

        $this->bank = Bank::query()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => WithdrawalTypeEnum::BANK->value,
            'name' => 'TestBank',
            'code' => 'TestBank',
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

        $this->createAgentCashFlow($this->agent);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING, 0);
    }

    protected function postBindBankAccount(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/bind-bank-account',
            data: $data,
            headers: [
                'Accept-Language' => 'en',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );
    }

    #[TestDox('新增銀行帳號成功')]
    public function test_create_bank_account_success(): void
    {
        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_name' => 'John Doe',
                    'account_no' => '1234567890',
                ],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $this->assertDatabaseHas('member_bank_accounts', [
            'member_id' => $this->member->id,
            'bank_id' => $this->bank->id,
            'account_no' => '1234567890',
        ]);
    }

    #[TestDox('account_no 含空格時驗證失敗')]
    public function test_account_no_with_space_fails_validation(): void
    {
        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_no' => '123 456',
                ],
            ],
        ]);

        $response->assertStatus(400);
    }

    #[TestDox('account_no 含 tab 時驗證失敗')]
    public function test_account_no_with_tab_fails_validation(): void
    {
        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_no' => "123\t456",
                ],
            ],
        ]);

        $response->assertStatus(400);
    }

    #[TestDox('更新既有銀行帳號成功')]
    public function test_update_existing_bank_account_success(): void
    {
        MemberBankAccount::create([
            'member_id' => $this->member->id,
            'bank_id' => $this->bank->id,
            'account_name' => 'Old Name',
            'account_no' => '0000000000',
            'sort' => 0,
            'status' => 1,
            'operator_cs' => 0,
        ]);

        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_name' => 'New Name',
                    'account_no' => '9999999999',
                ],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $this->assertDatabaseHas('member_bank_accounts', [
            'member_id' => $this->member->id,
            'bank_id' => $this->bank->id,
            'account_no' => '9999999999',
        ]);
    }

    #[TestDox('HAS_BANK_CARD_SETTING=1 時更新已綁定帳號拋出錯誤')]
    public function test_cannot_update_existing_account_when_bank_card_setting_locked(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING, 1);

        MemberBankAccount::create([
            'member_id' => $this->member->id,
            'bank_id' => $this->bank->id,
            'account_name' => 'Locked Name',
            'account_no' => '1111111111',
            'sort' => 0,
            'status' => 1,
            'operator_cs' => 0,
        ]);

        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_name' => 'Changed Name',
                    'account_no' => '2222222222',
                ],
            ],
        ]);

        $response->assertStatus(400);
    }

    #[TestDox('account_name 為空時自動帶入 first_name')]
    public function test_account_name_falls_back_to_first_name(): void
    {
        $this->member->first_name = 'AutoName';
        $this->member->save();

        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_no' => '5555555555',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('member_bank_accounts', [
            'member_id' => $this->member->id,
            'account_name' => 'AutoName',
        ]);
    }

    #[TestDox('account_name 與 first_name 皆空時自動帶入 username')]
    public function test_account_name_falls_back_to_username_when_first_name_empty(): void
    {
        $this->member->first_name = '';
        $this->member->save();

        $response = $this->postBindBankAccount([
            'account' => [
                [
                    'bank_id' => $this->bank->id,
                    'account_no' => '6666666666',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('member_bank_accounts', [
            'member_id' => $this->member->id,
            'account_name' => $this->member->username,
        ]);
    }
}
