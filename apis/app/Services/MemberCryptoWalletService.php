<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\CashFlowEnum;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlowOrderType;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Services\Payment\Contracts\SupportsPermanentDepositAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 會員加密貨幣收款地址。
 *
 * 兩種模式並存於同一張表 member_crypto_wallets（規則見該 Model 的 docblock）：
 * - 地址池（FonPay）：getWallet() 從預建的未指派列撥給會員。
 * - 永久地址（實作 SupportsPermanentDepositAddress 的金流商，如 CCPayment）：
 *   walletsForChannels() 在會員開存款方式清單時向目前商戶確認地址，地址變了就換發（fail-soft），
 *   getOrCreateForChannel() 是同一件事的嚴格版，供 deposit/crypto-address 端點（App 主動取址或重取）呼叫。
 *   表裡存的地址是回調反查「地址屬於誰」的依據，不是「要顯示哪個地址」的依據：商戶換了 APP_ID，
 *   存的地址就屬於舊商戶，顯示前一律以金流商的回應為準。
 *
 * @knowledge rules/provider-ccpayment
 */
class MemberCryptoWalletService
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function getAllWallets(int $memberId): Collection
    {
        return MemberCryptoWallet::where('member_id', $memberId)
            ->where('status', 1)
            ->get();
    }

    /**
     * 存款方式清單用：回傳會員在這些支付通道上的啟用地址，以 agent_cash_flow_order_type_id 為鍵。
     *
     * 永久地址模式（CashFlowEnum::PERMANENT_CRYPTO_ADDRESS）的通道，每次都向目前商戶確認地址：App 只從這份清單
     * 拿地址，這裡就是顯示給會員前的最後一關。確認結果以「通道＋會員＋地址歸屬範圍」快取 5 分鐘，歸屬範圍
     * （CCPayment 為 APP_ID 雜湊）變了就對不到快取、立刻重新確認，所以換商戶不會被快取擋住。
     *
     * 確認是 fail-soft：任何失敗只記 log、該通道的地址留空（連表裡既有的地址也不給，沒確認過的地址可能屬於
     * 舊商戶），其他支付方式照常回傳；金流商失敗後該支付通道冷卻一分鐘不再向金流商取，避免金流商故障時每次開
     * 儲值頁都等到 HTTP 逾時。地址池模式（FonPay）的通道不在此確認，清單只帶已撥出的列。
     *
     * @param  Collection<int, AgentCashFlowOrderType>  $channels  父層支付通道，需已載入 agentCashFlow（只用到 cash_flow_id）
     * @return Collection<int, MemberCryptoWallet>
     */
    public function walletsForChannels(Member $member, Collection $channels): Collection
    {
        $wallets = $this->getAllWallets($member->id)->keyBy('agent_cash_flow_order_type_id');

        foreach ($channels as $channel) {
            $cashFlowId = (int) ($channel->agentCashFlow?->cash_flow_id ?? 0);
            if (!in_array($cashFlowId, CashFlowEnum::PERMANENT_CRYPTO_ADDRESS, true)) {
                continue;
            }

            $wallet = $this->tryGetOrCreateForChannel($member, (int) $channel->id, (int) $channel->agent_cash_flow_id);
            if ($wallet === null) {
                $wallets->forget($channel->id);

                continue;
            }

            $wallets->put($channel->id, $wallet);
        }

        return $wallets;
    }

    /**
     * 清單取址的 fail-soft 包裝：先看快取，沒命中才向金流商確認；失敗回 null 並記 log。
     *
     * 快取命中排在冷卻之前：冷卻是支付通道層級的，不能擋住已經確認過地址的會員。冷卻只在呼叫金流商失敗時設
     * （callProvider），單一會員的資料異常或資料庫錯誤不設，免得一位會員出事讓整個商戶的會員都拿不到地址。
     *
     * 支付通道從資料庫重新載入整列（含金流商戶的 info 憑證與 cash_flows.third_party_code）：清單那份是共用快取，
     * 金流商戶只帶 id／cash_flow_id／rate，建不出 Driver；憑證也不該進共用快取，所以只在真的要取址時多查這一次。
     */
    private function tryGetOrCreateForChannel(Member $member, int $channelId, int $agentCashFlowId): ?MemberCryptoWallet
    {
        try {
            $channel = AgentCashFlowOrderType::query()
                ->with(['agentCashFlow.cashFlow', 'orderType'])
                ->findOrFail($channelId);
            $provider = $this->permanentAddressProvider($channel);

            $verifiedKey = CacheKeys::cryptoAddressVerified($channel->id, $member->id, $provider->permanentDepositAddressScope());
            $verified = Cache::get($verifiedKey);
            if (is_string($verified)) {
                $wallet = $this->findActiveWalletWithAddress($member, $channel, $verified);
                if ($wallet !== null) {
                    return $wallet;
                }
            }

            if (Cache::has(CacheKeys::cryptoAddressFetchCooldown($channelId))) {
                return null;
            }

            $wallet = $this->syncWithProvider($member, $channel, $provider);
            Cache::put($verifiedKey, $wallet->address, CacheTtl::DEFAULT->value);

            return $wallet;
        } catch (Throwable $e) {
            Log::warning('Deposit address fetch on listing failed, channel listed without address', [
                'member_id' => $member->id,
                'channel_id' => $channelId,
                'agent_cash_flow_id' => $agentCashFlowId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }
    }

    public function getWallet(int $agentCashFlowOrderTypeId, int $memberId): ?MemberCryptoWallet
    {
        $wallet = MemberCryptoWallet::where('member_id', $memberId)
            ->where('agent_cash_flow_order_type_id', $agentCashFlowOrderTypeId)
            ->first();

        if (is_object($wallet)) {
            return $wallet;
        }

        try {
            DB::beginTransaction();
            $unassignedWallet = MemberCryptoWallet::lockForUpdate()
                ->where('member_id', 0)
                ->where('status', 1)
                ->where('agent_cash_flow_order_type_id', $agentCashFlowOrderTypeId)
                ->first();

            if (is_object($unassignedWallet)) { // only update one row
                $unassignedWallet->update([
                    'member_id' => $memberId,
                ]);
                DB::commit();

                return $unassignedWallet;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return null;
    }

    /**
     * 永久地址模式：向目前商戶確認會員在該支付通道的地址，跟表裡的啟用列對齊後回傳。
     *
     * 每次都呼叫金流商（取址對金流商冪等，商戶沒換就回同一地址、不多占配額），因為表裡的地址可能屬於舊商戶。
     * 流程與保底：
     * 1. 事務外向金流商取址（禁止在事務內呼叫三方）。
     * 2. 短事務把地址對到表：已是本會員的啟用列＝沿用；新地址＝本會員本通道的舊啟用列改停用（不刪除、不覆寫
     *    address，回調反查還要用）再寫入新列；屬於別的會員＝資料異常拋出。
     * 3. 金流商回的是本會員的停用列（風險地址等）：事務外先解綁再取一次，仍對到停用列就拋出。
     *
     * @throws ApiException 通道的金流商不支援永久地址、地址歸屬異常、或金流商重複回停用地址
     */
    public function getOrCreateForChannel(Member $member, AgentCashFlowOrderType $channel): MemberCryptoWallet
    {
        return $this->syncWithProvider($member, $channel, $this->permanentAddressProvider($channel));
    }

    /**
     * @throws ApiException 支付通道沒有金流商戶，或該金流商不支援永久地址
     */
    private function permanentAddressProvider(AgentCashFlowOrderType $channel): SupportsPermanentDepositAddress
    {
        $agentCashFlow = $channel->agentCashFlow
            ?? throw new ApiException(sprintf('Payment channel %d has no agent cash flow', $channel->id));

        $provider = $this->paymentService->getProvider($agentCashFlow);

        if (!$provider instanceof SupportsPermanentDepositAddress) {
            throw new ApiException(sprintf(
                'Payment channel %d does not support permanent deposit address',
                $channel->id
            ));
        }

        return $provider;
    }

    private function syncWithProvider(Member $member, AgentCashFlowOrderType $channel, SupportsPermanentDepositAddress $provider): MemberCryptoWallet
    {
        $address = $this->fetchAddress($member, $channel, $provider);
        $wallet = $this->bindAddress($member, $channel, $address);
        if ($wallet !== null) {
            return $wallet;
        }

        // 停用列（風險地址等）：金流商端仍綁著同一地址，先解綁再取一次
        $this->callProvider($channel, fn () => $provider->unbindPermanentDepositAddress($channel, $address));
        $address = $this->fetchAddress($member, $channel, $provider);

        return $this->bindAddress($member, $channel, $address)
            ?? throw new ApiException(sprintf(
                'Provider keeps returning a disabled deposit address on channel %d',
                $channel->id
            ));
    }

    /**
     * 短事務：把金流商回的地址對到表。回 null 代表該地址是本會員的停用列，呼叫端要解綁重取。
     *
     * 事務內第一個讀是鎖會員主鍵列（record lock、無 gap lock），同一會員的併發請求在這裡排隊；之後的一般讀
     * 快照從拿到鎖之後才開始，讀得到對方已提交的結果。表上只有單欄索引，對地址列下 FOR UPDATE 會拿 gap lock，
     * 新會員併發 INSERT 會 deadlock（見 .claude/rules/php.md「MySQL 鎖與事務」）。
     *
     * @throws ApiException 地址屬於別的會員
     */
    private function bindAddress(Member $member, AgentCashFlowOrderType $channel, string $address): ?MemberCryptoWallet
    {
        return DB::transaction(function () use ($member, $channel, $address): ?MemberCryptoWallet {
            Member::query()->whereKey($member->id)->lockForUpdate()->first();

            $active = $this->findActiveWallet($member, $channel);

            $existing = $this->findByAddress($channel, $address);
            if ($existing !== null) {
                if ((int) $existing->member_id !== $member->id) {
                    throw new ApiException(sprintf(
                        'Deposit address on channel %d is bound to another member',
                        $channel->id
                    ));
                }

                return (int) $existing->status === 1 ? $existing : null;
            }

            if ($active !== null) {
                $active->update(['status' => 0]);
                Log::info('Deposit address changed at provider, previous address disabled', [
                    'member_id' => $member->id,
                    'channel_id' => $channel->id,
                    'previous_wallet_id' => $active->id,
                ]);
            }

            try {
                return MemberCryptoWallet::create([
                    'agent_cash_flow_order_type_id' => $channel->id,
                    'member_id' => $member->id,
                    'address' => $address,
                    'status' => 1,
                    'balance' => 0,
                    'admin' => 0,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 併發：另一個請求已寫入同一地址（唯一鍵為通道＋地址），回讀即可
                return $this->findActiveWallet($member, $channel)
                    ?? throw new ApiException(sprintf('Deposit address race on channel %d could not be resolved', $channel->id));
            }
        });
    }

    private function findActiveWallet(Member $member, AgentCashFlowOrderType $channel): ?MemberCryptoWallet
    {
        return MemberCryptoWallet::query()
            ->where('agent_cash_flow_order_type_id', $channel->id)
            ->where('member_id', $member->id)
            ->where('status', 1)
            ->first();
    }

    private function findByAddress(AgentCashFlowOrderType $channel, string $address): ?MemberCryptoWallet
    {
        return MemberCryptoWallet::query()
            ->where('agent_cash_flow_order_type_id', $channel->id)
            ->where('address', $address)
            ->first();
    }

    /** 快取命中時用：地址比對在 SQL 做，不鎖（只讀、不改寫） */
    private function findActiveWalletWithAddress(Member $member, AgentCashFlowOrderType $channel, string $address): ?MemberCryptoWallet
    {
        return MemberCryptoWallet::query()
            ->where('agent_cash_flow_order_type_id', $channel->id)
            ->where('member_id', $member->id)
            ->where('address', $address)
            ->where('status', 1)
            ->first();
    }

    /** 向金流商取址並正規化：存入前 trim，比對只在 SQL 做（見 MemberCryptoWallet docblock） */
    private function fetchAddress(Member $member, AgentCashFlowOrderType $channel, SupportsPermanentDepositAddress $provider): string
    {
        return $this->callProvider($channel, function () use ($member, $channel, $provider): string {
            $address = trim($provider->getOrCreatePermanentDepositAddress($member, $channel)->address);

            if ($address === '') {
                throw new ApiException(sprintf('Provider returned an empty deposit address on channel %d', $channel->id));
            }

            return $address;
        });
    }

    /**
     * 呼叫金流商；失敗時該支付通道冷卻一分鐘，存款方式清單在冷卻期間不再替這條通道取址，
     * 避免金流商故障時每次開儲值頁都等到 HTTP 逾時。
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function callProvider(AgentCashFlowOrderType $channel, callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Cache::put(CacheKeys::cryptoAddressFetchCooldown($channel->id), true, CacheTtl::ONE_MINUTE->value);

            throw $e;
        }
    }
}
