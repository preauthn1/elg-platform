<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Common::getIp 測試（target/tickets#4187 Stage 2）
 *
 * getIp() 改為委派 resolveClientIp()，原本的 header 信任邏輯搬進
 * legacyGetIp()（保留、目前無呼叫端，供之後需要退回舊行為時使用）。
 *
 * php artisan test tests/Unit/Helpers/Common/CommonGetIpTest.php
 */
class CommonGetIpTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('trusted_proxy_base_cidrs');

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'trusted-proxies-test-') . '.json';
        file_put_contents($this->fixturePath, json_encode([
            'cidrs' => [
                'google_lb_ipv4' => ['0.0.0.0/16'],
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

        unset(
            $_SERVER['HTTP_ALI_CDN_REAL_IP'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_CLIENT_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
        );

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

    private function callLegacyGetIp(): string
    {
        $method = new ReflectionMethod(Common::class, 'legacyGetIp');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    public function test_get_ip_delegates_to_resolve_client_ip(): void
    {
        $this->bindRequest('0.0.0.0', '0.0.0.0, 0.0.0.0');

        $this->assertSame(Common::resolveClientIp(), Common::getIp());
        $this->assertSame('0.0.0.0', Common::getIp());
    }

    public function test_get_ip_ignores_spoofable_headers_unlike_legacy(): void
    {
        $this->bindRequest('0.0.0.0');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '0.0.0.0';

        // getIp() 現在完全不信任這個可偽造的 header，REMOTE_ADDR 不可信時直接回傳它自己。
        $this->assertSame('0.0.0.0', Common::getIp());
    }

    public function test_legacy_get_ip_still_trusts_ali_cdn_header(): void
    {
        $_SERVER['HTTP_ALI_CDN_REAL_IP'] = '0.0.0.0';

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }

    public function test_legacy_get_ip_still_trusts_cf_connecting_ip_header(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '0.0.0.0';

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }

    public function test_legacy_get_ip_still_trusts_client_ip_header(): void
    {
        $_SERVER['HTTP_CLIENT_IP'] = '0.0.0.0';

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }

    public function test_legacy_get_ip_still_trusts_xff_header(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '0.0.0.0, 0.0.0.0';

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }

    public function test_legacy_get_ip_falls_back_to_v_http_client_ip_header(): void
    {
        $this->bindRequest('0.0.0.0');
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '0.0.0.0',
            'HTTP_V_HTTP_CLIENT_IP' => '0.0.0.0',
        ]));

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }

    public function test_legacy_get_ip_falls_back_to_remote_addr(): void
    {
        $this->bindRequest('0.0.0.0');

        $this->assertSame('0.0.0.0', $this->callLegacyGetIp());
    }
}
