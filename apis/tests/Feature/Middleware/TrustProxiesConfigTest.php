<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trusted proxies 走 config('trustedproxy.proxies')（TRUSTED_PROXIES env）的行為（#3967 B2）。
 *
 * 設定不經 bootstrap/app.php 的 $middleware->trustProxies()（該時點 .env 尚未載入），
 * 而由內建 TrustProxies middleware 於 request 時 fallback 讀取 config，
 * 此處驗證該 fallback 路徑實際生效。IP 使用 TEST-NET 保留段。
 */
class TrustProxiesConfigTest extends TestCase
{
    #[Test]
    public function 信任的_proxy_可由_x_forwarded_for_還原真實_client_ip(): void
    {
        config(['trustedproxy.proxies' => '0.0.0.0']);

        $request = $this->handleThroughTrustProxies(
            remoteAddr: '0.0.0.0',
            xForwardedFor: '0.0.0.0'
        );

        $this->assertSame('0.0.0.0', $request->ip());
    }

    #[Test]
    public function 未信任的來源不採用_x_forwarded_for(): void
    {
        config(['trustedproxy.proxies' => '0.0.0.0']);

        $request = $this->handleThroughTrustProxies(
            remoteAddr: '0.0.0.0',
            xForwardedFor: '0.0.0.0'
        );

        $this->assertSame('0.0.0.0', $request->ip());
    }

    #[Test]
    public function 未設定時不信任任何_proxy_維持既有行為(): void
    {
        config(['trustedproxy.proxies' => null]);

        $request = $this->handleThroughTrustProxies(
            remoteAddr: '0.0.0.0',
            xForwardedFor: '0.0.0.0'
        );

        $this->assertSame('0.0.0.0', $request->ip());
    }

    /**
     * 讓 request 走過內建 TrustProxies middleware（全域 stack 預設啟用）。
     */
    private function handleThroughTrustProxies(string $remoteAddr, string $xForwardedFor): Request
    {
        $request = Request::create(uri: '/up', server: [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $xForwardedFor,
        ]);

        (new TrustProxies)->handle($request, fn () => response('ok'));

        return $request;
    }
}
