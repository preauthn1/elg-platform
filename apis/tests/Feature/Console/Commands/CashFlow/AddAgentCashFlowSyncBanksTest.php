<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use App\Models\Bank;
use Database\Seeders\CurrencySeeder;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowSyncBanksTest.php
 *
 * L1 後 syncBanksToAgentGroup 委派 AgentBankSyncService（code 四元組去重鍵、code 穩定不覆寫）。
 * 驗證融合 !1769 防護：同碼存在 inactive shadow 時 active 優先（不 activate shadow 撞 uk_banks_active）、
 * 同碼不同名 active 對齊 base canonical、code 為去重鍵不被覆寫。
 */
class AddAgentCashFlowSyncBanksTest extends TestCase
{
    private const int CURRENCY_SGD = 2;

    private const int AGENT_GROUP = 688;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    public function test_updates_active_row_instead_of_inactive_shadow_with_same_name(): void
    {
        // 重現 TPower AGRO 實案：agent 層同名同碼兩列，先建的 shadow status=0、後建的 active status=1
        $this->createBank(name: 'Zephyr Agro Bank', code: 'ZQAG23', status: 1);
        $shadow = $this->createBank(name: 'Zephyr Agro Bank', code: 'ZQAG23', agentGroupId: self::AGENT_GROUP, status: 0, minAmount: 11);
        $active = $this->createBank(name: 'Zephyr Agro Bank', code: 'ZQAG23', agentGroupId: self::AGENT_GROUP, status: 1, minAmount: 22);

        $this->syncBanksToAgentGroup(['ZQAG23']);

        // shadow 不得被 activate（生產環境會撞 uk_banks_active），同步應落在 active 列
        $this->assertSame(0, $shadow->refresh()->status, 'shadow 列不得被 activate');
        $this->assertSame(1, $active->refresh()->status);
        $this->assertSame(22, (int) $active->min_amount, 'active 列既有限額應被沿用');
        $this->assertSame(11, (int) $shadow->min_amount, 'shadow 列不應被更新');
    }

    public function test_reactivates_sole_inactive_row_when_no_active_holder(): void
    {
        // 回歸鎖定：同名僅一列 inactive、無 active 持碼者 → 沿 base status 重新啟用（原行為）
        $this->createBank(name: 'Zephyr Solo Bank', code: 'ZQSO24', status: 1);
        $sole = $this->createBank(name: 'Zephyr Solo Bank', code: 'ZQSO24', agentGroupId: self::AGENT_GROUP, status: 0);

        $this->syncBanksToAgentGroup(['ZQSO24']);

        $this->assertSame(1, $sole->refresh()->status, '無撞鍵疑慮時應照常重新啟用');
        $this->assertSame(1, Bank::query()->where('agent_group_id', self::AGENT_GROUP)->where('name', 'Zephyr Solo Bank')->count(), '不應另建新列');
    }

    public function test_same_code_different_name_updates_holder_to_canonical(): void
    {
        // L1 code 去重鍵：agent 有同碼但名字不同的 active 列 → update 對齊 base canonical（name→base），
        // 不新建、不撞 uk_banks_active（取代舊 name-key「不同名同碼跳過建列」的防撞行為）
        $this->createBank(name: 'Zephyr Alpha Bank', code: 'ZQAL25', status: 1);
        $holder = $this->createBank(name: 'Zephyr Beta Bank', code: 'ZQAL25', agentGroupId: self::AGENT_GROUP, status: 1);

        $this->syncBanksToAgentGroup(['ZQAL25']);

        $this->assertSame('Zephyr Alpha Bank', $holder->refresh()->name, '同碼 holder 應對齊 base canonical name');
        $this->assertSame('ZQAL25', $holder->refresh()->code, 'code 為去重鍵不變');
        $this->assertSame(1, Bank::query()->where('agent_group_id', self::AGENT_GROUP)->where('currency_id', self::CURRENCY_SGD)->where('code', 'ZQAL25')->where('status', 1)->count(), '仍單一 active、不新建');
    }

    public function test_skips_code_update_when_target_code_held_by_other_active_row(): void
    {
        // 既有列要對齊的新碼被同範疇另一 active 列持有 → 跳過更新，防撞鍵/靜默重複
        $this->createBank(name: 'Zephyr Gamma Bank', code: 'ZQGA26', status: 1);
        $bank = $this->createBank(name: 'Zephyr Gamma Bank', code: 'ZQGA99', agentGroupId: self::AGENT_GROUP, status: 1);
        $holder = $this->createBank(name: 'Zephyr Delta Bank', code: 'ZQGA26', agentGroupId: self::AGENT_GROUP, status: 1);

        $this->syncBanksToAgentGroup(['ZQGA26', 'ZQGA99']);

        $this->assertSame('ZQGA99', $bank->refresh()->code, '目標碼被他列持有時不應對齊 code');
        $this->assertSame('ZQGA26', $holder->refresh()->code);
    }

    private function syncBanksToAgentGroup(array $bankCodes): void
    {
        $command = $this->app->make(AddAgentCashFlow::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $agentBanksMap = Bank::query()
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_SGD)
            ->whereIn('code', $bankCodes)
            ->get()
            ->keyBy('code');

        $stats = ['banks_created' => 0, 'banks_updated' => 0];

        $method = new ReflectionMethod(AddAgentCashFlow::class, 'syncBanksToAgentGroup');
        $method->invokeArgs($command, [
            $bankCodes,
            self::AGENT_GROUP,
            self::CURRENCY_SGD,
            50,
            30000,
            $agentBanksMap,
            &$stats,
        ]);
    }

    private function createBank(
        string $name,
        string $code,
        int $agentGroupId = 0,
        int $status = 1,
        int $minAmount = 0,
    ): Bank {
        return Bank::query()->create([
            'agent_group_id' => $agentGroupId,
            'type' => 4,
            'name' => $name,
            'code' => $code,
            'third_party_code' => null,
            'currency_id' => self::CURRENCY_SGD,
            'info' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => $minAmount,
            'max_amount' => 0,
            'sort' => 0,
            'image_url' => null,
            'status' => $status,
            'remark' => null,
            'admin' => 0,
        ]);
    }
}
