<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Enums\Payment\PaymentChannelDirection;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Concerns\NormalizesThirdPartyAmounts;
use App\Services\Payment\Contracts\SupportsDepositQuery;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;

class Driver extends AbstractDriver implements SupportsDepositQuery, SupportsPaymentChannelQuery
{
    use NormalizesThirdPartyAmounts;

    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    protected QueryOrder $queryOrder;

    protected Setting $setting;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 代付
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);

        // 主動查單（api.md 3.3 /query）
        $this->queryOrder = new QueryOrder($agentCashFlow);

        // 商戶設定查詢（api.md 3.4 /setting）
        $this->setting = new Setting($agentCashFlow);
    }

    /**
     * 以 info[DRIVER_LABEL] 覆寫商戶顯示名（見 DriverTrait::driverLabelOverride）
     *
     * Driver 層未 use PayMateTrait、僅持有 agentCashFlow，故單獨覆寫；否則
     * 「PayMate Driver initialized」這類 log 仍會把 SpeedPay 商戶標成 PayMate。
     */
    protected function driverLabelOverride(): ?string
    {
        $label = $this->agentCashFlow->info['DRIVER_LABEL'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * 主動反查代收訂單狀態（api.md 3.3 /query，service=QRH5）
     */
    public function queryOrder(Order $order): QueryOrderResponseData
    {
        return $this->queryOrder->handle(QueryOrderRequestData::from([
            'type' => 'deposit',
            'order' => $order,
        ]));
    }

    /**
     * 主動反查代付訂單狀態（api.md 3.3 /query，service=PAYOUT）
     */
    public function queryPayoutOrder(WithdrawalOrder $order): QueryOrderResponseData
    {
        return $this->queryOrder->handle(QueryOrderRequestData::from([
            'type' => 'withdraw',
            'order' => $order,
        ]));
    }

    /**
     * 查詢三方商戶設定（api.md 3.4 /setting）：開通狀態、逐線路限額、支援銀行清單
     *
     * 唯讀，不在代收／代付流程內；供 queryPaymentChannels() 映射與 artisan cash-flow:third-party-query 核對配置。
     *
     * @return array<string, mixed>
     */
    public function merchantSetting(): array
    {
        return $this->setting->handle();
    }

    /**
     * 通道快照（SupportsPaymentChannelQuery）：/setting 回應的標準化映射。
     * merchantSetting() 保留為原始資料入口；本方法只做投影，不重打 API 的職責在呼叫端
     * （排查命令單次執行，同請求不會重複呼叫）。
     */
    public function queryPaymentChannels(): PaymentChannelSnapshot
    {
        $setting = $this->merchantSetting();
        $payments = is_array($setting['payments'] ?? null) ? $setting['payments'] : [];

        $channels = [];
        foreach ($payments as $payment) {
            if (!is_array($payment) || !is_string($payment['name'] ?? null) || $payment['name'] === '') {
                continue;
            }

            $bankCodes = array_values(array_filter(array_map(
                'trim',
                explode(',', is_scalar($payment['supportBankCode'] ?? null) ? (string) $payment['supportBankCode'] : ''),
            ), static fn (string $code): bool => $code !== ''));

            $channels[] = new PaymentChannel(
                name: $payment['name'],
                direction: $payment['name'] === 'PAYOUT'
                    ? PaymentChannelDirection::Payout
                    : PaymentChannelDirection::Deposit,
                status: is_scalar($payment['status'] ?? null) ? (string) $payment['status'] : null,
                minAmount: $this->normalizeAmount($payment['minAmount'] ?? null),
                maxAmount: $this->normalizeAmount($payment['maxAmount'] ?? null),
                supportBankCodes: $bankCodes,
                todayLimit: $this->normalizeAmount($payment['todayLimit'] ?? null),
                totalAmount: $this->normalizeAmount($payment['totalAmount'] ?? null),
            );
        }

        return new PaymentChannelSnapshot(
            merchantStatus: is_scalar($setting['merchantStatus'] ?? null) ? (string) $setting['merchantStatus'] : null,
            depositStatus: is_scalar($setting['depositStatus'] ?? null) ? (string) $setting['depositStatus'] : null,
            payoutStatus: is_scalar($setting['payoutStatus'] ?? null) ? (string) $setting['payoutStatus'] : null,
            availableBalance: $this->normalizeAmount($setting['availableBalance'] ?? null),
            totalBalance: $this->normalizeAmount($setting['totalBalance'] ?? null),
            channels: $channels,
            raw: (array) Common::maskCredentials($setting),
        );
    }
}
