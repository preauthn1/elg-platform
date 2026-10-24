<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Enums\Payment\PaymentChannelDirection;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;

/**
 * @knowledge rules/payout-bank-code-mapping
 *
 * @see https://doc.fpay.support/cn/
 */
class Driver extends AbstractDriver implements SupportsPaymentChannelQuery
{
    /** 通道快照裡代表「代付銀行清單」的通道識別，取自資料來源端點名稱 */
    private const string WITHDRAW_BANK_LIST_CHANNEL = 'withdraw_bank_list';

    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    protected WithdrawBankList $withdrawBankList;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代理的商戶配置
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);

        // 擴充：代付銀行清單查詢
        $this->withdrawBankList = new WithdrawBankList($agentCashFlow);
    }

    /**
     * 通道快照（SupportsPaymentChannelQuery）：把 withdraw_bank_list 映射成一條代付通道，
     * 銀行 id 放進 supportBankCodes，供 cash-flow:third-party-query 比對 available_banks 的 BankCode_Third。
     *
     * ! 這支查詢沒有商戶開通狀態、餘額與限額，商戶層與限額欄位一律 null，不偽造。
     * ! 銀行名稱沒有對應的標準欄位，只留在 raw；人工對照新舊代號時用 --raw 看。
     * ! 缺 id 的列略過、不轉成空字串代號：空字串進 supportBankCodes 會讓比對誤報「三方有、我方沒有」。
     */
    public function queryPaymentChannels(): PaymentChannelSnapshot
    {
        $rows = $this->withdrawBankList->handle();

        $bankIds = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                $bankIds[] = (string) $id;
            }
        }

        return new PaymentChannelSnapshot(
            merchantStatus: null,
            depositStatus: null,
            payoutStatus: null,
            availableBalance: null,
            totalBalance: null,
            channels: [
                new PaymentChannel(
                    name: self::WITHDRAW_BANK_LIST_CHANNEL,
                    direction: PaymentChannelDirection::Payout,
                    status: null,
                    minAmount: null,
                    maxAmount: null,
                    supportBankCodes: $bankIds,
                ),
            ],
            raw: (array) Common::maskCredentials($rows),
        );
    }
}
