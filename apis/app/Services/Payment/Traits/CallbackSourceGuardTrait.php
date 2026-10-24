<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use Illuminate\Support\Facades\Log;

/**
 * 回調來源 IP 白名單守衛（工單 target/tickets#4446、target/tickets#4449）。
 *
 * 沒有驗簽的金流商，回調內容完全可以偽造——`DepositNotifyController` 只驗 agent_cash_flow
 * 存在與 cash_flow.type，external 回調路由在 code 層也沒有 IP 限制（CLAUDE.md 寫明防線在
 * nginx / ingress）。來源 IP 因此是這些金流商唯一擋得住偽造回調的地方。
 *
 * 硬規則：
 *
 * 🔴 **fail-closed：白名單為空＝一律拒絕，不是「關掉檢查」。**
 * 這與遊戲商的 {@see \App\Helpers\Common::verifyIPWhitelist()} 相反（那支在非 production 一律放行、
 * 白名單為空也放行），所以**禁止改用它**。TngPin 原本的 `guardCallbackIp()` 也是 fail-open，
 * 已收斂到本 trait。代價是上線前沒把 IP 配好，該金流商的回調會全部被擋、儲值中斷——
 * 這是刻意的取捨：擋掉真回調會立刻有人喊，放過假回調沒有人會發現。
 *
 * 🔴 **白名單的單一來源是 `cash_flows.whitelist`**，不吃 `agent_cash_flows.info`。
 * 廠商的出口 IP 是**支付商**層級的屬性，對該支付商底下所有商戶、所有代理群組都是同一份；
 * 存進 per 商戶的設定會複製 N 份各自漂移，改一處漏三處。
 *
 * 🔴 **來源 IP 一律取 {@see \App\Helpers\Common::getIp()}**，禁止用 `$request->ip()`：
 * 後者直接信任 `X-Forwarded-For`，偽造者自己加一個標頭就繞過了。`\App\Helpers\Common::getIp()` 走
 * 可信代理鏈（target/tickets#4187），才是擋得住偽造的那個。
 *
 * 本 trait 只負責「判斷 ＋ 記錄安全事件」，**不拋例外也不決定回應內容**：
 * 中止與回應交由呼叫端，以該金流商與三方約定的失敗文本回覆，避免回一個三方看不懂的錯誤
 * 而觸發無限重試。這一點與 {@see CredentialGuardTrait} 的分工相同。
 *
 * @knowledge rules/payment-callback-common-checks
 *
 * @see CredentialGuardTrait 同一層的前置守衛：憑證完整性
 */
trait CallbackSourceGuardTrait
{
    /**
     * 此 Trait 需要使用的方法（由 DriverTrait 提供）
     *
     * @see DriverTrait::getDriverName()
     */
    abstract protected function getDriverName(): string;

    /**
     * 此 Trait 需要使用的屬性（由 {@see \App\Services\Payment\AbstractMethod} 宣告）：
     *
     * - `$agentCashFlow`：本次回調的金流商戶；白名單從它的 `cashFlow->whitelist` 讀。
     *   各 Callback 類別的 constructor 必須寫 `$this->agentCashFlow = $agentCashFlow;`，
     *   只呼叫 `setInfo()` 是不夠的（見 isCallbackSourceAllowed() 的 provider_not_wired）。
     */

    /**
     * 回調來源 IP 是否通過白名單。
     *
     * 不通過時已寫好安全事件日誌，呼叫端只要以該金流商約定的失敗格式中止即可。
     *
     * @param  string  $context  用於日誌的情境（如 deposit callback / withdrawal callback）
     * @return bool false ＝ 呼叫端必須中止，不得繼續處理這次回調
     */
    protected function isCallbackSourceAllowed(string $ip, string $context = 'callback'): bool
    {
        // 🔴 Callback 類別的 constructor 若只呼叫 setInfo()、沒把 $agentCashFlow 存進屬性，
        // 這裡讀不到渠道就等於讀不到白名單 ⇒ fail-closed 會把那家的回調全部擋掉。
        // 安全方向雖然是對的（不會放行假回調），但症狀是「整家儲值不上分」而且毫無頭緒，
        // 所以單獨記成 error 並用 provider_not_wired 標出來，與「IP 沒配」區分開。
        if (!isset($this->agentCashFlow)) {
            Log::error(sprintf('%s %s rejected: provider not wired', $this->getDriverName(), $context), [
                'ip' => $ip,
                'reason' => 'provider_not_wired',
                'hint' => 'DepositCallback/WithdrawalCallback 的 constructor 必須寫 $this->agentCashFlow = $agentCashFlow;',
            ]);

            return false;
        }

        $allowIps = $this->callbackAllowIps();

        if ($allowIps !== [] && in_array($ip, $allowIps, true)) {
            return true;
        }

        // 白名單為空與 IP 不在名單內分開記：前者是「設定還沒配好」的維運問題，
        // 後者才可能是攻擊，兩者的處置完全不同，混在同一行會讓人判斷錯方向。
        Log::warning(sprintf('%s %s rejected: source IP not allowed', $this->getDriverName(), $context), [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'ip' => $ip,
            'reason' => $allowIps === [] ? 'whitelist_not_configured' : 'ip_not_in_whitelist',
            'allow_ips' => $allowIps,
        ]);

        return false;
    }

    /**
     * 本金流商允許的回調來源 IP。
     *
     * @return list<string>
     */
    protected function callbackAllowIps(): array
    {
        $whitelist = isset($this->agentCashFlow)
            ? (string) ($this->agentCashFlow->cashFlow?->whitelist ?? '')
            : '';

        return array_values(array_filter(
            array_map('trim', explode(',', $whitelist)),
            static fn (string $ip): bool => $ip !== ''
        ));
    }
}
