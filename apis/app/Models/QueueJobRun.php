<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\QueueJobRunStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Queue job 執行紀錄（通用；job_key 區分，work_key × bucket_time 語意由各 job 定義）
 *
 * 一列＝一次執行的完整生命週期：派發當下以 {@see self::createPending()} 建列
 * （status=未處理），job 收尾以 {@see self::finishRun()} update 同列為成功／失敗並
 * 寫入執行輸出（output）。每次各自產生 run_uuid，同一格重跑各自成列、不併掉；
 * 「該格是否已完成」看**成功列**存在與否——isCompleted()／missingPairs() 比對三欄
 * 且只認 status=成功，未處理／失敗列不算數；呼叫端可另以 excludeSources 排除特定
 * 觸發來源的成功列（SBR 回看補洞不認手動補跑，見 SummarizeBetRecordHour）。
 * 接入者分兩種語意，差別在是否受同交易約束；擇定後寫進該 job_key 的 enum case 註解：
 *
 * **A. 工作單位完成標記**（受同交易約束，可作判缺補洞的判定依據）
 * 以 {@see self::markCompleted()} 與該工作單位的業務資料寫在**同一個 DB 交易**，
 * rollback（含 dry-run）時一併消失，「缺列＝缺資料」因此成立。
 * 使用者：{@see QueueJobKeyEnum::SUMMARY_BET_RECORD}（SBR 整點彙總，
 * work_key = vendor_id、bucket_time = UTC 整點；見 SummarizeBetRecordHour）。
 *
 * **B. job 執行生命週期**（不受同交易約束，不可單獨作為判缺依據）
 * 派發端以 {@see self::createPending()} 建列、job 收尾以 {@see self::finishRun()}
 * update 同列（接入契約見 {@see \App\Jobs\Contracts\HasQueueJobRun}）。不承諾
 * 「缺列＝缺資料」（業務寫入可能已部分落地），用途是執行軌跡、即時狀態與漏跑
 * 稽核；同步執行的 artisan 路徑（--direct／--dry-run）不是 queue job，不留列。
 * 使用者：{@see QueueJobKeyEnum::UPDATE_GAME_LIST}、{@see QueueJobKeyEnum::FETCH_BET_RECORD}、
 * {@see QueueJobKeyEnum::ADMIN_OPERATION}。
 *
 * 接入契約（兩種語意共同適用，新 job 接入前逐條確認）：
 * - 判缺一律只認 status=成功；直接查表時忘了過濾，未處理／失敗列會被誤判
 *   「已完成」而靜默漏補——用本類 helper 就不會踩到
 * - work_key 必須是穩定、canonical 的字串形式（無前後空白；欄位
 *   utf8mb4_bin，byte 級比對、大小寫敏感），上限 50 字元
 * - 對本表的查詢一律以**字串**綁定 work_key（int 綁定使 varchar 欄位退化
 *   為數值比較、索引失效）；本類 helper 已在邊界統一轉型，直接查表時自理
 * - 補跑動作必須冪等（標記被重置後整格重算不得重複計帳）
 * - 全表保留一個月，由 clean:queue-job-runs 每日清理（SchemaMonitor 對 50 萬列
 *   以上的表會擋 ALTER）；讀取端的回看視窗不得超過保留期
 *
 * 與 {@see CommandLog} 的分工：command_logs 記「**命令**跑了沒、跑多久」（命令層，由
 * ScheduleProvider／CommandProvider 自動涵蓋、零接入）；本表記「哪個**工作單位**成功完成」
 * （資料層，需接入）。排查「排程有沒有跑」看 command_logs，判斷「某格資料做完了沒、
 * 要不要補」看本表。
 *
 * @property int $id
 * @property string $job_key job 識別鍵（QueueJobKeyEnum）
 * @property string $work_key 工作單位鍵（語意由各 job 定義）
 * @property Carbon $bucket_time 工作單位時間格起點（UTC）
 * @property string $run_uuid 單次執行識別碼（UUIDv7）；空字串僅限部署預塞等外部直接寫入
 * @property QueueJobRunStatusEnum $status 執行狀態（未處理→成功／失敗）
 * @property string $source 觸發來源（QueueJobRunSourceEnum）
 * @property string|null $output 執行輸出（人讀摘要）
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|QueueJobRun newModelQuery()
 * @method static Builder<static>|QueueJobRun newQuery()
 * @method static Builder<static>|QueueJobRun query()
 *
 * @mixin \Eloquent
 */
class QueueJobRun extends Model
{
    protected $guarded = [
        'id',
        'created_at',
    ];

    protected $casts = [
        'bucket_time' => 'datetime',
        'status' => QueueJobRunStatusEnum::class,
    ];

    /**
     * 語意 A：寫入工作單位完成標記。
     *
     * 每次各自一列、各自 run_uuid；獨立成方法是為了讓呼叫端把交易契約寫在
     * 型面上。同格重跑各自成列（如關帳調帳的強制重算），
     * 列即重算軌跡；判缺（isCompleted／missingPairs）只看三欄，不受列數影響。
     *
     * 不走「派發時建 pending」的生命週期：本方法的呼叫端（SBR）一格的完成是在
     * 業務交易內才確定的，沒有派發時點可預寫，直接一次落成功列。
     *
     * ⚠️ 必須在與業務資料同一個 DB 交易內呼叫（見類別 docblock 的接入契約）。
     */
    public static function markCompleted(
        QueueJobKeyEnum $jobKey,
        int|string $workKey,
        string $bucketTime,
        QueueJobRunSourceEnum $source,
    ): void {
        self::create([
            'job_key' => $jobKey->value,
            'work_key' => (string) $workKey,
            'bucket_time' => $bucketTime,
            'run_uuid' => (string) Str::uuid7(),
            'status' => QueueJobRunStatusEnum::SUCCESS->value,
            'source' => $source->value,
        ]);
    }

    /**
     * 語意 B：派發當下建立本次執行的列（status=未處理），回傳列 id 供 job 收尾 update。
     *
     * run_uuid 取 UUIDv7：前綴為時間戳、單調遞增，作為唯一鍵尾欄時插入集中在 B-tree
     * 右端而非如 v4 隨機分佈造成頁分裂；同一 (job_key, work_key, bucket_time) 下依
     * run_uuid 排序即為執行先後。與 Laravel 塞進 queue payload 的 uuid（Str::uuid()，v4）無關。
     */
    public static function createPending(
        QueueJobKeyEnum $jobKey,
        int|string $workKey,
        string $bucketTime,
        QueueJobRunSourceEnum $source,
    ): int {
        return self::create([
            'job_key' => $jobKey->value,
            'work_key' => (string) $workKey,
            'bucket_time' => $bucketTime,
            'run_uuid' => (string) Str::uuid7(),
            'status' => QueueJobRunStatusEnum::PENDING->value,
            'source' => $source->value,
        ])->id;
    }

    /**
     * output 寫入上限（bytes）。欄位是 MEDIUMTEXT（16MB），實務縮到 1MB：
     * 超長輸出（異常時例外訊息內嵌整段 SQL、超大清單）截斷後仍可寫入收尾，
     * 不會讓收尾本身爆 1406 連鎖出「成功執行卻標失敗、失敗訊息又寫不進去」。
     * 截斷保留頭尾各半：頭段有起始資訊與首筆例外，尾段有逐人指令的收尾摘要。
     */
    private const int OUTPUT_MAX_BYTES = 1_000_000;

    /**
     * 執行中即時輸出（{@see self::streamOutput()}）只留最後這麼多 bytes：
     * 中途只需看「目前進度」，且頁面每 5 秒輪詢會整段重送，不宜太大。
     */
    private const int STREAM_TAIL_BYTES = 100_000;

    /**
     * job 收尾：把派發時建立的列 update 為成功／失敗並寫入執行輸出。
     *
     * 冪等防護：只碰仍為未處理的列——finish 後 failed() hook 再進來（timeout 邊界）
     * 不會把成功改成失敗。$runId 為 null（防禦：舊 payload、手動 dispatch）時靜默略過。
     */
    public static function finishRun(?int $runId, bool $success, ?string $output = null): void
    {
        if ($runId === null) {
            return;
        }

        if ($output !== null && strlen($output) > self::OUTPUT_MAX_BYTES) {
            // mb_strcut 以 byte 截斷且不切壞 UTF-8 字元
            $half = intdiv(self::OUTPUT_MAX_BYTES, 2);
            $output = mb_strcut($output, 0, $half)
                . "\n…（輸出過長，中段已截斷；完整內容見 Log）…\n"
                . mb_strcut($output, strlen($output) - $half);
        }

        self::query()
            ->where('id', $runId)
            ->where('status', QueueJobRunStatusEnum::PENDING->value)
            ->update([
                'status' => ($success ? QueueJobRunStatusEnum::SUCCESS : QueueJobRunStatusEnum::FAILED)->value,
                'output' => $output,
            ]);
    }

    /**
     * 執行中即時回寫輸出（只動未處理列，收尾後不再覆寫），供頁面輪詢顯示進度。
     * 超過 STREAM_TAIL_BYTES 只留尾段；收尾時 finishRun 會以全文重寫。
     */
    public static function streamOutput(int $runId, string $output): void
    {
        if (strlen($output) > self::STREAM_TAIL_BYTES) {
            $output = "…（執行中，僅顯示最後 100KB；跑完後會寫入完整輸出）…\n"
                . mb_strcut($output, strlen($output) - self::STREAM_TAIL_BYTES);
        }

        self::query()
            ->where('id', $runId)
            ->where('status', QueueJobRunStatusEnum::PENDING->value)
            ->update(['output' => $output]);
    }

    /**
     * 該工作單位是否已有完成標記。
     *
     * @param  array<int, QueueJobRunSourceEnum>  $excludeSources  不算數的觸發來源（例：回看補洞不認手動補跑的標記）
     */
    public static function isCompleted(
        QueueJobKeyEnum $jobKey,
        int|string $workKey,
        string $bucketTime,
        array $excludeSources = [],
    ): bool {
        return self::query()
            ->where('job_key', $jobKey->value)
            ->where('work_key', (string) $workKey)
            ->where('bucket_time', $bucketTime)
            ->where('status', QueueJobRunStatusEnum::SUCCESS->value)
            ->when($excludeSources !== [], fn (Builder $query) => $query->whereNotIn('source', self::sourceValues($excludeSources)))
            ->exists();
    }

    /**
     * 刪除完成標記（例：結算失敗時重置整格，讓 retry 重新判缺重算）。
     *
     * @param  array<int, string>  $buckets  bucket_time 清單（Y-m-d H:00:00）
     * @param  array<int, int|string>  $workKeys
     * @param  array<int, QueueJobRunSourceEnum>  $excludeSources  保留不刪的觸發來源（例：手動補跑的紀錄不影響判缺，無須刪）
     * @return int 刪除列數
     */
    public static function resetMarks(QueueJobKeyEnum $jobKey, array $buckets, array $workKeys, array $excludeSources = []): int
    {
        return self::query()
            ->where('job_key', $jobKey->value)
            ->whereIn('bucket_time', $buckets)
            ->whereIn('work_key', array_map(strval(...), $workKeys))
            ->when($excludeSources !== [], fn (Builder $query) => $query->whereNotIn('source', self::sourceValues($excludeSources)))
            ->delete();
    }

    /**
     * 以完成標記反查缺漏的 (work_key, bucket)。
     *
     * 回看補洞（SummarizeBetRecordHour）與缺口稽核（summary_bet_record:audit）
     * 共用同一判定：無成功列＝該工作單位未成功完成（本方法已過濾 status=成功；
     * 未處理／失敗列不算數，直接查表忘了過濾會誤判「已完成」而靜默漏補）。
     *
     * @param  array<int, string>  $buckets  bucket_time 清單（Y-m-d H:00:00）
     * @param  array<int, int|string>  $workKeys
     * @param  array<int, QueueJobRunSourceEnum>  $excludeSources  不算數的觸發來源（回看補洞傳 MANUAL；稽核不傳，手動補跑照樣算補上）
     * @return array<string, array<int, int|string>> bucket => 缺漏 work_key 清單（無缺漏的 bucket 不含鍵；元素保持傳入型別）
     */
    public static function missingPairs(QueueJobKeyEnum $jobKey, array $buckets, array $workKeys, array $excludeSources = []): array
    {
        // 巢狀陣列比對（work_key 一層、bucket 一層），不用字串串接組鍵——
        // work_key 語意開放給各 job 後，串接鍵會被含分隔字元的 work_key 撞出
        // 「誤判已完成」的靜默漏格
        $completed = [];
        self::query()
            ->select('work_key', 'bucket_time')
            ->where('job_key', $jobKey->value)
            ->where('status', QueueJobRunStatusEnum::SUCCESS->value)
            ->whereIn('bucket_time', $buckets)
            ->whereIn('work_key', array_map(strval(...), $workKeys))
            ->when($excludeSources !== [], fn (Builder $query) => $query->whereNotIn('source', self::sourceValues($excludeSources)))
            ->get()
            ->each(function (self $run) use (&$completed): void {
                $completed[$run->work_key][$run->bucket_time->format('Y-m-d H:i:s')] = true;
            });

        $missing = [];

        foreach ($buckets as $bucket) {
            foreach ($workKeys as $workKey) {
                if (!isset($completed[(string) $workKey][$bucket])) {
                    $missing[$bucket][] = $workKey;
                }
            }
        }

        return $missing;
    }

    /**
     * @param  array<int, QueueJobRunSourceEnum>  $sources
     * @return array<int, string>
     */
    private static function sourceValues(array $sources): array
    {
        return array_map(static fn (QueueJobRunSourceEnum $source): string => $source->value, $sources);
    }
}
