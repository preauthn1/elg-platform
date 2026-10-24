<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 代付停留單（21/22）的人工兜底定案（target/tickets#4137）。
 *
 * 代付請求結果未明（逾時／連線失敗）後訂單停留在 21（代付請求中）等回調自動定案；
 * 已受理但回調始終未達的訂單停留在 22（代付處理中）。舊制的 23（三方查證中）已廢除並移除，
 * 本命令刻意不處理 23：線上若再出現，代表有未知寫入者，須先追查來源。
 * 回調一直沒來時，由人工到三方商戶後台核對實際出款結果，再以本命令定案：
 *  - 未出款 → --outcome=resend：解鎖為 29，重新開放重送
 *  - 已出款 → --outcome=success：定案 24，鏡射回調成功側效
 *    （payment_time 冪等回填＋TransactionLog → STATUS_SUCCESS；日誌缺失不擋定案，改告警）
 *
 * 守衛：21 的 in-flight 保護窗（3 分鐘，與後台人工接口同步）；鎖內以「狀態＋updated_at」
 * 雙比對複核——防 ABA 問題（ABA problem：值走了 A→B→A 一圈又變回原值，「值沒變」的
 * 檢查會誤以為沒被動過）。本案場景：21 → 29 → 重新發起又回到 21，兩個 21 的狀態值
 * 相同、實為兩次不同的發起，只比對狀態會把針對前一次發起的人工判斷錯寫到新一次
 * 發起的訂單上。
 *
 * @knowledge rules/payment-cost-summary
 * @knowledge rules/payout-submit-and-status
 */
class ResolvePayoutOrder extends Command
{
    protected $signature = 'withdrawal:resolve-payout
        {order_id : 提款訂單 ID}
        {--outcome= : 人工核對結果：resend（支付商查無此單，解鎖為 29 可重送）或 success（支付商已出款，定案 24）}
        {--dry-run : 只顯示將執行的動作，不寫入}';

    /** @var array<int, string> 舊名保留（歷史告警訊息／runbook 仍引用） */
    protected $aliases = ['withdrawal:resolve-verifying'];

    protected $description = '代付停留單人工定案（21/22）：解鎖重送(29)或定案成功(24)';

    /** 本命令可處理的訂單狀態（target/tickets#4137） */
    private const RESOLVABLE_STATUSES = [
        WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
        WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
    ];

    /** 21 的 in-flight 保護窗（分鐘，與後台人工接口同步）：低於此時長視為原請求可能仍在途 */
    private const IN_FLIGHT_PROTECTION_MINUTES = 3;

    public function handle(): int
    {
        $outcome = (string) $this->option('outcome');
        if (!in_array($outcome, ['resend', 'success'], true)) {
            $this->error('--outcome 必須為 resend（查無此單，解鎖重送）或 success（已出款，定案成功）');

            return self::FAILURE;
        }

        $orderId = (int) $this->argument('order_id');
        $order = WithdrawalOrder::query()->find($orderId);

        if (!$order) {
            $this->error(sprintf('訂單 %d 不存在', $orderId));

            return self::FAILURE;
        }

        $initialStatus = (int) $order->status;
        $initialUpdatedAt = (string) $order->updated_at;

        $guardError = $this->guardResolvable($order, $initialStatus);
        if ($guardError !== null) {
            $this->error($guardError);

            return self::FAILURE;
        }

        $targetStatus = $outcome === 'success'
            ? WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS
            : WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED;

        $this->info(sprintf(
            '訂單 %d（金額 %s，member_id %d）：%d → %d（%s）',
            $order->id,
            $order->amount,
            $order->member_id,
            $initialStatus,
            $targetStatus->value,
            $outcome === 'success' ? '已出款，定案成功' : '查無此單，解鎖重送',
        ));

        if ($this->option('dry-run')) {
            $this->info('[dry-run] 未寫入');

            return self::SUCCESS;
        }

        if (!$this->confirm('確認執行定案？')) {
            $this->info('已取消，未寫入');

            return self::SUCCESS;
        }

        $missingTransactionLog = false;

        try {
            DB::transaction(function () use ($orderId, $initialStatus, $initialUpdatedAt, $targetStatus, &$missingTransactionLog): void {
                $locked = WithdrawalOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

                // 鎖內複核：狀態值＋updated_at 雙比對——確認期間回調（或後台按鈕）可能已搶先改單；
                // 也防 ABA（21 → 29 → 重新發起又回到 21：狀態值相同但已是新一次發起，
                // 針對前一次發起的人工判斷不得寫到新一次發起的訂單上）。
                // updated_at 為秒級精度，但同值碰撞已被 in-flight 保護窗物理排除：本命令只受理
                // 停留 ≥3 分鐘的 21，重新發起的新 21 其 updated_at 與舊 21 必差 3 分鐘以上
                if ((int) $locked->status !== $initialStatus || (string) $locked->updated_at !== $initialUpdatedAt) {
                    throw new RuntimeException(sprintf(
                        '訂單 %d 已被異動（狀態 %d→%d 或時間戳改變，可能回調／後台按鈕／重新發起已介入），本次不寫入',
                        $orderId,
                        $initialStatus,
                        $locked->status,
                    ));
                }

                $updateData = ['status' => $targetStatus->value];

                // 鏡射回調成功側效：payment_time 冪等回填（沒值才寫）
                if ($targetStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS && $locked->payment_time === null) {
                    $updateData['payment_time'] = now()->toDateTimeString();
                }

                // 鏡射回調成功側效：支付商手續費（成本）依最新 payout record 的渠道計算
                if ($targetStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS) {
                    $updateData['payment_cost'] = $locked->paymentCostVia();
                }

                $locked->update($updateData);

                // 鏡射回調成功側效：交易日誌轉成功；日誌缺失＝資料異常，出款既成事實仍定案，
                // 但必須告警交人工補帳，不得靜默留下「訂單 24、帳務無紀錄」的半套終態
                if ($targetStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS) {
                    $missingTransactionLog = !$this->markTransactionLogSuccess($orderId);
                }
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::warning('withdrawal:resolve-payout 人工定案', [
            'order_id' => $orderId,
            'initial_status' => $initialStatus,
            'outcome' => $outcome,
            'target_status' => $targetStatus->value,
        ]);

        $this->notifyResolution($orderId, $outcome, $targetStatus, $missingTransactionLog);

        $this->info('定案完成');

        return self::SUCCESS;
    }

    /**
     * 前置守衛：狀態必須在可定案集合，且 21 須越過 in-flight 保護窗。
     * 回傳錯誤訊息；合格回傳 null。
     */
    private function guardResolvable(WithdrawalOrder $order, int $initialStatus): ?string
    {
        if (!in_array($initialStatus, self::RESOLVABLE_STATUSES, true)) {
            return sprintf('訂單 %d 當前狀態 %d 非可定案狀態（21/22），本命令不處理', $order->id, $order->status);
        }

        // in-flight 保護窗：剛寫入的 21 原 HTTP 可能仍在途，此刻 resend 解鎖為 29 後
        // 原請求仍可能被支付商受理＝重複出款（與後台人工接口同一道防線）
        if ($initialStatus === WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value
            && $order->updated_at !== null
            && $order->updated_at->gt(now()->subMinutes(self::IN_FLIGHT_PROTECTION_MINUTES))) {
            return sprintf(
                '訂單 %d 進入代付請求中(21)未滿 %d 分鐘，原請求可能仍在途，請稍候再試',
                $order->id,
                self::IN_FLIGHT_PROTECTION_MINUTES,
            );
        }

        return null;
    }

    /** 交易日誌轉成功。回傳是否找到並更新（false＝日誌缺失，呼叫端告警）。 */
    private function markTransactionLogSuccess(int $orderId): bool
    {
        $transactionLog = TransactionLog::query()
            ->where('type', TransactionEnum::WITHDRAWAL->value)
            ->where('order_id', $orderId)
            ->first();

        if (!$transactionLog) {
            return false;
        }

        $transactionLog->update(['status' => TransactionStatusEnum::STATUS_SUCCESS->value]);

        return true;
    }

    /**
     * 定案後通知：資料已提交，通知失敗不得讓 CLI 呈現失敗（操作者會誤判定案未生效而重做）。
     */
    private function notifyResolution(int $orderId, string $outcome, WithdrawalOrderStatusEnum $targetStatus, bool $missingTransactionLog): void
    {
        try {
            if ($missingTransactionLog) {
                BotNotify::send(sprintf(
                    '代付訂單 %d 人工定案 24 時查無提款交易日誌（TransactionLog type=%d），請人工核對帳務並補建紀錄',
                    $orderId,
                    TransactionEnum::WITHDRAWAL->value,
                ));
            }

            BotNotify::send(sprintf(
                '代付停留訂單 %d 已由人工定案為 %s(%d)（withdrawal:resolve-payout）',
                $orderId,
                $outcome,
                $targetStatus->value,
            ));
        } catch (\Throwable $notifyError) {
            Log::error('withdrawal:resolve-payout 定案通知送出失敗（定案已提交生效）', [
                'order_id' => $orderId,
                'error' => $notifyError->getMessage(),
            ]);
            $this->warn('定案已生效，但通知送出失敗（已記 log）');
        }
    }
}
