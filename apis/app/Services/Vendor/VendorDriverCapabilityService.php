<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Enums\Vendor\VendorChannelEnum;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\HasGameList;

/**
 * 遊戲商 Driver 能力推導 target/tickets#4400
 *
 * 只讀 config('vendor.driver') 的 class 字串、以 is_a(..., true) 判斷契約，不實例化 Driver、不碰 DB。
 * 判斷方式與 Olympus「更新遊戲清單」頁的 gameListCapableVendorIds() 同一套。
 *
 * - 接入通道：Driver 實作 HasAggregatorChannel 即為該聚合商，未實作一律為直連（`direct`）。
 *   禁止以「同一 Driver 掛幾個 vendor id」推導，見 HasAggregatorChannel 的說明。
 * - gamelist API：Driver 是否實作 HasGameList。
 * - 回調路徑預設：Driver 實作 HasCallbackPath 時的宣告值。
 * - config 沒掛 Driver（或掛的不是存在的 class）的 vendor，三項一律回 null，代表「無從得知」。
 *
 * @knowledge rules/vendor-and-channel-management
 */
class VendorDriverCapabilityService
{
    /** 直連在列表與下拉中的字串值；不是 VendorChannelEnum 的成員 */
    public const string DIRECT = 'direct';

    /**
     * vendor 對應的 Driver class；沒掛或不是存在的 class 回 null
     *
     * @return class-string|null
     */
    public function driverClass(int $vendorId): ?string
    {
        $driverClass = config('vendor.driver.' . $vendorId);

        return is_string($driverClass) && class_exists($driverClass) ? $driverClass : null;
    }

    /**
     * 接入通道：聚合商對外名稱，直連回 `direct`，沒掛 Driver 回 null
     */
    public function channel(int $vendorId): ?string
    {
        $driverClass = $this->driverClass($vendorId);

        if ($driverClass === null) {
            return null;
        }

        return $this->channelOfDriver($driverClass);
    }

    /**
     * 是否具備 gamelist API；沒掛 Driver 回 null
     */
    public function hasGameList(int $vendorId): ?bool
    {
        $driverClass = $this->driverClass($vendorId);

        if ($driverClass === null) {
            return null;
        }

        return is_a($driverClass, HasGameList::class, true);
    }

    /**
     * Driver 宣告的預設回調路徑後綴；未宣告或沒掛 Driver 回 null
     */
    public function defaultCallbackPath(int $vendorId): ?string
    {
        $driverClass = $this->driverClass($vendorId);

        if ($driverClass === null || !is_a($driverClass, HasCallbackPath::class, true)) {
            return null;
        }

        return $driverClass::defaultCallbackPath();
    }

    /**
     * config 內實際出現過的接入通道，供列表篩選下拉：direct 排第一、去重，其餘依 VendorChannelEnum 宣告順序
     *
     * @return list<string>
     */
    public function availableChannels(): array
    {
        $present = [];

        foreach ((array) config('vendor.driver', []) as $driverClass) {
            if (is_string($driverClass) && class_exists($driverClass)) {
                $present[$this->channelOfDriver($driverClass)] = true;
            }
        }

        $ordered = [self::DIRECT, ...array_map(
            static fn (VendorChannelEnum $channel): string => $channel->value,
            VendorChannelEnum::cases(),
        )];

        return array_values(array_filter(
            $ordered,
            static fn (string $channel): bool => isset($present[$channel]),
        ));
    }

    /**
     * @param  class-string  $driverClass
     */
    private function channelOfDriver(string $driverClass): string
    {
        if (is_a($driverClass, HasAggregatorChannel::class, true)) {
            return $driverClass::aggregatorChannel()->value;
        }

        return self::DIRECT;
    }
}
