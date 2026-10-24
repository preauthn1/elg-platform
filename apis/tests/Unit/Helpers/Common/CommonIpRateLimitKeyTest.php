<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::ipRateLimitKey：限流用的 IP key（工單 4540）。
 *
 * IPv6 一般一個用戶就配到一整段 /64，只截到單一位址的話，換位址就換到新額度。
 *
 * php artisan test tests/Unit/Helpers/Common/CommonIpRateLimitKeyTest.php
 */
class CommonIpRateLimitKeyTest extends TestCase
{
    public function test_ipv6_addresses_in_same_64_share_one_key(): void
    {
        $this->assertSame(
            Common::ipRateLimitKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'),
            Common::ipRateLimitKey('2001:db8:1:2:1:1:1:1'),
        );
    }

    public function test_ipv6_addresses_in_different_64_get_different_keys(): void
    {
        $this->assertNotSame(
            Common::ipRateLimitKey('2001:db8:1:2::1'),
            Common::ipRateLimitKey('2001:db8:1:3::1'),
        );
    }

    public function test_ipv6_key_is_the_64_network(): void
    {
        $this->assertSame('2001:db8:1:2::/64', Common::ipRateLimitKey('2001:DB8:1:2:aaaa:bbbb:cccc:dddd'));
    }

    public function test_ipv4_is_kept_as_is(): void
    {
        $this->assertSame('0.0.0.0', Common::ipRateLimitKey('0.0.0.0'));
    }

    public function test_non_ip_string_is_kept_as_is(): void
    {
        $this->assertSame('0.0.0.0', Common::ipRateLimitKey('0.0.0.0'));
        $this->assertSame('unknown', Common::ipRateLimitKey('unknown'));
    }
}
