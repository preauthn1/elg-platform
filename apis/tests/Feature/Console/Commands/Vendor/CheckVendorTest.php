<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/Vendor/CheckVendorTest.php --compact
 */
class CheckVendorTest extends TestCase
{
    public function test_it_does_not_report_success_when_fatal_validation_fails(): void
    {
        Config::set('database.default', 'sqlite');

        $this->artisan('vendor:check_vendor')
            ->expectsQuestion('請輸入遊戲商ID', '999999')
            ->expectsQuestion('請輸入代理商ID', '999999')
            ->assertFailed()
            ->doesntExpectOutputToContain('✓ 全部檢查通過')
            ->expectsOutputToContain('檢查中斷');
    }
}
