<?php

declare(strict_types=1);

namespace Tests\Feature\BotNotify;

use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * 驗證例外經 Laravel 真實的 bootstrap report callback 鏈路（非直接呼叫 AlertGate）確實會匯流到 AlertGate。
 * ApiException 屬業務邏輯例外，無論路由為何一律靜音；一般例外仍會觸發告警。
 * php artisan test --filter=AlertGateIntegrationTest
 */
class AlertGateIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'custom.site_name' => 'testsite']);
        Cache::flush();
    }

    private function bindRoute(string $uri): void
    {
        $route = new Route(['POST'], $uri, []);
        $request = Request::create('/' . $uri, 'POST');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
    }

    private function reportThrough(\Throwable $e): void
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    #[Test]
    public function api_exception_stays_silent_through_the_chain_regardless_of_route(): void
    {
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->never();

        $this->bindRoute('external/api/deposit/notify/155');
        $this->reportThrough(new ApiException('callback failed', 400));

        $this->bindRoute('frontend/api/member/profile');
        $this->reportThrough(new ApiException('boom', 400));
    }

    #[Test]
    public function generic_exception_reaches_alert_gate_and_sends(): void
    {
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once();

        $this->reportThrough(new RuntimeException('unexpected'));
    }
}
