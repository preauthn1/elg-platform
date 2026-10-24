<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TgPay;

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
use Illuminate\Support\Str;

class DepositCallback extends AbstractMethod
{
    use TgPayTrait;

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
    //   "status": 1,
    //   "msg": "Success",
    //   "data": {
    //     "amount": 10.0,
    //     "transactionRefNum": "62dc336cdedb8be530b1e3999e828b6e",
    //     "merchantRefNum": "202606026859440553",
    //     "paymentMethod": "CREDIT_CARD",
    //     "bankName": "Visa ••0005",
    //     "status": "APPROVED"
    //   }
    // }
    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 verifyCallbackHeaders() 以 hash_equals 比對 config 與 header，
        // config 為空字串時攻擊者送空 header 即 hash_equals('', '') 為 true 而通過、偽造成功回調上分
        // ⇒ 先擋掉整個回調。對外沿用與 header 驗證失敗相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['PUBLIC_KEY', 'SECRET_KEY'], 'deposit callback') !== []) {
            return $this->buildDepositFailResponse(errorMessage: 'Callback header verification failed');
        }

        if (!$this->verifyCallbackHeaders($request)) {
            return $this->buildDepositFailResponse(errorMessage: 'Callback header verification failed');
        }

        $input = $request->validate([
            'status' => 'required',
            'msg' => 'nullable|string',
            'data' => 'required|array',
            'data.amount' => 'required|numeric',
            'data.transactionRefNum' => 'required|string',
            'data.merchantRefNum' => 'required|string',
            'data.status' => 'required|string',
            'data.paymentMethod' => 'nullable|string',
            'data.bankName' => 'nullable|string',
        ]);

        $orderNo = (string) $input['data']['merchantRefNum'];
        $amount = Common::numAdd('0', (string) $input['data']['amount'], 4);

        if ($this->isDepositCallbackPending((string) $input['data']['status'])) {
            Log::info($this->getDriverName() . ' deposit callback pending, skip processing', [
                'order_no' => $orderNo,
                'data_status' => $input['data']['status'],
            ]);

            return $this->buildDepositSuccessResponse();
        }

        $isSuccess = $this->isDepositCallbackSuccess($input['status'], (string) $input['data']['status']);

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($orderNo);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            if ($isSuccess) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['data']['transactionRefNum'],
                    callbackData: $input,
                    amount: $amount
                );
            } else {
                Log::warning($this->getDriverName() . ' callback indicates deposit failure', [
                    'order_no' => $orderNo,
                    'transaction_ref_num' => $input['data']['transactionRefNum'],
                    'status' => $input['status'],
                    'data_status' => $input['data']['status'],
                ]);

                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['data']['transactionRefNum'],
                    callbackData: $input
                );
            }

            DB::commit();

            if ($isSuccess) {
                return $this->buildDepositSuccessResponse($order);
            }

            return $this->buildDepositFailResponse(
                errorMessage: sprintf(
                    'Deposit failed with status: %s, data.status: %s',
                    (string) $input['status'],
                    (string) $input['data']['status'],
                ),
                logMessage: (string) ($input['msg'] ?? $input['data']['status']),
                order: $order,
            );
        } catch (Exception $e) {
            DB::rollBack();

            $message = $this->getDriverName() . ' deposit callback error: ' . $e->getMessage();
            Log::error($message, [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            BotNotify::channel('error')->send($message);

            return $this->buildDepositFailResponse(errorMessage: $e->getMessage());
        }
    }

    private function isDepositCallbackPending(string $dataStatus): bool
    {
        return Str::upper($dataStatus) === 'PENDING';
    }

    private function isDepositCallbackSuccess(mixed $status, string $dataStatus): bool
    {
        $topLevelOk = (string) $status === '1' || (int) $status === 1;

        return $topLevelOk && Str::upper($dataStatus) === 'APPROVED';
    }
}
