<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use App\Services\Payment\Contracts\SupportsPermanentDepositAddress;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;
use App\Services\Payment\Data\PayoutQueryResult;
use App\Services\Payment\Data\PermanentDepositAddress;

/**
 * CCPayment：USDT-TRC20 代收（永久地址模式）與代付。
 *
 * 代收不走 deposit() 建單：會員點選支付通道時經 SupportsPermanentDepositAddress 取固定地址，
 * 到帳後由 DirectDeposit 回調建存款單（見 Deposit、DepositCallback 的 docblock）。
 *
 * @knowledge rules/provider-ccpayment
 */
class Driver extends AbstractDriver implements SupportsPaymentChannelQuery, SupportsPayoutQuery, SupportsPermanentDepositAddress
{
    /**
     * 代幣與鏈的正常狀態值。
     *
     * 代幣的 status 為 Normal／Maintain／Pre-delisting／Delisted（api.md §7.1），
     * 鏈的 status 為 Normal／Maintenance（api.md §7.2），兩者都以 Normal 表示正常。
     * 非 Normal 一律視為該通道不可用：Maintain 只暫停提現、Pre-delisting 只暫停存款，
     * 但通道查詢不分收付方向，一律從嚴。
     */
    private const STATUS_NORMAL = 'Normal';

    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    protected CoinQuery $coinQuery;

    /** @var list<array<string, mixed>>|null */
    private ?array $coinRows = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $chainRows = null;

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
        $this->coinQuery = new CoinQuery($agentCashFlow);
    }

    public function getOrCreatePermanentDepositAddress(Member $member, AgentCashFlowOrderType $channel): PermanentDepositAddress
    {
        return $this->deposit->getOrCreateAddress($member, $channel);
    }

    public function permanentDepositAddressScope(): string
    {
        return $this->deposit->addressScope();
    }

    public function unbindPermanentDepositAddress(AgentCashFlowOrderType $channel, string $address): void
    {
        $this->deposit->unbind($channel, $address);
    }

    /**
     * 代付查單（SupportsPayoutQuery）：停在 21 的提款單由排程 withdrawal:alert-stuck-submitted
     * 以我方訂單號向金流商查證，查到有單就補三方單號轉 22。三態判定見 Withdrawal::queryByOrderId()。
     */
    public function queryPayout(WithdrawalPayoutRecord $record): PayoutQueryResult
    {
        return $this->withdrawal->queryByOrderId((string) $record->transaction_no);
    }

    /**
     * 通道快照（SupportsPaymentChannelQuery）：幣種 × 鏈逐組合映射。
     *
     * CCPayment 的「通道」概念是幣種與鏈的組合（例：USDT 於 TRX 鏈），故一個 coin 的
     * 每條 network 各產生一列 PaymentChannel。
     *
     * ! name 必須用 ChannelKey::build() 產生，也就是我方 order type key 的同一個格式
     * ! （例 `CCPAYMENT.USDT_TRX`）。`cash-flow:third-party-query` 的代收比對是拿 `order_types.key`
     * ! 與三方通道名逐字比對；name 另立格式（例如 `USDT-TRX`）會讓已配置的通道
     * ! 比對不上限額，同時又被列進「三方有、我方未配置的線路」。
     *
     * ! direction、minAmount／maxAmount 目前一律 null，不可為了填滿欄位而偽造。
     * ! getCoinList 的 networks 其實帶 canDeposit／canWithdraw 與 minimumDepositAmount／
     * ! minimumWithdrawAmount／maximumWithdrawAmount（api.md §7.1），尚未對應進快照，是否採用待定。
     * ! supportBankCodes 放該組合的 {幣別代號}_{三方鏈代號}（如 USDT_TRX，ChannelKey::coinChain()）——
     * ! 對 CCPayment 而言「三方銀行代碼」就是幣加鏈，也是提款銀行三方代碼存的值。
     * ! 但 `cash-flow:third-party-query` 的代付銀行碼比對目前不生效：它只取 direction 為代付的通道，
     * ! 而本快照的 direction 一律 null（見上）。要生效須先用 networks 的 canDeposit／canWithdraw 標方向（待定）。
     * ! status 取幣種與鏈之中較嚴格者：幣種非 Normal 時整條鏈都不可用，以幣種狀態為準。
     *
     * 同一 Driver 實例內 memoize（request-local），禁止跨 process 快取。
     */
    public function queryPaymentChannels(): PaymentChannelSnapshot
    {
        $coins = $this->coinRows ??= $this->coinQuery->coins();
        $chains = $this->chainRows ??= $this->coinQuery->chains();

        $chainStatusMap = [];
        foreach ($chains as $chainRow) {
            $chainName = $this->stringOrNull($chainRow['chain'] ?? null);
            if ($chainName === null) {
                continue;
            }

            $chainStatusMap[$chainName] = $this->stringOrNull($chainRow['status'] ?? null);
        }

        $channels = [];
        foreach ($coins as $coin) {
            $symbol = $this->stringOrNull($coin['symbol'] ?? null);
            $networks = $coin['networks'] ?? null;

            if ($symbol === null || !is_array($networks)) {
                continue;
            }

            $coinStatus = $this->stringOrNull($coin['status'] ?? null);

            foreach (array_keys($networks) as $chain) {
                $chain = (string) $chain;

                $channels[] = new PaymentChannel(
                    name: ChannelKey::build($symbol, $chain),
                    direction: null,
                    status: $coinStatus !== null && $coinStatus !== self::STATUS_NORMAL
                        ? $coinStatus
                        : ($chainStatusMap[$chain] ?? null),
                    minAmount: null,
                    maxAmount: null,
                    supportBankCodes: [ChannelKey::coinChain($symbol, $chain)],
                );
            }
        }

        return new PaymentChannelSnapshot(
            merchantStatus: null,
            depositStatus: null,
            payoutStatus: null,
            availableBalance: null,
            totalBalance: null,
            channels: $channels,
            raw: (array) Common::maskCredentials(['coins' => $coins, 'chains' => $chains]),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
