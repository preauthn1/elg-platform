<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

/**
 * CCPayment 代收支付通道的 order type key：`CCPAYMENT.{幣別代號}_{三方鏈代號}`，
 * 例 `CCPAYMENT.USDT_TRX`、`CCPAYMENT.TETH_ETH_SEPOLIA`。
 *
 * key 只寫兩個三方後台的字面值——幣別代號是
 * getCoinList 回的 `symbol`，鏈代號是同一筆 `networks` 的鍵（也就是 API 的 `chain` 參數值）。
 * 三方內部的 coinId 不寫進代碼，執行期依幣別代號向 getCoinList 查（CCPaymentTrait::channelSpec()），
 * 所以哪些幣、哪些鏈可用由三方後台控制：後台沒啟用，key 再怎麼寫都取不到 coinId、通道就不能用。
 *
 * 幣別代號不含底線，鏈代號可以含底線（ETH_SEPOLIA），所以固定以第一個底線切分。
 * 加一種幣或一條鏈＝資料檔 deposit.channels 加一條、後台啟用，代碼不動。
 *
 * 🔴 通道 key 一旦有會員取過地址，改名只能用 `cash-flow:sync --rename-key=舊key=新key` 就地改鍵
 * （order_types 列與 id 不變、關聯自動跟上）；禁止改資料檔直接 sync（等於刪舊建新）：地址綁的是支付通道關聯
 * （member_crypto_wallets 的 agent_cash_flow_order_type_id），回調靠它反查通道再解析 key 取幣鏈，
 * 舊 key 的通道被下架後，打到舊地址的款會因解析不到幣鏈而不入帳。
 */
final class ChannelKey
{
    public const PREFIX = 'CCPAYMENT.';

    private const SEPARATOR = '_';

    public static function build(string $symbol, string $chain): string
    {
        return self::PREFIX . self::coinChain($symbol, $chain);
    }

    /**
     * 拆出幣別代號與鏈代號；不是本格式（沒有前綴、缺幣別或鏈、含非法字元）一律回 null。
     *
     * @return array{symbol: string, chain: string}|null
     */
    public static function parse(string $key): ?array
    {
        if (!str_starts_with($key, self::PREFIX)) {
            return null;
        }

        return self::parseCoinChain(substr($key, strlen(self::PREFIX)));
    }

    /**
     * 代付用的三方銀行代碼：`{幣別代號}_{三方鏈代號}`，例 `USDT_TRX`，就是支付通道 key 去掉前綴的後半段。
     *
     * 代付沒有支付通道，幣與鏈跟著會員選的提款銀行走（例「USDT - TRC20」本身就代表一種幣加一條鏈），
     * 所以這個值放在該銀行的三方代碼（資料檔代付 banks 的 code，同步後是 cash_flow_banks 的
     * provider_bank_code 與商戶 info 的 available_banks），商戶 info 不另存幣別。
     */
    public static function coinChain(string $symbol, string $chain): string
    {
        return $symbol . self::SEPARATOR . $chain;
    }

    /**
     * 拆 `{幣別代號}_{三方鏈代號}`；規則與 parse() 相同（第一個底線切分），格式不符一律回 null。
     *
     * @return array{symbol: string, chain: string}|null
     */
    public static function parseCoinChain(string $code): ?array
    {
        if (preg_match('/^([A-Za-z0-9]+)_([A-Za-z0-9_]+)$/', $code, $matches) !== 1) {
            return null;
        }

        return ['symbol' => $matches[1], 'chain' => $matches[2]];
    }
}
