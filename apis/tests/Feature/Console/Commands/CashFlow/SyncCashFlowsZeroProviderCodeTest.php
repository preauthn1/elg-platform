<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsZeroProviderCodeTest.php
 *
 * 驗證代付銀行的代碼為字串 "0" 時不會被當成空值略過，而真正的空字串仍然要略過。
 * 背景：原實作用 empty() 判空，PHP 會把 "0" 判成空，於是 PayEssence 的
 * USDT(TRC20)／USDT(ERC20)（兩筆代碼都是 "0"）永遠建不起銀行對應。
 */
class SyncCashFlowsZeroProviderCodeTest extends TestCase
{
    public function test_zero_provider_code_is_accepted(): void
    {
        $result = $this->extractWithdrawalBankInput(['name' => 'USDT(TRC20)', 'code' => '0']);

        $this->assertSame(['USDT(TRC20)', '0'], $result);
    }

    public function test_zero_bank_name_is_accepted(): void
    {
        // 名稱同樣不可用 empty() 判空，否則名為 "0" 的銀行也會被略過
        $result = $this->extractWithdrawalBankInput(['name' => '0', 'code' => 'ZERO']);

        $this->assertSame(['0', 'ZERO'], $result);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $result = $this->extractWithdrawalBankInput(['name' => '  USDT(ERC20) ', 'code' => ' 0 ']);

        $this->assertSame(['USDT(ERC20)', '0'], $result);
    }

    public function test_blank_name_or_code_is_still_skipped(): void
    {
        // 另一個方向也要鎖住：真正的空白仍然略過
        $this->assertNull($this->extractWithdrawalBankInput(['name' => 'Some Bank', 'code' => '   ']));
        $this->assertNull($this->extractWithdrawalBankInput(['name' => '  ', 'code' => 'ABC']));
        $this->assertNull($this->extractWithdrawalBankInput(['name' => 'Some Bank']));
    }

    /**
     * @param  array<string, string>  $bankData
     * @return array{0: string, 1: string}|null
     */
    private function extractWithdrawalBankInput(array $bankData): ?array
    {
        $command = $this->app->make(SyncCashFlowsCommand::class);
        // 略過分支會呼叫 warn()，命令物件沒有輸出時會直接拋錯，這裡補一個緩衝輸出
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = new ReflectionMethod(SyncCashFlowsCommand::class, 'extractWithdrawalBankInput');

        return $method->invoke($command, $bankData);
    }
}
