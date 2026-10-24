<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Main;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * 代理登入路由 `POST agent/api/login` 已停用（target/tickets#4548）。
 *
 * 這條路徑的二次驗證可被繞過，且新舊後台與客服都沒有呼叫端，故直接移除路由註冊；
 * 本測試守住「請求連路由都碰不到」，以及同檔的 health 路由仍在。
 *
 * 執行：php artisan test tests/Feature/Agent/Main/LoginRouteDisabledTest.php --compact
 */
class LoginRouteDisabledTest extends TestCase
{
    public function test_agent_api_login_route_is_gone(): void
    {
        $this->postJson('agent/api/login', [
            'action' => 'login',
            'username' => 'x',
            'password' => 'y',
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 404);
    }

    public function test_agent_api_login_route_is_not_registered(): void
    {
        // HTTP 404 也可能來自一條直接回 404 的 login 路由；直接查路由集合，才能證明這條路徑根本沒有註冊
        $request = Request::create('/agent/api/login', 'POST');

        $this->expectException(NotFoundHttpException::class);
        app('router')->getRoutes()->match($request);
    }

    public function test_agent_api_health_route_still_works(): void
    {
        $this->getJson('agent/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_login_route_issues_no_token_and_counts_no_failure(): void
    {
        $agent = $this->createAgentAndDomain();
        // factory 建立後的模型不含 DB 預設值，先 refresh 才讀得到實際的 login_fail_count
        $failCountBefore = $agent->refresh()->login_fail_count;

        $this->postJson('agent/api/login', [
            'action' => 'whatever',
            'username' => $agent->username,
            'password' => '123456',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('agent_access_tokens', ['agent_id' => $agent->id]);
        $this->assertSame($failCountBefore, $agent->refresh()->login_fail_count);
    }
}
