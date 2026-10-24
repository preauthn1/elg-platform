<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositCallback extends AbstractMethod
{
    use FPayTrait;

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
        // 憑證未配置時 generateToken() 的 secret 段為空、token 退化為 md5(orderId) 可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['APP_SECRET'], 'deposit callback') !== []) {
            throw new ApiException('Token verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'order_id' => 'required|string',
            'amount' => 'required|string',
            'currency' => 'required|string',
            'order_status' => 'required|string',
            'status' => 'required|boolean',
            'charge' => 'required|string',
            'token' => 'required|string',
            'name' => 'nullable|string',
            'type' => 'required|string|in:deposit,withdrawal',
            'ccno' => 'nullable|string',
            'mode' => 'nullable|string',
            'payment_type' => 'nullable|string',
            'username' => 'nullable|string',
        ]);

        // 驗證簽名
        if ($this->generateToken($this->info['APP_SECRET'], $input['order_id']) !== $input['token']) {
            throw new ApiException('Token verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['order_id']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 根據 payment_type 動態更新 order_type_id（FPay 特殊邏輯）
            $orderTypeId = $this->getOrderTypeId($input['payment_type'] ?? null, $order);

            // 訂單狀態判斷
            if ($input['order_status'] === 'completed') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: '',
                    callbackData: $input,
                    amount: $input['amount'],
                    fee: $input['charge']
                );

                // FPay 特殊：更新 order_type_id
                if ($orderTypeId !== $order->order_type_id) {
                    $order->update(['order_type_id' => $orderTypeId]);
                }
            } elseif ($input['order_status'] === 'fail') {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: '',
                    callbackData: $input
                );
            } else {
                throw new ApiException('Unknown order status: ' . $input['order_status']);
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 根據 payment_type 獲取對應的 OrderType ID
     *
     * 規則：
     *  - payment_type = 'EWallet' → 使用 FPay.EWallet
     *  - 其他情況（空值、'VA' 或其他） → 使用 FPay.VA
     *
     * @param  string|null  $paymentType  回調中的 payment_type
     * @param  Order  $order  原始訂單
     * @return int OrderType ID
     */
    protected function getOrderTypeId(?string $paymentType, Order $order): int
    {
        // 只有明確是 EWallet 時才使用 FPay.EWallet，其他都用 FPay.VA
        $orderTypeKey = ($paymentType === 'EWallet') ? 'FPay.EWallet' : 'FPay.VA';

        // 查找相同幣別且 key 匹配的 OrderType
        $newOrderType = OrderType::query()
            ->where('cash_flow_id', $this->agentCashFlow->cash_flow_id)
            ->where('currency_id', $order->currency_id)
            ->where('key', $orderTypeKey)
            ->where('parent', '>', 0)
            ->first();

        // 如果找到，返回新的 order_type_id，否則保持原值
        return $newOrderType ? $newOrderType->id : $order->order_type_id;
    }
}
