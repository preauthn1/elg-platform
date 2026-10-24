<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Resources\Frontend\Version\VersionResource;
use App\Models\AgentDomain;
use App\Models\Version;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Support\Facades\Cache;

#[Group('Version')]
class VersionController extends Controller
{
    #[HeaderParameter('Authorization', description: 'Token', required: false, type: 'string')]
    /**
     * APP下載
     *
     * OLD: api/versions
     */
    public function __invoke(DomainRequest $request)
    {
        $domain = $request->input('domain');
        $cacheKey = CacheKeys::versionByDomain($domain);

        $version = Cache::remember($cacheKey, CacheTtl::HALF_HOUR->value, function () use ($domain) {
            // 先查所屬代理是誰
            $agentDomain = AgentDomain::where('domain', $domain)->first();

            if (!$agentDomain) {
                throw new ApiException(__('error_message.1213'));
            }

            // 取得代理並獲取代理鏈
            $agent = $agentDomain->agent;

            if (!$agent) {
                throw new ApiException(__('error_message.1213'));
            }

            // 獲取代理鏈（由近到遠）
            $agentIds = $agent->hierarchy_array;

            $agentIdString = implode(',', $agentIds);

            // 用代理鏈去查 version，由近到遠排序
            return Version::where('type', 1)
                ->whereIn('agent_id', $agentIds)
                ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
                ->first();
        });

        return ApiResponse::success(VersionResource::make($version));
    }
}
