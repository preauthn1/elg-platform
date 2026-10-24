<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

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
    use TakaPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 驗證請求參數格式（根據 API 文檔）
        $input = $request->validate([
            'status' => 'required|string', // 狀態碼
            'currencyCode' => 'required|string',
            'amount' => 'required|numeric',
            'reference' => 'required|string', // 商戶生成的參考號（order_no）
            'transactionTime' => 'required|string', // yyyy-MM-dd HH:mm:ss
            'systemTransactionId' => 'required|string', // GanyoPay 生成的唯一參考碼
            'securityToken' => 'required|string', // MD5 簽名
        ]);

        // 驗證簽名
        if ($this->checkSign($input, $this->info) === false) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['reference']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->successResponse()
                    : $this->failResponse();

                return DepositCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            if ($input['status'] === TakaPayStatusCodeEnum::SUCCESS->value) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['systemTransactionId'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['systemTransactionId'],
                    callbackData: $input
                );
            }

            DB::commit();

            return DepositCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
