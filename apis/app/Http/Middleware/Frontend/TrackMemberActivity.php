<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\CacheKeys\CacheKeys;
use App\Jobs\UpdateMemberActivity;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * 記錄會員最後請求 API 時間（member_statistics.last_activity_at）。
 * 以緩存降頻：每會員隨機 3–5 分鐘最多寫一次，避免高頻請求打爆 DB。
 * 須置於 AuthMember 之後（依賴 Auth::setUser 的當前會員）。
 */
class TrackMemberActivity
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $member = Auth::user();

        if ($member instanceof Member) {
            $cacheKey = CacheKeys::memberLastActivity($member->id);

            if (!Cache::has($cacheKey)) {
                // 隨機 180–300 秒降頻，兼顧避免惊群
                Cache::put($cacheKey, 1, now()->addSeconds(random_int(180, 300)));
                UpdateMemberActivity::dispatchSafely($member->id, UpdateMemberActivity::TYPE_REQUEST, now()->toDateTimeString());
            }
        }

        return $next($request);
    }
}
