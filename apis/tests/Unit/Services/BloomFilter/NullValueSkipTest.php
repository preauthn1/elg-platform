<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BloomFilter;

use App\Services\BloomFilter\MemberFirstNameFilter;
use App\Services\BloomFilter\MemberMobileFilter;
use App\Services\BloomFilter\MemberRegisterIpFilter;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * 會員的 first_name / mobile / register_ip 允許為 null，
 * 丟進 BloomFilter 時必須略過而不是拋 TypeError（md5(null) / long2ip(null)）。
 *
 * php artisan test tests/Unit/Services/BloomFilter/NullValueSkipTest.php
 */
class NullValueSkipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 全部 null 時不該碰到 Redis；有碰到就是沒略過
        $connection = Mockery::mock();
        $connection->shouldNotReceive('getbit');
        $connection->shouldNotReceive('setbit');
        Redis::shouldReceive('connection')->with('db_15')->andReturn($connection);
    }

    public function test_first_name_filter_skips_null(): void
    {
        $this->assertSame([], (new MemberFirstNameFilter)->getDuplicateData([null]));
    }

    public function test_mobile_filter_skips_null(): void
    {
        $this->assertSame([], (new MemberMobileFilter)->getDuplicateData([null]));
    }

    public function test_register_ip_filter_skips_null(): void
    {
        $this->assertSame([], (new MemberRegisterIpFilter)->getDuplicateData([null]));
    }
}
