<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\CurrencyEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use Database\Seeders\CashFlowSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Database\Seeders\WithdrawalTypeSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 提款建單的銀行歸屬檢查。
 *
 * 銀行清單端只回本代理財務群組、且啟用中的銀行；建單端必須套用同一組條件，
 * 否則會員可以直接送出別的群組的 banks.id，系統就會用那一列的 rate 計算扣點、
 * 用它的 min_amount／max_amount 判限額。banks.rate 在同一個幣別內就有數倍差距
 * （VWorld 生產實查：MYR 0.23～1.0），扣點與提款金額因此可以脫鉤。
 *
 * 這四支測試各自對應建單端的一個條件，拔掉任何一個都會有測試轉紅：
 *   - 別的群組的銀行 → test_bank_from_another_agent_group_is_rejected
 *   - 停用中的銀行   → test_disabled_bank_is_rejected
 *   - 不存在的銀行   → test_unknown_bank_is_rejected（今天會拋型別錯誤而非可讀錯誤）
 *   - 自己群組啟用中 → test_bank_from_own_agent_group_is_accepted（確認沒有擋過頭）
 *
 * php artisan test tests/Feature/Frontend/Withdrawal/CreateOrderBankOwnershipTest.php
 */
class CreateOrderBankOwnershipTest extends TestCase
{
    private const string URI = 'frontend/api/withdrawal/create-order';

    /** 別的群組那列銀行的匯率；與自己群組的 1.0 拉開，扣點差異才看得出來 */
    private const string FOREIGN_RATE = '0.23';

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
        $this->seed(CashFlowSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->createAgentCashFlow($this->agent);

        $this->member->balance = 100;
        $this->member->save();
    }

    #[Group('rules/withdrawal-member-fee#R-10')]
    public function test_bank_from_another_agent_group_is_rejected(): void
    {
        $foreignBank = $this->createBank([
            'agent_group_id' => (int) $this->agent->cash_flow_group_id + 1,
            'rate' => self::FOREIGN_RATE,
        ]);

        $this->createOrderWith($foreignBank)->assertJson(['code' => 400]);

        $this->assertNoOrderCreated('不屬於本代理財務群組的銀行不可建單');
        $this->assertBalanceUntouched();
    }

    #[Group('rules/withdrawal-member-fee#R-10')]
    public function test_disabled_bank_is_rejected(): void
    {
        // 清單端同時看 agent_group_id 與 status；只擋群組不擋狀態，等於留下已下架的銀行可用
        $disabledBank = $this->createBank([
            'agent_group_id' => (int) $this->agent->cash_flow_group_id,
            'status' => 0,
            'rate' => self::FOREIGN_RATE,
        ]);

        $this->createOrderWith($disabledBank)->assertJson(['code' => 400]);

        $this->assertNoOrderCreated('停用中的銀行不可建單');
        $this->assertBalanceUntouched();
    }

    #[Group('rules/withdrawal-member-fee#R-12')]
    public function test_unknown_bank_is_rejected(): void
    {
        // 今天 Bank::find() 回 null，接著讀 $bank->rate 會拋錯而不是回可讀訊息
        $existing = $this->createBank(['agent_group_id' => (int) $this->agent->cash_flow_group_id]);

        $response = $this->json(
            method: 'POST',
            uri: self::URI,
            data: [
                'amount' => 50,
                'account_name' => 'tim',
                'account_no' => '123546789',
                'bank_id' => $existing->id + 9999,
                'bank_name' => $existing->name,
                'bank_code' => $existing->code,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        );

        $response->assertJson(['code' => 400]);

        $this->assertNoOrderCreated('不存在的銀行編號不可建單');
        $this->assertBalanceUntouched();
    }

    #[Group('rules/withdrawal-member-fee#R-10')]
    public function test_bank_from_own_agent_group_is_accepted(): void
    {
        // 反向護欄：確認上面三道沒有把正常提款一起擋掉
        $ownBank = $this->createBank(['agent_group_id' => (int) $this->agent->cash_flow_group_id]);

        $this->createOrderWith($ownBank)->assertJson(['code' => 200]);

        $order = WithdrawalOrder::query()->where('member_id', $this->member->id)->first();
        $this->assertNotNull($order, '自己群組啟用中的銀行必須建得了單');
        $this->assertEquals($ownBank->id, $order->bank_id);
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

    private function createOrderWith(Bank $bank): \Illuminate\Testing\TestResponse
    {
        return $this->json(
            method: 'POST',
            uri: self::URI,
            data: [
                'amount' => 50,
                'account_name' => 'tim',
                'account_no' => '123546789',
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
                'bank_code' => $bank->code,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        );
    }

    private function assertNoOrderCreated(string $message): void
    {
        $this->assertSame(
            0,
            WithdrawalOrder::query()->where('member_id', $this->member->id)->count(),
            $message,
        );
        $this->assertSame(
            0,
            TransactionLog::query()->where('member_id', $this->member->id)->count(),
            $message . '（也不可留下交易紀錄）',
        );
    }

    private function assertBalanceUntouched(): void
    {
        // 扣點與建單在同一個交易內；擋下之後餘額必須原封不動
        $this->assertEquals(100, $this->member->fresh()->balance, '被擋下的提款不可扣點');
    }
}
