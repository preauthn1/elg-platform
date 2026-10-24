<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;

/**
 * 代幣與鏈別查詢（api.md §7）：唯讀，不建單不動金額。
 *
 * CCPayment 沒有靜態的代幣／鏈別對照表，代收 getOrCreateAppDepositAddress 必填的 chain、
 * 代付 applyAppWithdrawToNetwork 必填的 coinId 與 chain 只能由此取得。
 *
 * ! 回應不含 PII，但仍依 SupportsPaymentChannelQuery 的實作守則，由呼叫端在進 DTO 前
 * ! 過 Common::maskCredentials()。
 * ! fail-closed：形狀不符一律拋出，不得靜默回空陣列——那會讓呼叫端誤判成「商戶零通道」。
 */
class CoinQuery extends AbstractMethod
{
    use CCPaymentTrait;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 商戶已啟用的代幣清單（POST /getCoinList，無請求參數；api.md §7.1）。
     *
     * ! 回傳的是商戶在廠商後台啟用的代幣（Tokens for your business），不是平台全集
     * ! （見 integration.md 第 4 節）。要新增代幣須先在廠商後台開啟，不是改代碼。
     *
     * @return list<array<string, mixed>>
     */
    public function coins(): array
    {
        $result = $this->sendRequest('/getCoinList');

        return $this->extractList($result, 'coins', 'getCoinList');
    }

    /**
     * 鏈別狀態清單（POST /getChainList，省略 chains 時回全部；api.md §7.2）。
     *
     * 提供各鏈的 status（Normal／Maintenance）、confirmations 與 supportMemo。
     *
     * @return list<array<string, mixed>>
     */
    public function chains(): array
    {
        $result = $this->sendRequest('/getChainList');

        return $this->extractList($result, 'chains', 'getChainList');
    }

    /**
     * 自回應信封取出清單並驗形狀。
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function extractList(array $result, string $key, string $endpoint): array
    {
        $rows = $result['data'][$key] ?? null;

        if (!is_array($rows) || !array_is_list($rows)) {
            throw new ApiException(sprintf(
                '%s %s returned unexpected response shape',
                $this->getDriverName(),
                $endpoint
            ));
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ApiException(sprintf(
                    '%s %s returned unexpected row shape',
                    $this->getDriverName(),
                    $endpoint
                ));
            }
        }

        return $rows;
    }
}
