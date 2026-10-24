<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\Member\MemberRestrictScopeEnum;
use App\Enums\Member\MemberRestrictTypeEnum;
use App\Enums\StatusEnum;
use App\Enums\Vendor\GameMaintainTypeEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Exceptions\VendorNotFoundException;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\GameMaintenanceGame;
use App\Models\Member;
use App\Models\MemberGameRestrictionTarget;
use App\Models\VendorInfo;
use App\Models\VendorMaintenances;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorService
{
    private Agent $agent;

    private bool $isEventWallet = false;

    private int $vendorId = 0;

    public function getDriver(int $vendorId, ?Member $member = null): AbstractTransferWallet|AbstractSingleWallet
    {
        $vendorClass = config('vendor.driver.' . $vendorId, '');

        if (!class_exists($vendorClass)) {
            Log::error(sprintf('Vendor ID %s 缺少對應的 class %s', $vendorId, $vendorClass));
            throw new VendorNotFoundException(__('error_message.9994'));
        }

        return App::make($vendorClass, ['member' => $member, 'vendorId' => $vendorId]);
    }

    public function setIsEventWallet(bool $isEventWallet): self
    {
        $this->isEventWallet = $isEventWallet;

        return $this;
    }

    /**
     * 該會員被限制的遊戲商 ID 工單4126
     *
     * 供大廳 FlowLimitService::checkVendorStatus 判定灰化用——該處以
     * vendor_customized_items 的關聯查維護，與本表的查詢路徑不同，
     * 故另開公開入口而非共用 getMaintenanceVendorIds。
     *
     * @return array<int>
     */
    public function getMemberRestrictedVendorIds(?Member $member): array
    {
        return $this->getMemberRestrictedTargetIds($member, MemberRestrictTypeEnum::VENDOR);
    }

    /**
     * 查詢該會員被限制的標的 ID 工單4126
     *
     * 與 4120 的維護（代理層級、有時間區間、到點自動恢復）平行的另一套：
     * 會員層級的永久限制，命中即比照維護灰化，但不回傳恢復時間。
     *
     * 對象比對兩條路徑擇一命中即算：
     *   target_scope=1 指定會員 → member_id 相符
     *   target_scope=2 會員群組 → member_group_id 相符（會員只屬單一群組）
     *
     * 刻意不比對 mgr.agent_id：本表的作用對象已精確到 member_id／member_group_id，
     * 再以建單者的代理層級過濾一次是多餘的，且會讓跨層級建的單（例如平台端
     * 直接限制某代理線下的會員）失效。agent_id 僅供後台可見範圍使用。
     *
     * @param  MemberRestrictTypeEnum  $type  VENDOR 回 vendor_id、GAME 回 game_id
     * @param  array<int>|null  $targetIds  限縮比對範圍；null 表示全查
     * @return array<int> 受限的標的 ID
     */
    private function getMemberRestrictedTargetIds(
        ?Member $member,
        MemberRestrictTypeEnum $type,
        ?array $targetIds = null,
    ): array {
        // 未登入沒有會員身分，不適用會員層級限制
        if ($member === null) {
            return [];
        }

        // 呼叫端多為列表頁，先短路避免空 whereIn 掃全表
        if ($targetIds !== null && $targetIds === []) {
            return [];
        }

        return MemberGameRestrictionTarget::query()
            ->join('member_game_restrictions as mgr', 'mgr.id', 'member_game_restriction_targets.restriction_id')
            ->join('member_game_restriction_members as mgrm', 'mgrm.restriction_id', 'mgr.id')
            ->when($targetIds !== null, fn ($q) => $q->whereIn('member_game_restriction_targets.target_id', $targetIds))
            ->where('mgr.status', StatusEnum::ENABLE->value)
            ->where('mgr.restrict_type', $type->value)
            ->where(function ($query) use ($member) {
                $query->where(function ($sub) use ($member) {
                    $sub->where('mgr.target_scope', MemberRestrictScopeEnum::MEMBER->value)
                        ->where('mgrm.member_id', $member->id);
                });
                // 會員未分群（member_group_id 為 0/null）時不比對群組，避免誤命中未設定的限制單
                if (!empty($member->member_group_id)) {
                    $query->orWhere(function ($sub) use ($member) {
                        $sub->where('mgr.target_scope', MemberRestrictScopeEnum::GROUP->value)
                            ->where('mgrm.member_group_id', $member->member_group_id);
                    });
                }
            })
            ->distinct()
            ->pluck('member_game_restriction_targets.target_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 查詢指定時間區間, 代理下使否有維護中的遊戲商
     *
     * 傳入 $member 時一併併入該會員的遊戲商層級限制（工單4126）：
     * 兩者對前台的效果相同（整商灰化），差別僅在維護有時間區間、限制為永久。
     *
     * @param  VendorMaintainTypeEnum|array<VendorMaintainTypeEnum>  $status
     */
    public function getMaintenanceVendorIds(
        Agent $agent,
        VendorMaintainTypeEnum|array $status,
        int|array|null $vendorIds = null,
        ?Member $member = null,
    ): array {
        $datetime = now()->format('Y-m-d H:i:s');

        $query = VendorMaintenances::query()
            ->where('start_time', '<=', $datetime)
            ->where('end_time', '>', $datetime)
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->whereNotIn('exclude_agent_id', $agent->hierarchy_array)
            ->where('status', StatusEnum::ENABLE->value)
            ->when($vendorIds !== null, fn ($q) => $q
                ->when(is_array($vendorIds), fn ($q) => $q->whereIn('vendor_id', $vendorIds))
                ->when(is_int($vendorIds), fn ($q) => $q->where('vendor_id', $vendorIds))
            );

        if (is_array($status)) {
            $query->whereIn('type', $status);
        } else {
            $query->where('type', $status);
        }

        $maintenanceIds = $query->pluck('vendor_id')->toArray();

        // 工單4126：併入會員層級的遊戲商限制（未帶 member 時為空陣列，行為與原本一致）
        $restrictedIds = $this->getMemberRestrictedTargetIds(
            $member,
            MemberRestrictTypeEnum::VENDOR,
            is_int($vendorIds) ? [$vendorIds] : $vendorIds,
        );

        return array_values(array_unique([...$maintenanceIds, ...$restrictedIds]));
    }

    /**
     * 查詢該代理當下維護中的「單一遊戲」ID 工單4120
     *
     * 與 getMaintenanceVendorIds 平行：後者是整商維護（vendor_maintenances），
     * 本方法是遊戲層級（game_maintenances + 子表），兩者互不影響、可同時生效。
     *
     * 生效條件與整商維護一致：status=1、當下落在時間區間、維護代理在自己的
     * hierarchy 上、且未被 exclude_agent_id 排除。
     *
     * @param  array<int>|null  $gameIds  限縮比對範圍（當頁遊戲）；null 表示全查
     * @return array<int> 維護中的 game_id
     */
    public function getMaintenanceGameIds(Agent $agent, ?array $gameIds = null, ?Member $member = null): array
    {
        return array_keys($this->getMaintenanceGameMap($agent, $gameIds, $member));
    }

    /**
     * 維護中的遊戲與其結束時間 工單4120
     *
     * 前台列表用：遊戲**不從清單移除**，而是標記為維護中並顯示恢復時間
     * （與遊戲商維護的 maintenance_end_time 同概念）。
     *
     * 同一遊戲被多張單涵蓋時取最晚的 end_time——避免前一張單先結束就顯示成
     * 已恢復，實際仍在另一張單的維護區間內。
     *
     * 傳入 $member 時一併併入該會員的遊戲層級限制（工單4126）。限制為永久，
     * end_time 回 null；同一遊戲同時被維護與限制涵蓋時以限制為準（永久蓋過暫時），
     * 前台皮膚的 v-if="isMaintenance && maintenanceEndTime" 即只灰化、不顯示恢復時間，
     * 避免出現「顯示某時恢復、時間到卻仍不能玩」的矛盾。
     *
     * @param  array<int>|null  $gameIds  限縮比對範圍（當頁遊戲）；null 表示全查
     * @return array<int, string|null> game_id => end_time（會員限制為 null）
     */
    public function getMaintenanceGameMap(Agent $agent, ?array $gameIds = null, ?Member $member = null): array
    {
        // 呼叫端多為列表頁，先短路避免空 whereIn 掃全表
        if ($gameIds !== null && empty($gameIds)) {
            return [];
        }

        $datetime = now()->format('Y-m-d H:i:s');

        $map = GameMaintenanceGame::query()
            ->join('game_maintenances as gm', 'gm.id', 'game_maintenance_games.game_maintenance_id')
            ->when($gameIds !== null, fn ($q) => $q->whereIn('game_maintenance_games.game_id', $gameIds))
            ->where('gm.status', StatusEnum::ENABLE->value)
            ->where('gm.type', GameMaintainTypeEnum::GAME_CLOSED->value)
            ->where('gm.start_time', '<=', $datetime)
            ->where('gm.end_time', '>', $datetime)
            ->whereIn('gm.agent_id', $agent->hierarchy_array)
            ->whereNotIn('gm.exclude_agent_id', $agent->hierarchy_array)
            ->groupBy('game_maintenance_games.game_id')
            ->selectRaw('game_maintenance_games.game_id, MAX(gm.end_time) as end_time')
            ->pluck('end_time', 'game_id')
            ->mapWithKeys(fn ($endTime, $gameId) => [(int) $gameId => (string) $endTime])
            ->toArray();

        // 工單4126：併入會員層級的遊戲限制，覆寫 end_time 為 null（永久限制無恢復時間）
        foreach ($this->getMemberRestrictedTargetIds($member, MemberRestrictTypeEnum::GAME, $gameIds) as $gameId) {
            $map[$gameId] = null;
        }

        return $map;
    }

    /**
     * 遊戲商費率列表（vendor/rate/list）
     *
     * 遷移自 API_Frontend VendorController::rate_list（tpower-master 與 vw-master 逐行完全相同）。
     * 依代理層級由近到遠找第一層有設定的代理，取其配置的遊戲商列表；找不到即回空集合。
     *
     * @return Collection<int, mixed>
     */
    public function getRateList(Agent $agent): Collection
    {
        $vendors = collect();

        foreach ($agent->hierarchy_array as $agentId) {
            $vendors = DB::table('vendors as v')
                ->join('vendor_infos as vi', fn ($join) => $join->on('vi.vendor_id', 'v.id')->where('vi.status', 1))
                ->join('agent_vendors as av', function ($join) use ($agentId) {
                    $join->on('av.vendor_info_id', 'vi.id')->where('av.status', 1)->where('av.agent_id', $agentId);
                })
                ->leftJoin('vendor_images as vim', function ($join) use ($agent) {
                    $join->on('vim.vendor_id', 'v.id')->where('vim.agent_group_id', $agent->vendor_customize_group_id);
                })
                ->where('v.status', 1)
                ->orderBy('v.id')
                ->select([
                    'v.id',
                    DB::raw('(CASE WHEN vim.name IS NULL THEN v.name ELSE vim.name END) as name'),
                    DB::raw('IFNULL(vim.valid_bet_rate, v.valid_bet_rate) as unlimit_rate'),
                ])
                ->get();

            if ($vendors->isNotEmpty()) {
                break;
            }
        }

        return $vendors;
    }

    /**
     * 查詢指定時間區間, 代理下維護中的遊戲商, 依維護類型分組
     *
     * 一次撈出全部類型供呼叫端自行過濾，避免同一請求內對不同類型組合重複查詢。
     *
     * @return array<int, array<int, int>> [維護類型 => vendor_id 列表]
     */
    public function getMaintenanceVendorIdsByType(Agent $agent): array
    {
        $datetime = now()->format('Y-m-d H:i:s');

        return VendorMaintenances::query()
            ->where('start_time', '<=', $datetime)
            ->where('end_time', '>', $datetime)
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->whereNotIn('exclude_agent_id', $agent->hierarchy_array)
            ->where('status', StatusEnum::ENABLE->value)
            ->select(['vendor_id', 'type'])
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $rows->pluck('vendor_id')->all())
            ->all();
    }

    /**
     * 根據 agent 的 hierarchy 和 agent_vendors 取得有效的 vendor_id 列表
     *
     * @param  int  $showDisable  0: show disable vendor, 1: hide disable vendor
     * @return array vendor ids
     */
    public function getVendorIds(Agent $agent): array
    {
        $this->agent = $agent;

        return Cache::remember(
            key: CacheKeys::vendorServiceVendorIds($agent->id),
            ttl: CacheTtl::ONE_HOUR->value + rand(0, 600),
            callback: fn () => $this->getValidVendorInfos()->pluck('vendor_id')->unique()->toArray(),
        );
    }

    public function getEventWalletVendorIds(Agent $agent): array
    {
        $this->agent = $agent;
        $this->isEventWallet = true;

        return Cache::remember(
            key: CacheKeys::vendorServiceEventWalletVendorIds($agent->id),
            ttl: CacheTtl::ONE_HOUR->value + rand(0, 600),
            callback: fn () => $this->getValidVendorInfos()->pluck('vendor_id')->unique()->toArray(),
        );
    }

    /**
     * 根據 agent 的 hierarchy 和 agent_vendors 取得有效的 vendor_info_id 列表
     *
     * @return array vendor info ids
     */
    public function getVendorInfoIds(Agent $agent, int|array|null $vendorId = null): array
    {
        $vendorInfos = $this->getVendorInfos($agent, $vendorId);

        return $vendorInfos->pluck('id')->toArray();
    }

    /**
     * 根據 agent 的 hierarchy 和 agent_vendors 取得有效的 vendor_infos 列表
     *
     * @return Collection<int, VendorInfo>
     */
    public function getVendorInfos(Agent $agent, int|array|null $vendorId = null): Collection
    {
        $this->agent = $agent;

        $key = ($this->isEventWallet) ?
            CacheKeys::vendorServiceEventWalletVendorInfos($agent->id) :
            CacheKeys::vendorServiceVendorInfos($agent->id);

        $vendorInfos = Cache::remember(
            key: $key,
            ttl: CacheTtl::ONE_HOUR->value + rand(0, 600),
            callback: fn () => $this->getValidVendorInfos(),
        );

        if (!empty($vendorId)) {
            if (is_array($vendorId)) {
                $vendorInfos = $vendorInfos->whereIn('vendor_id', $vendorId);
            } else {
                $vendorInfos = $vendorInfos->where('vendor_id', $vendorId);
            }
        }

        return $vendorInfos;
    }

    public function getVendorInfo(Agent $agent, int $vendorId): ?VendorInfo
    {
        /** @var VendorInfo|null */
        return $this->getVendorInfos($agent, $vendorId)->first();
    }

    /**
     * 查詢有效 VendorInfo
     */
    private function getValidVendorInfos(): Collection
    {
        $agentGroupId = $this->agent->vendor_customize_group_id;
        $agentIds = array_filter($this->agent->hierarchy_array, fn ($id) => $id > 1); // except admin
        $agentIdString = implode(',', $agentIds); // from self to root

        // 第1步, 取得代理鏈上所有的 agent_vendors, 並從子代理到根代理排序, 別在這邊篩選其他表的狀態
        $agentVendors = AgentVendor::query()
            ->with('vendor', function ($query) use ($agentGroupId) {
                $query->where('status', 1);
                $query->with('vendorCustomizedItems', function ($query) use ($agentGroupId) {
                    $query->where('agent_group_id', $agentGroupId);
                    $query->where('status', 1);
                });
            })
            ->with('vendorInfo', function ($query) {
                $query->where('status', 1);
            })
            ->when($this->vendorId > 0, function ($query) { // 只有 getVendorInfoIds 會需要指定
                $query->where('agent_vendors.vendor_id', $this->vendorId);
            })
            ->whereIn('agent_vendors.agent_id', $agentIds)
            ->select(
                'agent_vendors.vendor_id',
                'agent_vendors.vendor_info_id',
                'agent_vendors.agent_id',
                'agent_vendors.status',
                'agent_vendors.self_status'
            )
            ->orderBy('agent_vendors.vendor_id')
            ->orderByRaw("FIELD(`agent_id`,{$agentIdString})") // order from self to root
            ->orderByDesc('agent_vendors.vendor_info_id')
            ->get();

        // 第2步, 篩選 agent_vendors 的多層代理, 代理鏈近到遠只留第一個代理.
        // 將資料轉化成以下結構, 再依照結構進行篩選
        // [
        //     'vendor_id' => [
        //         agentVendor1,
        //         agentVendor2,
        //         agentVendor3,
        //     ]
        //     'vendor_id2' => [
        //       ...
        // ]
        $agentVendors = $agentVendors->groupBy(['vendor_id', 'agent_id'])
            ->map(fn (Collection $collection) => $collection->first());

        // 第2.5步, 篩選 agent_vendors 的 status
        $agentVendors = $agentVendors->map(function (Collection $collection) {
            return $collection->filter(function (AgentVendor $agentVendor) {
                return $agentVendor->status === 1 ||
                    (
                        $agentVendor->status === 0 &&
                        $agentVendor->self_status === 1 &&
                        $agentVendor->agent_id === $this->agent->id
                    );
            });
        });

        // 第3步, 判斷 vendor 的 status
        $agentVendors = $agentVendors->map(function (Collection $collection) {
            return $collection->filter(function (AgentVendor $agentVendor) {
                return $agentVendor?->vendor?->status === 1;
            });
        });

        // 第4步, 判斷 vendor_info 的 status
        $agentVendors = $agentVendors->map(function (Collection $collection) {
            return $collection->filter(function (AgentVendor $agentVendor) {
                return $agentVendor?->vendorInfo?->status === 1;
            });
        });

        // 第5步, 判斷 vendor_customized_items 的 status
        $agentVendors = $agentVendors->map(function (Collection $collection) {
            return $collection->filter(function (AgentVendor $agentVendor) {
                return $agentVendor?->vendor?->vendorCustomizedItems->first()?->status === 1;
            });
        });

        // 第6步, 判斷 是否參加活動
        if ($this->isEventWallet) {
            $agentVendors = $agentVendors->map(function (Collection $collection) {
                return $collection->filter(function (AgentVendor $agentVendor) {
                    return $agentVendor?->vendorInfo?->type == VendorInfoTypeEnum::EVENT_V2->value;
                });
            });
        } else {
            $agentVendors = $agentVendors->map(function (Collection $collection) {
                return $collection->filter(function (AgentVendor $agentVendor) {
                    return $agentVendor?->vendorInfo?->type != VendorInfoTypeEnum::EVENT_V2->value;
                });
            });
        }

        // 第7步, 取出其中的 vendorInfo 並去重
        $vendorInfos = collect();
        foreach ($agentVendors as $vendorCollection) {
            foreach ($vendorCollection as $vendor) {
                $vendorInfos->push($vendor->vendorInfo);
            }
        }

        $vendorInfos = $vendorInfos->unique('id')->values();

        return $vendorInfos;
    }
}
