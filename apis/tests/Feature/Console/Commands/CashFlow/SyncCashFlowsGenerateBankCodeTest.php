<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Enums\WithdrawalTypeEnum;
use App\Models\Bank;
use Database\Seeders\CurrencySeeder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsGenerateBankCodeTest.php
 *
 * 驗證 generateBankCode 產生的新代碼不與任何既有列（不分 status / agent_group_id）重複。
 * 背景：原實作以「count+1」產生序號，只數筆數、不檢查該號是否已被佔用；
 * TPower SGD 平台銀行 code 為 TPSGxx / SGDxxxx 混用，count 與已用序號脫鉤，
 * cash-flow:sync 為 ANEXT Bank 產生 SGD0023 時撞上 Wise 既有列的 uk_banks_active。
 * 停用列（status=0）舊碼亦不得重用，避免歷史訂單 bank_code 語意漂移。
 */
class SyncCashFlowsGenerateBankCodeTest extends TestCase
{
    private const int CURRENCY_SGD = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    public function test_generated_code_skips_code_occupied_by_active_row(): void
    {
        // 模擬 TPower 現況：混用非序號碼（TPSGxx），使 count 與已用序號脫鉤
        $this->createBank(name: 'Zephyr Legacy Bank', code: 'TPSG01');
        $occupied = $this->createBank(name: 'Zephyr Wise Bank', code: 'TPSG99');

        // 佔住「count+1 將產出的碼」，重現 SGD0023 撞鍵
        $occupied->update(['code' => $this->nextCountBasedCode()]);

        $code = $this->generateBankCode('Zephyr Anext Bank');

        $this->assertCodeIsFresh($code);
    }

    public function test_generated_code_does_not_reuse_disabled_row_code(): void
    {
        // 停用列保留舊碼作審計痕跡；新銀行重用該碼會使歷史訂單 bank_code 語意漂移
        $disabled = $this->createBank(name: 'Zephyr Retired Bank', code: 'TPSG98', status: 0);

        $disabled->update(['code' => $this->nextCountBasedCode()]);

        $code = $this->generateBankCode('Zephyr Anext Bank');

        $this->assertNotSame($disabled->refresh()->code, $code);
        $this->assertCodeIsFresh($code);
    }

    public function test_generated_code_avoids_agent_level_row_code(): void
    {
        // agent 層列與平台層共用 code 語意；count 只數 agent_group_id=0，
        // 原實作可能產出僅存在於 agent 層的碼，形成同碼異行
        $agentBank = $this->createBank(name: 'Zephyr Agent Bank', code: 'TPSG97', agentGroupId: 674);

        $agentBank->update(['code' => $this->nextCountBasedCode()]);

        $code = $this->generateBankCode('Zephyr Anext Bank');

        $this->assertNotSame($agentBank->refresh()->code, $code);
        $this->assertCodeIsFresh($code);
    }

    public function test_same_name_active_bank_still_reuses_its_code(): void
    {
        // 既有行為回歸鎖定：同名（正規化後）active 銀行複用其 code，不產新碼
        $this->createBank(name: 'Zephyr Wise Bank', code: 'TPSG96');

        $code = $this->generateBankCode('Zephyr Wise Bank');

        $this->assertSame('TPSG96', $code);
    }

    /** 產生的新碼必須格式正確，且未被同幣別任何列（不分 status / agent_group）佔用 */
    private function assertCodeIsFresh(string $code): void
    {
        $this->assertMatchesRegularExpression('/^SGD\d{4}$/', $code);

        $this->assertSame(
            0,
            Bank::query()
                ->where('currency_id', self::CURRENCY_SGD)
                ->where('code', $code)
                ->count(),
            sprintf('新碼 %s 已被既有列佔用（code 重用）', $code),
        );
    }

    /** 原 count+1 邏輯將產出的碼（用於預先佔住以重現撞鍵） */
    private function nextCountBasedCode(): string
    {
        $count = Bank::query()
            ->where('agent_group_id', 0)
            ->where('currency_id', self::CURRENCY_SGD)
            ->count();

        return 'SGD' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    private function generateBankCode(string $name): string
    {
        $method = new ReflectionMethod(SyncCashFlowsCommand::class, 'generateBankCode');

        return $method->invoke(
            $this->app->make(SyncCashFlowsCommand::class),
            $name,
            self::CURRENCY_SGD,
            'SGD',
            WithdrawalTypeEnum::BANK,
        );
    }

    private function createBank(string $name, string $code, int $status = 1, int $agentGroupId = 0): Bank
    {
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
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'image_url' => null,
            'status' => $status,
            'remark' => null,
            'admin' => 0,
        ]);
    }
}
