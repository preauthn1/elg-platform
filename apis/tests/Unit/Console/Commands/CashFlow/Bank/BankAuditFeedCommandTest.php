<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow\Bank;

use App\Helpers\StandardBankNameHelper;
use Database\Seeders\SyncCurrenciesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Console/Commands/CashFlow/BankAuditFeedCommandTest.php
 */
class BankAuditFeedCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncCurrenciesSeeder::class);
        StandardBankNameHelper::clearCache();
    }

    protected function tearDown(): void
    {
        StandardBankNameHelper::clearCache();
        parent::tearDown();
    }

    public function test_audit_feed_runs_for_single_currency(): void
    {
        $this->artisan('bank:audit-feed', ['--currency' => 'MYR'])
            ->assertSuccessful()
            ->expectsOutputToContain('Currency: MYR');
    }

    public function test_audit_feed_runs_for_all_currencies_when_no_filter(): void
    {
        $this->artisan('bank:audit-feed')
            ->assertSuccessful()
            ->expectsOutputToContain('Summary:');
    }

    public function test_audit_feed_unknown_currency_returns_failure(): void
    {
        $this->artisan('bank:audit-feed', ['--currency' => 'XXX'])
            ->assertFailed();
    }

    public function test_audit_feed_json_outputs_valid_json(): void
    {
        $this->artisan('bank:audit-feed', ['--currency' => 'MYR', '--json' => true])
            ->assertSuccessful();
    }
}
