<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Models\Bank;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SyncCashFlowsCommand::findPlatformBankByName 只認 active canonical（status=1）。
 * 避免 sync 鎖定已下架的同名舊 row，使 cash_flow 關聯黏在舊 bank_code。
 */
class SyncCashFlowsFindPlatformBankTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    private function makeBank(string $name, string $code, int $status): Bank
    {
        return Bank::create([
            'agent_group_id' => 0, 'type' => 1, 'currency_id' => 1,
            'name' => $name, 'code' => $code, 'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0, 'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function invokeFindPlatformBank(int $currencyId, string $name): ?Bank
    {
        $command = $this->app->make(SyncCashFlowsCommand::class);
        $method = new ReflectionMethod(SyncCashFlowsCommand::class, 'findPlatformBankByName');

        return $method->invoke($command, $currencyId, $name);
    }

    public function test_find_platform_bank_skips_inactive_same_name_row(): void
    {
        // 同精確名：status=0 舊列 id 較小、status=1 canonical id 較大 → 只認 active canonical
        $this->makeBank(name: '(MYR) - Bank Islam', code: 'BIMB_OLD', status: 0);
        $new = $this->makeBank(name: '(MYR) - Bank Islam', code: 'MYR0059', status: 1);

        $found = $this->invokeFindPlatformBank(1, '(MYR) - Bank Islam');

        $this->assertNotNull($found);
        $this->assertSame($new->id, $found->id);
    }
}
