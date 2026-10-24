<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BotNotify;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Exceptions\SmsGatewayException;
use App\Facades\BotNotify;
use App\Services\BotNotify\AlertGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class AlertGateTest extends TestCase
{
    private AlertGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'custom.site_name' => 'testsite',
        ]);
        Cache::flush();
        $this->gate = app(AlertGate::class);
    }

    private function bindRoute(string $uri): void
    {
        $route = new Route(['POST'], $uri, []);
        $request = Request::create('/' . $uri, 'POST');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
    }

    private function setVendor(int $id, string $name): void
    {
        Context::addHidden(ContextKeyEnum::VENDOR_INFO->value, (object) ['id' => $id, 'name' => $name]);
    }

    /** 從不同程式位置拋出，使 traceInfo['at'] 不同。 */
    private function throwAlpha(): RuntimeException
    {
        try {
            throw new RuntimeException('alpha source');
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    /** 模擬三方回傳失敗的建構位置。 */
    private function smsGatewayFailure(string $reason): SmsGatewayException
    {
        return new SmsGatewayException(
            message: $reason,
            memberId: 11,
            mobile: '6285119112610',
            smsGatewayGroupId: 8,
            gatewayInfoId: 3,
            gatewayCode: 'cx',
            response: '{"code":"F0015","desc":"余额不足"}',
        );
    }

    /** 模擬查無簡訊樣板的建構位置（與 {@see smsGatewayFailure()} 不同 file:line）。 */
    private function smsTemplateMissing(): SmsGatewayException
    {
        return new SmsGatewayException(
            message: '查無簡訊樣板',
            memberId: 11,
            mobile: '6285119112610',
            smsGatewayGroupId: 8,
        );
    }

    private function throwBeta(): RuntimeException
    {
        try {
            throw new RuntimeException('beta source');
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    #[Test]
    public function api_exception_is_always_silenced_regardless_of_route_or_code_and_clears_context(): void
    {
        // ApiException 屬業務邏輯例外，一律不通知：無論是否命中舊業務路由白名單（轉帳／代收代付回調）
        // 或例外 code（含 423 LOCKED），都不該送出告警。需要告警的業務例外請拋專屬 exception class。
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->never();

        $this->bindRoute('frontend/api/member/other');
        $this->setVendor(39, 'Mega888');
        $this->gate->handle(new ApiException('boom', 400));
        $this->assertNull(Context::getHidden(ContextKeyEnum::VENDOR_INFO->value));

        $this->bindRoute('frontend/api/member/transfer');
        $this->setVendor(39, 'Mega888');
        $this->gate->handle(new ApiException('boom', 400));
        $this->assertNull(Context::getHidden(ContextKeyEnum::VENDOR_INFO->value));

        $this->bindRoute('frontend/api/member/transfer');
        $this->setVendor(39, 'Mega888');
        $this->gate->handle(new ApiException('locked', 423));
        $this->assertNull(Context::getHidden(ContextKeyEnum::VENDOR_INFO->value));
    }

    #[Test]
    public function connection_exception_is_silenced(): void
    {
        // 三方連線／讀取超時屬外部網路瞬時故障，不發 TG；
        // log 由 framework 預設 report logging 與 bootstrap/app.php 的 render callback 負責。
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->never();

        $this->setVendor(39, 'Mega888');
        $this->gate->handle(new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertNull(Context::getHidden(ContextKeyEnum::VENDOR_INFO->value));
    }

    #[Test]
    public function generic_exception_with_vendor_sends_with_vendor_name_and_context(): void
    {
        $this->setVendor(39, 'Mega888');
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once()->with(
            Mockery::on(fn (string $title): bool => str_contains($title, '【Mega888】')),
            Mockery::on(fn (array $ctx): bool => $ctx['vendor_info_id'] === 39
                && $ctx['tid'] === 'tid-123'),
        );
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'tid-123');

        $this->gate->handle(new RuntimeException('timeout'));
    }

    #[Test]
    public function repeated_generic_exception_within_window_sends_only_once(): void
    {
        $this->setVendor(39, 'Mega888');
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once();

        $e = new RuntimeException('timeout');
        $this->gate->handle($e);
        $this->gate->handle($e);
        $this->gate->handle($e);
    }

    #[Test]
    public function missing_vendor_still_throttles_and_omits_attribution_prefix(): void
    {
        // 沒有 vendor 也沒有 agentCashFlow 時，標題不帶任何歸屬前綴（不 fallback 成「未知遊戲商」）。
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once()->with(
            Mockery::on(fn (string $title): bool => !str_contains($title, '【')),
            Mockery::type('array'),
        );

        $e = $this->throwAlpha();
        $this->gate->handle($e); // first hit sends
        $this->gate->handle($e); // same source → suppressed
    }

    #[Test]
    public function missing_vendor_different_sources_do_not_mask_each_other(): void
    {
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->twice();

        $this->gate->handle($this->throwAlpha()); // source A
        $this->gate->handle($this->throwBeta());  // source B → distinct at → also sends
    }

    #[Test]
    public function sms_gateway_exception_carries_reason_and_diagnostic_context(): void
    {
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, 'tid-sms');
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->once()->with(
            'sms發送失敗',
            Mockery::on(fn (array $ctx): bool => $ctx['msg'] === 'code:cx API 回傳失敗'
                && $ctx['member'] === 11
                && $ctx['mobile'] === '6285119112610'
                && $ctx['sms_group'] === 8
                && $ctx['gateway_code'] === 'cx'
                && $ctx['SP'] === "call ADMIN_check_sms_gateway('6285119112610', 8, 0)"
                && $ctx['response'] === '{"code":"F0015","desc":"余额不足"}'
                && $ctx['tid'] === 'tid-sms'),
        );

        $this->gate->handle($this->smsGatewayFailure('code:cx API 回傳失敗'));
    }

    #[Test]
    public function sms_gateway_failures_from_different_sites_do_not_mask_each_other(): void
    {
        // 降噪 key 是例外建構位置，故各失敗原因需在各自呼叫點 new 才能各自分窗（不會被前一個原因遮蔽 30 分鐘）。
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->twice();

        $this->gate->handle($this->smsGatewayFailure('code:cx API 回傳失敗'));
        $this->gate->handle($this->smsGatewayFailure('code:cx API 回傳失敗'));  // 同一位置 → 抑制
        $this->gate->handle($this->smsTemplateMissing());                       // 另一位置 → 照發
    }

    #[Test]
    public function clear_context_runs_even_when_classify_throws(): void
    {
        $gate = new class extends AlertGate
        {
            protected function classify(Throwable $e): ?array
            {
                throw new RuntimeException('classify boom');
            }
        };
        Log::spy();
        $this->setVendor(39, 'Mega888');

        $gate->handle(new ApiException('x', 400));

        $this->assertNull(Context::getHidden(ContextKeyEnum::VENDOR_INFO->value));
    }

    #[Test]
    public function cache_failure_propagates_and_alert_is_not_sent(): void
    {
        $this->setVendor(39, 'Mega888');
        Log::spy();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));
        BotNotify::shouldReceive('send')->never();

        $this->gate->handle(new RuntimeException('timeout'));

        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function send_failure_is_logged_and_next_hit_within_window_is_still_suppressed(): void
    {
        $this->setVendor(39, 'Mega888');
        Log::spy();
        BotNotify::shouldReceive('channel')->andReturnSelf();
        // lock 不因發送失敗被重置，視窗內第二筆仍被抑制（send 只嘗試一次）。
        BotNotify::shouldReceive('send')->once()->andThrow(new RuntimeException('tg down'));

        $e = new RuntimeException('timeout');
        $this->gate->handle($e);
        $this->gate->handle($e);

        Log::shouldHaveReceived('error')->once();
    }
}
