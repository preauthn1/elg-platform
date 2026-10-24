<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AllPay;

use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DepositCallback extends AbstractMethod
{
    use AllPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'Failure';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 makeSignature() 的 key 段為空、簽名可離線計算，
        // 且 merchantNo 比對會因 '' !== '' 為 false 而一併失守 ⇒ 先擋掉整個回調
        if ($this->findMissingCredentials(['MerchantNo', 'MerchantKey'], 'deposit callback') !== []) {
            return DepositCallbackResponseData::from([
                'response' => $this->notifyFailText,
                'errorMessage' => 'Credentials not configured',
            ]);
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'content' => 'required|string',
            'notifyTime' => 'required|date_format:YmdHis',
            'signMsg' => 'required|string',
            'version' => 'required|string',
        ]);

        // 驗證簽名
        $signature = $this->makeSignature($input);
        if ($signature !== $input['signMsg']) {
            return DepositCallbackResponseData::from([
                'response' => 'Failure',
                'errorMessage' => 'Invalid signature',
            ]);
        }

        // 解碼 content
        $content = json_decode(base64_decode($input['content']), true);

        Log::debug('AllPay Deposit Callback Content:', $content);

        // 驗證 content 格式
        $validator = Validator::make($content, [
            'service' => 'required|string',
            'merchantNo' => 'required|string',
            'paymentCode' => 'required|string',
            'status' => 'required|string',
            'amount' => 'required|string',
            'orderNo' => 'required|string',
            'gbpOrderNo' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return DepositCallbackResponseData::from([
                'response' => 'Failure',
                'errorMessage' => $validator->errors()->first(),
            ]);
        }

        // 驗證商戶號
        if ($content['merchantNo'] !== $this->info['MerchantNo']) {
            return DepositCallbackResponseData::from([
                'response' => 'Failure',
                'errorMessage' => 'Merchant number does not match',
            ]);
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($content['orderNo']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 處理 extraContent
            $extraContent = $content['extraContent'] ?? null;
            if ($extraContent) {
                $extraContent = json_decode(base64_decode($extraContent), true);
                $extraContent = Common::safeJsonEncode($extraContent);
            }

            // 訂單狀態判斷
            if ($content['status'] === 'SUCCESS') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $content['gbpOrderNo'] ?? '',
                    callbackData: $content,
                    amount: $content['amount']
                );

                // AllPay 特殊：更新 extra_content
                if ($extraContent) {
                    $order->update(['extra_content' => $extraContent]);
                }
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $content['gbpOrderNo'] ?? '',
                    callbackData: $content
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
