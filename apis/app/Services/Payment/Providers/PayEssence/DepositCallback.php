<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayEssence;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use PayEssenceTrait;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(\App\Models\AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $merchantCode = $this->info['MERCHANT_CODE'] ?? null;
        $secretKey = $this->info['SECRET_KEY'] ?? null;

        if (!$merchantCode || !$secretKey) {
            throw new ApiException('PayEssence configuration missing');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'MerchantCode' => 'required|string',
            'TransNum' => 'required|string',
            'DepositId' => 'required|string',
            'Amount' => 'required|string',
            'Result' => 'required|string',
            'CheckString' => 'required|string',
            'CheckString2' => 'nullable|string',
        ]);

        Log::info('PayEssence Deposit Callback Received', [
            'merchant_code' => $input['MerchantCode'] ?? null,
            'trans_num' => $input['TransNum'] ?? null,
            'result' => $input['Result'] ?? null,
        ]);

        // 驗證 MerchantCode
        if (($input['MerchantCode'] ?? null) !== $merchantCode) {
            throw new ApiException('Invalid merchant code in callback');
        }

        // 獲取訂單
        $transNum = $input['TransNum'] ?? null;
        if (!$transNum) {
            throw new ApiException('Missing TransNum in callback');
        }

        DB::beginTransaction();

        try {
            $order = $this->findAndLockDepositOrder($transNum);

            try {
                // 檢查訂單狀態：僅 status=0 (CREATED) 的訂單允許被回調修改
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 解析結果
            $result = (int) ($input['Result'] ?? 0);
            $resultStatus = match ($result) {
                10001 => OrderStatusEnum::SUCCESS->value,   // 成功
                10002 => OrderStatusEnum::FAILED->value,    // 失敗
                10004 => OrderStatusEnum::CREATED->value,   // 待處理，等待後續回調
                default => OrderStatusEnum::FAILED->value,
            };

            // 10004 = 待處理（Pending）：保持訂單狀態不變，等待後續回調
            if ($resultStatus === OrderStatusEnum::CREATED->value) {
                DB::rollBack();

                Log::info('PayEssence Deposit Callback pending, waiting for next callback', [
                    'order_no' => $transNum,
                    'result' => $result,
                ]);

                return $this->buildDepositSuccessResponse($order);
            }

            // 驗證簽名 - CheckString
            $checkString = $input['CheckString'] ?? null;
            if ($checkString && !$this->verifyDepositCheckString($merchantCode, $secretKey, $transNum, $checkString)) {
                Log::warning('PayEssence Callback CheckString verification failed', [
                    'order_no' => $transNum,
                    'expected' => hash('sha256', $merchantCode . $secretKey . $transNum),
                    'received' => $checkString,
                ]);
                throw new ApiException('CheckString verification failed');
            }

            // 成功交易才需驗證 CheckString2
            if ($resultStatus === OrderStatusEnum::SUCCESS->value) {
                $checkString2 = $input['CheckString2'] ?? null;
                if (!$checkString2 || !$this->verifyDepositCheckString2($merchantCode, $secretKey, $transNum, $checkString2)) {
                    Log::error('PayEssence Callback CheckString2 verification failed', [
                        'order_no' => $transNum,
                    ]);
                    throw new ApiException('CheckString2 verification failed');
                }

                // 驗證金額（金額不符標記為人工確認）
                $this->updateDepositOrderSuccess($order, $input['DepositId'] ?? '', $input, $input['Amount']);
            } else {
                // 失敗的訂單
                $this->updateDepositOrderFailed($order, $input['DepositId'] ?? '', $input);
            }

            DB::commit();

            Log::info('PayEssence Deposit Callback Processed', [
                'order_id' => $order->id,
                'order_no' => $transNum,
                'status' => $resultStatus,
                'deposit_id' => $input['DepositId'] ?? '',
            ]);

            // 返回成功的回調響應
            return new DepositCallbackResponseData(
                order: $order,
                response: json_encode(['Status' => 'OK']),
                httpStatusCode: 200,
                errorMessage: null
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }
}
