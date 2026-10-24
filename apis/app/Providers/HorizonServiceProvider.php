<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserStatusEnum;
use App\Helpers\Common;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('user@example.invalid');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments
     *（官方授權為 gate 通過 || APP_ENV=local，本機保留開發後門）.
     *
     * 雙重防護：IP 白名單（#3967 D1）＋ Olympus Office 365 SSO 登入（#4081），兩者皆須通過。
     * - IP：來源必須在 config('horizon.allowed_ips')（HORIZON_ALLOWED_IPS，逗號分隔）內；
     *   空白名單＝拒絕所有來源。來源 IP 以 Common::resolveClientIp() 解析（#4187 信任鏈）：
     *   request()->ip() 只認 TRUSTED_PROXIES env，XFF 鏈上出現未列名的中繼（如 WAF 出口）
     *   時會停在中繼 IP，白名單永遠比不中而全員 403。
     * - SSO：admin guard 須有已登入且狀態正常的後台帳號（經 /olympus 登入）。
     * $user 可能為 null（Horizon 以預設 guard 解析使用者，本專案後台身分在 admin guard），
     * 保留預設值參數讓 guest 進入 closure，實際身分自行查 admin guard。
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $allowedIps = array_filter(array_map(
                trim(...),
                explode(',', (string) config('horizon.allowed_ips', ''))
            ));

            if (!in_array(Common::resolveClientIp(), $allowedIps, true)) {
                return false;
            }

            $admin = Auth::guard('admin')->user();

            return $admin instanceof User && $admin->status === UserStatusEnum::NORMAL->value;
        });
    }
}
