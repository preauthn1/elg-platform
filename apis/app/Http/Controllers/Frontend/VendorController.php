<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Data\VendorResetPasswordData;
use App\Enums\General\CacheTtl;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\Vendor\RecentlyVendorIdsRequest;
use App\Http\Requests\Frontend\Vendor\ResetPasswordRequest;
use App\Http\Resources\Frontend\Vendor\GameTypesResource;
use App\Http\Resources\Frontend\Vendor\RecentlyVendorIdsResource;
use App\Models\Game;
use App\Models\GameType;
use App\Models\MemberLaunchLog;
use App\Models\Vendor;
use App\Services\AgentGameService;
use App\Services\VendorService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Group('Vendor (遊戲商相關)')]
class VendorController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private AgentGameService $agentGameService
    ) {}

    /**
     * 遊戲商支援的遊戲類別列表
     *
     * OLD: /api/vendor/gameTypes
     */
    public function gameTypes(DomainRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;

        $cacheKey = CacheKeys::agentGameTypeVendors($agent->id);

        $buildEnabledGames = function () use ($agent) {
            $gameTypes = GameType::select('id', 'parent')->get();
            $vendorInfoIds = $this->vendorService->getVendorInfoIds($agent);
            $agentGameIds = $this->agentGameService->getGameIdList($agent);

            $enabledGames = Game::where('status', 1)
                ->whereIn('id', $agentGameIds)
                ->whereHas('vendorInfoGames', function ($query) use ($vendorInfoIds) {
                    $query->where('status', 1)
                        ->whereIn('vendor_info_id', $vendorInfoIds);
                })
                ->groupBy(['type_id', 'vendor_id'])
                ->select([
                    'type_id',
                    'vendor_id',
                    DB::raw('0 as root_type'),
                ])
                ->get();

            foreach ($enabledGames as $game) {
                $matchItem = $gameTypes->firstWhere('id', $game->type_id);
                if (!is_null($matchItem)) {
                    $game->root_type = $matchItem->parent == 0 ? $matchItem->id : $matchItem->parent;
                }
            }

            return $enabledGames;
        };

        $enabledGames = Cache::safeRemember($cacheKey, CacheTtl::ONE_HOUR->value, $buildEnabledGames);

        return ApiResponse::success(GameTypesResource::collection($enabledGames));
    }

    /**
     * 重設遊戲帳號密碼
     *
     * OLD: /api/resetVendorPassword
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $input = $request->validated();
        $vendorId = (int) $input['vendor_id'];

        $vendor = Vendor::where('need_login', 1)
            ->where('id', $vendorId)
            ->first();

        if (!$vendor) {
            // 查無此遊戲商或此遊戲商不需另外登入
            throw new ApiException(__('error_message.9994'));
        }

        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);

        if (!method_exists($vendorDriver, 'resetPassword')) { // @phpstan-ignore function.alreadyNarrowedType (BaseAbstractVendor 提供 stub，子類未必覆寫)
            Log::error('Vendor ' . $input['vendor_id'] . ' does not support resetPassword method');
            throw new ApiException(__('error_message.9994'));
        }

        $params = VendorResetPasswordData::from([
            'newPassword' => $input['new_password'],
        ]);

        $result = $vendorDriver->resetPassword($params);

        if ($result == VendorTransferResultEnum::SUCCESS) {
            return ApiResponse::success();
        }

        throw new ApiException('Password change failed');
    }

    public function recentlyVendorIds(RecentlyVendorIdsRequest $request)
    {
        $memberId = $request->input('member_id');

        $now = Carbon::now();
        $startTime = $now->copy()->subDays(7)->toSystemTime();
        $endTime = $now->copy()->toSystemTime();

        $recentlyVendorIds = MemberLaunchLog::where('member_id', $memberId)
            ->join('games as g', 'member_launch_logs.game_id', '=', 'g.id')
            ->whereBetween('launch_date', [$startTime, $endTime])
            ->groupBy('g.vendor_id')
            ->orderByDesc('launch_date')
            ->select('g.vendor_id as vendor_id')
            ->get();

        return ApiResponse::success(RecentlyVendorIdsResource::collection($recentlyVendorIds));
    }

    /**
     * 遊戲商費率列表
     *
     * OLD: /api/vendor/rate/list
     */
    public function rateList()
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;

        return ApiResponse::success($this->vendorService->getRateList($agent));
    }
}
