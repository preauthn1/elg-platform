<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TrustPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepositCallback extends AbstractMethod
{
    use TrustPayTrait;

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
        $requestAll = $request->all();
        $input = $request->validate([
            'amount' => 'required|string',
            'currency' => 'required|string',
            'hash' => 'required|string',
            'ref_no' => 'required|string',
            'status' => 'required|string',
            'transaction_reference' => 'required|string',
        ]);

        $secretKey = $this->info['SECRET_KEY'] ?? null;
        if (!$secretKey) {
            throw new ApiException('Secret key not configured');
        }

        $statusNormalized = Str::lower($input['status']);
        $isSuccess = $statusNormalized === 'completed';
        $orderNo = $input['ref_no'];
        $thirdPartyOrderNo = $input['transaction_reference'];
        $amount = Common::numAdd('0', $input['amount'], 2);
        $currency = $input['currency'];

        $expectedHash = $this->generateCallbackSignature($thirdPartyOrderNo, $amount, $currency, $secretKey);
        if (!hash_equals($expectedHash, $input['hash'])) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($orderNo);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            if ($isSuccess === true) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $requestAll,
                    amount: $amount
                );

                $response = $this->buildDepositSuccessResponse($order);
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $requestAll
                );

                $response = $this->buildDepositFailResponse(
                    errorMessage: 'thirdPartyOrderNo: ' . $thirdPartyOrderNo . ', status: ' . $input['status'],
                    order: $order
                );
            }

            DB::commit();

            return $response;
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
