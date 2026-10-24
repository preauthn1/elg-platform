<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use VtPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'SUCCESS';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 1. 獲取加密的回調數據
        $encryptedData = $request->input('response');

        if (empty($encryptedData)) {
            Log::error($this->getDriverName() . ' callback data is empty');
            throw new ApiException('Callback data is empty');
        }

        // 2. AES 解密回調數據
        $input = $this->decrypt($encryptedData);

        // [
        //     'order_status' => 5,
        //     'sh_order_no' => '202602098622232661',
        //     'order_amount' => 100.0,
        //     'request_amount' => 100.0,
        //     'order_id' => 'D-0902262020685354906095617',
        // ]

        // 3. 驗證必要字段（根據文檔：sh_order_no, order_status, order_amount, paid_amount）
        $requiredFields = ['sh_order_no', 'order_status'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                Log::error($this->getDriverName() . ' callback missing required field', [
                    'field' => $field,
                    'input' => $input,
                ]);
                throw new ApiException('Missing required field: ' . $field);
            }
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['sh_order_no']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 判斷是否成功
            // order_status: 3 = 成功，5 = 失敗（參考文檔）
            $isSuccess = (int) $input['order_status'] === 3;

            if ($isSuccess) {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['order_id'] ?? '',
                    callbackData: $input,
                    amount: $input['paid_amount'] ?? $input['order_amount'] ?? $order->amount
                );

                $response = $this->buildDepositSuccessResponse($order);
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['order_id'] ?? '',
                    callbackData: $input
                );
                $response = $this->buildDepositFailResponse(
                    errorMessage: sprintf('Deposit failed, order_id: %s, order_status: %s', $input['order_id'] ?? '', $input['order_status'] ?? ''),
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
