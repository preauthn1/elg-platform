<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Database\Seeders\LanguageSeeder;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/SyncOrderTypeNamesCashFlowIdTest.php
 *
 * 驗證 SyncCashFlowsCommand::syncOrderTypeNames() 新建 order_type_names 時帶入
 * $orderType->cash_flow_id（denormalize 來源）。因該方法為 protected 且整支
 * cash-flow:sync 走完整 Data 檔流程過重，改以 Reflection 直接呼叫該方法單元測試。
 */
class SyncOrderTypeNamesCashFlowIdTest extends TestCase
{
    private const CF = 99803;

    private const LANGUAGE_ID = 1; // en，見 LanguageSeeder

    public function test_creates_order_type_name_with_order_type_cash_flow_id(): void
    {
        $this->seed(LanguageSeeder::class);

        $orderType = OrderType::create([
            'cash_flow_id' => self::CF, 'currency_id' => 1, 'name' => 'x', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);

        $command = new SyncCashFlowsCommand;
        $command->setLaravel($this->app);

        // syncOrderTypeNames 內部呼叫 $this->line()/$this->warn()，需要一個真的 output
        // 才不會對 null 呼叫方法；不需要真的觀察輸出內容。
        $outputProperty = new \ReflectionProperty($command, 'output');
        $outputProperty->setAccessible(true);
        $outputProperty->setValue($command, new BufferedOutput);

        $stats = [
            'order_type_name_inserted' => 0,
            'order_type_name_updated' => 0,
            'order_type_name_skipped' => 0,
        ];

        $method = new ReflectionMethod($command, 'syncOrderTypeNames');
        $method->setAccessible(true);

        $args = [$orderType, ['en' => '英文名'], &$stats];
        $method->invokeArgs($command, $args);

        $created = OrderTypeName::query()
            ->where('type_id', $orderType->id)
            ->where('language_id', self::LANGUAGE_ID)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame('英文名', $created->name);
        $this->assertSame(self::CF, $created->cash_flow_id);
        $this->assertSame(1, $stats['order_type_name_inserted']);
    }
}
