<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\OrientationEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class DetectDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        // 加上通用資料
        $device = $this->checkIsMobile($request);
        Context::addHidden(ContextKeyEnum::IS_MOBILE->value, $device > 0);
        Context::addHidden(ContextKeyEnum::IS_PAD->value, $device == 2);
        // 螢幕方向由前端帶 X-Orientation，不是每個請求都會有
        Context::addHidden(
            ContextKeyEnum::ORIENTATION->value,
            OrientationEnum::fromHeader($request->header('X-Orientation'))
        );

        return $next($request);
    }

    // 判斷是否為行動裝置
    protected function checkIsMobile(Request $request): int
    {
        $userAgent = $request->server('HTTP_USER_AGENT');

        if ($userAgent === null) {
            return 0;
        }

        // Detect special conditions devices
        $iPod = stripos($userAgent, 'iPod');
        $iPhone = stripos($userAgent, 'iPhone');
        $iPad = stripos($userAgent, 'iPad');
        $Android = stripos($userAgent, 'Android') && stripos($userAgent, 'mobile');
        $AndroidTablet = stripos($userAgent, 'Android') && !$Android;
        $webOS = stripos($userAgent, 'webOS');
        $BlackBerry = stripos($userAgent, 'BlackBerry');
        $RimTablet = stripos($userAgent, 'RIM Tablet');

        if ($iPad || $AndroidTablet || $RimTablet) {
            return 2;
        }

        if ($iPod || $iPhone || $Android || $webOS || $BlackBerry) {
            return 1;
        }

        return 0;
    }
}
