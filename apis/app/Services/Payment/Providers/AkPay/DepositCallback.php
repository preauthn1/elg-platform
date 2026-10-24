<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AkPay;

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

/**
 * AkPay 代收回調
 *
 * 參照官方文檔：https://example.invalid/view/22077629/2s9Ykt4ycb#4d619cce-5794-423a-92c9-86b468beec11
 */
class DepositCallback extends AbstractMethod
{
    use AkPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    // 定義返回三方的信息
    protected string|array $notifySuccessText = [
        'success' => true,
    ];

    protected string|array $notifyFailText = [
        'success' => false,
    ];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 generateSecretKey() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤的例外相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['MERCHANT_TOKEN'], 'deposit callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'source_id' => 'required|string',
            'tx_id' => 'required|integer',
            'status' => 'required|string',
            'bank_code' => 'required|string',
            'amount' => 'required|string',
            'actual_amount' => 'required|string',
            'currency' => 'required|string',
            'secret_key' => 'required|string',
        ]);

        // 驗證簽名
        if (!$this->checkSecretKey($input, $this->info['MERCHANT_TOKEN'])) {
            throw new ApiException('Signature verification failed');
        }

        $orderId = str_replace($this->info['MERCHANT_CODE'] . '_', '', $input['source_id']);
        $orderStatus = $input['status'];
        $thirdPartyOrderNo = $input['tx_id'];
        $amount = $input['amount'];

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）- 使用 Trait 方法
            $order = $this->findAndLockDepositOrder($orderId);

            try {
                // 檢查訂單狀態 - 使用 Trait 方法
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                Log::error($e->getMessage());

                // 使用 Trait 方法處理異常
                return $this->handleDepositOrderException($e, $order);
            }

            // 訂單狀態判斷
            if ($orderStatus === 'Completed') {
                // 更新訂單為成功（會自動檢查金額是否匹配）- 使用 Trait 方法
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input,
                    amount: $amount
                );
            } else {
                // 更新訂單為失敗 - 使用 Trait 方法
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input
                );
            }

            DB::commit();

            // 返回成功響應 - 使用 Trait 方法
            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
