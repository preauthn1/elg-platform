<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\YeePay;

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
    use YeePayTrait;

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
        // 驗證請求參數格式
        $input = $request->validate([
            'code' => 'required|integer|in:0,1',
            'merchant_num' => 'required|string',
            'coin' => 'required|string', // 代收金额单位：1 = 1越南盾 (VND)
            'pay_coin' => 'required|string',
            'merchant_order' => 'required|string', // 我方訂單號
            'serial_number' => 'required|string', // 三方訂單號
            'order_time' => 'required|string', // 下單時間 格式：YYYY-MM-DD HH:MM:SS
            'success_time' => 'required|string', // 付款成功時間 格式：YYYY-MM-DD HH:MM:SS
            'sign' => 'required|string',
        ]);

        // 驗證簽名
        if ($this->checkSign($input, $this->info['API_SECRET']) === false) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['merchant_order']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // YeePay 只有成功才會收到回調
            // 更新訂單為成功（會自動檢查金額是否匹配）
            $this->updateDepositOrderSuccess(
                order: $order,
                thirdPartyOrderNo: $input['serial_number'],
                callbackData: $input,
                amount: $input['coin']
            );

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
