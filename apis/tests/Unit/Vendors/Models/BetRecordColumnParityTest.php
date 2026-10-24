<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\Models;

use App\Vendors\Models\AbstractBetRecord;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * BetRecord 的「金額存取器」與「金額欄位名」必須成對維護。
 *
 * getBet / getValidBet / getWinLose 是單筆存取器（供逐筆累加），
 * getBetColumn / getValidBetColumn / getWinLoseColumn 是欄位名（供 SQL SUM）。
 * 只改一邊會讓對帳查詢在該遊戲商上算出錯的數字，故以測試把關。
 *
 * php artisan test tests/Unit/Vendors/Models/BetRecordColumnParityTest.php
 */
class BetRecordColumnParityTest extends TestCase
{
    /** 存取器 => 對應的欄位名 property */
    private const array PAIRS = [
        'getBet' => 'betColumn',
        'getValidBet' => 'validBetColumn',
        'getWinLose' => 'winLoseColumn',
        'getWin' => 'winColumn',
        'getRollingChip' => 'rollingChipColumn',
        'getJackpot' => 'jackpotColumn',
        'getBonus' => 'bonusColumn',
        'getBetId' => 'betIdColumn',
        'getPayoutTimeColumn' => 'payoutTimeColumn',
        'getBetTimeColumn' => 'betTimeColumn',
        'getVendorUsernameColumn' => 'vendorUsernameColumn',
    ];

    /** 欄位名 property => 取值方法 */
    private const array COLUMN_GETTERS = [
        'betColumn' => 'getBetColumn',
        'validBetColumn' => 'getValidBetColumn',
        'winLoseColumn' => 'getWinLoseColumn',
        'winColumn' => 'getWinColumn',
        'rollingChipColumn' => 'getRollingChipColumn',
        'jackpotColumn' => 'getJackpotColumn',
        'bonusColumn' => 'getBonusColumn',
        'betIdColumn' => 'getBetIdColumn',
        'payoutTimeColumn' => 'getPayoutTimeColumn',
        'betTimeColumn' => 'getBetTimeColumn',
        'vendorUsernameColumn' => 'getVendorUsernameColumn',
    ];

    /**
     * @return array<int, AbstractBetRecord>
     */
    private function models(): array
    {
        $models = [];

        foreach (glob(app_path('Vendors/Models/*BetRecord.php')) ?: [] as $file) {
            $class = 'App\\Vendors\\Models\\' . basename($file, '.php');

            if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = new $class;
        }

        return $models;
    }

    /**
     * 每支 model 都必須自己宣告三個金額欄位名——基底刻意不給預設值，
     * 欄位名各家不同（bet / bet_amount / betPoints / betCoin / TotalLoss…），
     * 猜錯會算錯帳。null 是合法值，代表無單一欄位可 SUM。
     */
    #[Test]
    public function test_every_model_declares_its_amount_columns(): void
    {
        $missing = [];

        foreach ($this->models() as $model) {
            foreach (array_values(self::PAIRS) as $property) {
                $owner = (new ReflectionProperty($model, $property))->getDeclaringClass()->getName();

                if ($owner === AbstractBetRecord::class) {
                    $missing[] = sprintf('%s 沒宣告 $%s', $model::class, $property);
                }
            }
        }

        $this->assertSame([], $missing, PHP_EOL . implode(PHP_EOL, $missing));
    }

    /**
     * override 存取器就必須一起宣告對應的欄位名 property。
     */
    #[Test]
    public function test_overridden_accessors_must_declare_their_column(): void
    {
        $missing = [];

        foreach ($this->models() as $model) {
            foreach (self::PAIRS as $accessor => $property) {
                $accessorOwner = (new ReflectionMethod($model, $accessor))->getDeclaringClass()->getName();
                $propertyOwner = (new ReflectionProperty($model, $property))->getDeclaringClass()->getName();

                if ($accessorOwner !== AbstractBetRecord::class && $propertyOwner === AbstractBetRecord::class) {
                    $missing[] = sprintf('%s::%s() 有 override 但沒宣告 $%s', $model::class, $accessor, $property);
                }
            }
        }

        $this->assertSame([], $missing, PHP_EOL . implode(PHP_EOL, $missing));
    }

    /**
     * 宣告出來的欄位名必須真的存在於該資料表（null 代表計算得出，跳過）。
     */
    #[Test]
    public function test_declared_columns_exist_in_their_table(): void
    {
        $invalid = [];
        $checked = 0;

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach (array_values(self::PAIRS) as $property) {
                $column = $model->{self::COLUMN_GETTERS[$property]}();

                if ($column === null) {
                    continue;
                }

                $checked++;

                if (!Schema::hasColumn($table, $column)) {
                    $invalid[] = sprintf('%s 的 $%s = %s，但 %s 沒有這個欄位', $model::class, $property, $column, $table);
                }
            }
        }

        $this->assertGreaterThan(0, $checked, '沒有檢查到任何欄位，glob 或 namespace 可能有問題');
        $this->assertSame([], $invalid, PHP_EOL . implode(PHP_EOL, $invalid));
    }
}
