<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

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

class DepositCallback extends AbstractMethod
{
    use SurePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // {
    //     "status_message": "success",
    //     "token": "5e965948a66b715c6e761b2671a16513",
    //     "currency": "MYR",
    //     "amount": "52.00",
    //     "fee": "0.91",
    //     "merchant": "SPREE003CJ7",
    //     "customer": "yunko0026",
    //     "refid": "202511271794992494",
    //     "status": "1",
    //     "srcbankcode": "DNDQR",
    //     "trxno": "89072921",
    //     "clientip": "0.0.0.0"
    // }
    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 generateCallbackToken() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與token 驗證失敗相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['CALLBACK_API_KEY'], 'deposit callback') !== []) {
            return $this->buildDepositFailResponse(errorMessage: 'Token verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'token' => 'required|string',
            'merchant' => 'required|string',
            'amount' => 'required|string',
            'refid' => 'required|string', // 我方訂單號
            'trxno' => 'required|string', // 三方交易號
            'status' => 'required|string', // 1=success
            'status_message' => 'nullable|string', // 1=success
        ]);

        try {
            DB::beginTransaction();

            // 驗證 token
            if (!$this->checkToken($input)) {
                DB::rollBack();

                return $this->buildDepositFailResponse(errorMessage: 'Token verification failed');
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['refid']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 訂單狀態判斷
            if ($input['status'] === '1') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['trxno'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['trxno'],
                    callbackData: $input
                );
            }

            DB::commit();

            if ($input['status'] === '1') {
                return $this->buildDepositSuccessResponse($order);
            }

            return $this->buildDepositFailResponse(
                errorMessage: sprintf('Deposit failed with status: %s, message: %s', $input['status'], $input['status_message'] ?? ''),
                logMessage: $input['status_message'],
                order: $order,
            );

        } catch (Exception $e) {
            DB::rollBack();

            $message = $this->getDriverName() . ' deposit callback error: ' . $e->getMessage();
            Log::error($message, [
                'message' => $e->getMessage(),
                'exception' => Common::formatTrace($e),
            ]);
            BotNotify::channel('error')->send($message);

            return $this->buildDepositFailResponse(errorMessage: $e->getMessage());
        }
    }
}
