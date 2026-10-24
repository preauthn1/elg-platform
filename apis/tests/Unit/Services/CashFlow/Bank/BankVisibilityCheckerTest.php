<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankVisibilityChecker;
use Tests\TestCase;

class BankVisibilityCheckerTest extends TestCase
{
    private BankVisibilityChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new BankVisibilityChecker;
    }

    public function test_suggestion_for_step_1_recommends_audit_feed(): void
    {
        $reflect = new \ReflectionClass($this->checker);
        $method = $reflect->getMethod('suggestionForStep');
        $method->setAccessible(true);

        $result = $method->invoke($this->checker, 1);

        $this->assertSame('feed_missing', $result['type']);
        $this->assertStringContainsString('bank:audit-feed', $result['command']);
    }

    public function test_suggestion_for_step_2_recommends_add_agent_cash_flow(): void
    {
        $reflect = new \ReflectionClass($this->checker);
        $method = $reflect->getMethod('suggestionForStep');
        $method->setAccessible(true);

        $result = $method->invoke($this->checker, 2);

        $this->assertSame('agent_cash_flow_missing', $result['type']);
        $this->assertStringContainsString('agent:add-agent-cash-flow', $result['command']);
    }

    public function test_suggestion_for_step_4_recommends_add_agent_cash_flow(): void
    {
        $reflect = new \ReflectionClass($this->checker);
        $method = $reflect->getMethod('suggestionForStep');
        $method->setAccessible(true);

        $result = $method->invoke($this->checker, 4);

        $this->assertSame('available_banks_missing', $result['type']);
        $this->assertStringContainsString('agent:add-agent-cash-flow', $result['command']);
    }

    public function test_suggestion_for_step_6_recommends_add_agent_cash_flow(): void
    {
        $reflect = new \ReflectionClass($this->checker);
        $method = $reflect->getMethod('suggestionForStep');
        $method->setAccessible(true);

        $result = $method->invoke($this->checker, 6);

        $this->assertSame('banks_row_missing_for_group', $result['type']);
        $this->assertStringContainsString('agent:add-agent-cash-flow', $result['command']);
    }

    public function test_suggestion_for_unknown_step_returns_unknown(): void
    {
        $reflect = new \ReflectionClass($this->checker);
        $method = $reflect->getMethod('suggestionForStep');
        $method->setAccessible(true);

        $result = $method->invoke($this->checker, 99);

        $this->assertSame('unknown', $result['type']);
    }

    public function test_check_bank_for_nonexistent_bank_throws(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->checker->checkBankForAgent(99999999, 1);
    }
}
