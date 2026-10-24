<?php

declare(strict_types=1);

namespace Tests\Fixtures\Payment;

use App\Models\AgentCashFlow;
use App\Services\Payment\Traits\WithdrawalPayoutTrait;

/**
 * 只用 WithdrawalPayoutTrait 的最小測試替身，暴露 protected 的 convertPayoutBankCode()。
 *
 * 共用於：
 * - ConvertPayoutBankCodeTest（雙路徑本身）
 * - BankRepairServiceTest（merge 後真的走一次代付查表鏈）
 */
class PayoutBankCodeHarness
{
    use WithdrawalPayoutTrait;

    public AgentCashFlow $agentCashFlow;

    /** @var array<string, mixed> */
    public array $info = [];

    public function __construct(private readonly string $driverName) {}

    public function call(string $bankCode): string
    {
        return $this->convertPayoutBankCode($bankCode);
    }

    protected function getDriverName(): string
    {
        return $this->driverName;
    }
}
