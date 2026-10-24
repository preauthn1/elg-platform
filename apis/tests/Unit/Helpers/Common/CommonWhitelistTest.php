<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Common::verifyIPWhitelist 測試
 *
 * php artisan test tests/Unit/Helpers/Common/CommonWhitelistTest.php
 */
class CommonWhitelistTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('app.env', 'testing');
        parent::tearDown();
    }

    public function test_verify_ip_whitelist_non_production_always_true(): void
    {
        Config::set('app.env', 'local');
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';
        $this->assertTrue(Common::verifyIPWhitelist('0.0.0.0'));
    }

    public function test_verify_ip_whitelist_production_empty_whitelist_true(): void
    {
        Config::set('app.env', 'production');
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';
        $this->assertTrue(Common::verifyIPWhitelist(''));
    }

    public function test_verify_ip_whitelist_production_matching_ip(): void
    {
        Config::set('app.env', 'production');
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';
        $server = $_SERVER;
        $server['REMOTE_ADDR'] = '0.0.0.0';
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], $server));

        $this->assertTrue(Common::verifyIPWhitelist('0.0.0.0'));
        $this->assertFalse(Common::verifyIPWhitelist('0.0.0.0'));
    }

    public function test_verify_ip_whitelist_production_matching_ipv6(): void
    {
        Config::set('app.env', 'production');
        $_SERVER['REMOTE_ADDR'] = '2001:db8::cafe';
        $server = $_SERVER;
        $server['REMOTE_ADDR'] = '2001:db8::cafe';
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], $server));

        $this->assertTrue(Common::verifyIPWhitelist('2001:db8::cafe'));
        $this->assertFalse(Common::verifyIPWhitelist('2001:db8::babe'));
    }
}
