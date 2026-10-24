<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Vendor;

use App\Console\Commands\Vendor\AddVendorInfo;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use ReflectionMethod;
use Tests\TestCase;

class AddVendorInfoTest extends TestCase
{
    public function test_joker_baccarat_sw_account_prefix_must_be_empty(): void
    {
        $vendor = new Vendor;
        $vendor->id = VendorIdEnum::JOKER_BACCARAT_SW->value;
        $vendor->code = 'JokerBaccarat';

        $this->assertSame('', $this->resolveAccountPrefix($vendor, 'custom_prefix'));
        $this->assertSame('', $this->resolveAccountPrefix($vendor, ''));
    }

    public function test_other_vendor_uses_custom_prefix_or_vendor_code(): void
    {
        $vendor = new Vendor;
        $vendor->id = VendorIdEnum::JOKER->value;
        $vendor->code = 'Joker';

        $this->assertSame('custom_prefix', $this->resolveAccountPrefix($vendor, 'custom_prefix'));
        $this->assertSame('joker', $this->resolveAccountPrefix($vendor, ''));
    }

    private function resolveAccountPrefix(Vendor $vendor, string $prefix): string
    {
        return (string) (new ReflectionMethod(AddVendorInfo::class, 'resolveAccountPrefix'))
            ->invoke(new AddVendorInfo, $vendor, $prefix);
    }
}
