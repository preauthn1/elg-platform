<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Admin\Pages\Operations\SchemaOperation;
use App\Services\SchemaOperationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 加欄位表單「型別選單＋長度＋UNSIGNED」組成的型別字串，必須是 SchemaOperationService 白名單接受的形狀。
 *
 * php artisan test tests/Unit/Filament/SchemaOperationComposeTypeTest.php
 */
class SchemaOperationComposeTypeTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string}> */
    public static function typeCases(): array
    {
        return [
            'varchar 帶長度' => [['type' => 'VARCHAR', 'length' => '100'], 'VARCHAR(100)'],
            'char 帶長度' => [['type' => 'CHAR', 'length' => '32'], 'CHAR(32)'],
            'decimal 精度,小數位（容忍空白）' => [['type' => 'DECIMAL', 'length' => '18, 4'], 'DECIMAL(18,4)'],
            'int unsigned' => [['type' => 'INT', 'unsigned' => true], 'INT UNSIGNED'],
            'bigint 不勾 unsigned' => [['type' => 'BIGINT', 'unsigned' => false], 'BIGINT'],
            'datetime 忽略多填的長度與 unsigned' => [['type' => 'DATETIME', 'length' => '6', 'unsigned' => true], 'DATETIME'],
            'json' => [['type' => 'JSON'], 'JSON'],
        ];
    }

    #[DataProvider('typeCases')]
    public function test_compose_type_matches_service_whitelist(array $data, string $expected): void
    {
        $type = SchemaOperation::composeType($data);

        $this->assertSame($expected, $type);

        // 組出來的字串要能直接進 buildAddColumnSql，不被白名單擋下
        $sql = SchemaOperationService::buildAddColumnSql('orders', 'payment_cost', $type, false, null, null);
        $this->assertStringContainsString("`payment_cost` {$expected}", $sql);
    }
}
