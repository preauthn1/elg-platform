<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Facades\BotNotify;
use App\Http\Middleware\RateLimitNotify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 回歸測試：sendAlertIfNeeded() 呼叫 Redis SET NX EX 不可拋出 TypeError。
 *
 * php artisan test tests/Feature/Middleware/RateLimitNotifySendAlertTest.php
 */
class RateLimitNotifySendAlertTest extends TestCase
{
    public function test_send_alert_if_needed_sets_redis_key_with_ttl(): void
    {
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send');

        $alertKey = 'test:rate_limit_alert:' . uniqid();
        Redis::connection('cache')->del($alertKey);

        $middleware = new RateLimitNotify;
        $method = new ReflectionMethod($middleware, 'sendAlertIfNeeded');
        $method->setAccessible(true);
        $method->invoke($middleware, Request::create('/foo'), '0.0.0.0', 'foo', 60, $alertKey);

        $this->assertEquals(1, Redis::connection('cache')->get($alertKey));
        $this->assertGreaterThan(0, Redis::connection('cache')->ttl($alertKey));

        Redis::connection('cache')->del($alertKey);
    }
}
