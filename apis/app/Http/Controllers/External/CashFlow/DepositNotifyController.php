<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\CashFlow;

use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\FirebaseCloudMessage;
use App\Models\Member;
use App\Models\Order;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 三方金流通知回調訂單狀態與上分
 *
 * ⚠️ **回調端只用 agent_cash_flow_id 反查、不看 acf.status**（是否刻意未見出處）——所以
 * **停用一個渠道不會擋掉在途訂單**，已經送到三方那邊的單仍會回來上分。
 * 要真正止血必須另外處理在途單，不能只把渠道 status 關掉就當作結束。
 *
 * @knowledge rules/payment-callback-common-checks
 */
class DepositNotifyController extends BaseController
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * 三方金流回調通知
     */
    public function __invoke(Request $request, int $agentCashFlowId = 0)
    {
        if (!$agentCashFlowId) {
            throw new ApiException('Unknow payment provider');
        }

        $agentCashFlow = AgentCashFlow::with(['cashFlow'])->find($agentCashFlowId);

        if (!$agentCashFlow) {
            throw new ApiException('Payment provider not found', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 記錄收到的三方回調請求（遮硬憑證如 PIN/卡號，保留其餘欄位供除錯／驗簽）
        Log::info(sprintf('[%s] Deposit callback received', $agentCashFlow->name), [
            'agent_cash_flow_id' => $agentCashFlowId,
            'agent_cash_flow' => $agentCashFlow->only(['id', 'name']),
            'url' => $request->fullUrl(),
            'request' => Common::maskCredentials($request->all()),
        ]);

        // 應確認 cashFlow.type 為 1 (線上代收)
        if (!$agentCashFlow->cashFlow || $agentCashFlow->cashFlow->type !== 1) {
            Log::error('Invalid cash flow type for deposit callback', [
                'agent_cash_flow_id' => $agentCashFlowId,
                'cash_flow_type' => $agentCashFlow->cashFlow->type ?? null,
            ]);
            throw new ApiException('Invalid cash flow type: Expected online deposit', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 調用三方 Driver：未特殊處理的例外交給 bootstrap/app.php 的 render()/report() 鏈路
        $provider = $this->paymentService->getProvider($agentCashFlow);
        /** @var DepositCallbackResponseData $result */
        $result = $provider->depositCallback($request);

        if ($result->errorMessage) {
            Log::error($result->errorMessage, [
                'request' => Common::maskCredentials($request->all()),
                'agent_cash_flow_id' => $agentCashFlowId,
            ]);
        }

        if ($result->order) {
            $order = $result->order;
            // ! 檢查訂單狀態使否已經上分避免重複上分
            if ($order->status === OrderStatusEnum::SUCCESS->value) {
                $owner = $order->owner;

                if ($owner instanceof Member && $owner->event_mode > 0) {
                    // 鎖分模式; 不上分, 直接發TG通知
                    $this->notifyLockMode($owner, $order);
                } else {
                    // 回調成功，上分，新增推播
                    $this->processSuccessfulDeposit($owner, $order);
                }
            }
        }

        // 依據三方規範，返回回調成功格式（使用 provider 指定的 HTTP status code）
        if (is_array($result->response)) {
            // 返回 json
            return response()->json($result->response, $result->httpStatusCode);
        }

        // 返回字串
        return response($result->response, $result->httpStatusCode);
    }

    /**
     * 鎖分模式通知
     */
    private function notifyLockMode(Member $member, Order $order): void
    {
        $agent = $member->agent;
        $message = '[鎖分模式禁止上分通知]' . PHP_EOL .
            '代　　理:' . $agent->username . '(' . $agent->id . ')' . PHP_EOL .
            '玩　　家:' . $member->username . '(' . $member->id . ')' . PHP_EOL .
            '訂單編號:' . $order->order_no . PHP_EOL .
            '點　　數:' . $order->point;

        Log::warning($message);
        BotNotify::channel('warn')->send($message);
    }

    /**
     * 處理儲值成功
     */
    private function processSuccessfulDeposit(Member|Agent $owner, $order): void
    {
        if ($order->status === OrderStatusEnum::SUCCESS->value) {
            $this->paymentService->updateMemberBalance($order);

            // Firebase 推播僅適用於會員，代理不發送
            if ($owner instanceof Member) {
                FirebaseCloudMessage::create([
                    'member_id' => $owner->id,
                    'title' => '系統訊息',
                    'content' => '線上儲值成功',
                    'url' => 'RecordList',
                ]);
            }
        }
    }
}
