<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\Models;

use App\Vendors\Models\AbstractAccount;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Account 的欄位名宣告把關。
 *
 * $vendorUsernameColumn 是玩家在遊戲商那邊的帳號欄位：代理後台查帳號、重設密碼，
 * 以及拉注單轉 TBR 解析會員都靠它。欄位名各家不同（SCR 是 player_id、gw99 是
 * username_modified），權威來源是 database/procedures/SP_transfer_bet_record_*.sql 的 JOIN 條件。
 *
 * php artisan test tests/Unit/Vendors/Models/AccountColumnParityTest.php
 */
class AccountColumnParityTest extends TestCase
{
    /** 欄位名 property => 取值方法 */
    private const array COLUMN_GETTERS = [
        'vendorUsernameColumn' => 'getVendorUsernameColumn',
    ];

    /**
     * @return array<int, AbstractAccount>
     */
    private function models(): array
    {
        $models = [];

        foreach (glob(app_path('Vendors/Models/*Account.php')) ?: [] as $file) {
            $class = 'App\\Vendors\\Models\\' . basename($file, '.php');

            if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = new $class;
        }

        return $models;
    }

    /**
     * 每支 model 都要自己宣告帳號欄位：基底不給預設也不猜，沒宣告就是 null，
     * 拉注單解析會員時直接拋錯，不會拿錯欄位默默查不到人。
     */
    #[Test]
    public function test_every_model_declares_its_username_column(): void
    {
        $missing = [];

        foreach ($this->models() as $model) {
            $owner = (new ReflectionProperty($model, 'vendorUsernameColumn'))->getDeclaringClass()->getName();

            if ($owner === AbstractAccount::class) {
                $missing[] = sprintf('%s 沒宣告 $vendorUsernameColumn', $model::class);
            }
        }

        $this->assertSame([], $missing, PHP_EOL . implode(PHP_EOL, $missing));
    }

    /**
     * 宣告出來的欄位名必須真的存在於該資料表。
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

            foreach (self::COLUMN_GETTERS as $property => $getter) {
                $column = $model->{$getter}();

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
