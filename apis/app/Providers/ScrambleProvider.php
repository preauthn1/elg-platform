<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ScrambleProvider extends ServiceProvider
{
    private $basicDescription = '';

    /**
     * Register services.
     */
    public function register(): void
    {
        /**
         * php artisan scramble:export --api=frontend --path=public/api-frontend.json
         * php artisan scramble:export --api=external --path=public/api-external.json
         * php artisan scramble:export --api=agent --path=public/api-agent.json
         */
        $this->registerFrontendApi();
        $this->registerExternalApi();
        $this->registerAgentApi();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', function ($user = null) {
            return true;
        });

        if (!config('scramble.enabled') || !(request()->is('docs') || request()->is('docs/*'))) {
            return;
        }

        $requestDomain = request()->schemeAndHttpHost();
        $this->basicDescription = "API 文件：\n\n" .
                "1. 前台 (Frontend) API 文件： <a href='{$requestDomain}/docs/frontend'>/docs/frontend</a>\n" .
                "2. 外部 (External) API 文件： <a href='{$requestDomain}/docs/external'>/docs/external</a>\n" .
                "3. 代理後台 (Agent) API 文件： <a href='{$requestDomain}/docs/agent'>/docs/agent</a>\n\n" .
                'API 文件僅供測試使用，請勿用於生產環境。
        ';

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->info->description = $this->basicDescription;
        });

        // 如果沒經過 AuthMember 中介層，則不需要 Bearer Token
        foreach (['frontend', 'external', 'agent'] as $api) {
            Scramble::configure($api)
                ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo) {
                    $routeMiddleware = $routeInfo->route->gatherMiddleware();

                    $hasAuthMiddleware = collect($routeMiddleware)->contains(
                        fn ($m) => Str::endsWith($m, 'AuthMember')
                    );

                    if (!$hasAuthMiddleware) {
                        $operation->security = [];
                    }
                });
        }

        // 設定一個單例，讓 routes/web.php 知道 Scramble 已經準備好了，不然 composer dump-autoload 會出錯
        app()->singleton('scramble.boot.ready', fn () => true);
    }

    private function registerFrontendApi(): void
    {
        Scramble::registerApi('frontend', ['api_path' => 'frontend']);

        Scramble::configure('frontend')
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
                $openApi->info->title = '前台 Frontend API';
                $openApi->info->description = $this->basicDescription;
            });
    }

    private function registerExternalApi(): void
    {
        Scramble::registerApi('external', ['api_path' => 'external']);

        Scramble::configure('external')
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
                $openApi->info->title = '外部 External API';
                $openApi->info->description = $this->basicDescription;
            });
    }

    private function registerAgentApi(): void
    {
        Scramble::registerApi('agent', ['api_path' => 'agent']);

        Scramble::configure('agent')
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
                $openApi->info->title = '代理後台 Agent API';
                $openApi->info->description = $this->basicDescription;
            });
    }
}
