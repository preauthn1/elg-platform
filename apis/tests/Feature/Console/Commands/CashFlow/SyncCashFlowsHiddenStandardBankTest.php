<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Enums\WithdrawalTypeEnum;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsHiddenStandardBankTest.php
 *
 * 金流商碼表裡屬於只寫進 standard_banks 條目（sync_visible=false）的名稱，cash-flow:sync 要照低信心度跳過：
 * 不建平台銀行、不寫 cash_flow_banks。
 *
 * 背景：TopPay 的 Kalbar 一般戶 123 排在 Kalbar UUS 1230 前面，UUS 名稱落到一般戶時，後寫的 1230
 * 蓋掉 123；Kaltim UUS 的標準名和平台銀行名稱對不上，會新建一列重複的平台銀行。
 */
class SyncCashFlowsHiddenStandardBankTest extends TestCase
{
    /** 測試用的識別碼，取高位數避開測試庫既有資料 */
    private const int CASH_FLOW_ID = 999101;

    private const int CURRENCY_ID = 999103;

    public function test_hidden_names_are_skipped_without_touching_banks_or_relations(): void
    {
        $banksBefore = Bank::query()->count();

        $stats = $this->process([
            ['name' => '(IDR) - BPD Kalimantan Barat UUS', 'code' => '1230'],
            ['name' => '(IDR) - BPD Kalimantan Timur UUS', 'code' => '1240'],
        ]);

        $this->assertSame(2, $stats['bank_skipped']);
        $this->assertSame(['1230', '1240'], array_column($stats['banks_low_confidence'], 'code'));
        $this->assertSame($banksBefore, Bank::query()->count());
        $this->assertSame(0, CashFlowBank::query()->where('cash_flow_id', self::CASH_FLOW_ID)->count());
    }

    /**
     * @param  list<array{name: string, code: string}>  $banks
     * @return array<string, mixed>
     */
    private function process(array $banks): array
    {
        $command = $this->app->make(SyncCashFlowsCommand::class);
        // 跳過分支會呼叫 warn()，命令物件沒有輸出時會直接拋錯，這裡補一個緩衝輸出
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $cashFlow = new CashFlow;
        $cashFlow->id = self::CASH_FLOW_ID;

        $method = new ReflectionMethod(SyncCashFlowsCommand::class, 'processSingleWithdrawalBank');
        $stats = ['bank_skipped' => 0, 'banks_low_confidence' => []];

        foreach ($banks as $bankData) {
            $args = [$cashFlow, ['currency' => 'IDR'], $bankData, WithdrawalTypeEnum::BANK, self::CURRENCY_ID];
            $args[] = &$stats;
            $method->invokeArgs($command, $args);
        }

        return $stats;
    }
}
