<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Data\PermanentDepositAddress;

/**
 * 代收：永久地址模式（api.md「代收 API」的永久地址模式那節，POST /getOrCreateAppDepositAddress）。
 *
 * 每位會員在本支付通道有一個固定收款地址，會員自行轉帳；到帳後由 DirectDeposit 回調
 * （DepositCallback）回查後才建存款單入帳。因此本類別不建存款單：handle() 一律拋出，
 * 取址走 getOrCreateAddress()，由 MemberCryptoWalletService 在會員開存款方式清單時呼叫
 * （walletsForChannels()：每次開清單都向目前商戶確認地址，商戶換了 APP_ID 就換發；
 * deposit/crypto-address 端點走的 getOrCreateForChannel() 保留給 App 主動取址或重取）。
 *
 * referenceId 格式見 ReferenceId；幣與鏈都由支付通道的 order type key 決定（CCPaymentTrait::channelSpec()），
 * 同一個商戶掛幾組（幣, 鏈）就是幾條支付通道，商戶 info 只放憑證。
 *
 * ! notifyUrl 綁定於地址、持久有效：一律帶含金流商戶 id 的回調網址（Common::depositCallbackUrl），
 * ! 網域必須先加入金流商後台的 notifyUrl 白名單。
 * ! 取址對金流商冪等：同一 referenceId 回同一地址，呼叫端寫表失敗重試即可，不會多占配額。
 *
 * @knowledge rules/provider-ccpayment
 */
class Deposit extends AbstractMethod
{
    use CCPaymentTrait;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 永久地址模式不建存款單，存款單在收到 DirectDeposit 回調並回查後才建立。
     * Frontend 的建單流程對 BIND_CRYPTO_WALLET 的支付通道會在建單前擋下，這裡是最後防線。
     */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        throw new ApiException(sprintf(
            '%s permanent address channel does not create deposit orders, use the deposit address endpoint',
            $this->getDriverName()
        ));
    }

    /**
     * 取得或建立會員在本支付通道的固定收款地址。
     */
    public function getOrCreateAddress(Member $member, AgentCashFlowOrderType $channel): PermanentDepositAddress
    {
        $data = $this->getOrCreateDepositAddress(
            ReferenceId::build($channel->id, $member->id),
            $this->channelChain($channel),
            Common::depositCallbackUrl($this->agentCashFlow->id)
        );

        $address = $data['address'] ?? null;
        if (!is_string($address) || trim($address) === '') {
            throw new ApiException($this->getDriverName() . ' deposit address response has no address');
        }

        $memo = $data['memo'] ?? null;

        return new PermanentDepositAddress(
            address: trim($address),
            memo: is_string($memo) && $memo !== '' ? $memo : null,
        );
    }

    /**
     * 地址歸屬範圍：CCPayment 的永久地址按 APP_ID 發放（配額與 referenceId 綁定都按 APP_ID 算，見 api.md 永久地址模式那節），
     * 只換 APP_SECRET 不影響既有地址，換了 APP_ID 才要重新取址。回傳 APP_ID 的 SHA-256 前 16 碼，不回原值。
     */
    public function addressScope(): string
    {
        return substr(hash('sha256', (string) ($this->info['APP_ID'] ?? '')), 0, 16);
    }

    /**
     * 解除地址在金流商端的綁定（風險地址換發、取址保底用），鏈別由支付通道決定。
     */
    public function unbind(AgentCashFlowOrderType $channel, string $address): void
    {
        $this->unbindAddress($this->channelChain($channel), $address);
    }
}
