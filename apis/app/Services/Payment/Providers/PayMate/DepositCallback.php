<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

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

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 generateSign() 的 key 段為空、簽名可離線計算，
        // 且 :58 的 merchantNo 比對會因 '' !== '' 為 false 而一併失守 ⇒ 先擋掉整個回調。
        // 欄位名為屬性（SpeedPay 零複製共用本 Provider、鍵名不同）故動態取。
        if ($this->findMissingCredentials([$this->merchantNoField, $this->md5KeyField], 'deposit callback') !== []) {
            // 守衛已記錄安全事件，此處不重複落 log
            return $this->buildDepositFailResponse(
                errorMessage: 'Credentials not configured',
                logMessage: '',
            );
        }

        $input = $request->validate([
            'signMsg' => 'required|string',
            'version' => 'required|string',
            'notifyTime' => 'required|string',
            'content' => 'required|string',
        ]);

        if (!$this->verifyNotifySign($input)) {
            return $this->buildDepositFailResponse(
                errorMessage: 'Signature verification failed',
                logContext: ['input' => $input]
            );
        }

        $content = $this->decodeNotifyContent($input['content']);

        if (!isset($content['orderNo'], $content['status'], $content['amount'])) {
            return $this->buildDepositFailResponse(
                errorMessage: 'Missing required content field',
                logContext: ['content' => $content]
            );
        }

        if (($content['merchantNo'] ?? null) !== $this->info[$this->merchantNoField]) {
            return $this->buildDepositFailResponse(
                errorMessage: 'Merchant number does not match',
                logContext: ['content' => $content]
            );
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($content['orderNo']);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            if ($content['status'] === 'SUCCESS') {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $content['gbpOrderNo'] ?? '',
                    callbackData: $content,
                    amount: $content['amount']
                );

                $response = $this->buildDepositSuccessResponse($order);
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $content['gbpOrderNo'] ?? '',
                    callbackData: $content
                );

                $response = $this->buildDepositFailResponse(
                    errorMessage: sprintf(
                        'Deposit failed, gbpOrderNo: %s, status: %s',
                        $content['gbpOrderNo'] ?? '',
                        $content['status']
                    ),
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
