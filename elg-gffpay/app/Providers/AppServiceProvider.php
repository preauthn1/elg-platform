<?php

declare(strict_types=1);

namespace App\Providers;

use App\Helpers\Common;
use App\Services;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (request()->has('dd')) {
            Config::set('api-debugger.enabled', true);
        }

        $ip = Common::getIp();
        $isInternalIp = Str::startsWith($ip, '192.168.') || $ip == config('custom.office.ip');
        if (!$isInternalIp) {
            Config::set('api-debugger.enabled', false);
        }

        $this->app->singleton(Services\BotNotifyService::class, function () {
            return new Services\BotNotifyService;
        });

        $this->app->singleton(Common::class, function () {
            return new Common;
        });

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch->locales(['zh_TW', 'zh_CN', 'en']); // also accepts a closure
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::globalOptions([
            'timeout' => 10,
        ]);
    }
}
