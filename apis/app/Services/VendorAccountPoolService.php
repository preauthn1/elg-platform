<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\MerchantAccountPoolResultData;
use App\Data\Vendor\VendorAccountPoolResultData;
use App\Enums\StatusEnum;
use App\Helpers\Common;
use App\Jobs\ReplenishVendorAccountPool;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsAccountCreation;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 遊戲帳號池補充服務
 *
 * 逐商檢查各啟用商戶的未綁定帳號水位（member_id = 0），低於
 * vendor_info.prepared_account_number 時呼叫 driver createAccount() 補齊，
 * 供用戶進入遊戲時綁定。
 *
 * 排程全量範圍由 driver 實作 ShouldScheduleAccountCreation 決定（不以 vendor id
 * 分界線對照）；只實作 SupportsAccountCreation 的 driver 可用 --vendor-id 手動補建。
 *
 * 三方逾時（不確定對方是否已建）的重試風險：本服務自己不寫帳號列，只呼叫 Driver、記錄失敗、
 * 在記憶體裡遞增流水號；下一輪的起點是 DB 裡最大流水號。因此「同一個 username 不會再送第二次」
 * 只在 Driver 已把帳號列寫進 DB 且該次交易有提交時成立——previewReplenish() 整趟回滾，
 * 下一輪就可能對三方重送同一個 username。多數遊戲商對重複建帳是冪等回應或回「username 已存在」
 * 錯誤碼（AG、Dg、Evo 設計當時依三方文件確認，未實測），不冪等的廠商要在該 Driver 的 createAccount() 個別處理，
 * 不能假設一律安全。
 *
 * @knowledge rules/vendor-account-pool
 */
class VendorAccountPoolService extends AbstractVendorBatchService
{
    /**
     * 同上游（同 driver）job 的派發間隔秒數
     *
     * 聚合遊戲商（Top55 / TTHSW / GMSW 等）數十個子商共用同一三方 API 與限流額度，
     * 整點並發派發會集體撞限流；同 driver 逐商錯開 15 秒（口徑同 GameListSyncService）。
     */
    private const int SAME_UPSTREAM_DISPATCH_INTERVAL_SECONDS = 15;

    /**
     * 帳號池監測的表尾批次查詢，單批併入幾個商戶
     *
     * 取 50：266 個商戶約 6 趟往返（遠端 DB 單趟 100～500ms），單批 SQL 文字約 10KB，
     * 遠低於 `max_allowed_packet`，也不會讓單一 UNION 的分支數多到拖累 MySQL 規劃。
     */
    private const int TAIL_QUERY_CHUNK = 50;

    /**
     * @param  Builder<Vendor>  $query
     */
    protected function applySchedulableVendorScope(Builder $query): void
    {
        $schedulableVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, ShouldScheduleAccountCreation::class),
        ));

        $query->whereIn('id', $schedulableVendorIds);
    }

    /**
     * 排程模式：逐商派發帳號池補充 Job（同 driver 依序錯開 delay，避免打爆共用上游限流）
     *
     * @return list<array{vendor_id: int, vendor_name: string, delay: int, error?: string}> 派發清單
     */
    public function dispatchQueueReplenish(?int $specificVendorId = null): array
    {
        $delaysByDriver = [];

        return $this->loopVendors(
            callback: function (Vendor $vendor) use (&$delaysByDriver): array {
                $driverClass = config('vendor.driver')[$vendor->id] ?? '';
                $delay = $delaysByDriver[$driverClass] ?? 0;
                $delaysByDriver[$driverClass] = $delay + self::SAME_UPSTREAM_DISPATCH_INTERVAL_SECONDS;

                $job = new ReplenishVendorAccountPool($vendor->id);
                $job->setQueueJobRunId(QueueJobRun::createPending(
                    jobKey: $job->queueJobRunKey(),
                    workKey: $job->queueJobRunWorkKey(),
                    bucketTime: $job->queueJobRunBucketTime(),
                    source: $job->source,
                ));
                dispatch($job)->delay($delay);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'delay' => $delay];
            },
            onError: function (Vendor $vendor, Throwable $e): array {
                Log::error('補充帳號池派發錯誤', [
                    'vendor_id' => $vendor->id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'delay' => 0, 'error' => $e->getMessage()];
            },
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 同步模式（--direct）：於當前程序逐商補池（失敗以 error 欄位回報並繼續）
     *
     * 不包事務：補建逐帳號打三方，事務內呼叫三方會把「已送出的請求」綁進可回滾的範圍，
     * 中斷時我方回滾、三方撤不掉（見 createAccounts() 的 docblock）。
     *
     * @return list<VendorAccountPoolResultData>
     */
    public function replenishNow(?int $specificVendorId = null): array
    {
        return $this->loopVendors(
            callback: fn (Vendor $vendor): VendorAccountPoolResultData => $this->resolveAndReplenish($vendor),
            onError: $this->replenishErrorCallback(),
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 乾跑：同 replenishNow，但整趟包事務、結束一律回滾（正式補建不包事務，見 replenishNow()）
     *
     * ⚠️ 回滾僅及於我方資料庫；driver createAccount() 已送出三方的建號請求不會撤銷，
     * 乾跑完三方其實已經多出這批帳號，而我方沒有紀錄、下一輪會重用同一批 username
     *
     * @return list<VendorAccountPoolResultData>
     */
    public function previewReplenish(?int $specificVendorId = null): array
    {
        return $this->previewLoop(
            callback: fn (Vendor $vendor): VendorAccountPoolResultData => $this->resolveAndReplenish($vendor),
            onError: $this->replenishErrorCallback(),
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 解析 driver 後補池；未實作能力契約的 driver 以 error 結果回報
     */
    private function resolveAndReplenish(Vendor $vendor): VendorAccountPoolResultData
    {
        $driver = $this->vendorService->getDriver($vendor->id);

        if (!$driver instanceof SupportsAccountCreation) {
            Log::warning('補充帳號池略過：driver 未實作 SupportsAccountCreation', ['vendor_id' => $vendor->id]);

            return new VendorAccountPoolResultData(
                vendorId: $vendor->id,
                vendorName: $vendor->name,
                error: 'driver 未實作 SupportsAccountCreation',
            );
        }

        return $this->replenishVendor($vendor, $driver);
    }

    /**
     * 補充單一遊戲商全部啟用商戶的帳號池
     *
     * @param  float|null  $deadline  本輪補建的截止時點（microtime(true) 格式），null 為不限。
     *                                由有執行上限的呼叫端（queue job）傳入，到點即收手，
     *                                剩餘缺口留待下一輪——缺口每輪自 DB 重算，天然可續。
     */
    public function replenishVendor(Vendor $vendor, SupportsAccountCreation $driver, ?float $deadline = null): VendorAccountPoolResultData
    {
        $merchantResults = [];

        foreach ($vendor->vendorInfos as $vendorInfo) {
            $merchantResults[] = $this->replenishMerchantPool($driver, $vendorInfo, $deadline);
        }

        return new VendorAccountPoolResultData(
            vendorId: $vendor->id,
            vendorName: $vendor->name,
            merchantResults: $merchantResults,
        );
    }

    /**
     * @return Closure(Vendor, Throwable): VendorAccountPoolResultData
     */
    private function replenishErrorCallback(): Closure
    {
        return function (Vendor $vendor, Throwable $e): VendorAccountPoolResultData {
            Log::error('補充帳號池錯誤', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return new VendorAccountPoolResultData(
                vendorId: $vendor->id,
                vendorName: $vendor->name,
                error: $e->getMessage(),
            );
        };
    }

    /**
     * 帳號池庫存查詢（唯讀，供 Olympus 查詢頁）：逐商戶回報未綁定數、目標水位與缺口。
     *
     * 範圍＝driver 有實作 SupportsAccountCreation 的啟用遊戲商（排除 disabled_vendors；
     * 不排除維護中——維護中庫存仍要看得到，列以 maintenance 標註：維護期間三方 API
     * 不可用，補建排程（availableVendors）會跳過該商，缺口不會自動補上）；
     * 水位口徑與補建共用同一計算。
     *
     * @return array{rows: list<array{vendor_id: int, vendor_name: string, scheduled: bool, maintenance: bool, vendor_info_id: int, vendor_info_name: string, currency: string, unbound: int, target: int, lack: int}>, errors: list<array{vendor_id: int, vendor_name: string, error: string}>}
     */
    public function inventory(?int $specificVendorId = null): array
    {
        $supportedVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, SupportsAccountCreation::class),
        ));

        $vendors = $this->enabledVendorsQuery()
            ->whereIn('id', $supportedVendorIds)
            ->when($specificVendorId !== null, fn ($q) => $q->where('id', $specificVendorId))
            ->orderBy('id')
            ->get();

        $maintenanceIds = $this->maintenanceVendorIds();
        $rows = [];
        $errors = [];

        foreach ($vendors as $vendor) {
            try {
                $driver = $this->vendorService->getDriver($vendor->id);

                if (!$driver instanceof SupportsAccountCreation) {
                    continue;
                }

                foreach ($vendor->vendorInfos as $vendorInfo) {
                    $unbound = $this->countUnboundAccounts($driver->getAccountModel(), $vendorInfo);
                    $target = (int) $vendorInfo->prepared_account_number;

                    $rows[] = [
                        'vendor_id' => $vendor->id,
                        'vendor_name' => $vendor->name,
                        'scheduled' => $driver instanceof ShouldScheduleAccountCreation,
                        'maintenance' => in_array($vendor->id, $maintenanceIds, true),
                        'vendor_info_id' => $vendorInfo->id,
                        'vendor_info_name' => (string) $vendorInfo->name,
                        'currency' => (string) $vendorInfo->currency,
                        'unbound' => $unbound,
                        'target' => $target,
                        'lack' => max(0, $target - $unbound),
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'error' => $e->getMessage()];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * 偵測「補不進去」的帳號池（唯讀，供每小時監測排程）。
     *
     * 判準：逐商戶自帳號表**尾端**（主鍵倒序）往回數，連續幾筆是 `member_id < 0` 的未完成佔號。
     * 遇到第一筆 `member_id >= 0`（已入池或已被綁走）即停止計數——那代表補建仍有成功。
     *
     * 為什麼用「表尾連續未完成」而不是累積量或錯誤碼：
     * - **不必解析各家三方的錯誤碼**。67 支 driver 一律以 `member_id = -999999999` 佔號（已全庫確認），
     *   負值即「送了但沒完成」，跨廠商通用；逐家比對「帳號已存在」之類的回應格式不但成本高，
     *   判錯還會把三方真有的帳號誤刪（見 createAccounts() 的 docblock）。
     * - **反映當下而非歷史**。累積量（佔號總數／成功數比例）在商戶停用後仍會持續命中，變成噪音；
     *   表尾連續數會在補建恢復或商戶停用後自動歸零。
     * - **只讀表尾、不碰 `created_at` 索引**。主鍵倒序取 $sampleSize 筆即可判定；帳號表可達百萬列
     *   （2026-10 實測 ns_accounts 117 萬列），以時間範圍掃描會逾時。
     *
     * $withinHours 用來排除「久未補建」的商戶：池滿時排程不會建號，表尾那幾筆是上次缺口時留下的舊列，
     * 不該因此告警。
     *
     * ⚠️ 連續數的上限是 $sampleSize：實際連續筆數可能更多，回報值到此為止（判定已足夠，不需再往回掃）。
     *
     * @param  int  $withinHours  只看這段時間內建立的列
     * @param  int  $sampleSize  表尾取樣筆數，同時是連續數的上限
     * @return list<array{vendor_id: int, vendor_name: string, vendor_info_id: int, vendor_info_name: string, consecutive: int, latest_username: string, latest_created_at: string, unbound: int, target: int}>
     */
    public function detectStuckPools(int $withinHours = 1, int $sampleSize = 20): array
    {
        $supportedVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, SupportsAccountCreation::class),
        ));

        $since = now()->subHours($withinHours);
        $stuck = [];

        // 第一階段：解析各商的帳號表，不碰 DB
        $targets = [];
        $driverMap = config('vendor.driver', []);

        foreach ($this->enabledVendorsQuery()->whereIn('id', $supportedVendorIds)->orderBy('id')->get() as $vendor) {
            try {
                $accountClass = $this->accountModelFromDriverClass($driverMap[$vendor->id])
                    ?? $this->vendorService->getDriver($vendor->id)->getAccountModel();

                $targets[] = ['vendor' => $vendor, 'accountClass' => $accountClass];
            } catch (Throwable $e) {
                // 單一遊戲商解析失敗不影響其他商的偵測（driver 未配置、帳號表不存在等）
                Log::warning('帳號池監測略過遊戲商', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
            }
        }

        // 第二階段：攤平成「一張表 × 一個商戶」的查詢單位
        $lookups = [];

        foreach ($targets as $target) {
            foreach ($target['vendor']->vendorInfos as $vendorInfo) {
                $lookups[] = [
                    'vendor' => $target['vendor'],
                    'accountClass' => $target['accountClass'],
                    'vendorInfo' => $vendorInfo,
                ];
            }
        }

        // 第三階段：UNION ALL 批次取表尾，每個商戶各自 LIMIT
        $tails = $this->accountTailsByVendorInfo($lookups, $sampleSize);

        // 第四階段：逐商戶自表尾往回數連續未完成筆數
        foreach ($lookups as $lookup) {
            $vendor = $lookup['vendor'];
            $vendorInfo = $lookup['vendorInfo'];
            $consecutive = 0;
            $latest = null;

            foreach ($tails[$vendorInfo->id] ?? [] as $row) {
                // 已入池（0）或已被綁走（>0）即代表補建仍有成功，連續計數到此為止
                if ((int) $row->member_id >= 0 || Carbon::parse($row->created_at)->lt($since)) {
                    break;
                }

                $latest ??= $row;
                $consecutive++;
            }

            if ($consecutive === 0) {
                continue;
            }

            $stuck[] = [
                'vendor_id' => $vendor->id,
                'vendor_name' => (string) $vendor->name,
                'vendor_info_id' => $vendorInfo->id,
                'vendor_info_name' => (string) $vendorInfo->name,
                'consecutive' => $consecutive,
                'latest_username' => (string) $latest->username,
                'latest_created_at' => Carbon::parse($latest->created_at)->toDateTimeString(),
                // 命中的才查水位：正常情況下只有個位數，不值得為它再開一輪批次查詢
                'unbound' => $this->countUnboundAccounts($lookup['accountClass'], $vendorInfo),
                'target' => (int) $vendorInfo->prepared_account_number,
            ];
        }

        return $stuck;
    }

    /**
     * 批次取多個商戶的帳號表尾端列（主鍵倒序），以 vendor_info_id 分組回傳
     *
     * 🔴 **每個商戶各自一個 `LIMIT`，不是整張表共用一個**：聚合遊戲商共用同一張帳號表
     * （Evo 61 商、TTHSW 51 商、Top55 31 商共表），若整表只取前 N 筆，單一商戶的連續失敗會佔滿
     * 整個視窗，同表其他商戶連一筆都分不到而漏報。各自 LIMIT 再以 `UNION ALL` 併成一次往返，
     * 取樣範圍與逐商戶查完全相同，只省掉往返成本。
     *
     * 為什麼要省往返：DB 在遠端（dbc 代理），單次往返固定成本約 100～500ms，與查詢本身的複雜度無關。
     * 266 個商戶逐一查＝266 趟，VWorld 實測 54 秒；併成批次後僅數趟。
     *
     * 表名直接內插進 SQL（識別字無法參數綁定），來源是 driver 宣告的 Model `getTable()`、
     * 非外部輸入；`vendor_info_id` 與 `LIMIT` 皆為已轉型的整數。
     *
     * @param  list<array{vendor: Vendor, accountClass: class-string, vendorInfo: VendorInfo}>  $lookups
     * @return array<int, list<\stdClass>> vendor_info_id => 該商戶的表尾列（id 由大到小）
     */
    private function accountTailsByVendorInfo(array $lookups, int $sampleSize): array
    {
        $tails = [];

        // 分批併送：單批過大時 SQL 文字長度與 MySQL 的 UNION 分支數都會成為新瓶頸
        foreach (array_chunk($lookups, self::TAIL_QUERY_CHUNK) as $chunk) {
            $selects = [];

            foreach ($chunk as $lookup) {
                $table = (new ($lookup['accountClass']))->getTable();
                $vendorInfoId = (int) $lookup['vendorInfo']->id;

                $selects[] = sprintf(
                    '(SELECT %d AS lookup_info_id, id, username, member_id, created_at FROM `%s` WHERE vendor_info_id = %d ORDER BY id DESC LIMIT %d)',
                    $vendorInfoId,
                    $table,
                    $vendorInfoId,
                    $sampleSize,
                );
            }

            try {
                foreach (DB::connection()->select(implode(' UNION ALL ', $selects)) as $row) {
                    $tails[(int) $row->lookup_info_id][] = $row;
                }
            } catch (Throwable $e) {
                // 整批失敗（某張表不存在或缺欄位）時退回逐商戶查，避免一顆壞表拖垮整批
                Log::warning('帳號池監測批次查詢失敗，改逐商戶查', ['error' => $e->getMessage()]);

                foreach ($chunk as $lookup) {
                    try {
                        $rows = DB::table((new ($lookup['accountClass']))->getTable())
                            ->where('vendor_info_id', $lookup['vendorInfo']->id)
                            ->orderByDesc('id')
                            ->limit($sampleSize)
                            ->get(['id', 'username', 'member_id', 'created_at']);

                        $tails[(int) $lookup['vendorInfo']->id] = $rows->all();
                    } catch (Throwable $inner) {
                        Log::warning('帳號池監測略過商戶', [
                            'vendor_info_id' => $lookup['vendorInfo']->id,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }
            }
        }

        // UNION ALL 不保證跨分支的順序，分組後各自重排確保「由新到舊」
        foreach ($tails as &$rows) {
            usort($rows, static fn (object $a, object $b): int => $b->id <=> $a->id);
        }

        return $tails;
    }

    /**
     * 該商戶目前池內可綁定的帳號數（水位口徑單一事實來源：member_id = 0 且啟用）
     *
     * 一律走主庫（`useWritePdo()`）：補建每輪只補一部分、剩餘留待下一輪，讀到 replica 的
     * 舊值會把上一輪剛建好的帳號當成不存在而重複補建。查詢頁（inventory()）共用本方法，
     * 多出的主庫負載僅此 count，可接受。
     */
    private function countUnboundAccounts(string $accountClass, VendorInfo $vendorInfo): int
    {
        return App::make($accountClass)
            ->where('member_id', 0)
            ->where('status', StatusEnum::ENABLE->value)
            ->when(
                $this->accountTableHasVendorInfoId($accountClass, $vendorInfo),
                fn ($q) => $q->where('vendor_info_id', $vendorInfo->id),
            )
            ->useWritePdo()
            ->count();
    }

    /**
     * 自 driver class 的預設屬性取帳號 Model，不實例化 driver
     *
     * `getDriver()` 會載入並解密該商的 vendor info（VWorld 實測單次 100ms、快取一小時），
     * 但監測只需要知道帳號表是哪張。排程每小時跑一次、快取剛好過期，244 個商逐一解密是純浪費。
     * 取不到（driver 在建構子才指定）時回 null，由呼叫端退回 `getDriver()`。
     *
     * @param  class-string  $driverClass
     * @return class-string|null
     */
    private function accountModelFromDriverClass(string $driverClass): ?string
    {
        $model = (new \ReflectionClass($driverClass))->getDefaultProperties()['accountModel'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * 早期的帳號模型可能沒有 vendor_info_id 欄位，先檢查欄位是否存在
     */
    private function accountTableHasVendorInfoId(string $accountClass, VendorInfo $vendorInfo): bool
    {
        return Cache::remember(
            'vendor:hasVendorInfoId:' . $vendorInfo->id,
            300,
            fn (): bool => Schema::hasColumn((new $accountClass)->getTable(), 'vendor_info_id'),
        );
    }

    /**
     * 檢查單一商戶的帳號池水位，不足則補建
     */
    private function replenishMerchantPool(SupportsAccountCreation $driver, VendorInfo $vendorInfo, ?float $deadline = null): MerchantAccountPoolResultData
    {
        $accountClass = $driver->getAccountModel();
        $hasVendorInfoId = $this->accountTableHasVendorInfoId($accountClass, $vendorInfo);
        $unboundCount = $this->countUnboundAccounts($accountClass, $vendorInfo);

        $preparedAccountNumber = (int) $vendorInfo->prepared_account_number;
        $lackNumber = $preparedAccountNumber - $unboundCount;

        if ($lackNumber <= 0) {
            return new MerchantAccountPoolResultData(
                vendorInfoId: $vendorInfo->id,
                unboundCount: $unboundCount,
                preparedAccountNumber: $preparedAccountNumber,
            );
        }

        $latestNumber = $this->latestAccountNumber($accountClass, $vendorInfo, $driver, $hasVendorInfoId);
        [$createdUsernames, $failedUsernames] = $this->createAccounts($driver, $vendorInfo, $latestNumber, $lackNumber, $deadline);

        return new MerchantAccountPoolResultData(
            vendorInfoId: $vendorInfo->id,
            unboundCount: $unboundCount,
            preparedAccountNumber: $preparedAccountNumber,
            lackNumber: $lackNumber,
            createdUsernames: $createdUsernames,
            failedUsernames: $failedUsernames,
        );
    }

    /**
     * 取得該商戶目前最大的帳號流水號（帳號表為空時為 0）
     *
     * 🔴 一律走主庫（`useWritePdo()`）：流水號是下一批 username 的起點，讀到 replica 的舊值
     * 會拿已經用掉的號重送三方。補建改為每輪落地（不再整趟回滾）後，上一輪剛寫入的列若還沒
     * 複寫過來就會直接撞號。
     */
    private function latestAccountNumber(string $accountClass, VendorInfo $vendorInfo, SupportsAccountCreation $driver, bool $hasVendorInfoId): int
    {
        $prefix = $driver->getStoredAccountPrefix($vendorInfo);

        // 以流水號數值排序取最大（字串排序會把 '9' 排在 '10' 後面）
        $latestUser = DB::table((new $accountClass)->getTable())
            ->where('username', 'like', $prefix . '%')
            ->when($hasVendorInfoId, fn ($q) => $q->where('vendor_info_id', $vendorInfo->id))
            ->orderByRaw('CAST(SUBSTRING(username, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->useWritePdo()
            ->first();

        return $latestUser ? (int) substr((string) $latestUser->username, strlen($prefix)) : 0;
    }

    /**
     * 逐一補建帳號至安全庫存（單一帳號失敗記錄後繼續補下一個）
     *
     * 🔴 **禁止把本方法包進事務**：每一筆都是「INSERT 佔號 → 打三方開戶 → UPDATE 回填結果」，
     * 三方請求送出去就撤不回來，事務回滾只會抹掉我方「這個 username 已經送給三方」的憑據，
     * 變成三方開了帳號、我方不知道。兩步之間的 `member_id = -999999999` 不是待清理的髒資料，
     * 中斷時留著才有東西可以對帳。單筆 INSERT 自身即為原子，不需要外層事務保護。
     *
     * 2026-09-10～10-06 的實例：job 的 `handle()` 曾把整商補建包在一個事務裡，每輪跑到
     * `$timeout` 被 SIGALRM 砍掉後整趟回滾，當輪已建好的帳號一列都不留，下一輪重算缺口依舊
     * 是滿的 ⇒ 連續 26 天一筆都補不進去，池空到新玩家拿不到帳號（target/op_tickets#3463）。
     *
     * @param  float|null  $deadline  本輪截止時點（microtime(true) 格式），null 為不限
     * @return array{0: list<string>, 1: list<string>} [成功帳號, 失敗帳號]
     */
    private function createAccounts(SupportsAccountCreation $driver, VendorInfo $vendorInfo, int $latestNumber, int $lackNumber, ?float $deadline = null): array
    {
        $createdUsernames = [];
        $failedUsernames = [];

        for ($i = 0; $i < $lackNumber; $i++) {
            // 時間預算用罄就收手：單筆要打兩次三方，慢的遊戲商（NextSpin 實測約 20 秒/筆）
            // 補滿一個商戶可能遠超 job timeout。剩餘缺口下一輪自 DB 重算續補。
            if ($deadline !== null && microtime(true) >= $deadline) {
                Log::info('補充帳號池本輪時間預算用罄，剩餘缺口留待下一輪', [
                    'vendor_info_id' => $vendorInfo->id,
                    'lack_number' => $lackNumber,
                    'created' => count($createdUsernames),
                    'failed' => count($failedUsernames),
                ]);

                break;
            }

            // 帳號規則：前綴加上 8 位流水數字
            $username = $vendorInfo->account_prefix . str_pad((string) ($latestNumber + 1), 8, '0', STR_PAD_LEFT);

            try {
                $result = $driver->createAccount(new CreateAccountParamsData(
                    vendorInfo: $vendorInfo,
                    serialNumber: $latestNumber,
                    username: $username,
                ));

                if ($result->status) {
                    $createdUsernames[] = $result->username !== '' ? $result->username : $username;
                } else {
                    $failedUsernames[] = $username;
                    Log::warning('建立遊戲帳號失敗', [
                        'vendor_info_id' => $vendorInfo->id,
                        'username' => $username,
                    ]);
                }
            } catch (Throwable $e) {
                $failedUsernames[] = $username;
                Log::error('建立遊戲帳號異常', [
                    'vendor_info_id' => $vendorInfo->id,
                    'username' => $username,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }

            $latestNumber++;
        }

        return [$createdUsernames, $failedUsernames];
    }
}
