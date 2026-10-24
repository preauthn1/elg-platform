<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use Illuminate\Support\Facades\Log;

/**
 * 憑證完整性守衛
 *
 * 對稱簽名的金流商（AllPay／DgPay／PayMate 等）以 md5(...payload... . $key) 驗簽。
 * 一旦 $key 為空字串，簽名字串就只由回調自身內容組成、可被離線計算，驗簽形同虛設；
 * 同型的「商戶號比對」也會因為 '' !== '' 為 false 而一併失守。
 *
 * 三個放大因子讓這個缺口直通上分：
 * 1. DepositNotifyController 只驗 agent_cash_flow 存在與 cash_flow.type，不驗來源
 * 2. external 回調路由無 throttle、無 IP 白名單（code 層）
 * 3. 訂單查找不綁 agent_cash_flow_id，空憑證通道可用來結算其他通道建立的訂單
 *
 * 因此在驗簽之前先確認憑證存在，缺任一項就不讓後續的訂單查找與上分流程有機會執行。
 *
 * 本 Trait 只負責「判斷 ＋ 記錄安全事件」，不拋例外也不決定回應內容：
 * 中止與回應交由呼叫端，以該金流商與三方約定的失敗文本回覆，
 * 避免回一個三方看不懂的錯誤而觸發無限重試。
 *
 * @see DepositCallbackTrait::findAndLockDepositOrder()
 * @see \App\Vendors\WudiSW\Driver::check() 遊戲商側的同型守衛（private，各 Vendor Driver 自行實作、不走本 trait）
 */
trait CredentialGuardTrait
{
    /**
     * 此 Trait 需要使用的方法（由 DriverTrait 提供）
     *
     * @see DriverTrait::getDriverName()
     */
    abstract protected function getDriverName(): string;

    /**
     * 找出缺漏的憑證（依 info 鍵名）
     *
     * 適用於憑證直接以固定鍵名存放的金流商。鍵名有新舊相容（同一份憑證可能落在
     * 兩個鍵之一）的，改用 findMissingCredentialValues() 傳入解析後的值。
     *
     * @param  list<string>  $keys  必要憑證鍵（如 MerchantKey）
     * @param  string  $context  用於日誌的情境（如 deposit callback）
     * @return list<string> 缺漏的鍵名；回傳非空陣列時呼叫端必須中止回調
     */
    protected function findMissingCredentials(array $keys, string $context = 'callback'): array
    {
        $credentials = [];

        foreach ($keys as $key) {
            $credentials[$key] = $this->info[$key] ?? null;
        }

        return $this->findMissingCredentialValues($credentials, $context);
    }

    /**
     * 找出缺漏的憑證（依已解析出的值）
     *
     * @param  array<string, mixed>  $credentials  鍵名 => 已解析的憑證值
     * @param  string  $context  用於日誌的情境
     * @return list<string> 缺漏的鍵名；回傳非空陣列時呼叫端必須中止回調
     */
    protected function findMissingCredentialValues(array $credentials, string $context = 'callback'): array
    {
        $missing = [];

        foreach ($credentials as $key => $value) {
            if (!is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return [];
        }

        // 只記鍵名不記值：缺的鍵本來就是空的，同一份 info 的其他鍵仍是憑證，不可整包落 log
        Log::error(sprintf('%s %s rejected: credentials not configured', $this->getDriverName(), $context), [
            'agent_cash_flow_id' => isset($this->agentCashFlow) ? $this->agentCashFlow->id : null,
            'missing_keys' => $missing,
        ]);

        return $missing;
    }
}
