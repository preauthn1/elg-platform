<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\CurrencyEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberBankAccount;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Database\Seeders\WithdrawalTypeSeeder;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 綁定提款銀行帳號時，也要檢查這家銀行是不是「這個會員看得到」的那一家。
 *
 * 白話說：畫面上會員只會看到自己代理底下、還開著的銀行。如果他自己改請求、
 * 送一個看不到的銀行編號進來，系統以前照樣幫他存起來。存起來的只是一筆綁定
 * 紀錄、不會馬上少錢，但那是「先綁、再拿它去提款」的第一步，所以一起補上。
 *
 * 兩支測試各對應一個條件，拔掉哪一條就會有一支變紅：
 *   - 別的代理群組的銀行 → test_bank_from_another_agent_group_is_rejected
 *   - 已經停用的銀行     → test_disabled_bank_is_rejected
 * 第三支是反向護欄，確認正常綁定沒有被一起擋掉。
 *
 * php artisan test tests/Feature/Frontend/Withdrawal/BindBankAccountOwnershipTest.php
 */
class BindBankAccountOwnershipTest extends TestCase
{
    private const string URI = 'frontend/api/withdrawal/bind-bank-account';

    protected Agent $agent;

    protected Member $member;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(WithdrawalTypeSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
    }

    #[Group('rules/withdrawal-member-fee#R-11')]
    public function test_bank_from_another_agent_group_is_rejected(): void
    {
        $foreignBank = $this->createBank([
            'agent_group_id' => (int) $this->agent->cash_flow_group_id + 1,
        ]);

        $this->bind($foreignBank)->assertJson(['code' => 400]);

        $this->assertNothingBound('不屬於本代理財務群組的銀行不可綁定');
    }

    #[Group('rules/withdrawal-member-fee#R-11')]
    public function test_disabled_bank_is_rejected(): void
    {
        $disabledBank = $this->createBank([
            'agent_group_id' => (int) $this->agent->cash_flow_group_id,
            'status' => 0,
        ]);

        $this->bind($disabledBank)->assertJson(['code' => 400]);

        $this->assertNothingBound('已停用的銀行不可綁定');
    }

    #[Group('rules/withdrawal-member-fee#R-11')]
    public function test_bank_from_own_agent_group_is_accepted(): void
    {
        $ownBank = $this->createBank([
            'agent_group_id' => (int) $this->agent->cash_flow_group_id,
        ]);

        $this->bind($ownBank)->assertJson(['code' => 200]);

        $this->assertDatabaseHas('member_bank_accounts', [
            'member_id' => $this->member->id,
            'bank_id' => $ownBank->id,
            'account_no' => '1234567890',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBank(array $overrides = []): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => 0,
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
            ...$overrides,
        ]);
    }

    private function bind(Bank $bank): TestResponse
    {
        return $this->json(
            method: 'POST',
            uri: self::URI,
            data: [
                'account' => [
                    [
                        'bank_id' => $bank->id,
                        'account_name' => 'John Doe',
                        'account_no' => '1234567890',
                    ],
                ],
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        );
    }

    private function assertNothingBound(string $message): void
    {
        $this->assertSame(
            0,
            MemberBankAccount::query()->where('member_id', $this->member->id)->count(),
            $message,
        );
    }
}
