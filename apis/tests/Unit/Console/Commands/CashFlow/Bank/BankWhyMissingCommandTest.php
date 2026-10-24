<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow\Bank;

use Tests\TestCase;

class BankWhyMissingCommandTest extends TestCase
{
    public function test_missing_agent_returns_failure(): void
    {
        $this->artisan('bank:why-missing')
            ->assertFailed()
            ->expectsOutputToContain('--agent 為必填');
    }

    public function test_missing_mode_argument_returns_failure(): void
    {
        $this->artisan('bank:why-missing', ['--agent' => '4249'])
            ->assertFailed()
            ->expectsOutputToContain('--bank / --currency / --cashflow');
    }

    // 完整模式測試（mode A/B/C with real fixtures）overhead 太大，先 skip。
    // BankVisibilityCheckerTest 已覆蓋 service 層的核心邏輯。
}
