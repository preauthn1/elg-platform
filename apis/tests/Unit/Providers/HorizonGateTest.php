<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * viewHorizon gate 雙重防護：IP 白名單（#3967 D1）＋ Olympus SSO 登入（#4081）。
 *
 * gate 由 HorizonServiceProvider 定義：來源 IP 必須在
 * config('horizon.allowed_ips')（逗號分隔）內（空白名單＝拒絕所有），
 * 且 admin guard 須有已登入、狀態正常的後台帳號。
 * IP 使用 TEST-NET（0.0.0.0/24、0.0.0.0/24）保留段，避免真實 IP 入碼。
 */
class HorizonGateTest extends TestCase
{
    #[Test]
    public function 白名單命中且已登入正常帳號時允許存取(): void
    {
        config(['horizon.allowed_ips' => '0.0.0.0, 0.0.0.0']);
        $this->bindRequestWithIp('0.0.0.0');
        $this->loginAdmin();

        $this->assertTrue(Gate::allows('viewHorizon'));
    }

    #[Test]
    public function 白名單未命中時即使已登入仍拒絕存取(): void
    {
        config(['horizon.allowed_ips' => '0.0.0.0, 0.0.0.0']);
        $this->bindRequestWithIp('0.0.0.0');
        $this->loginAdmin();

        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    #[Test]
    public function 空白名單拒絕所有來源(): void
    {
        config(['horizon.allowed_ips' => '']);
        $this->bindRequestWithIp('0.0.0.0');
        $this->loginAdmin();

        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    #[Test]
    public function 白名單命中但未登入時拒絕存取(): void
    {
        config(['horizon.allowed_ips' => '0.0.0.0']);
        $this->bindRequestWithIp('0.0.0.0');

        // 比照 Horizon 授權流程：Gate::check('viewHorizon', [$request->user()])，user 為 null
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }

    #[Test]
    public function 白名單命中但帳號狀態非正常時拒絕存取(): void
    {
        config(['horizon.allowed_ips' => '0.0.0.0']);
        $this->bindRequestWithIp('0.0.0.0');
        $this->loginAdmin(UserStatusEnum::LOCKED->value);

        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    /**
     * 以指定 REMOTE_ADDR 綁定 request 實例，使 gate 內 request()->ip() 取得該 IP。
     */
    private function bindRequestWithIp(string $ip): void
    {
        $request = Request::create(uri: '/horizon', server: ['REMOTE_ADDR' => $ip]);

        $this->app->instance('request', $request);
    }

    private function loginAdmin(int $status = 1): void
    {
        $user = User::create([
            'parent' => 0,
            'username' => 'horizon_tester_' . uniqid(),
            'email' => uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => $status,
            'role' => 1,
            'admin' => 0,
        ]);

        Auth::guard('admin')->login($user);
    }
}
