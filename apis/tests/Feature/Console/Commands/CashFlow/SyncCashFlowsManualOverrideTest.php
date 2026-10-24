<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\SyncCashFlowsCommand;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsManualOverrideTest.php
 *
 * 驗證「人工覆寫保護」：cash_flow_banks 的列標記過 manual_override_at 之後，
 * cash-flow:sync 不再把 provider_bank_code 覆寫成共用設定檔的值，但其餘欄位照常同步。
 *
 * 背景：三方的銀行代號可能按商戶帳號發放（FPay 實證），單一白標修好的代號會在下一次同步時
 * 被沖回設定檔的舊值、該站代付整條斷掉（target/op_tickets#3174）。
 *
 * ⚠️ 保護範圍刻意只到 provider_bank_code 一個欄位：sync 會先把該金流商該幣別的全部關聯設為
 * status = 0、再把比對得上的重新啟用，所以「整列跳過」會讓被保護的列停在 status = 0，
 * 反而造成代付查無而斷付。下方 test_protected_row_is_still_reactivated 就是鎖住這個行為。
 */
class SyncCashFlowsManualOverrideTest extends TestCase
{
    use DatabaseTransactions;

    /** 測試用的識別碼，取高位數避開測試庫既有資料 */
    private const int CASH_FLOW_ID = 999001;

    private const int BANK_ID = 999002;

    private const int CURRENCY_ID = 999003;

    private const string OVERRIDE_NOTE = 'target/op_tickets#3174 FPay 按商戶帳號換碼';

    #[Group('rules/payout-bank-code-mapping#R-4')]
    public function test_manual_override_row_keeps_its_provider_bank_code(): void
    {
        $relation = $this->makeRelation(providerBankCode: '581', isOverridden: true);

        $this->syncOnce(configProviderBankCode: '105');

        $this->assertSame('581', $relation->refresh()->provider_bank_code);
    }

    #[Group('rules/payout-bank-code-mapping#R-5')]
    public function test_row_without_marker_is_overwritten(): void
    {
        // 反向鎖住：沒有標記的列必須維持原本「與設定檔同步」的行為
        $relation = $this->makeRelation(providerBankCode: '581', isOverridden: false);

        $this->syncOnce(configProviderBankCode: '105');

        $this->assertSame('105', $relation->refresh()->provider_bank_code);
    }

    #[Group('rules/payout-bank-code-mapping#R-4')]
    public function test_protected_row_is_still_reactivated(): void
    {
        // 被保護的列仍要跟著 sync 重新啟用並更新我方銀行代碼，否則會停在 status = 0 造成斷付
        $relation = $this->makeRelation(providerBankCode: '581', isOverridden: true, status: 0, bankCode: 'IDR_OLD');

        $this->syncOnce(configProviderBankCode: '105', bankCode: 'IDR_NEW');

        $relation->refresh();
        $this->assertSame(1, $relation->status);
        $this->assertSame('IDR_NEW', $relation->bank_code);
        $this->assertSame('581', $relation->provider_bank_code);
    }

    #[Group('rules/payout-bank-code-mapping#R-6')]
    public function test_protected_row_is_reported_in_the_sync_log(): void
    {
        $this->makeRelation(providerBankCode: '581', isOverridden: true);

        $bankLog = $this->syncOnce(configProviderBankCode: '105');

        $joined = implode("\n", $bankLog);
        $this->assertStringContainsString('保留人工覆寫值', $joined);
        $this->assertStringContainsString('581', $joined);
        $this->assertStringContainsString(self::OVERRIDE_NOTE, $joined);
    }

    #[Group('rules/payout-bank-code-mapping#R-6')]
    public function test_protected_row_is_not_reported_when_config_matches(): void
    {
        // 設定檔的值本來就與現值相同時沒有「被保護」這件事可通報，不要製造雜訊
        $this->makeRelation(providerBankCode: '581', isOverridden: true);

        $bankLog = $this->syncOnce(configProviderBankCode: '581');

        $this->assertStringNotContainsString('保留人工覆寫值', implode("\n", $bankLog));
    }

    private function makeRelation(
        string $providerBankCode,
        bool $isOverridden,
        int $status = 1,
        string $bankCode = 'IDR_OLD',
    ): CashFlowBank {
        return CashFlowBank::query()->create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => self::CURRENCY_ID,
            'bank_id' => self::BANK_ID,
            'bank_code' => $bankCode,
            'provider_bank_code' => $providerBankCode,
            'status' => $status,
            'manual_override_at' => $isOverridden ? now() : null,
            'manual_override_note' => $isOverridden ? self::OVERRIDE_NOTE : null,
        ]);
    }

    /**
     * 直接呼叫 sync 的關聯建立／更新方法，回傳它寫進去的變更通報。
     *
     * @return array<int, string>
     */
    private function syncOnce(string $configProviderBankCode, string $bankCode = 'IDR_OLD'): array
    {
        $command = $this->app->make(SyncCashFlowsCommand::class);
        // 命令物件沒有輸出時呼叫 warn() 會直接拋錯，補一個緩衝輸出
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $cashFlow = new CashFlow;
        $cashFlow->id = self::CASH_FLOW_ID;

        $bank = new Bank;
        $bank->id = self::BANK_ID;
        $bank->code = $bankCode;

        $stats = ['cash_flow_bank_skipped' => 0, 'cash_flow_bank_inserted' => 0];
        $bankLog = [];

        $args = [$cashFlow, $bank, self::CURRENCY_ID, $configProviderBankCode, 'Test Bank'];
        $args[] = &$stats;
        $args[] = &$bankLog;

        (new ReflectionMethod(SyncCashFlowsCommand::class, 'createCashFlowBankRelation'))
            ->invokeArgs($command, $args);

        return $bankLog;
    }
}
