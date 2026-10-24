<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TopPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use TopPayTrait;

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
        $input = $request->validate([
            'platOrderNum' => 'required|string',
            'version' => 'nullable|string',
            'orderNum' => 'required|string',
            'amount' => 'required|numeric',
            'fee' => 'nullable|numeric',
            'customerName' => 'nullable|string',
            'customerEmail' => 'nullable|string',
            'customerPhone' => 'nullable|string',
            'status' => 'required|string',
            'sign' => 'required|string',
        ]);

        if (!$this->verifyCallbackSign($input)) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($input['orderNum']);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // Pay-In status: SUCCESS / PAY_CANCEL / PAY_ERROR / INIT_ORDER / NO_PAY
            if ($input['status'] === 'SUCCESS') {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['platOrderNum'],
                    callbackData: $input,
                    amount: $input['amount'],
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['platOrderNum'],
                    callbackData: $input,
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' deposit callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => \App\Helpers\Common::formatTrace($e),
            ]);

            throw $e;
        }
    }
}
