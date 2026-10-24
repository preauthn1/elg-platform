<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use Illuminate\Support\Carbon;

/**
 * 查詢三方商戶設定（見 api.md §3.4 商戶設定查詢，端點 /setting）
 *
 * ! 唯讀查詢：不建單、不動金額，僅取回三方端的商戶開通狀態與各線路限額／支援銀行。
 * ! 用途是核對「我方 agent_cash_flows.info 的限額與 available_banks」是否與三方實際開通一致
 * ! ——新接商戶的限額常先借用同協定既有商戶的值當暫用值，靠本 API 才能取得真實值。
 *
 * ! 回應欄位（api.md §3.4）：merchantStatus / depositStatus / payoutStatus 為 ON|OFF；
 * ! payments[] 為逐線路設定（name / status / minAmount / maxAmount / todayLimit /
 * ! totalAmount / supportBankCode）；availableBalance 與 totalBalance 需三方後台開啟才回傳。
 */
class Setting extends AbstractMethod
{
    use PayMateTrait;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 取回三方商戶設定的內層 response
     *
     * 信封層（resCode / merchantNo / 驗簽 / Base64 內層）全部由 sendRequest 處理，
     * 任一環節不符即拋 ApiException——查詢類流程沒有「結果未知」的語意，
     * 拿不到可信資料就不該回半套讓呼叫端誤判。
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return $this->sendRequest('/setting', [
            'queryTime' => Carbon::now()->toFrontendTime()->format('YmdHis'),
        ]);
    }
}
