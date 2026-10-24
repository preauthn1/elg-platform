<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\PlatformAnnouncement\ListRequest;
use App\Http\Resources\Frontend\PlatformAnnouncementResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Repositories\PlatformAnnouncementRepository;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

#[Group('PlatformAnnouncement (平台公告)')]
class PlatformAnnouncementController extends Controller
{
    public function __construct(
        private PlatformAnnouncementRepository $repository,
    ) {}

    /**
     * 平台公告列表
     *
     * OLD: /api/platformAnnouncement/list
     */
    public function list(ListRequest $request)
    {
        // 驗證request參數
        $input = $request->validated();
        $page = $input['page'] ?? 1;
        $perPage = $input['per_page'] ?? 10;

        // 取得資訊
        $userData = $this->getUserContext();
        $languageId = $userData->languageId;
        $agent = $userData->agent;
        $agentIdList = $agent->hierarchy_array;

        // 緩存
        // $cacheKey = CacheKeys::platformAnnouncementAgent($agent->id, $languageId);
        // $data = Cache::remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($agentIdList, $languageId) {
        //     return $this->repository->getList($agentIdList, $languageId);
        // });

        $data = $this->repository->getList($agentIdList, $languageId);
        $data = $data->paginate($perPage);

        return ApiResponse::successWithPagination(
            data: PlatformAnnouncementResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 平台公告未讀數量
     *
     * OLD: /api/platformAnnouncement/unread
     */
    public function unread(Request $request)
    {
        // 取得資訊
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $agentIdList = $agent->hierarchy_array;

        // 緩存
        $cacheKey = CacheKeys::platformAnnouncementMemberUnread($member->id);
        $unreadResult = Cache::remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($agentIdList, $member) {
            $memberReadRecord = $this->repository->getMemberReadRecord($member->id);

            return $this->repository->getUnreadCount($agentIdList, $memberReadRecord->last_read_time);
        });

        return ApiResponse::success([
            'unread' => (int) $unreadResult,
        ]);
    }

    /**
     * 平台公告已讀全部
     *
     * OLD: /api/platformAnnouncement/readAll
     */
    public function readAll(Request $request)
    {
        $lastReadTime = Carbon::now()->toDateTimeString();

        // 取得資訊
        $userData = $this->getUserContext();
        $member = $userData->member;

        // 取得用戶讀取紀錄
        $memberReadRecord = $this->repository->getMemberReadRecord($member->id);

        // 如果不是剛建立的讀取紀錄，則更新最後讀取時間
        if (!$memberReadRecord->wasRecentlyCreated) {
            $memberReadRecord->update(['last_read_time' => $lastReadTime]);
        }

        // 刪除緩存的最後讀取時間
        $cacheKey = CacheKeys::platformAnnouncementMemberUnread($member->id);
        Cache::forget($cacheKey);

        return ApiResponse::success([
            'lastReadTimeUtc' => $lastReadTime,
        ]);
    }
}
