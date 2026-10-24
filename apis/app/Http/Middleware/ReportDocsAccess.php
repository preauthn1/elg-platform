<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportDocsAccess
{
    /**
     * 報告中心存取閘門：沿用 Scramble 開關（config('scramble.enabled') / SCRAMBLE_ENABLED）。
     *
     * 凡 API 文件可見的環境即可瀏覽報告：本機預設啟用、dev2 等以 SCRAMBLE_ENABLED 開啟者亦可；
     * 正式環境關閉 Scramble → 一律 abort 404（與 API 文件在停用時不註冊路由的行為一致，不洩漏路由存在）。
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('scramble.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
