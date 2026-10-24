<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use EasyPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'EASY';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        // 根據銀行代碼決定使用哪個端點
        if ($params['bank_code'] === 'PAYNOW') {
            unset($params['bank_code']);
            $path = '/withdraw/paynow';
        } else {
            $path = '/withdraw/bank_order';
        }

        // 簽名必須在端點分流之後才做：PAYNOW 會把 bank_code 拿掉，沒送出的欄位不參與簽名。
        // 文件列的 /withdraw/bank_order 簽這六個；/withdraw/paynow 文件未收錄，比照快轉的
        // 「有傳才簽」規則，bank_code 被 unset 後自然落空。
        $params = $this->signParams($params, [
            'amount', 'tx_id', 'callback_url', 'account_number', 'account_name', 'bank_code',
        ]);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            // 例外分類原則：只有三方給得出業務信封才算明確答覆，交 interpretResult 判成功或拒絕
            // （拒絕 → -2、訂單轉 29 可重新發起）；解不出信封的一律拋 → -1 REQUEST_FAILED，訂單
            // 停留 21 待回調或人工定案——那種情況請求可能已經送達三方，判成拒絕會讓營運重發 ⇒ 二次出款。
            performRequest: function () use ($path, $params): array {
                // 非 2xx 不再轉成例外（見 EasyPayTrait::sendRequest），400 的拒絕信封走得到這裡
                $result = $this->sendRequest($path, $params);

                // 解得出 JSON 卻沒有 status：不是本家的業務信封（閘道自己的 JSON 錯誤頁之類），
                // 無依據斷定三方沒受理 → 結果未知
                if (!array_key_exists('status', $result)) {
                    throw new ApiException($this->getDriverName() . ' response without status envelope');
                }

                return $result;
            },
            // 嚴格判斷：必須 status === 1 才視為成功；
            // 官方失敗 envelope 為 {status:0, code:XXX, message:null}，錯誤原因在 code，優先取 code
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === 1,
                thirdPartyOrderNo: $result['data']['order_id'] ?? '',
                errorMessage: $result['code'] ?? $result['message'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        return [
            'amount' => Common::numFloor($withdrawalOrder->amount, 2), // 保留兩位小數，與代收一致改用 Common 避免原生浮點
            'tx_id' => $transactionNo,
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'bank_code' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'account_number' => $withdrawalOrder->account_no,
            'account_name' => $withdrawalOrder->account_name,
        ];
    }
}
