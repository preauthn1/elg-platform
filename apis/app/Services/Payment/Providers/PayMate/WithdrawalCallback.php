<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WithdrawalCallback extends AbstractMethod
{
    use PayMateTrait;

    protected AgentCashFlow $agentCashFlow;

    // 見 api.md 4.3：通知成功商戶回應字串，須回傳純文字 OK 才會停止重複通知
    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'Failure';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 generateSign() 的 key 段為空、簽名可離線計算，且下方 merchantNo 比對
        // 會因 '' !== '' 為 false 而一併失守 ⇒ 先擋掉整個回調。欄位名為屬性（SpeedPay 零複製
        // 共用本 Provider、鍵名不同）故動態取；守衛已記安全事件，logMessage 留 null 不重複落 log。
        if ($this->findMissingCredentials([$this->merchantNoField, $this->md5KeyField], 'withdrawal callback') !== []) {
            return $this->buildWithdrawalFailResponse();
        }

        $input = $request->validate([
            'signMsg' => 'required|string',
            'version' => 'required|string',
            'notifyTime' => 'required|string',
            'content' => 'required|string',
        ]);

        if (!$this->verifyNotifySign($input)) {
            return $this->buildWithdrawalFailResponse(
                logMessage: 'Signature verification failed',
                logContext: ['input' => $input]
            );
        }

        $content = $this->decodeNotifyContent($input['content']);

        if (!isset($content['orderNo'], $content['status'], $content['amount'])) {
            return $this->buildWithdrawalFailResponse(
                logMessage: 'Missing required content field',
                logContext: ['content' => $content]
            );
        }

        if (($content['merchantNo'] ?? null) !== $this->info[$this->merchantNoField]) {
            return $this->buildWithdrawalFailResponse(
                logMessage: 'Merchant number does not match',
                logContext: ['content' => $content]
            );
        }

        try {
            DB::beginTransaction();

            // 我方送出代付請求時的 orderNo 即為 transactionNo
            $payoutRecord = $this->findAndLockPayoutRecord($content['orderNo']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'Payout record not found',
                    logContext: ['orderNo' => $content['orderNo']]
                );
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'Withdrawal order not found',
                    logContext: ['withdrawal_order_id' => $payoutRecord->withdrawal_order_id]
                );
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException($e, $order, $content['orderNo']);
            }

            // 見 api.md 4.1：status 為 SUCCESS/FAIL，PayMate 僅定義此二態，無中間態
            $outcome = $content['status'] === 'SUCCESS'
                ? WithdrawalCallbackOutcome::SUCCESS
                : WithdrawalCallbackOutcome::FAILURE;

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $content['amount'], $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $content,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $content['amount'],
                transactionTime: now()->toDateTimeString(),
            );

            $transactionLog = $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($order, $transactionLog);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
