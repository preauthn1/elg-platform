<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Models\AgentCashFlow;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use App\Services\Payment\Data\PayoutQueryResult;

/**
 * A3Pay（SGD）代收代付 Driver。規格見 docs/integrations/payments/A3Pay/api.md，工單 target/tickets#4394。
 *
 * 信封 merchantCode／data／signature／version；Data 以 AES-256-CBC 加密、Signature 為 JSON 明文的 RSA SHA-1 簽名（A3PayTrait）。
 * 代收狀態 6 成功／7 失敗／其餘非終態；代付狀態 4 成功／5 失敗／其餘處理中；代付前三方會打 WithdrawalQuery 反查確認。
 * 實作 SupportsPayoutQuery：停 21 的單由 withdrawal:alert-stuck-submitted 以 GetPayoutDetail 自動查證。
 *
 * @knowledge rules/provider-a3pay
 */
class Driver extends AbstractDriver implements SupportsPayoutQuery
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    protected WithdrawalQuery $withdrawalQuery;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 代付
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
        $this->withdrawalQuery = new WithdrawalQuery($agentCashFlow);
    }

    public function queryPayout(WithdrawalPayoutRecord $record): PayoutQueryResult
    {
        return $this->withdrawal->queryByTransactionNo((string) $record->transaction_no);
    }
}
