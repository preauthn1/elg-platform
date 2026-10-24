<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GffPay;

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
        // 憑證未配置時 sign() 的 secretKey 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['MerchantKey'], 'deposit callback') !== []) {
            return DepositCallbackResponseData::from([
                'response' => ['code' => 400, 'message' => 'Invalid signature'],
            ]);
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'currency_code' => 'required|string',
            'merchant_code' => 'required|string',
            'order_no' => 'required|string',
            'merchant_order_no' => 'required|string',
            'status' => 'required',
            'amount' => 'required',
            'sign' => 'required|string',
        ]);

        $signature = $this->sign($input, $this->info['MerchantKey']);
        if ($signature !== $input['sign']) {
            return DepositCallbackResponseData::from([
                'response' => ['code' => 400, 'message' => 'Invalid signature'],
            ]);
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['merchant_order_no']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                // GffPay 特殊響應格式
                $response = ($e->getCode() === 200)
                    ? ['code' => 200, 'message' => 'Order already processed']
                    : ['code' => 400, 'message' => $e->getMessage()];

                return DepositCallbackResponseData::from([
                    'response' => $response,
                    'order' => $order,
                ]);
            }

            // 訂單狀態判斷
            if ($input['status'] == 2) {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['order_no'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['order_no'],
                    callbackData: $input
                );
            }

            DB::commit();

            return DepositCallbackResponseData::from([
                'response' => ['code' => 200, 'message' => 'Success'],
                'order' => $order,
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 生成簽名
     */
    private function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);

        $signString = '';
        foreach ($input as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;

        return strtoupper(md5($signString));
    }
}
