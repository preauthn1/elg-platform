<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use App\Console\Commands\CashFlow\Maintenance\FixCashFlowOrphans;
use App\Console\Commands\CashFlow\Maintenance\NormalizeBanksByEnvironmentMainCurrency;
use App\Console\Commands\Vendor\AddVendorInfo;
use App\Console\Commands\Vendor\InsertAgentVendor;
use App\Console\Commands\Vendor\PlayTech\CheckDailyBetRecord;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 驗證 MR 1537 對 6 個 Console Command 的 case-insensitive 環境檢查修正
 * （原 8 個；舊版單筆 AddAgentCashFlow 併入 by-channel 版更名、fix:split 遷移補丁併入
 *  agent:add-agent-cash-flow 的 --migrate-single，各減一）：
 *
 *   Case 1：小寫 dev 連線（mysql / dev / testing）       → 直接放行
 *   Case 2：大小寫變異（Dev / MySQL / TESTING / Mysql）   → strtolower 後仍放行（修正前會誤判）
 *   Case 3：正式白標連線（TPower / VWorld / ...）          → 觸發二次確認
 *
 * 結構差異：
 *   - 3 個 CashFlow command 把判斷封裝在 `confirmExecutionEnvironment()`
 *   - CheckDailyBetRecord 把判斷封裝在 `confirmEnvironment()`（方法名不同）
 *   - AddVendorInfo / InsertAgentVendor 把判斷 inline 在 `handle()`，前置另有「測試模式」prompt
 *
 * 對應策略：
 *   - Case 1/2 對「4 個有方法的 command」用 Reflection 直接呼叫該方法驗證
 *   - Case 3 對「4 個有方法的 command」用 anonymous subclass 覆寫 confirm/warn/info 驗證觸發
 *   - Case 3 對「2 個 inline command」走 artisan + expectsConfirmation 驗證
 *   - Case 1/2 對「2 個 inline command」由 `test_all_commands_apply_case_insensitive_check`
 *     結構性驗證 `if (!in_array(strtolower(...)))` 字面包覆判斷（lowercase / variant 都會被 strtolower 後落入 in_array → 不進入確認分支）
 */
class ConfirmExecutionEnvironmentTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = (string) config('database.default');
    }

    protected function tearDown(): void
    {
        // 還原 database.default，避免 DatabaseTransactions tearDown 抓不到測試中改成的虛擬連線
        config(['database.default' => $this->originalDefaultConnection]);
        parent::tearDown();
    }

    private const COMMANDS_WITH_METHOD = [
        AddAgentCashFlow::class => 'confirmExecutionEnvironment',
        FixCashFlowOrphans::class => 'confirmExecutionEnvironment',
        NormalizeBanksByEnvironmentMainCurrency::class => 'confirmExecutionEnvironment',
        CheckDailyBetRecord::class => 'confirmEnvironment',
    ];

    private const ALL_COMMANDS = [
        AddAgentCashFlow::class,
        FixCashFlowOrphans::class,
        NormalizeBanksByEnvironmentMainCurrency::class,
        CheckDailyBetRecord::class,
        AddVendorInfo::class,
        InsertAgentVendor::class,
    ];

    public static function lowercaseDevConnectionsProvider(): array
    {
        return [
            'mysql' => ['mysql'],
            'dev' => ['dev'],
            'testing' => ['testing'],
        ];
    }

    public static function caseVariantDevConnectionsProvider(): array
    {
        return [
            'Dev' => ['Dev'],
            'MySQL' => ['MySQL'],
            'TESTING' => ['TESTING'],
            'Mysql' => ['Mysql'],
        ];
    }

    public static function productionConnectionsProvider(): array
    {
        return [
            'TPower' => ['TPower'],
            'VWorld' => ['VWorld'],
            'Yes2Win' => ['Yes2Win'],
            'Alfawin77' => ['Alfawin77'],
            'Me77' => ['Me77'],
            'VPlus33' => ['VPlus33'],
        ];
    }

    #[DataProvider('lowercaseDevConnectionsProvider')]
    public function test_case1_lowercase_dev_connection_skips_confirmation_in_method_commands(string $connection): void
    {
        config(['database.default' => $connection]);

        foreach (self::COMMANDS_WITH_METHOD as $class => $method) {
            $command = new $class;
            $this->assertTrue(
                (new ReflectionMethod($command, $method))->invoke($command),
                sprintf('%s::%s() with connection=%s 應直接放行', $class, $method, $connection)
            );
        }
    }

    #[DataProvider('caseVariantDevConnectionsProvider')]
    public function test_case2_case_variant_dev_connection_skips_confirmation_in_method_commands(string $connection): void
    {
        config(['database.default' => $connection]);

        foreach (self::COMMANDS_WITH_METHOD as $class => $method) {
            $command = new $class;
            $this->assertTrue(
                (new ReflectionMethod($command, $method))->invoke($command),
                sprintf('%s::%s() with connection=%s 應在 strtolower 後放行（修正前會誤判）', $class, $method, $connection)
            );
        }
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_AddAgentCashFlow(string $connection): void
    {
        config(['database.default' => $connection]);

        $command = new class extends AddAgentCashFlow
        {
            public int $confirmCalls = 0;

            public bool $confirmReturn = false;

            public function confirm($question, $default = false): bool
            {
                $this->confirmCalls++;

                return $this->confirmReturn;
            }

            public function warn($string, $verbosity = null): void {}

            public function info($string, $verbosity = null): void {}
        };

        $this->assertProductionPrompts($command, 'confirmExecutionEnvironment', $connection);
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_FixCashFlowOrphans(string $connection): void
    {
        config(['database.default' => $connection]);

        $command = new class extends FixCashFlowOrphans
        {
            public int $confirmCalls = 0;

            public bool $confirmReturn = false;

            public function confirm($question, $default = false): bool
            {
                $this->confirmCalls++;

                return $this->confirmReturn;
            }

            public function warn($string, $verbosity = null): void {}

            public function info($string, $verbosity = null): void {}
        };

        $this->assertProductionPrompts($command, 'confirmExecutionEnvironment', $connection);
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_NormalizeBanksByEnvironmentMainCurrency(string $connection): void
    {
        config(['database.default' => $connection]);

        $command = new class extends NormalizeBanksByEnvironmentMainCurrency
        {
            public int $confirmCalls = 0;

            public bool $confirmReturn = false;

            public function confirm($question, $default = false): bool
            {
                $this->confirmCalls++;

                return $this->confirmReturn;
            }

            public function warn($string, $verbosity = null): void {}

            public function info($string, $verbosity = null): void {}
        };

        $this->assertProductionPrompts($command, 'confirmExecutionEnvironment', $connection);
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_CheckDailyBetRecord(string $connection): void
    {
        config(['database.default' => $connection]);

        $command = new class extends CheckDailyBetRecord
        {
            public int $confirmCalls = 0;

            public bool $confirmReturn = false;

            public function confirm($question, $default = false): bool
            {
                $this->confirmCalls++;

                return $this->confirmReturn;
            }

            public function warn($string, $verbosity = null): void {}

            public function info($string, $verbosity = null): void {}
        };

        $this->assertProductionPrompts($command, 'confirmEnvironment', $connection);
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_InsertAgentVendor(string $connection): void
    {
        config(['database.default' => $connection]);

        $this->artisan('vendor:insert_agent_vendor', ['vendorId' => 0, 'agentId' => 0, 'vendorInfoId' => 0])
            ->expectsConfirmation('是否啟用測試模式（不會寫入資料）？', 'no')
            ->expectsConfirmation(
                sprintf('目前資料庫連線為 %s，確定要繼續？', $connection),
                'no'
            )
            ->expectsOutputToContain('腳本已終止')
            ->assertExitCode(Command::SUCCESS);
    }

    #[DataProvider('productionConnectionsProvider')]
    public function test_case3_production_in_AddVendorInfo(string $connection): void
    {
        config(['database.default' => $connection]);

        $this->artisan('vendor:add_vendor_info')
            ->expectsConfirmation('是否啟用測試模式（不會寫入資料）？', 'no')
            ->expectsConfirmation(
                sprintf('目前資料庫連線為 %s，確定要繼續？', $connection),
                'no'
            )
            ->expectsOutputToContain('腳本已終止')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_all_commands_apply_case_insensitive_check(): void
    {
        foreach (self::ALL_COMMANDS as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                'strtolower((string)',
                $source,
                sprintf('%s 缺少 strtolower((string) ...) 包覆 → case-insensitive 修正未套用', $class)
            );

            $this->assertStringContainsString(
                "['mysql', 'dev', 'testing']",
                $source,
                sprintf('%s 缺少 dev 白名單 [\'mysql\', \'dev\', \'testing\']', $class)
            );
        }
    }

    /**
     * @param  object  $command  Anonymous subclass instance with confirmCalls/confirmReturn override
     */
    private function assertProductionPrompts(object $command, string $method, string $connection): void
    {
        $reflectionMethod = new ReflectionMethod($command, $method);

        // 使用者拒絕
        $command->confirmReturn = false;
        $command->confirmCalls = 0;
        $denied = $reflectionMethod->invoke($command);
        $this->assertSame(
            1,
            $command->confirmCalls,
            sprintf('%s::%s() with connection=%s 應觸發 1 次 confirm()', get_parent_class($command), $method, $connection)
        );
        $this->assertFalse($denied, '使用者拒絕時應回傳 false');

        // 使用者同意
        $command->confirmReturn = true;
        $command->confirmCalls = 0;
        $accepted = $reflectionMethod->invoke($command);
        $this->assertSame(1, $command->confirmCalls, '第二次呼叫應再觸發 confirm()');
        $this->assertTrue($accepted, '使用者同意時應回傳 true');
    }
}
