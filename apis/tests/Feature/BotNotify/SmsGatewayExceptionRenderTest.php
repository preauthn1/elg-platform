<?php

declare(strict_types=1);

namespace Tests\Feature\BotNotify;

use App\Exceptions\SmsGatewayException;
use App\Facades\BotNotify;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SmsGatewayException 走 bootstrap/app.php 真實 render()／report() 鏈路：
 * 玩家端只拿到泛化訊息，失敗細節走 AlertGate 降噪後才發 TG。
 * php artisan test --filter=SmsGatewayExceptionRenderTest
 */
class SmsGatewayExceptionRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'custom.site_name' => 'testsite']);
        Cache::flush();
    }

    private function exception(): SmsGatewayException
    {
        return new SmsGatewayException(
            message: 'code:cx API 回傳失敗',
            memberId: 11,
            mobile: '6285119112610',
            smsGatewayGroupId: 8,
            gatewayInfoId: 3,
            gatewayCode: 'cx',
            response: '{"code":"F0015","desc":"余额不足"}',
        );
    }

    #[Test]
    public function render_returns_generic_message_without_leaking_gateway_detail(): void
    {
        $response = $this->app[ExceptionHandler::class]
            ->render(Request::create('/frontend/api/member/validate-token', 'POST'), $this->exception());

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        $this->assertSame(400, $payload['code']);
        $this->assertSame(__('error_message.2003'), $payload['message']);
        $this->assertArrayHasKey('tid', $payload);
        $this->assertStringNotContainsString('F0015', $response->getContent());
        $this->assertStringNotContainsString('6285119112610', $response->getContent());
    }

    #[Test]
    public function report_chain_alerts_once_per_window(): void
    {
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once();

        // 同一建構位置（本測試的 exception()）在冷卻視窗內只發一次，不再洗版。
        $this->app[ExceptionHandler::class]->report($this->exception());
        $this->app[ExceptionHandler::class]->report($this->exception());
        $this->app[ExceptionHandler::class]->report($this->exception());
    }
}
