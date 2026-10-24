<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Enums\Payment\PaymentChannelDirection;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Concerns\NormalizesThirdPartyAmounts;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Data\BalanceResponseData;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;

class Driver extends AbstractDriver implements SupportsPaymentChannelQuery
{
    use NormalizesThirdPartyAmounts;

    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    protected Balance $balance;

    protected BankQuery $bankQuery;

    /** @var list<array<string, mixed>>|null */
    private ?array $bankQueryRows = null;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 代付
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);

        // 擴充
        $this->balance = new Balance($agentCashFlow);
        $this->bankQuery = new BankQuery($agentCashFlow);
    }

    public function balance(): BalanceResponseData
    {
        return $this->balance->handle();
    }

    /**
     * 通道快照（SupportsPaymentChannelQuery）：bankquery 逐列映射。
     * bankquery 沒有商戶開通狀態與餘額——五個商戶層欄位一律 null，不偽造。
     * 同一 Driver 實例內 memoize（request-local），禁止跨 process 快取。
     *
     * ! bankname 進 accountBankName（該通道帳戶的開戶行）而非 supportBankCodes——
     * ! bankquery 不提供「可收付哪些銀行」的清單，兩者語意不可混（見 api.md §8 實測補充）。
     * ! 欄位名以 2026-08-28 實打為準、同時相容廠商文件寫法：
     * ! 限額實回 `bankminamt`／`bankmaxamt`，文件寫的是 `bankminamout`／`bankmaxamout`；
     * ! `banktype` 實回小寫 d／w，文件寫大寫 D／W。兩套都吃，三方改回文件寫法時不會
     * ! 靜默退化成「限額未回傳／方向未標示」。
     */
    public function queryPaymentChannels(): PaymentChannelSnapshot
    {
        $rows = $this->bankQueryRows ??= $this->bankQuery->handle();

        $channels = [];
        foreach ($rows as $row) {
            $iban = $row['iban'] ?? null;
            if (!is_scalar($iban) || (string) $iban === '') {
                continue;
            }

            $bankName = isset($row['bankname']) && is_scalar($row['bankname']) ? trim((string) $row['bankname']) : '';

            $bankType = is_scalar($row['banktype'] ?? null) ? strtolower(trim((string) $row['banktype'])) : '';

            $channels[] = new PaymentChannel(
                name: (string) $iban,
                direction: match ($bankType) {
                    'd' => PaymentChannelDirection::Deposit,
                    'w' => PaymentChannelDirection::Payout,
                    default => null,
                },
                status: null,
                minAmount: $this->normalizeAmount($row['bankminamt'] ?? $row['bankminamout'] ?? null),
                maxAmount: $this->normalizeAmount($row['bankmaxamt'] ?? $row['bankmaxamout'] ?? null),
                // bankquery 沒有「可收付哪些銀行」的清單；bankname 是該通道自身帳戶的開戶行，
                // 塞進 supportBankCodes 會讓代付比對把我方整批 available_banks 誤判成缺口
                supportBankCodes: [],
                accountBankName: $bankName === '' ? null : $bankName,
            );
        }

        return new PaymentChannelSnapshot(
            merchantStatus: null,
            depositStatus: null,
            payoutStatus: null,
            availableBalance: null,
            totalBalance: null,
            channels: $channels,
            raw: (array) Common::maskCredentials($rows),
        );
    }
}
