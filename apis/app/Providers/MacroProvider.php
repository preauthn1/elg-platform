<?php

declare(strict_types=1);

namespace App\Providers;

use App\Logging\HttpLoggerCustom;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class MacroProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        HttpLoggerCustom::registerMacro();

        /**
         * @method void timeColumns()
         */
        Blueprint::macro('timeColumns', function (): void {
            /** @var Blueprint $this */
            $this->timestamp('created_at')->useCurrent()->comment('建立日期');
            $this->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });

        /**
         * @method void vendorAccountColumns(int $usernameLength = 32, bool $uniqueUsername = false, bool $withSessionToken = [REDACTED]
         */
        Blueprint::macro('vendorAccountColumns', function (
            int $usernameLength = 32,
            bool $uniqueUsername = false,
            bool $withSessionToken = [REDACTED],
        ): void {
            /** @var Blueprint $this */
            $this->increments('id');
            $this->integer('vendor_info_id')->default(0)->index()->comment('遊戲商戶編號');

            $username = $this->string('username', $usernameLength)->comment('玩家帳號');
            $uniqueUsername ? $username->unique() : $username->index();

            $this->integer('member_id')->default(0)->index()->comment('會員系統編號');

            if ($withSessionToken) {
                $this->string('session_token', 200)->nullable();
            }

            $this->integer('admin')->default(0)->comment('管理員編號');
            $this->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');

            $this->timeColumns();

            $tname = str_replace('_accounts', '', $this->getTable());
            $this->index(['vendor_info_id', 'member_id', 'status'], "idx_{$tname}_bind_lookup");
            $this->index(['vendor_info_id', 'status', 'member_id', 'id'], "idx_{$tname}_pool_pick");

            if ($withSessionToken) {
                $this->index('session_token', "idx_{$tname}_session_token");
            }
        });

        /**
         * @method void vendorBetRecordColumns(bool $uniqueBetId = true, bool $withReferenceNo = true)
         */
        Blueprint::macro('vendorBetRecordColumns', function (
            bool $uniqueBetId = true,
            bool $withReferenceNo = true,
        ): void {
            /** @var Blueprint $this */
            $this->increments('id');
            $this->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $this->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $this->string('username', 50)->index()->comment('玩家帳號');

            $betId = $this->string('bet_id', 100)->comment('注單號');
            $uniqueBetId ? $betId->unique() : $betId->index();

            if ($withReferenceNo) {
                $this->string('reference_no', 50)->unique()->comment('三方訂單號');
            }

            $this->string('game_code', 50)->index()->comment('遊戲代碼');

            $this->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $this->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $this->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $this->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $this->decimal('bonus', 18, 4)->default(0)->comment('活動獎金');
            $this->decimal('jackpot', 18, 4)->default(0)->comment('彩金');
            $this->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');

            $this->timestamp('bet_time')->index()->comment('投注時間');
            $this->timestamp('payout_time')->nullable()->index()->comment('派彩時間');

            $this->tinyInteger('status')->default(0)->index()->comment('注單狀態 0:建立中 1:已結算 2:取消/回滾');

            $this->text('info')->nullable()->comment('原始請求資料');

            $this->timeColumns();
        });

        /**
         * @method self toFrontendTime()
         */
        Carbon::macro('toFrontendTime', function () {
            /** @var Carbon $this */
            return $this->setTimezone(config('custom.frontend.timezone'));
        });

        /**
         * @method self toBackendTime()
         */
        Carbon::macro('toBackendTime', function () {
            /** @var Carbon $this */
            return $this->setTimezone(config('custom.backend.timezone'));
        });

        /**
         * @method self toSystemTime()
         */
        Carbon::macro('toSystemTime', function () {
            /** @var Carbon $this */
            return $this->setTimezone(config('app.timezone'));
        });

        /**
         * @method static self parseFrontend(string $time)
         */
        Carbon::macro('parseFrontend', function (string $time) {
            return Carbon::parse($time, config('custom.frontend.timezone'));
        });

        /**
         * @method self paginate(int $perPage = null, int $page = null)
         */
        Collection::macro('paginate', function (?int $perPage = null, ?int $page = null): LengthAwarePaginator {
            /** @var Collection $this */
            $perPage = $perPage ?? request()->per_page ?? 10;
            $page = $page ?? request()->page ?? 1;

            return new LengthAwarePaginator(
                items: $this->forPage($page, $perPage),
                total: $this->count(),
                perPage: $perPage,
                currentPage: $page
            );
        });

        /**
         * 避免 Cache Breakdown (緩存擊穿) 的更新方式
         *
         * 🔴 共用快取讀取一律用本 macro 取代 `Cache::remember()`，
         * **禁止自行手寫「先 get → 取鎖 → 再 get → put」的雙檢邏輯**——重複實作只會各自漏掉
         * 下面兩條降級路徑之一。寫入型互斥見 {@see \App\Helpers\Traits\DistributedLock}。
         *
         * @method self safeRemember(string $key, int $ttl, callable $callback, int $writeLockTtl = 10)
         */
        Cache::macro('safeRemember', function (
            string $key,
            int $ttl,
            callable $callback,
            int $writeLockTtl = 10,
            int $waitSeconds = 3
        ) {
            // 先讀 cache
            $value = Cache::get($key);
            if ($value !== null) {
                return $value;
            }

            // 透過 instanceof 判斷，可在測試環境安全降級，不會因 driver 不支援鎖而拋例外
            $store = Cache::getStore();
            if (!($store instanceof LockProvider)) {
                return Cache::remember($key, $ttl, $callback);
            }

            $lockKey = $key . ':lock';

            try {
                return Cache::lock($lockKey, $writeLockTtl)
                    ->block($waitSeconds, function () use ($key, $ttl, $callback) {
                        // 拿到鎖後再檢查一次
                        $value = Cache::get($key);
                        if ($value !== null) {
                            return $value;
                        }

                        // 真正只有一個人會跑到這
                        $value = $callback();

                        Cache::put($key, $value, $ttl);

                        return $value;
                    });
            } catch (LockTimeoutException $e) {
                // 取鎖逾時同樣降級為一般 remember：寧可多重建一次，也不讓請求失敗
                return Cache::remember($key, $ttl, $callback);
            }
        });
    }
}
