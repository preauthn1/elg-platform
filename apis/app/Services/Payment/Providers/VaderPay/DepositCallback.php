<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

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
    use VaderPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'Operation Success';

    protected string|array $notifyFailText = 'Operation Failed';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 VaderPay 代收回調
     *
     * @param  Request  $request  回調請求
     * @return DepositCallbackResponseData 回調響應
     *
     * @throws ApiException 當簽名驗證失敗時
     */
    public function handle(Request $request): DepositCallbackResponseData
    {
        $input = $request->validate([
            'InvoiceNo' => 'required|string',
            'ReceiverBank' => 'nullable|string',
            'ReceiverAccount' => 'nullable|string',
            'Currency' => 'required|string',
            'Amount' => 'required|numeric',
            'OriginalAmount' => 'required|numeric',
            'PaymentDate' => 'required|string',
            'MerchantCode' => 'required|string',
            'RefId' => 'required|string',
            'Status' => 'required|integer',
            'Signature' => 'required|string',
            'StatusMessage' => 'required|string',
            'DeclineReason' => 'nullable|string',
        ]);

        $credentials = $this->getMerchantCredentials();
        if ($input['MerchantCode'] !== $credentials->merchantCode) {
            throw new ApiException(sprintf('%s deposit callback merchant code mismatch', $this->getDriverName()));
        }

        if (!$this->verifyPayinCallbackSignature($input, $credentials)) {
            throw new ApiException(sprintf('%s deposit callback signature verification failed', $this->getDriverName()));
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($input['RefId']);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'order' => $order,
                    'response' => ($e->getCode() === 200)
                        ? $this->successResponse()
                        : $this->failResponse(),
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            $status = (int) $input['Status'];
            $statusEnum = VaderPayStatusCodeEnum::fromValue($status);
            if ($statusEnum->isPayinSuccess()) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['InvoiceNo'],
                    callbackData: $input,
                    amount: (string) $input['Amount']
                );
            } elseif ($statusEnum->isPayinFailed()) {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['InvoiceNo'],
                    callbackData: $input
                );
            } else {
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => $order,
                ]);
            }

            DB::commit();

            return DepositCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send(
                sprintf('%s deposit callback error: %s', $this->getDriverName(), $e->getMessage())
            );

            throw $e;
        }
    }
}
