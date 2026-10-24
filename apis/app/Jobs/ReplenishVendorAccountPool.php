<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Jobs\Contracts\HasQueueJobRun;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorMaintenances;
use App\Services\VendorAccountPoolService;
use App\Services\VendorService;
use App\Vendors\Contracts\SupportsAccountCreation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 補充單一遊戲商的帳號池（由 create_vendor_account 排程逐商派發）
 *
 * 各遊戲商獨立成 Job，可由多個 worker 並行消化；執行時重新檢查商狀態
 * 與維護狀態（入列到執行間有時間差）。補建編排收斂於
 * VendorAccountPoolService::replenishVendor()，與 --direct 命令共用。
 *
 * @knowledge rules/vendor-account-pool
 */
class ReplenishVendorAccountPool implements HasQueueJobRun, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** 失敗不重試，交由下一輪排程重新派發（水位缺口自 DB 重算，天然可續） */
    public int $tries = 1;

    /** 補建逐帳號打三方 API，池缺口大（如新商初建）時需要較長的執行時間 */
    public int $timeout = 240;

    /**
     * 補建的時間預算佔 $timeout 的比例
     *
     * 逾時由 worker 的 SIGALRM 強制中斷，程序當場死亡、收尾來不及跑，所以補建必須在 timeout
     * 之前主動收手。留兩成（48 秒）給最後一筆的三方往返與 finishRun() 收尾。
     */
    private const float TIME_BUDGET_RATIO = 0.8;

    /**
     * 同商 Job 未消化完成前，重複派發直接略過。
     *
     * 取 270 秒（略短於 everyFiveMinutes 排程間隔）：唯一鎖在「取鎖後、入列失敗」時
     * 不會釋放，若取滿 300 秒會與下一輪排程對齊，造成同商連續整輪被略過。
     */
    public int $uniqueFor = 270;

    public function __construct(
        public int $vendorId,
        /** 執行紀錄的觸發來源（本 job 僅排程派發，SCHEDULE） */
        public QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::SCHEDULE,
        /** 派發端以 QueueJobRun::createPending() 建立的執行列 id；收尾 update 同列 */
        public ?int $queueJobRunId = null,
    ) {
        // 走長時 job 專用連線（retry_after > timeout）：預設連線 retry_after=90，
        // 池缺口大時逐帳號打三方常跑超過 90 秒，會被重複派發誤判 MaxAttemptsExceeded
        $this->onConnection('redis-long-running');
    }

    public function uniqueId(): string
    {
        return (string) $this->vendorId;
    }

    /**
     * 執行期同商互斥（第二道防線，補 uniqueFor 蓋不住的洞）：聚合遊戲商（Evo 61 子商、
     * Top55 等）同 driver 錯開 15 秒後，尾端 delay 可達 15 分鐘，遠超過唯一鎖 270 秒——
     * 鎖過期後下一輪排程會再派發同商 job，佇列同時掛著多輪波次。本 middleware 以
     * vendor id 互斥：先到者執行，重複波次撞鎖直接丟棄（dontRelease，缺口本就由每輪
     * 重算，不需重排）。被丟棄 job 的 queue_job_runs pending 列不收尾（判缺只認成功列，
     * 不受影響），由 clean:queue-job-runs 例行清理。
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        // expireAfter 須 ≥ timeout：worker 崩潰未釋放鎖時，殘鎖最多擋一個排程週期
        return [
            (new WithoutOverlapping((string) $this->vendorId))
                ->dontRelease()
                ->expireAfter(300),
        ];
    }

    public function queueJobRunKey(): QueueJobKeyEnum
    {
        return QueueJobKeyEnum::CREATE_VENDOR_ACCOUNT;
    }

    public function queueJobRunWorkKey(): string
    {
        return (string) $this->vendorId;
    }

    /** 派發當下 5 分鐘格，對齊 everyFiveMinutes 排程 */
    public function queueJobRunBucketTime(): string
    {
        return now()->floorMinutes(5)->format('Y-m-d H:i:00');
    }

    public function setQueueJobRunId(int $id): void
    {
        $this->queueJobRunId = $id;
    }

    public function handle(VendorService $vendorService, VendorAccountPoolService $poolService): void
    {
        // 收尾派發時建立的執行列（見 QueueJobRun 生命週期）。
        // 略過（商停用／維護中／driver 未實作）與水位足夠也是成功收尾——列在派發時
        // 已出生，output 載明原因，讓「派發了但沒做事」在表上有跡可循；
        // 例外上拋走 failed()，該列標失敗。
        QueueJobRun::finishRun(
            $this->queueJobRunId,
            success: true,
            output: $this->replenish($vendorService, $poolService),
        );
    }

    /**
     * timeout、worker 異常等框架層失敗的兜底：執行列標失敗
     */
    public function failed(?Throwable $exception): void
    {
        QueueJobRun::finishRun($this->queueJobRunId, success: false, output: $exception?->getMessage() ?? 'job failed');
    }

    /**
     * 補充本商帳號池；商停用／維護中／driver 未實作 SupportsAccountCreation 時記 Log 後略過。
     *
     * @return string 執行摘要（寫入執行列的 output；統計細節仍以 Log 為準）
     *
     * @throws Throwable 補建流程失敗（單一帳號失敗已於 Service 內隔離，不上拋）
     */
    private function replenish(VendorService $vendorService, VendorAccountPoolService $poolService): string
    {
        $deadline = microtime(true) + ($this->timeout * self::TIME_BUDGET_RATIO);

        $vendor = Vendor::query()
            ->where('id', $this->vendorId)
            ->where('status', 1)
            ->with(['vendorInfos' => fn ($q) => $q->where('status', 1)])
            ->select('id', 'name', 'code', 'valid_bet_rate')
            ->first();

        if ($vendor === null || $vendor->vendorInfos->isEmpty()) {
            Log::info('補充帳號池略過：遊戲商或商戶已停用', ['vendor_id' => $this->vendorId]);

            return '略過：遊戲商或商戶已停用';
        }

        if ($this->isUnderMaintenance()) {
            Log::info('補充帳號池略過：遊戲商維護中', ['vendor_id' => $this->vendorId]);

            return '略過：遊戲商維護中';
        }

        $vendorDriver = $vendorService->getDriver($vendor->id);

        if (!$vendorDriver instanceof SupportsAccountCreation) {
            Log::warning('補充帳號池略過：driver 未實作 SupportsAccountCreation', ['vendor_id' => $vendor->id]);

            return '略過：driver 未實作 SupportsAccountCreation';
        }

        // 🔴 不包事務：補建逐帳號打三方，事務回滾撤不掉已送出的請求，只會抹掉我方
        // 「這個 username 已送給三方」的紀錄（理由與事故實例見 VendorAccountPoolService::createAccounts()）
        $vendorResult = $poolService->replenishVendor($vendor, $vendorDriver, $deadline);

        $summary = [];

        foreach ($vendorResult->merchantResults as $merchant) {
            if ($merchant->lackNumber === 0) {
                $summary[] = sprintf(
                    '商戶 vendor_info_id %d：未綁定 %d／庫存目標 %d，水位足夠',
                    $merchant->vendorInfoId,
                    $merchant->unboundCount,
                    $merchant->preparedAccountNumber,
                );

                continue;
            }

            $attempted = count($merchant->createdUsernames) + count($merchant->failedUsernames);

            $summary[] = sprintf(
                '商戶 vendor_info_id %d：未綁定 %d／庫存目標 %d，補建 %d（失敗 %d）%s',
                $merchant->vendorInfoId,
                $merchant->unboundCount,
                $merchant->preparedAccountNumber,
                count($merchant->createdUsernames),
                count($merchant->failedUsernames),
                // 缺口沒跑完＝本輪時間預算用罄，標明剩幾筆，免得被誤讀成「補完了但失敗」
                $attempted < $merchant->lackNumber
                    ? sprintf('，本輪時間用罄，剩 %d 筆下一輪續補', $merchant->lackNumber - $attempted)
                    : '',
            );

            Log::info('補充帳號池完成', [
                'vendor_id' => $vendor->id,
                'vendor_info_id' => $merchant->vendorInfoId,
                'unbound_count' => $merchant->unboundCount,
                'prepared_account_number' => $merchant->preparedAccountNumber,
                'created_usernames' => $merchant->createdUsernames,
                'failed_usernames' => $merchant->failedUsernames,
            ]);
        }

        return implode("\n", $summary);
    }

    /**
     * 遊戲商是否在維護時段內（口徑同 AbstractVendorBatchService::maintenanceVendorIds）
     */
    private function isUnderMaintenance(): bool
    {
        $now = now()->toDateTimeString();

        return VendorMaintenances::query()
            ->where('vendor_id', $this->vendorId)
            ->where('status', 1)
            ->where('type', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->exists();
    }
}
