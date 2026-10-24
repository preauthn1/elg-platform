<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FlashPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use FlashPayTrait;

    protected array $info;

    protected array|string $notifySuccessText = 'OK';

    protected array|string $notifyFailText = 'FAIL';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 checkSign() 的 secret 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'deposit callback') !== []) {
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
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($input['tran_unique']);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // FlashPay 代收只在 completed 時才更新訂單狀態；
            // 其他狀態（initiated、expired 或未知）不更新，訂單維持 CREATED，回 200 OK 讓對方停止重試
            if ($input['tran_status'] === 'completed') {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['tran_token'],
                    callbackData: $input,
                    amount: $input['tran_amount'],
                );
            } else {
                Log::warning($this->getDriverName() . ' deposit callback non-completed status received, order kept at CREATED', [
                    'order_no' => $order?->order_no,
                    'tran_status' => $input['tran_status'],
                    'tran_token' => $input['tran_token'],
                ]);
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
