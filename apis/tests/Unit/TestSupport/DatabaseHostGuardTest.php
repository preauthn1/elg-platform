<?php

declare(strict_types=1);

namespace Tests\Unit\TestSupport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TestCase 的 DB 座標防呆：只放行本機／容器內的資料庫。
 *
 * php artisan test tests/Unit/TestSupport/DatabaseHostGuardTest.php
 */
class DatabaseHostGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hostProvider(): array
    {
        return [
            '容器服務名' => ['mysql', true],
            '含連字號的服務名' => ['php84-mysql', true],
            'localhost' => ['localhost', true],
            '完整網域' => ['db.test.local', true],
            'loopback v4' => ['0.0.0.0', true],
            'loopback v4 其他位址' => ['0.0.0.0', true],
            'loopback v6' => ['::1', true],
            '內網 IP' => ['0.0.0.0', false],
            '私有網段 IP' => ['0.0.0.0', false],
            '公網 IP' => ['0.0.0.0', false],
            '空字串' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('hostProvider')]
    public function test_only_local_database_hosts_are_allowed(string $host, bool $expected): void
    {
        $method = new ReflectionMethod($this, 'isLocalDatabaseHost');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this, $host), "host: {$host}");
    }

    /**
     * 目前測試環境本身必須通過防呆——否則整組測試根本不該跑起來
     */
    #[Test]
    public function test_current_test_connection_passes_the_guard(): void
    {
        $connection = config('database.default');
        $method = new ReflectionMethod($this, 'isLocalDatabaseHost');
        $method->setAccessible(true);

        foreach ([
            config("database.connections.{$connection}.read.host"),
            config("database.connections.{$connection}.write.host"),
        ] as $host) {
            if ($host === null) {
                continue;
            }

            $this->assertTrue($method->invoke($this, (string) $host), "host: {$host}");
        }
    }
}
