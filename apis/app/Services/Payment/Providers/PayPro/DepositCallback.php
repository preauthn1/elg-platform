<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayPro;

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
    use PayProTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時簽名的 secret／key 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials([$this->merchantKeyField], 'deposit callback') !== []) {
            throw new Exception('Invalid signature');
        }

        // {
        //     "tradeNo": "202602032630528378",
        //     "message": "Mock success status",
        //     "wayCode": "QRIS",
        //     "status": "0000",
        //     "channelCode": "QRIS",
        //     "amount": 100000,
        //     "timestamp": "1770101776756",
        //     "platFormTradeNo": "E000czqcfalkxyti0pz4_da",
        //     "sign": "05e162af807f112e4ee1dc5ba05e8c78",
        //     "successTime": "2026-02-03 14:56:16"
        //   }

        // 驗證請求參數格式
        $input = $request->validate([
            'platFormTradeNo' => 'required|string',
            'tradeNo' => 'required|string',
            'amount' => 'required|numeric',
            'status' => 'required|string',
            'message' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'sign' => 'required|string',
        ]);

        // 有一些不用驗證的參數，直接從 request 中取得
        $input = $request->all();

        // 驗證簽名
        if (!$this->verifySign($input)) {
            Log::error($this->getDriverName() . ' 代收回調簽名驗證失敗', [
                'input' => $this->maskSensitiveParams($input),
            ]);
            throw new Exception('Invalid signature');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['tradeNo']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 訂單狀態判斷
            // PayPro 狀態碼：0000=成功, 0001=部分支付, 0015=處理中, 其他=失敗
            $status = $input['status'] ?? '';
            if ($status === '0000') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['platFormTradeNo'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } elseif ($status === '0001') {
                // 部分支付，也視為成功（使用實際支付金額）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['platFormTradeNo'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } elseif ($status === '0015') {
                // 處理中，不更新訂單狀態，只記錄日誌
                Log::info($this->getDriverName() . ' 代收訂單處理中', [
                    'orderNo' => $input['tradeNo'],
                    'platFormTradeNo' => $input['platFormTradeNo'],
                    'status' => $status,
                ]);
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'order' => $order,
                    'response' => $this->notifySuccessText,
                    'errorMessage' => 'Order is processing',
                ]);
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['platFormTradeNo'],
                    callbackData: $input
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' 代收回調處理異常', [
                'input' => $this->maskSensitiveParams($input),
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
