<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Pages\Auth\Login;
use App\Models\AdminOperationLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * Olympus 後台 Office 365 SSO 登入流程測試。
 *
 * php artisan test tests/Feature/Olympus/AzureAuthTest.php
 */
class AzureAuthTest extends TestCase
{
    private const OID = '00000000-aaaa-bbbb-cccc-000000000001';

    private function createAdminUser(array $attributes = []): User
    {
        return User::create([
            'parent' => 0,
            'username' => 'sso_tester_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used-by-sso',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => 1,
            'admin' => 0,
            ...$attributes,
        ]);
    }

    private function mockSocialiteUser(?string $oid = self::OID, ?string $mail = 'user@example.invalid'): void
    {
        $socialiteUser = (new SocialiteUser)->setRaw([
            'id' => $oid,
            'displayName' => 'SSO Tester',
            'userPrincipalName' => 'user@example.invalid',
            'mail' => $mail,
        ])->map([
            'id' => $oid,
            'name' => 'SSO Tester',
            'email' => $mail,
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('azure')->andReturn($provider);
    }

    #[TestDox('首次 SSO 登入：以 email 綁定既有帳號、寫入 azure_id 並登入')]
    public function test_first_login_binds_existing_user_by_email(): void
    {
        $user = $this->createAdminUser();
        $this->mockSocialiteUser();

        $response = $this->get('/olympus/auth/callback?code=fake');

        $response->assertRedirect('/olympus');
        $this->assertSame(self::OID, $user->fresh()->azure_id);
        $this->assertAuthenticatedAs($user, 'admin');
        $this->assertNotNull($user->fresh()->last_login);

        $this->assertTrue(
            AdminOperationLog::where('operation', AdminOperationEnum::Login->value)
                ->where('user_id', $user->id)
                ->where('params->via', 'sso')
                ->exists(),
            'SSO 登入成功應寫入 admin_operation_logs'
        );
    }

    #[TestDox('已綁定 azure_id 的帳號：email 變動仍以 oid 比對成功登入')]
    public function test_bound_user_logs_in_by_azure_id_even_if_email_changed(): void
    {
        $user = $this->createAdminUser(['azure_id' => self::OID, 'email' => 'user@example.invalid']);
        $this->mockSocialiteUser(mail: 'user@example.invalid');

        $response = $this->get('/olympus/auth/callback?code=fake');

        $response->assertRedirect('/olympus');
        $this->assertAuthenticatedAs($user, 'admin');
    }

    #[TestDox('查無帳號：SSO 首登自動建立帳號並直接啟用登入，免管理員審核')]
    public function test_unknown_account_is_provisioned_and_logged_in(): void
    {
        $this->mockSocialiteUser(mail: 'user@example.invalid');

        $response = $this->get('/olympus/auth/callback?code=fake');

        $response->assertRedirect('/olympus');

        $created = User::where('azure_id', self::OID)->first();
        $this->assertNotNull($created);
        $this->assertSame(UserStatusEnum::NORMAL->value, $created->status);
        $this->assertSame('user@example.invalid', $created->email);
        $this->assertSame('stranger', $created->username);
        // SSO 首登預設運維：只看得到白名單菜單，升權由 superadmin 在帳號管理改
        $this->assertSame(UserRoleEnum::OPS, $created->role);
        $this->assertAuthenticatedAs($created, 'admin');

        $this->assertTrue(
            AdminOperationLog::where('operation', AdminOperationEnum::CreateUser->value)
                ->where('user_id', $created->id)
                ->where('params->via', 'sso')
                ->exists(),
            'SSO 首登自動建帳號應寫入 CreateUser 稽核紀錄'
        );
        $this->assertTrue(
            AdminOperationLog::where('operation', AdminOperationEnum::Login->value)
                ->where('user_id', $created->id)
                ->exists(),
            'SSO 首登也應寫入 Login 稽核紀錄'
        );
    }

    #[TestDox('自動建帳號時 username 撞名：加隨機後綴避開 unique 衝突')]
    public function test_provisioned_username_avoids_collision(): void
    {
        $this->createAdminUser(['username' => 'stranger', 'email' => 'user@example.invalid', 'azure_id' => 'some-other-oid']);
        $this->mockSocialiteUser(mail: 'user@example.invalid');

        $this->get('/olympus/auth/callback?code=fake');

        $created = User::where('azure_id', self::OID)->first();
        $this->assertNotNull($created);
        $this->assertStringStartsWith('stranger_', $created->username);
    }

    #[TestDox('帳號狀態非正常（LOCKED）：拒絕登入')]
    public function test_locked_user_is_rejected(): void
    {
        $user = $this->createAdminUser(['status' => UserStatusEnum::LOCKED->value]);
        $this->mockSocialiteUser();

        $response = $this->get('/olympus/auth/callback?code=fake');

        $response->assertRedirect(route('filament.olympus.auth.login'));
        $response->assertSessionHas('olympus_sso_error');
        $this->assertGuest('admin');
        $this->assertSame(self::OID, $user->fresh()->azure_id, '綁定先於狀態檢查：鎖定帳號可完成綁定但不得登入，解鎖後即可直接登入');
    }

    #[TestDox('Socialite token 交換失敗：回登入頁帶錯誤訊息')]
    public function test_socialite_failure_is_handled(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('token exchange failed'));
        Socialite::shouldReceive('driver')->with('azure')->andReturn($provider);

        $response = $this->get('/olympus/auth/callback?code=fake');

        $response->assertRedirect(route('filament.olympus.auth.login'));
        $response->assertSessionHas('olympus_sso_error');
        $this->assertGuest('admin');
    }

    #[TestDox('登入頁只有 Office 365 登入，沒有帳密表單與超級管理員登入區塊')]
    public function test_login_page_shows_sso_button_only(): void
    {
        $response = $this->get('/olympus/login');

        $response->assertOk();
        $response->assertSee('使用 Office 365 登入');
        $response->assertDontSee('超級管理員登入');
        $response->assertDontSee('Username');
    }

    #[TestDox('超級管理員直接呼叫 authenticate 用帳密登入被拒')]
    public function test_super_admin_cannot_login_with_password(): void
    {
        $user = $this->createAdminUser([
            'role' => UserRoleEnum::SUPERADMIN->value,
            'password' => Hash::make('secret-password'),
        ]);

        Livewire::test(Login::class)
            ->set('data', ['username' => $user->username, 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasErrors('data.username');

        $this->assertGuest('admin');
    }

    #[TestDox('一般管理員直接呼叫 authenticate 用帳密登入被拒')]
    public function test_regular_admin_cannot_login_with_password(): void
    {
        $user = $this->createAdminUser([
            'role' => UserRoleEnum::ADMIN->value,
            'password' => Hash::make('secret-password'),
        ]);

        Livewire::test(Login::class)
            ->set('data', ['username' => $user->username, 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasErrors('data.username');

        $this->assertGuest('admin');
    }
}
