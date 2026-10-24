<?php

namespace App\Providers;

use App\Enums\General\ContextKeyEnum;
use Bilfeldt\LaravelHttpClientLogger\HttpLoggerInterface;
use Bilfeldt\LaravelHttpClientLogger\HttpLoggingFilterInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
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
        PendingRequest::macro('logAction', function (
            $action,
            $context = [],
            $config = [],
            ?HttpLoggerInterface $logger = null,
            ?HttpLoggingFilterInterface $filter = null
        ): PendingRequest {
            /** @var PendingRequest $this */
            Context::addHidden(ContextKeyEnum::LOG_HTTP_ACTION->value, $action);

            return $this->log($context, $config, $logger, $filter);
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
         * @method self parseFrontend(string $time)
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
         * @method self safeRemember(string $key, int $ttl, callable $callback, int $writeLockTtl = 10)
         */
        Cache::macro('safeRemember', function (string $key, int $ttl, callable $callback, int $writeLockTtl = 10) {
            $value = Cache::get($key);
            if ($value !== null) {
                return $value;
            }

            $lockKey = "{$key}:write";
            $lock = Cache::lock($lockKey, $writeLockTtl);

            if ($lock->get()) {
                try {
                    $value = $callback();
                    Cache::put($key, $value, $ttl);

                    return $value;
                } finally {
                    $lock->release();
                }
            }

            throw new Exception('Cache safe updating, please try again later');
        });
    }
}
