<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Models\Bank;
use Database\Seeders\CurrencySeeder;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsSyncBankCodeTest.php
 *
 * 驗證 syncBankCode 的代碼對齊不會製造同範疇（agent_group_id + type + currency_id）
 * active 列的 code 重複。
 * 背景：TPower IDR 平台層存在兩條 active 同名列（(IDR) - Citibank：id 1231 IDR0013 /
 * id 1549 IDR0027，同行不同碼重複），syncBankCode 將 1231 對齊為 IDR0027 時
 * 撞 uk_banks_active；未上 guard 的站則會靜默把兩列改成同碼、製造新重複。
 * 此類重複屬 dedup / 人工合併職責，sync 應跳過對齊並警示。
 */
class SyncCashFlowsSyncBankCodeTest extends TestCase
{
    private const int CURRENCY_SGD = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    #[Group('rules/payout-bank-code-mapping#R-10')]
    public function test_alignment_skipped_when_target_code_held_by_active_same_scope_row(): void
    {
        // 重現 TPower Citibank 實案：平台層兩條 active 同名列、不同碼
        $holder = $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP01');
        $bank = $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP02');

        $bankLog = $this->syncBankCode($bank, 'Zephyr Citi Bank');

        // 對齊須跳過：否則兩列同碼（有 guard 站 1062、無 guard 站靜默重複）
        $this->assertSame('ZQTP02', $bank->refresh()->code);
        $this->assertSame('ZQTP01', $holder->refresh()->code);
        $this->assertNotEmpty($bankLog, '應留下跳過對齊的警示訊息');
    }

    #[Group('rules/payout-bank-code-mapping#R-8')]
    public function test_alignment_still_applies_within_same_agent_group(): void
    {
        // 既有行為回歸鎖定：同組別更早的同名列（不同 type，碼在本 type 範疇無人佔用）→ 正常對齊
        $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP03', type: 1);
        $bank = $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP04');

        $this->syncBankCode($bank, 'Zephyr Citi Bank');

        $this->assertSame('ZQTP03', $bank->refresh()->code);
    }

    #[Group('rules/payout-bank-code-mapping#R-9')]
    public function test_alignment_ignores_same_name_row_in_other_agent_group(): void
    {
        // VWorld 實案：base 層 UOB（SGD0001）被 group 116 的同名列（UOBSG）拖成別組的碼，
        // 隨後 AgentBankSyncService 在每個組別長出 UOBSG 新列、會員綁的 SGD0001 對不上代付清單
        $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP03', agentGroupId: 674);
        $bank = $this->createBank(name: 'Zephyr Citi Bank', code: 'ZQTP04');

        $bankLog = $this->syncBankCode($bank, 'Zephyr Citi Bank');

        $this->assertSame('ZQTP04', $bank->refresh()->code, '別組別的同名列不得改寫 base 層代碼');
        $this->assertEmpty($bankLog);
    }

    #[Group('rules/payout-bank-code-mapping#R-8')]
    public function test_alignment_skipped_for_fuzzy_only_name(): void
    {
        // L1（ⓒ²）收斂：僅完全同名才對齊；模糊同名（去 BANK/MALAYSIA 後同）不對齊，
        // 避免把 canonical code 反向拖回模糊同名舊碼（candidate 置不同 ag 避 targetCodeHeld）
        $this->createBank(name: 'Zephyr Islam Bank Malaysia', code: 'ZQFZ01', agentGroupId: 674);
        $bank = $this->createBank(name: 'Zephyr Islam Bank', code: 'ZQFZ02');

        $this->syncBankCode($bank, 'Zephyr Islam Bank');

        $this->assertSame('ZQFZ02', $bank->refresh()->code, '模糊同名不應對齊，canonical code 保持');
    }

    /** @return array 執行後的 bankLog 內容 */
    private function syncBankCode(Bank $bank, string $name): array
    {
        $bankLog = [];

        $method = new ReflectionMethod(SyncCashFlowsCommand::class, 'syncBankCode');
        $method->invokeArgs(
            $this->app->make(SyncCashFlowsCommand::class),
            [$bank, $name, self::CURRENCY_SGD, &$bankLog],
        );

        return $bankLog;
    }

    private function createBank(string $name, string $code, int $agentGroupId = 0, int $type = 4): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $agentGroupId,
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'third_party_code' => null,
            'currency_id' => self::CURRENCY_SGD,
            'info' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'image_url' => null,
            'status' => 1,
            'remark' => null,
            'admin' => 0,
        ]);
    }
}
