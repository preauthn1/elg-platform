<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Services\Payment\Data\PermanentDepositAddress;

/**
 * 永久收款地址能力（可選實作）：每位會員在一條支付通道上有一個固定收款地址，
 * 會員自行轉帳、到帳後由回調建存款單入帳，不走「輸入金額建單」流程。
 *
 * 呼叫端是 MemberCryptoWalletService::getOrCreateForChannel()，表的規則見 MemberCryptoWallet 的 docblock。
 *
 * 實作守則：
 * - getOrCreatePermanentDepositAddress() 對金流商必須是冪等的（同一會員同一通道重複呼叫回同一地址），
 *   呼叫端才能在寫表失敗時直接重試。
 * - 兩個方法都只跟金流商往來，禁止在實作內讀寫 member_crypto_wallets，那是呼叫端的責任。
 * - 金流商回應形狀不符一律拋 ApiException，不回半套資料。
 */
interface SupportsPermanentDepositAddress
{
    public function getOrCreatePermanentDepositAddress(Member $member, AgentCashFlowOrderType $channel): PermanentDepositAddress;

    /**
     * 地址歸屬範圍：金流商以什麼單位發地址（CCPayment 是 APP_ID）。
     *
     * 值變了代表目前商戶不是當初發地址的商戶，既有地址不再屬於它。回傳值會放進快取鍵，
     * 必須是不可逆的雜湊，禁止回傳憑證原值。
     */
    public function permanentDepositAddressScope(): string;

    /**
     * 解除地址在金流商端的綁定，之後同一會員再取址會拿到新地址（風險地址換發、保底重取用）。
     */
    public function unbindPermanentDepositAddress(AgentCashFlowOrderType $channel, string $address): void;
}
