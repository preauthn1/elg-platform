<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EpicPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 代收回調 https://example.invalid/external/api/deposit/notify/190
 * 代付回調 https://example.invalid/external/api/withdrawal/notify/191
 */
class DepositCallback extends AbstractMethod
{
    use EpicPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 generateCallbackToken() 的 merchant／secret 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['MERCHANT_CODE', 'SECRET_KEY'], 'deposit callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // {
        //   "id": "202601149042085136",
        //   "status": "1",
        //   "amount": "10.00",
        //   "fee": "0.11",
        //   "message": "Success",
        //   "bfname": null,
        //   "token": "f0df3d41a9801e93ea189dcd34687176",
        //   "ts": "1768379714"
        // }

        $input = $request->validate([
            'id' => 'required|string', // 提交的 trxid
            'status' => 'required|string',
            'amount' => 'required|string',
            'fee' => 'required|string',
            'message' => 'required|string',
            'bfname' => 'nullable|string',
            'token' => 'required|string',
            'ts' => 'required|string',
        ]);

        // 驗證簽名
        $token = [REDACTED], $this->info['MERCHANT_CODE'], $this->info['SECRET_KEY']);

        if ($token !== $input['token']) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['id']);

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
                    thirdPartyOrderNo: $input['id'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['id'],
                    callbackData: $input
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
