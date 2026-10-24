<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\Data\UserData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\General\OrientationEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 將中間件取得的上下文數據整合到 UserData
 */
class MergeUserData
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = [REDACTED];
        $member = Context::getHidden(ContextKeyEnum::MEMBER->value);
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        $ip = Context::getHidden(ContextKeyEnum::IP->value);

        // 判斷行動裝置
        $deviceType = DeviceTypeEnum::PC->value;
        if (Context::getHidden(ContextKeyEnum::IS_MOBILE->value)) {
            $deviceType = DeviceTypeEnum::MOBILE->value;
        }
        if (Context::getHidden(ContextKeyEnum::IS_PAD->value)) {
            $deviceType = DeviceTypeEnum::PAD->value;
        }
        // 螢幕方向（DetectDevice 由 X-Orientation 解析，未帶為 UNKNOWN）
        $orientation = Context::getHidden(ContextKeyEnum::ORIENTATION->value) ?? OrientationEnum::UNKNOWN;

        $userData = UserData::from(
            [
                'token' => $token,
                'member' => $member,
                'agent' => $agent,
                'languageId' => $languageId,
                'deviceType' => $deviceType,
                'ip' => $ip,
                'orientation' => $orientation,
            ]
        );

        Context::addHidden(ContextKeyEnum::USER_DATA->value, $userData);

        Log::withContext([
            'profile' => [
                'domain' => $request->getHttpHost(),
                'route' => $request->path(),
                'agent_id' => $agent?->id,
                'member_id' => $member?->id,
                'username' => $member?->username,
                'language_id' => $languageId,
                'device' => $deviceType,
                'orientation' => $orientation->value,
                'ip' => $ip,
            ],
        ]);

        return $next($request);
    }
}
