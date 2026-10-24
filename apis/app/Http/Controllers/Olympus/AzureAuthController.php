<?php

declare(strict_types=1);

namespace App\Http\Controllers\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminOperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Olympus 後台 Office 365 SSO（Entra ID / OpenID Connect authorization code flow）。
 *
 * 帳號對應策略：以不可變的 Entra object ID（azure_id）優先比對，首次登入以 email
 * 比對既有 user 並寫入 azure_id 完成綁定；查無帳號時自動建立帳號並直接啟用
 *（通過公司 tenant 驗證即可用），角色沿用 users 欄位預設，狀態非正常仍拒絕登入。
 */
class AzureAuthController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('azure')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            /** @var \Laravel\Socialite\Two\User $azureUser */
            $azureUser = Socialite::driver('azure')->user();
        } catch (Throwable $e) {
            Log::warning('[Olympus][AzureSSO] token 交換失敗', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->reject(__('SSO 登入失敗，請重試。'));
        }

        // Graph /me 的 id 即 Entra 不可變 object ID（oid）；email 可能為空（mail 未設定時退回 UPN）
        $oid = $azureUser->getId();
        $raw = $azureUser->getRaw();
        $email = $raw['mail'] ?? $raw['userPrincipalName'] ?? null;

        $user = User::where('azure_id', $oid)->first();

        if (!$user && filled($email)) {
            $user = User::whereNull('azure_id')->where('email', $email)->first();
            $user?->update(['azure_id' => $oid]);
        }

        if (!$user) {
            if (blank($email)) {
                Log::warning('[Olympus][AzureSSO] Azure 未回傳 email，無法建立帳號', ['oid' => $oid]);

                return $this->reject(__('SSO 登入失敗，請重試。'));
            }

            $user = $this->provisionUser($oid, $email, (string) $azureUser->getName());
        }

        if ($user->status !== UserStatusEnum::NORMAL->value) {
            Log::warning('[Olympus][AzureSSO] 帳號狀態非正常，拒絕登入', [
                'user_id' => $user->id,
                'status' => $user->status,
            ]);

            return $this->reject(__('此帳號已被停用，請聯繫管理員。'));
        }

        Auth::guard('admin')->login($user, remember: true);
        request()->session()->regenerate(); // 防 session fixation

        $user->update(['last_login' => now()]);

        app(AdminOperationService::class)->audit(
            operation: AdminOperationEnum::Login,
            userId: $user->id,
            params: ['via' => 'sso'],
            output: "SSO 登入 {$user->username} (ID: {$user->id})",
        );

        return redirect()->intended('/olympus');
    }

    /**
     * 首次 SSO 登入自動建立帳號並直接啟用——能通過公司 tenant 驗證即視為可用後台。
     * 密碼填入不可用的隨機值——此帳號僅供 SSO 登入（非超級管理員不開放密碼登入）。
     */
    private function provisionUser(string $oid, string $email, string $displayName): User
    {
        $base = Str::of($email)->before('@')->lower()->limit(24, '')->toString();
        $username = $base;
        while (User::where('username', $username)->exists()) {
            $username = $base . '_' . Str::lower(Str::random(4));
        }

        $user = User::create([
            'parent' => 0,
            'azure_id' => $oid,
            'username' => $username,
            'nickname' => $displayName ?: null,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'status' => UserStatusEnum::NORMAL->value,
            // 首登預設運維：只看得到白名單菜單；要升 RD／superadmin 由 superadmin 在帳號管理改
            'role' => UserRoleEnum::OPS->value,
            'admin' => 0,
        ]);

        Log::info('[Olympus][AzureSSO] SSO 首登已自動建立帳號', ['user_id' => $user->id, 'username' => $username]);

        app(AdminOperationService::class)->audit(
            operation: AdminOperationEnum::CreateUser,
            userId: $user->id,
            params: ['username' => $username, 'nickname' => $displayName, 'via' => 'sso', 'role' => UserRoleEnum::OPS->value],
            output: "SSO 首登自動建立後台帳號 {$username} (ID: {$user->id})",
        );

        return $user;
    }

    /**
     * 拒絕登入：flash 錯誤訊息並回登入頁（強制 SSO 時登入頁僅顯示訊息與 SSO 按鈕）
     */
    private function reject(string $message): RedirectResponse
    {
        return redirect()
            ->route('filament.olympus.auth.login')
            ->with('olympus_sso_error', $message);
    }
}
