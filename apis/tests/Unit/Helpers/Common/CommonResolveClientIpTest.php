<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Common::resolveClientIp 測試（target/tickets#4187）
 *
 * php artisan test tests/Unit/Helpers/Common/CommonResolveClientIpTest.php
 */
class CommonResolveClientIpTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        // file cache driver 會在測試間持久化，每次都要清掉，避免吃到上一筆
        // fixture 快取住的結果。
        Cache::forget('trusted_proxy_base_cidrs');

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'trusted-proxies-test-') . '.json';
        file_put_contents($this->fixturePath, json_encode([
            'cidrs' => [
                'google_lb_ipv4' => ['0.0.0.0/16'],
                'cloudflare_ipv4' => ['0.0.0.0/13'],
                'private_reserved' => ['0.0.0.0/8'],
            ],
        ]));

        Config::set('trustedproxy.data_path', $this->fixturePath);
        Config::set('trustedproxy.extra_cidrs', []);
        Config::set('trustedproxy.skip_last_hop', false);
    }

    protected function tearDown(): void
    {
        Cache::forget('trusted_proxy_base_cidrs');

        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    private function bindRequest(string $remoteAddr, ?string $xff = null): void
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];

        if ($xff !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $xff;
        }

        $this->app->instance('request', Request::create('/', 'GET', [], [], [], $server));
    }

    public function test_untrusted_remote_addr_ignores_xff(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_trusted_google_lb_resolves_real_client(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_trusted_cloudflare_skips_trusted_hop(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_private_remote_addr_is_treated_as_trusted(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_no_xff_returns_remote_addr(): void
    {
        $this->bindRequest('0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_skip_last_hop_drops_unconditional_last_hop(): void
    {
        Config::set('trustedproxy.skip_last_hop', true);
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_extra_cidrs_extend_trust(): void
    {
        Config::set('trustedproxy.extra_cidrs', ['0.0.0.0/24']);
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_entire_chain_trusted_falls_back_to_leftmost(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }

    public function test_missing_data_file_returns_remote_addr(): void
    {
        Config::set('trustedproxy.data_path', $this->fixturePath . '.does-not-exist');

        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame('0.0.0.0', Common::resolveClientIp());
    }
}
