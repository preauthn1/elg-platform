<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\Advertise\AdvertiseTypeEnum;
use App\Enums\General\CacheTtl;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Advertise\ListRequest;
use App\Http\Resources\Frontend\Advertise\ListResource;
use App\Models\Advertise;
use App\Services\AdvertiseService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Support\Facades\Cache;

#[Group('Advertise (廣告)')]
class AdvertiseController extends Controller
{
    public function __construct(
        private AdvertiseService $advertiseService,
    ) {}

    #[HeaderParameter('Authorization', description: 'Token', required: false, type: 'string')]
    /**
     * 廣告列表
     *
     * Both auth and non-auth can access
     *
     * OLD: /api/advertisements
     */
    public function list(ListRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;

        $key = CacheKeys::advertise($agent->id, (int) $request->path_id, $languageId);

        $data = Cache::remember($key, CacheTtl::ONE_HOUR->value, function () use ($request, $agent, $languageId) {
            $ads = Advertise::query()
                ->with('excludeAgents')
                ->where('start_time', '<=', date('Y-m-d H:i:s'))
                ->where('end_time', '>=', date('Y-m-d H:i:s'))
                ->where('status', 1)
                ->where('advertisement_path_id', $request->path_id)
                ->whereIn('agent_id', $agent->hierarchy_array)
                ->with([
                    'game:id,vendor_id',
                ])
                ->select(
                    'id',
                    'advertisement_path_id as path_id',
                    'type',
                    'content',
                    'open_window'
                )
                ->orderBy('sort', 'DESC')
                ->orderBy('id', 'DESC')
                ->get();

            $adImages = $this->advertiseService->getImages($ads->pluck('id')->toArray(), $languageId);

            foreach ($ads as $ad) {
                $ad->game_type_id = null;
                $ad->game_id = null;
                $ad->vendor_id = null;
                $ad->url = null;

                match ($ad->type) {
                    AdvertiseTypeEnum::GAME_TYPE => $ad->game_type_id = $ad->content,
                    AdvertiseTypeEnum::GAME => (function () use ($ad) {
                        $ad->game_id = $ad->content;
                        $ad->vendor_id = $ad->game->vendor_id ?? null;
                    })(),
                    AdvertiseTypeEnum::VENDOR => $ad->vendor_id = $ad->content,
                    AdvertiseTypeEnum::EXTERNAL_URL => (function () use ($ad, $languageId) {
                        $urls = json_decode($ad->content, true);
                        $ad->url = $urls[$languageId] ?? $urls[1];
                    })(),
                    AdvertiseTypeEnum::INTERNAL_URL => $ad->url = $ad->content,
                    default => null,
                };

                $adImage = $adImages->where('advertiseId', $ad->id)->first();
                $ad->image = $adImage->imageUrl ?? null;
                $ad->image_pc = $adImage->imagePcUrl ?? null;
            }

            return $ads;
        });

        // 過濾排除的代理
        $agentIds = $member?->agent?->hierarchy_array ?? [];
        $agentIds[] = $agent->id;
        $agentIds = array_unique($agentIds);

        $data = $data->filter(function ($ad) use ($agentIds) {
            $excludeAgentIds = $ad->excludeAgents->pluck('agent_id')->toArray();

            return !array_intersect($agentIds, $excludeAgentIds);
        });

        return ApiResponse::success(ListResource::collection($data));
    }
}
