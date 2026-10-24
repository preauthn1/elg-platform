<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\QuickPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositCallback extends AbstractMethod
{
    use QuickPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 generateSignature() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤的例外相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'deposit callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'status' => 'required|integer',
            'sign' => 'required|string',
            'result' => 'required|json',
        ]);

        // 驗證簽名
        if ($input['sign'] !== $this->generateSignature($input, $this->info['SECRET_KEY'])) {
            throw new ApiException('Signature verification failed');
        }

        $result = json_decode($input['result'], true);
        $status = $input['status'];
        $isSuccess = ($status == 10000);
        $orderNo = $result['orderid'];
        $thirdPartyOrderNo = $result['transactionid'];
        $amount = $result['amount'];

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($orderNo);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            if ($isSuccess === true) {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input,
                    amount: $amount
                );

                $response = $this->buildDepositSuccessResponse($order);
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input
                );

                $response = $this->buildDepositFailResponse(
                    errorMessage: 'thirdPartyOrderNo: ' . $thirdPartyOrderNo . ', status: ' . $status,
                    order: $order
                );
            }

            DB::commit();

            return $response;
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
