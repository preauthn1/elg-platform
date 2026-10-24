<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\Lobby\VendorStatusEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Models\EventV2DetailExcludeVendor;
use App\Models\EventV2DetailVendor;
use App\Models\Game;
use App\Models\Member;
use App\Models\PrizeContentFlowLimit;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use Illuminate\Support\Collection;

class FlowLimitService
{
    private ?Collection $availableVendors = null;

    /** 工單4126：該會員被限制的遊戲商 ID，同一輪請求只查一次 */
    private ?array $memberRestrictedVendorIds = null;

    public function checkVendorStatus(VendorCustomizedItem $item, ?Member $member = null): int
    {
        $result = $item->status;

        // 維護中
        $vendorMaintenance = $item->vendorMaintenances->first();
        if ($vendorMaintenance && in_array($vendorMaintenance->type, [
            VendorMaintainTypeEnum::BOTH_CLOSED->value,
            VendorMaintainTypeEnum::GAME_CLOSED->value,
        ], true)) {
            $result = VendorStatusEnum::MAINTENANCE->value;
        }

        // 未登入
        if (empty($member)) {
            return $result;
        }

        // 工單4126：該會員被限制的遊戲商比照維護灰化（永久，無恢復時間）。
        // 清單每次請求查一次即可，同一輪的多個 item 共用。
        if ($this->memberRestrictedVendorIds === null) {
            $this->memberRestrictedVendorIds = app(VendorService::class)
                ->getMemberRestrictedVendorIds($member);
        }
        if (in_array((int) $item->vendor_id, $this->memberRestrictedVendorIds, true)) {
            return VendorStatusEnum::MAINTENANCE->value;
        }

        // 非鎖分或活動中
        if ($member->event_mode <= 0 && $member->is_event_wallet !== true) {
            return $result;
        }

        // 取得可用遊戲商, 只會呼叫一次
        if (is_null($this->availableVendors)) {
            $this->setAvailableVendors($member);
        }

        // 沒有可用遊戲商
        if (empty($this->availableVendors) || $this->availableVendors->isEmpty()) {
            return $result;
        }

        // 活動中
        if ($member->is_event_wallet === true) {
            $availableCheck = $this->availableVendors->contains($item->vendor_id);

            return $availableCheck ? $result : VendorStatusEnum::EVENT_V2_FORBIDDEN->value;
        }

        // 鎖分模式是否可玩
        $availableCheck = $this->availableVendors
            ->whereIn('vendor_id', [$item->vendor_id, 0]) // 0 means all vendor, no limit
            ->when($item->type_id != 0, function ($query) use ($item) { // type_id 為 0 時一律通過
                $query->where('game_type_id', $item->type_id);
            })
            ->first();

        return $availableCheck ? $result : VendorStatusEnum::FORBIDDEN->value;
    }

    public function isGameDisabled(Game $game, ?Member $member = null): int
    {
        if (!isset($this->availableVendors)) {
            $this->setAvailableVendors($member);
        }

        if (empty($this->availableVendors) || $this->availableVendors->isEmpty()) {
            return 0;
        }

        // Has limits, check game is available in limits.
        // [<vendor_id>, <game_type_id>], 0 means all available
        $availableGameTypeIds = $this->availableVendors
            ->whereIn('vendor_id', [$game->vendor->id, 0])
            ->pluck('game_type_id')
            ->toArray();

        // No available game types
        if (empty($availableGameTypeIds)) {
            return 1;
        }

        // 0 means all game type available
        if (in_array(0, $availableGameTypeIds)) {
            return 0;
        }

        return in_array($game->type_id, $availableGameTypeIds) ? 0 : 1;
    }

    public function setAvailableVendors(?Member $member = null): void
    {
        $this->availableVendors = Collection::empty();

        if (empty($member)) {
            return;
        }

        if ($member->is_event_wallet === true) {
            $includevendors = EventV2DetailVendor::query()
                ->whereHas('eventV2Detail.eventV2UserJoins', fn ($q) => $q
                    ->where('member_id', $member->id)
                    ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
                )
                ->pluck('vendor_id');

            $excludeVendors = EventV2DetailExcludeVendor::query()
                ->whereHas('eventV2Detail.eventV2UserJoins', fn ($q) => $q
                    ->where('member_id', $member->id)
                    ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
                )
                ->pluck('vendor_id');

            // 只設定限定遊戲商
            if ($includevendors->isNotEmpty() && $excludeVendors->isEmpty()) {
                $this->availableVendors = $includevendors;

                return;
            }

            // 只設定排除遊戲商
            if ($includevendors->isEmpty() && $excludeVendors->isNotEmpty()) {
                $vendors = Vendor::query()->pluck('id');
                $this->availableVendors = $vendors->diff($excludeVendors);

                return;
            }

            // 設定限定遊戲商且有排除遊戲商
            if ($includevendors->isNotEmpty() && $excludeVendors->isNotEmpty()) {
                $this->availableVendors = $includevendors->diff($excludeVendors);

                return;
            }

            return;
        } elseif ($member->event_mode > 0) {
            $this->availableVendors = PrizeContentFlowLimit::where('prize_content_id', $member->event_mode)
                ->select('vendor_id', 'game_type_id')
                ->get();
        }
    }
}
