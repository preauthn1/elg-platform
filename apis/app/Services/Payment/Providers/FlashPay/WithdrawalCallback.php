<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FlashPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use FlashPayTrait;

    protected array $info;

    protected array|string $notifySuccessText = 'OK';

    protected array|string $notifyFailText = 'FAIL';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 checkSign() 的 secret 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'withdrawal callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        $input = $request->validate([
            'tran_token' => 'required|string',
            'tran_unique' => 'required|string',
            'tran_amount' => 'required|numeric',
            'tran_status' => 'required|string',
            'tran_signature' => 'required|string',
        ]);

        if (!$this->checkSign($request->all(), $this->info['SECRET_KEY'])) {
            Log::error($this->getDriverName() . ' callback signature verification failed', [
                'tran_unique' => $input['tran_unique'],
            ]);
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($input['tran_unique']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse();
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'callback order not found',
                    logContext: [
                        'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
                        'transaction_no' => $input['tran_unique'],
                    ],
                );
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException(
                    exception: $e,
                    order: $order,
                    orderNo: $input['tran_unique'],
                );
            }

            // refund：銀行端退款，僅在訂單尚未終態（PAYMENT_PROCESSING）時會走到這裡
            // （已是 THIRD_PARTY_SUCCESS 的訂單會在上面 isPayoutRecordProcessed() 就被攔截，不會進來這段）
            // 目前僅記錄，不轉移狀態；訂單會停留在 PAYMENT_PROCESSING 待人工確認
            if ($input['tran_status'] === 'refund') {
                Log::warning($this->getDriverName() . ' withdrawal refund callback received, order kept at PAYMENT_PROCESSING', [
                    'order_id' => $order->id,
                    'tran_unique' => $input['tran_unique'],
                    'tran_token' => $input['tran_token'],
                ]);
            }

            $outcome = match ($input['tran_status']) {
                'completed' => WithdrawalCallbackOutcome::SUCCESS,
                'rejected' => WithdrawalCallbackOutcome::FAILURE,
                default => WithdrawalCallbackOutcome::INDETERMINATE,
            };

            $isSuccessPayout = $outcome === WithdrawalCallbackOutcome::SUCCESS;

            if ($isSuccessPayout) {
                $this->validateCallbackAmount($order, $input['tran_amount'], $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $isSuccessPayout,
            );

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $isSuccessPayout);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => \App\Helpers\Common::formatTrace($e),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
