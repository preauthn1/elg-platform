<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Enums\Vendor\VendorChannelEnum;
use App\Services\Vendor\VendorDriverCapabilityService;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\HasGameList;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 遊戲商 Driver 能力推導服務測試 target/tickets#4400
 *
 * 核心守護：接入通道由 Driver 宣告（未宣告即直連，不看同一 Driver 掛幾個 vendor id）、
 * gamelist API 看是否實作 HasGameList、回調路徑預設由 HasCallbackPath 宣告；
 * config 沒掛 Driver 的 vendor 三項皆為 null。
 *
 * php artisan test tests/Unit/Services/VendorDriverCapabilityServiceTest.php
 */
class VendorDriverCapabilityServiceTest extends TestCase
{
    /** 測試用 vendor id：取大數避免與真實 config 的 id 混淆（config 整份覆寫，不會撞到） */
    private const int VENDOR_A = 900001;

    private const int VENDOR_B = 900002;

    private const int VENDOR_C = 900003;

    private const int VENDOR_D = 900004;

    private const int VENDOR_E = 900005;

    private VendorDriverCapabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(VendorDriverCapabilityService::class);
    }

    #[TestDox('實作 HasAggregatorChannel 的 driver：channel() 回該聚合商的對外名稱')]
    public function test_channel_returns_declared_aggregator(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => $this->eliteHubDriverClass(),
        ]);

        $this->assertSame(VendorChannelEnum::ELITE_HUB->value, $this->service->channel(self::VENDOR_A));
    }

    #[TestDox('未實作 HasAggregatorChannel 的 driver：channel() 回 direct')]
    public function test_channel_returns_direct_when_not_declared(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => $this->plainDriverClass(),
        ]);

        $this->assertSame('direct', $this->service->channel(self::VENDOR_A));
    }

    #[TestDox('同一個未宣告通道的 driver 掛兩個 id（如 WudiSW 本體＋免盤費商戶）：兩個都是 direct')]
    public function test_shared_driver_without_declaration_is_direct_for_every_id(): void
    {
        $driverClass = $this->plainDriverClass();

        config()->set('vendor.driver', [
            self::VENDOR_A => $driverClass,
            self::VENDOR_B => $driverClass,
        ]);

        $this->assertSame('direct', $this->service->channel(self::VENDOR_A));
        $this->assertSame('direct', $this->service->channel(self::VENDOR_B));
    }

    #[TestDox('config 沒掛 driver 的 vendor：channel／hasGameList／defaultCallbackPath 皆為 null')]
    public function test_unmapped_vendor_returns_null_for_all_capabilities(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => $this->eliteHubDriverClass(),
        ]);

        $this->assertNull($this->service->driverClass(self::VENDOR_B));
        $this->assertNull($this->service->channel(self::VENDOR_B));
        $this->assertNull($this->service->hasGameList(self::VENDOR_B));
        $this->assertNull($this->service->defaultCallbackPath(self::VENDOR_B));
    }

    #[TestDox('config 掛的值不是存在的 class：視同沒掛，三項皆為 null')]
    public function test_non_class_driver_value_is_treated_as_unmapped(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => 'App\\Vendors\\NotExists\\Driver',
        ]);

        $this->assertNull($this->service->driverClass(self::VENDOR_A));
        $this->assertNull($this->service->channel(self::VENDOR_A));
        $this->assertNull($this->service->hasGameList(self::VENDOR_A));
        $this->assertNull($this->service->defaultCallbackPath(self::VENDOR_A));
    }

    #[TestDox('hasGameList()：實作 HasGameList 回 true，沒實作回 false')]
    public function test_has_game_list_follows_contract(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => $this->eliteHubDriverClass(),
            self::VENDOR_B => $this->plainDriverClass(),
        ]);

        $this->assertTrue($this->service->hasGameList(self::VENDOR_A));
        $this->assertFalse($this->service->hasGameList(self::VENDOR_B));
    }

    #[TestDox('defaultCallbackPath()：實作 HasCallbackPath 回宣告值，沒實作回 null')]
    public function test_default_callback_path_follows_contract(): void
    {
        config()->set('vendor.driver', [
            self::VENDOR_A => $this->eliteHubDriverClass(),
            self::VENDOR_B => $this->plainDriverClass(),
        ]);

        $this->assertSame('/external/api/sw/tthsw', $this->service->defaultCallbackPath(self::VENDOR_A));
        $this->assertNull($this->service->defaultCallbackPath(self::VENDOR_B));
    }

    #[TestDox('availableChannels()：direct 排第一、去重，其餘依 VendorChannelEnum 宣告順序')]
    public function test_available_channels_are_unique_and_ordered(): void
    {
        $eliteHub = $this->eliteHubDriverClass();
        $titanHub = $this->titanHubDriverClass();
        $direct = $this->plainDriverClass();

        // 刻意讓 EliteHub 先出現、直連夾在中間，驗證排序不依 config 順序
        config()->set('vendor.driver', [
            self::VENDOR_A => $eliteHub,
            self::VENDOR_B => $direct,
            self::VENDOR_C => $titanHub,
            self::VENDOR_D => $titanHub,
            self::VENDOR_E => $direct,
        ]);

        $this->assertSame(
            ['direct', VendorChannelEnum::TITAN_HUB->value, VendorChannelEnum::ELITE_HUB->value],
            $this->service->availableChannels(),
        );
    }

    /** 經 EliteHub 接入、有 gamelist API、宣告回調後綴的 driver */
    private function eliteHubDriverClass(): string
    {
        return get_class(new class implements HasAggregatorChannel, HasCallbackPath, HasGameList
        {
            public static function aggregatorChannel(): VendorChannelEnum
            {
                return VendorChannelEnum::ELITE_HUB;
            }

            public static function defaultCallbackPath(): string
            {
                return '/external/api/sw/tthsw';
            }

            public function getGameList(GetGameListParamsData $params): GetGameListResultData
            {
                return new GetGameListResultData(gameList: []);
            }
        });
    }

    /** 經 TitanHub 接入的 driver */
    private function titanHubDriverClass(): string
    {
        return get_class(new class implements HasAggregatorChannel
        {
            public static function aggregatorChannel(): VendorChannelEnum
            {
                return VendorChannelEnum::TITAN_HUB;
            }
        });
    }

    /** 什麼契約都沒實作的直連 driver */
    private function plainDriverClass(): string
    {
        return get_class(new class {});
    }
}
