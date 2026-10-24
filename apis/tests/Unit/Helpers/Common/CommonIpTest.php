<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common IP 解析測試
 *
 * php artisan test tests/Unit/Helpers/Common/CommonIpTest.php
 */
class CommonIpTest extends TestCase
{
    public function test_extract_first_ip_trims_and_takes_first(): void
    {
        $this->assertSame('0.0.0.0', Common::extractFirstIp('  0.0.0.0  , 0.0.0.0  '));
        $this->assertSame('0.0.0.0', Common::extractFirstIp('0.0.0.0'));
    }

    public function test_extract_first_ip_skips_empty_segments(): void
    {
        $this->assertSame('0.0.0.0', Common::extractFirstIp(', , 0.0.0.0'));
    }

    public function test_extract_first_ip_ipv6_trims_and_takes_first(): void
    {
        $this->assertSame('2001:db8::1', Common::extractFirstIp('2001:db8::1'));
        $this->assertSame(
            '2001:db8::1',
            Common::extractFirstIp('  2001:db8::1  , fe80::1  '),
        );
    }

    public function test_extract_first_ip_ipv6_skips_empty_segments(): void
    {
        $this->assertSame('2001:db8::2', Common::extractFirstIp(', , 2001:db8::2'));
    }
}
