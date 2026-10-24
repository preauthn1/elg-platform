<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\NewbieChallengeConfig;
use App\Models\Vendor;
use App\Services\NewbieChallengeService;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use Tests\TestCase;

/**
 * NewbieChallengeService 測試
 *
 * php artisan test tests/Unit/Services/NewbieChallengeServiceTest.php
 */
class NewbieChallengeServiceTest extends TestCase
{
    private function resolveEffectiveVendorIds(?array $allowedVendorIds, ?array $excludedVendorIds, array $vendorPool): ?array
    {
        $service = app(NewbieChallengeService::class);
        $method = new ReflectionMethod($service, 'resolveEffectiveVendorIds');

        return $method->invoke($service, $allowedVendorIds, $excludedVendorIds, $vendorPool);
    }

    private function activeVendorIds(bool $isFreeOnly = false): array
    {
        $service = app(NewbieChallengeService::class);
        $method = new ReflectionMethod($service, 'activeVendorIds');

        return $method->invoke($service, $isFreeOnly);
    }

    #[TestDox('限制清單有值時直接採用，不理會排除清單與候選池')]
    public function test_allowed_takes_precedence_over_excluded(): void
    {
        $result = $this->resolveEffectiveVendorIds([214, 223], [999], [1, 2, 3]);

        $this->assertSame([214, 223], $result);
    }

    #[TestDox('限制與排除皆空時回傳 null（不限制）')]
    public function test_both_empty_returns_null(): void
    {
        $this->assertNull($this->resolveEffectiveVendorIds(null, null, [1, 2, 3]));
        $this->assertNull($this->resolveEffectiveVendorIds([], [], [1, 2, 3]));
    }

    #[TestDox('只有排除清單時換算成候選池扣掉排除清單')]
    public function test_excluded_resolves_to_complement_of_pool(): void
    {
        $result = $this->resolveEffectiveVendorIds(null, [2], [1, 2, 3]);

        $this->assertSame([1, 3], array_values($result));
    }

    #[TestDox('isFreeOnly=true 的候選池只含免盤費且啟用中的遊戲商')]
    public function test_active_vendor_ids_free_only_pool(): void
    {
        $free = Vendor::factory()->create(['status' => 1, 'is_free' => 1]);
        $normal = Vendor::factory()->create(['status' => 1, 'is_free' => 0]);
        $disabledFree = Vendor::factory()->create(['status' => 0, 'is_free' => 1]);

        $pool = $this->activeVendorIds(isFreeOnly: true);

        $this->assertContains($free->id, $pool);
        $this->assertNotContains($normal->id, $pool);
        $this->assertNotContains($disabledFree->id, $pool);
    }

    #[TestDox('isFreeOnly=false 的候選池涵蓋全部啟用中遊戲商')]
    public function test_active_vendor_ids_all_pool(): void
    {
        $free = Vendor::factory()->create(['status' => 1, 'is_free' => 1]);
        $normal = Vendor::factory()->create(['status' => 1, 'is_free' => 0]);
        $disabled = Vendor::factory()->create(['status' => 0, 'is_free' => 0]);

        $pool = $this->activeVendorIds();

        $this->assertContains($free->id, $pool);
        $this->assertContains($normal->id, $pool);
        $this->assertNotContains($disabled->id, $pool);
    }

    #[TestDox('resolveAllowedVendorIds()：限制與排除皆空時展開成完整免盤費候選池，而非回傳 null 導致 whereIn(null) 炸掉')]
    public function test_resolve_allowed_vendor_ids_expands_null_to_full_pool(): void
    {
        $free = Vendor::factory()->create(['status' => 1, 'is_free' => 1]);
        Vendor::factory()->create(['status' => 1, 'is_free' => 0]);

        $config = new NewbieChallengeConfig(['allowed_vendor_ids' => null, 'excluded_vendor_ids' => null]);

        $result = app(NewbieChallengeService::class)->resolveAllowedVendorIds($config);

        $this->assertContains($free->id, $result);
    }

    #[TestDox('resolveAllowedVendorIds()：只有排除清單時換算成候選池扣掉排除清單')]
    public function test_resolve_allowed_vendor_ids_with_exclusion(): void
    {
        $free = Vendor::factory()->create(['status' => 1, 'is_free' => 1]);
        $excludedFree = Vendor::factory()->create(['status' => 1, 'is_free' => 1]);

        $config = new NewbieChallengeConfig(['allowed_vendor_ids' => null, 'excluded_vendor_ids' => [$excludedFree->id]]);

        $result = app(NewbieChallengeService::class)->resolveAllowedVendorIds($config);

        $this->assertContains($free->id, $result);
        $this->assertNotContains($excludedFree->id, $result);
    }
}
