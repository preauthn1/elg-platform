<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Redis;

use App\Console\Commands\Redis\AnalyzeRedisMemoryCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * redis:analyze-memory 的前綴聚合規則。
 *
 * php artisan test tests/Unit/Console/Commands/Redis/AnalyzeRedisMemoryCommandTest.php --compact
 */
class AnalyzeRedisMemoryCommandTest extends TestCase
{
    #[TestDox('前綴取到指定層數，還有下層時補 :* 表示這一類底下還有東西')]
    #[DataProvider('prefixCases')]
    public function test_prefix_of(string $key, int $depth, string $expected): void
    {
        $this->assertSame($expected, AnalyzeRedisMemoryCommand::prefixOf($key, $depth));
    }

    #[TestDox('SCAN 的 COUNT 夾回合法範圍：給到百萬會把不阻塞的 SCAN 變成阻塞操作')]
    #[DataProvider('scanCountCases')]
    public function test_resolve_scan_count(int $requested, int $expected): void
    {
        $this->assertSame($expected, AnalyzeRedisMemoryCommand::resolveScanCount($requested));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function scanCountCases(): array
    {
        return [
            '正常值照用' => [1000, 1000],
            '超過上限夾回' => [999999, 10000],
            '低於下限夾回' => [1, 100],
            '零' => [0, 100],
            '負數' => [-5, 100],
            '剛好上限' => [10000, 10000],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function prefixCases(): array
    {
        return [
            '取兩層' => ['vendor_account_bind:12:906965', 2, 'vendor_account_bind:12:*'],
            '取一層' => ['vendor_account_bind:12:906965', 1, 'vendor_account_bind:*'],
            // 段數不足時整個 key 自成一類，不要補出一個不存在的下層
            '段數剛好等於層數' => ['laravel:cache', 2, 'laravel:cache'],
            '段數少於層數' => ['heartbeat', 3, 'heartbeat'],
            '沒有冒號' => ['someRandomKey', 2, 'someRandomKey'],
            '層數比實際多一層' => ['a:b:c', 3, 'a:b:c'],
            '層數比實際少一層' => ['a:b:c', 2, 'a:b:*'],
        ];
    }
}
