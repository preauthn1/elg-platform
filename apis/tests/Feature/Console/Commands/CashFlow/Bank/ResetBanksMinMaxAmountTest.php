<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Agent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ResetBanksMinMaxAmount 測試（幣別判準修復）
 *
 * 守三個出口：ACF 幣別判準走 currency_id（名稱無「(CUR)」後綴也要納入計算）、
 * currency_id 為空的 ACF 排除並警告、完全沒有限額資料的銀行略過不寫 0.00。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/ResetBanksMinMaxAmountTest.php
 */
class ResetBanksMinMaxAmountTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP_ID = 99907;

    private const CURRENCY_ID = 99901;

    private const CURRENCY_CODE = 'TSD';

    private const CASH_FLOW_ID = 99902;

    private const ACF_ID = 990902;

    private const ACF_NULL_CURRENCY_ID = 990903;

    private const BANK_ID = 990005;

    private const BANK_CODE = 'TESTRST1';

    private const BANK_NO_AMOUNT_ID = 990006;

    private const BANK_NO_AMOUNT_CODE = 'TESTRST2';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUp();
        $this->seedFixtures();
    }

    private function cleanUp(): void
    {
        DB::table('banks')->whereIn('id', [self::BANK_ID, self::BANK_NO_AMOUNT_ID])->delete();
        DB::table('agent_cash_flows')->whereIn('id', [self::ACF_ID, self::ACF_NULL_CURRENCY_ID])->delete();
        DB::table('cash_flows')->where('id', self::CASH_FLOW_ID)->delete();
        DB::table('currencies')->where('id', self::CURRENCY_ID)->delete();
    }

    private function seedFixtures(): void
    {
        $this->agent = Agent::factory()->create([
            'cash_flow_group_id' => self::AGENT_GROUP_ID,
        ]);

        DB::table('currencies')->insert([
            'id' => self::CURRENCY_ID,
            'code' => self::CURRENCY_CODE,
            'name' => 'Test Dollar',
            'rate' => '1.0000',
            'admin' => 0,
        ]);

        DB::table('cash_flows')->insert([
            'id' => self::CASH_FLOW_ID,
            'name' => 'TestPay - Withdrawal',
            'type' => 3,
            'status' => 1,
            'admin' => 0,
        ]);

        DB::table('banks')->insert([
            'id' => self::BANK_ID,
            'code' => self::BANK_CODE,
            'name' => '(TSD) - Test Bank',
            'agent_group_id' => self::AGENT_GROUP_ID,
            'currency_id' => self::CURRENCY_ID,
            'type' => 4,
            'status' => 1,
            'min_amount' => '10.00',
            'max_amount' => '100.00',
        ]);
    }

    /**
     * 建一張代付 ACF；$currencyId 傳 null 模擬未回填的存量列。
     *
     * @param  list<array{BankCode_ELG: string, BankCode_Third: string}>  $availableBanks
     */
    private function seedAgentCashFlow(int $id, string $name, ?int $currencyId, array $availableBanks, ?string $minAmount, ?string $maxAmount): void
    {
        $info = ['available_banks' => $availableBanks];

        if ($minAmount !== null) {
            $info['MinAmount'] = $minAmount;
        }

        if ($maxAmount !== null) {
            $info['MaxAmount'] = $maxAmount;
        }

        DB::table('agent_cash_flows')->insert([
            'id' => $id,
            'agent_group_id' => self::AGENT_GROUP_ID,
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => $currencyId,
            'name' => $name,
            'status' => 1,
            'info' => json_encode($info, JSON_THROW_ON_ERROR),
            'admin' => 0,
        ]);
    }

    public function test_名稱無幣別後綴的_acf_依_currency_id_納入計算(): void
    {
        // 舊判準 name LIKE '%(TSD)%' 抓不到這張——這正是本次修復的缺陷
        $this->seedAgentCashFlow(
            id: self::ACF_ID,
            name: 'TestPay - Withdrawal',
            currencyId: self::CURRENCY_ID,
            availableBanks: [['BankCode_ELG' => self::BANK_CODE, 'BankCode_Third' => '542']],
            minAmount: '5.00',
            maxAmount: '500.00',
        );

        $this->artisan('cash-flow:reset-banks-min-max-amount', [
            '--agent-id' => $this->agent->id,
            '--currency-code' => self::CURRENCY_CODE,
        ])
            ->expectsConfirmation('確定要更新這些銀行的 min_amount 和 max_amount 嗎？', 'yes')
            ->assertSuccessful();

        $bank = DB::table('banks')->where('id', self::BANK_ID)->first();
        $this->assertSame('5.0000', $bank->min_amount);
        $this->assertSame('500.0000', $bank->max_amount);
    }

    public function test_currency_id_為空的_acf_被排除並警告(): void
    {
        $this->seedAgentCashFlow(
            id: self::ACF_NULL_CURRENCY_ID,
            name: 'AutoWithdrawal',
            currencyId: null,
            availableBanks: [['BankCode_ELG' => self::BANK_CODE, 'BankCode_Third' => '542']],
            minAmount: '1.00',
            maxAmount: '999.00',
        );

        $this->artisan('cash-flow:reset-banks-min-max-amount', [
            '--agent-id' => $this->agent->id,
            '--currency-code' => self::CURRENCY_CODE,
        ])
            ->expectsOutputToContain('因 currency_id 為空被排除')
            ->assertFailed();

        // 該 ACF 未納入計算 → 找不到符合條件的 ACF → 銀行限額不變
        $bank = DB::table('banks')->where('id', self::BANK_ID)->first();
        $this->assertSame('10.0000', $bank->min_amount);
        $this->assertSame('100.0000', $bank->max_amount);
    }

    public function test_無限額資料的銀行略過不寫_0(): void
    {
        DB::table('banks')->insert([
            'id' => self::BANK_NO_AMOUNT_ID,
            'code' => self::BANK_NO_AMOUNT_CODE,
            'name' => '(TSD) - No Amount Bank',
            'agent_group_id' => self::AGENT_GROUP_ID,
            'currency_id' => self::CURRENCY_ID,
            'type' => 4,
            'status' => 1,
            'min_amount' => '20.00',
            'max_amount' => '200.00',
        ]);

        // info 無 MinAmount/MaxAmount：舊版會把 0.00/0.00 蓋進 banks（限額壞檔根因）
        $this->seedAgentCashFlow(
            id: self::ACF_ID,
            name: 'TestPay - Withdrawal',
            currencyId: self::CURRENCY_ID,
            availableBanks: [['BankCode_ELG' => self::BANK_NO_AMOUNT_CODE, 'BankCode_Third' => '014']],
            minAmount: null,
            maxAmount: null,
        );

        $this->artisan('cash-flow:reset-banks-min-max-amount', [
            '--agent-id' => $this->agent->id,
            '--currency-code' => self::CURRENCY_CODE,
        ])
            ->expectsOutputToContain('無任何限額資料，略過不更新')
            ->assertSuccessful();

        $bank = DB::table('banks')->where('id', self::BANK_NO_AMOUNT_ID)->first();
        $this->assertSame('20.0000', $bank->min_amount);
        $this->assertSame('200.0000', $bank->max_amount);
    }
}
