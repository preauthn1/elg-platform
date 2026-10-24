<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\Param;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * 回應碼固定 1000，路由掛在 api/ 而不是 frontend/api/。
 */
class ApkCompatController extends Controller
{
    /** 該代理的前台網域 */
    public function frontendDomain(Request $request): JsonResponse
    {
        $agentId = (int) $request->input('agent_id');

        $domain = Cache::remember(
            CacheKeys::legacyFrontendDomain($agentId),
            CacheTtl::DEFAULT->value,
            static fn () => AgentDomain::query()
                ->where('agent_id', $agentId)
                ->where('status', 1)
                ->where('type', 0)
                ->orderByDesc('sort')
                ->value('domain'),
        );

        return $this->legacyResponse($domain);
    }

    /** APP 版本 */
    public function versions(Request $request): JsonResponse
    {
        $columns = [
            'ios_version',
            'ios_link',
            'android_version',
            'android_link',
        ];

        $version = Version::query()
            ->join('agent_domains as ad', function ($join) use ($request) {
                $join->on('ad.agent_id', 'versions.agent_id')
                    ->where('ad.domain', $request->input('domain'));
            })
            ->where('versions.type', 1)
            ->select(array_map(static fn ($c) => 'versions.' . $c, $columns))
            ->first();

        // 該網域沒設定版本就退回預設那筆
        $version ??= Version::query()
            ->where('type', 1)
            ->select($columns)
            ->first();

        return $this->legacyResponse([
            'ios' => [
                'version' => $version?->ios_version,
                'downloadLink' => $version?->ios_link,
            ],
            'android' => [
                'version' => $version?->android_version,
                'downloadLink' => $version?->android_link,
            ],
        ]);
    }

    /** 備用 API 網域 */
    public function alternateDomain(): JsonResponse
    {
        return $this->legacyResponse(
            Param::query()
                ->where('type', 'alternate_domain')
                ->where('status', 1)
                ->orderByDesc('sort')
                ->pluck('parameter'),
        );
    }

    /** 回應信封：code 固定 1000，tid 沿用本專案的請求批號 */
    private function legacyResponse(mixed $data): JsonResponse
    {
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $data,
            'tid' => Context::getHidden(ContextKeyEnum::REQUEST_ID->value),
        ]);
    }
}
