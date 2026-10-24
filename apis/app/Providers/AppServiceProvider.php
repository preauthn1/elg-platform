<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Services;
use App\Services\LivechatBot\LivechatBotContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Services\BotNotifyService::class);
        $this->app->singleton(Services\Encryption\AesService::class);
        $this->app->singleton(Services\Encryption\DesService::class);
        $this->app->singleton(Services\FileStorageService::class);
        $this->app->singleton(Common::class);

        // LivechatBotContext 採可變 singleton：middleware 在驗證通過後 setBot()，
        // service 透過 constructor 注入時取到同一份 instance。詳見 class docblock。
        $this->app->singleton(LivechatBotContext::class);

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->loadMigrationsFrom(base_path('vendor/laravel/telescope/database/migrations'));
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 參數有帶 dd，則開啟 API 除錯器
        // 若環境為 development 或 production，需要再檢查 ip 是否為內部 ip
        if (request()->has('dd')) {
            if (App::isLocal()) {
                Config::set('api-debugger.enabled', true);
            } else {
                $ip = Common::getIp();
                $isInternalIp = Str::startsWith($ip, '192.168.') || $ip === config('custom.office.ip');
                if ($isInternalIp) {
                    Config::set('api-debugger.enabled', true);
                }
            }
        }

        Http::globalOptions([
            'timeout' => 15,
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ]);

        $this->configureGuestPasswordResetRateLimiters();

        // 多型關聯以 alias 入庫（member_restriction_rules.target_type 等），避免 DB 綁死 FQCN
        //
        // 🔴 order_request_logs.orderable_type 只收這兩個金流 alias，禁止加入遊戲商相關 model：
        // 遊戲商注單回調的量比金流高好幾個數量級，要記往返一律另開表（見該表 migration 的註解）。
        //
        // 代付掛 WithdrawalPayoutRecord 而不是 WithdrawalOrder：一張提款單可能有多次代付請求
        // （重試、換渠道），一次往返對應的是其中一次請求，不是整張單；代付的冪等主體也是 record
        // 本身而非訂單（見 WithdrawalCallbackTrait::isPayoutRecordProcessed()）。
        Relation::morphMap([
            'member' => \App\Models\Member::class,
            'member_group' => \App\Models\MemberGroup::class,
            'deposit_order' => \App\Models\Order::class,
            'payout_record' => \App\Models\WithdrawalPayoutRecord::class,
        ]);

        // 生產環境禁止執行 migrate:fresh、db:wipe 等破壞性指令
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // 非生產環境啟用 Model 嚴格模式，提早暴露問題
        // 生產環境改為記錄 Log，不拋出例外，避免中斷服務但仍可追蹤問題
        Model::preventLazyLoading();
        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading violation', [
                    'model' => $model::class,
                    'relation' => $relation,
                    'trace' => $this->formatBacktrace(),
                ]);
            });
        }

        // fill() 填入非 $fillable 欄位時拋出例外
        Model::preventSilentlyDiscardingAttributes();
        if ($this->app->isProduction()) {
            Model::handleDiscardedAttributeViolationUsing(function (Model $model, array $keys): void {
                Log::warning('Discarding attributes violation', [
                    'model' => $model::class,
                    'keys' => $keys,
                    'trace' => $this->formatBacktrace(),
                ]);
            });
        }

        // Olympus 後台 Office 365 SSO：註冊 Socialite 的 azure driver
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        // 將請求的 request_id 設為 job 執行期間的 Log context（tid），方便以批號跨 process 追蹤日誌。
        // Context（含 hidden）由框架自動隨 job payload 傳遞、於 job 執行前還原；
        // worker 取下一個 job 前也會自動清除殘留的 shared context，毋須手動重設。
        Queue::before(function (): void {
            Log::shareContext([
                'tid' => Context::getHidden(ContextKeyEnum::REQUEST_ID->value) ?? '-',
            ]);
        });
    }

    /**
     * 忘記密碼兩支端點的頻率限制，各自一個具名限流器，額度互不干擾：
     * - `throttle:guest-reset-password`（重設密碼）：username 一把 key＋IP 一把 key。
     * - `throttle:guest-member-accounts`（取得帳號列表、檢核驗證碼，兩支共用額度）：IP 一把 key，請求有帶手機、信箱就各再加一把。
     *   檢核驗證碼也驗得了忘記密碼的碼，不一起限流的話可以在那裡無限猜碼、猜中再去重設密碼。
     *   刻意不看 username：CheckCodeRequest 不驗它、validated() 會丟掉，讀它等於讓人附不同 username
     *   換新額度。手機與信箱要分開計數：查驗證碼是「手機或信箱」任一相符，只看其中一個的話，
     *   固定受害者信箱、每次換一個手機就能換到新額度。
     *
     * IP key 經 Common::ipRateLimitKey()：IPv6 截到 /64，免得換一個位址就換到新額度。
     * 帳號、手機、信箱經 Common::identityRateLimitKey() 正規化（去重音、全形轉半形、轉小寫），
     * 免得換一種與 utf8mb4_unicode_ci 定序等價的寫法就換到新額度；這只是近似，IP key 仍是主要防線。
     *
     * 工單 4540：每把 key 獨立計數，任一把超過就擋；預設每把 key 3 分鐘窗口內最多 10 次，超過擋到窗口結束。
     * 次數與窗口讀 config/custom.php 的 guest_password_reset_throttle，次數設 0 等於關閉（誤擋時改 env 止血）。
     * 代理端 API_Agent 沿用既有 RateLimitRequests，是「1 分鐘 10 次、封 3 分鐘」；
     * 兩邊效果都是超過後 3 分鐘內擋，窗口定義不同是因為各自沿用既有工具。
     * 這是頻率限制、不鎖帳：鎖帳會讓知道帳號的人把別人鎖住（取捨見 docs/adr/0005）。
     *
     * DecryptRequest 與 DetectLanguage 必須先跑（key 要讀明文欄位、429 訊息要跟著請求語系），
     * 順序由 bootstrap/app.php 的 middleware priority 保證。
     */
    private function configureGuestPasswordResetRateLimiters(): void
    {
        RateLimiter::for('guest-reset-password', function (Request $request): array|Limit {
            if ($this->guestPasswordResetMaxAttempts() <= 0) {
                return Limit::none();
            }

            return [
                $this->guestPasswordResetLimit('guest-reset-password', 'user', Common::identityRateLimitKey($request->input('username'))),
                $this->guestPasswordResetLimit('guest-reset-password', 'ip', Common::ipRateLimitKey(Common::getIp())),
            ];
        });

        RateLimiter::for('guest-member-accounts', function (Request $request): array|Limit {
            if ($this->guestPasswordResetMaxAttempts() <= 0) {
                return Limit::none();
            }

            $limits = [
                $this->guestPasswordResetLimit('guest-member-accounts', 'ip', Common::ipRateLimitKey(Common::getIp())),
            ];

            foreach (['mobile', 'email'] as $field) {
                $identity = Common::identityRateLimitKey($request->input($field));

                if ($identity !== '') {
                    $limits[] = $this->guestPasswordResetLimit('guest-member-accounts', $field, $identity);
                }
            }

            return $limits;
        });
    }

    /**
     * 忘記密碼限流的單把 key，key 為「限流器:種類:值」。
     *
     * 被擋時記一筆 warning 供觀察誤擋與攻擊，只記限流器、key 的種類與來源 IP，不記帳號與聯絡方式的值。
     * 回應不走 ApiResponse::error()：它把 code 直接當 HTTP 狀態碼，2037 不是合法狀態碼。
     *
     * @param  'user'|'ip'|'mobile'|'email'  $keyType
     */
    private function guestPasswordResetLimit(string $limiter, string $keyType, string $value): Limit
    {
        $maxAttempts = $this->guestPasswordResetMaxAttempts();
        $decayMinutes = max(1, (int) config('custom.guest_password_reset_throttle.decay_minutes'));

        return Limit::perMinutes($decayMinutes, $maxAttempts)
            ->by("{$limiter}:{$keyType}:{$value}")
            ->response(function (Request $request, array $headers) use ($limiter, $keyType, $maxAttempts): JsonResponse {
                Log::warning('guest password reset throttled', [
                    'limiter' => $limiter,
                    'key_type' => $keyType,
                    'ip' => Common::getIp(),
                ]);

                return response()->json([
                    'code' => 2037,
                    'message' => __('error_message.2037', ['values' => $maxAttempts]),
                    'tid' => Context::getHidden(ContextKeyEnum::REQUEST_ID->value),
                ], 429, $headers);
            });
    }

    private function guestPasswordResetMaxAttempts(): int
    {
        return (int) config('custom.guest_password_reset_throttle.max_attempts');
    }

    /**
     * 擷取精簡呼叫堆疊，供嚴格模式違規記錄追蹤來源。
     */
    private function formatBacktrace(): string
    {
        // skip(1) 略過本方法自身的 frame，維持與呼叫端就地擷取相同的可視深度
        return collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9))
            ->skip(1)
            ->map(fn ($frame) => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . ' (' . ($frame['file'] ?? '') . ':' . ($frame['line'] ?? '') . ')')
            ->implode("\n");
    }
}
