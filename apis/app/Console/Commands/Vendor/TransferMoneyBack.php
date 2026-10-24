<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\AccountNotExistsException;
use App\Helpers\Common;
use App\Models\AdminOperationLog;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TransferMoneyBack extends Command
{
    /**
     * php artisan vendor:transfer_money_back --vendorId=25 --agentId=100003 --eventV2=0
     * php artisan vendor:transfer_money_back --vendorId=25 --agentId=59457 --eventV2=0 --beforeId=919000 --limit=2000
     *
     * 只撈該代理子樹（agents.hierarchy_path 前綴）且會員仍存在的帳號：VWorld 這類多白標共用同一商戶
     * （vendor_info）的 DB，商戶全部帳號 15 萬、該代理只佔 2.5 萬，不在 SQL 過濾會把時間與輸出燒在無效列上。
     * 帳號依 id 由大到小處理；--beforeId 只處理 id 小於該值的帳號（續跑用），--limit 限制本次筆數，
     * --maxSeconds 為時間預算（0 = 不限），到點就收尾並印出「續跑起點」。
     * 三者都用帳號 id 定位、不用 offset：跑到一半有新帳號建立時 offset 會錯位而漏人或重複。
     * --memberUsername 指定只抽某一位會員（members.username，仍須在該代理子樹內）。
     * --perSecond 為每秒查詢三方的上限（預設與硬上限皆為 MAX_PER_SECOND），超出預算就原地等到下一秒。
     * 由後台觸發時每 CANCEL_POLL_SECONDS 秒讀一次中斷旗標，按下「中斷」即跑完當筆收尾並印出續跑起點。
     *
     * @var string
     */
    protected $signature = 'vendor:transfer_money_back {--vendorId=} {--agentId=} {--eventV2=0} {--beforeId=0} {--limit=0} {--maxSeconds=0} {--memberUsername=} {--perSecond=5}';

    protected $description = '轉回遊戲商所有玩家的錢(更換商戶用)';

    /**
     * 每秒查詢三方的上限（同時是 --perSecond 的預設值與硬上限）。
     *
     * 918（SCR）對 getUserInfo 有每秒約 10 次的配額，超出即整筆退
     * （HTTP 200 但 body 為 code -123 / msg errora2 / retime 30），實測逐筆連打會丟掉近半數帳號。
     * 三方未公告確切數字，取 5 留一半餘裕；上限寫死不吃參數，避免下次有人把它調回會被退件的量。
     */
    public const int MAX_PER_SECOND = 5;

    /** 每秒配額的衰減秒數：MAX_PER_SECOND 是「每秒」幾筆，窗口就是 1 秒 */
    private const int RATE_LIMIT_DECAY_SECONDS = 1;

    /**
     * 每隔幾秒讀一次後台的中斷旗標（admin_operation_logs.cancel_requested_at）。
     *
     * 每筆都讀等於每秒多打幾次 DB，而人按下中斷後多等幾秒無感；跑到安全點才停，
     * 當下那筆轉帳不會停在一半。
     */
    private const int CANCEL_POLL_SECONDS = 5;

    /**
     * 一次載入的 member 筆數（見 handle 內註解）。
     *
     * 限速後一批的消化時間就是 MEMBER_BATCH / MAX_PER_SECOND 秒，批次開大等於讓整批 Member
     * （含 eventV2Joins、agent）在記憶體裡放上好幾分鐘——這支指令有過記憶體被 SIGKILL 的前科。
     * 取 10 讓常駐量壓到最低；多出來的查詢次數（兩萬筆約 1900 次 whereIn，每次只帶 10 個 id）
     * 攤在 5 筆/秒的節奏上約每秒 0.5 次，DB 不是這段流程的瓶頸。
     */
    private const int MEMBER_BATCH = 10;

    /**
     * 每處理幾筆帳號寫一次記憶體取樣 log。
     *
     * 本指令曾兩次在「處理到約 2150 筆」時整個 worker 進程被 SIGKILL（無 PHP fatal、無 k8s OOMKilled 事件、
     * pod 未重啟），兩次耗時差近一倍但筆數幾乎相同，指向記憶體而非時間。取樣寫進 log（而非只寫指令輸出）是因為
     * queue_job_runs.output 只留尾段 100KB，且進程被硬砍時最後 5 秒的輸出可能還沒 flush。
     */
    private const int MEMORY_SAMPLE_INTERVAL = 100;

    /** 記憶體換算單位（bytes → MB） */
    private const int BYTES_PER_MB = 1048576;

    /**
     * 續跑起點的輸出標記；後台操作頁（Filament TransferMoneyBack）以正則從 queue_job_runs.output 取回，
     * 改字面要一併改頁面的 RESUME_MARKER_PATTERN
     */
    public const string RESUME_MARKER = '續跑起點 beforeId: ';

    public function __construct(
        protected VendorService $vendorService,
        protected MemberTransferService $memberTransferService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $this->line(sprintf('起跑：memory_limit=%s, 記憶體 %s', ini_get('memory_limit') ?: '未知', $this->memoryUsage()));
        $eventV2 = (int) $this->option('eventV2');

        $vendorId = (int) $this->option('vendorId');
        if (!$vendorId) {
            $vendorId = (int) $this->ask('請輸入遊戲商ID');
        }

        $agentId = (int) $this->option('agentId');
        if (!$agentId) {
            $agentId = (int) $this->ask('請輸入代理ID');
        }

        $beforeId = (int) $this->option('beforeId');
        $limit = (int) $this->option('limit');
        $maxSeconds = (int) $this->option('maxSeconds');
        $memberUsername = trim((string) $this->option('memberUsername'));
        $perSecond = self::resolvePerSecond((int) $this->option('perSecond'));
        // 預算掛在 vendor 上而非單次執行：同一個遊戲商同時跑多個續跑 job 時共用同一份額度
        $limiterKey = 'transfer_money_back:' . $vendorId;
        // 由後台（RunAdminOperationJob）觸發時才有值；命令列直接跑沒有操作紀錄可讀，不輪詢
        $operationLogId = (int) Context::getHidden(ContextKeyEnum::ADMIN_OPERATION_LOG_ID->value);

        $ymdhis = Carbon::now()->toFrontendTime()->format('YmdHis');
        $filename = sprintf('transfer_money_back_%s_%s.txt', $vendorId, $ymdhis);
        $filename = storage_path('app/' . $filename);
        $filenameError = sprintf('transfer_money_back_error_%s.txt', $ymdhis);
        $filenameError = storage_path('app/' . $filenameError);
        $filenameErrorMemberIds = sprintf('transfer_money_back_error_member_ids_%s.txt', $ymdhis);
        $filenameErrorMemberIds = storage_path('app/' . $filenameErrorMemberIds);

        // 由後台（RunAdminOperationJob）觸發時跑在 queue worker 內，查無資料只能回失敗碼，不可 exit 砍掉 worker
        $tname = $this->_getVendorTname($vendorId);
        if ($tname === null) {
            $this->error('vendor 不存在, vendor_id:' . $vendorId);

            return self::FAILURE;
        }

        $vendorInfo = $this->_getVendorInfo($vendorId, $agentId, $eventV2);
        if ($vendorInfo === null) {
            $this->error(sprintf('vendor_info 不存在（此代理未開通該遊戲商）, vendor_id: %s, agent_id: %s, eventV2: %s', $vendorId, $agentId, $eventV2));

            return self::FAILURE;
        }
        $rootPath = Agent::query()->whereKey($agentId)->value('hierarchy_path');
        if (blank($rootPath)) {
            $this->error('agent 不存在或無 hierarchy_path, agent_id:' . $agentId);

            return self::FAILURE;
        }

        $vendorLog = sprintf('vendorInfo: id: %s, name: %s, type: %s; 範疇: 代理 %s 子樹 (%s)', $vendorInfo->id, $vendorInfo->name, $vendorInfo->type, $agentId, $rootPath);
        if ($memberUsername !== '') {
            $vendorLog .= sprintf('; 只抽指定會員: %s', $memberUsername);
        }
        $this->info($vendorLog);
        File::append($filename, $vendorLog . "\n");

        // 帳號 → 會員 → 直屬代理，以代理子樹的 hierarchy_path 前綴過濾（同 whitelabel-db-query 的 scoped template）；
        // 會員已不存在的帳號沒有錢包可入帳，inner join 直接排除
        $accounts = DB::table($tname . '_accounts as a')
            ->select('a.id', 'a.username', 'a.member_id')
            ->join('members as m', 'm.id', '=', 'a.member_id')
            ->join('agents as ag', 'ag.id', '=', 'm.parent')
            ->where('a.vendor_info_id', $vendorInfo->id)
            ->where('a.member_id', '>', 0)
            ->where('a.status', 1)
            ->where(function ($query) use ($rootPath) {
                $query->where('ag.hierarchy_path', $rootPath)
                    ->orWhere('ag.hierarchy_path', 'like', $rootPath . '/%');
            })
            ->when($memberUsername !== '', fn ($query) => $query->where('m.username', $memberUsername))
            ->when($beforeId > 0, fn ($query) => $query->where('a.id', '<', $beforeId))
            // 多撈一筆判斷「確實還有下一筆」，不靠 count === limit 猜（剛好等於剩餘筆數時會誤印續跑起點）
            ->when($limit > 0, fn ($query) => $query->take($limit + 1))
            ->orderBy('a.id', 'desc')
            ->get();

        $hasMore = $limit > 0 && $accounts->count() > $limit;
        if ($hasMore) {
            $accounts = $accounts->take($limit);
        }

        $count = $accounts->count();
        $this->line(sprintf('帳號清單載入完成：%s 筆，記憶體 %s', $count, $this->memoryUsage()));
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $purged = 0;
        $walletMismatch = 0;
        $totalAmount = '0';
        $failedMemberIds = [];
        // 一筆都還沒處理就被中斷時沿用本次起點：續跑游標不能因為停得早就退回從頭（--beforeId 續跑的那一輪會整段漏掉）
        $lastAccountId = $beforeId;
        $budgetExhausted = false;
        $cancelled = false;
        // 0 = 第一筆就先讀一次：上一輪按下的中斷若在派發後才寫入，這一輪不該又整批跑下去
        $cancelCheckedAt = 0.0;

        $memberMap = collect();

        foreach ($accounts as $key => $account) {
            // 時間預算：後台 job 有 3600 秒硬上限，到點會被 SIGKILL 砍掉 worker、輸出也斷在半路；
            // 自己在預算內收尾才能印出續跑起點，讓下一次接著跑
            if ($maxSeconds > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                $budgetExhausted = true;

                break;
            }

            // 後台「中斷」：旗標由操作頁寫進 admin_operation_logs.cancel_requested_at，這裡輪詢
            if ($operationLogId > 0 && microtime(true) - $cancelCheckedAt >= self::CANCEL_POLL_SECONDS) {
                $cancelCheckedAt = microtime(true);

                if (AdminOperationLog::isCancelRequested($operationLogId)) {
                    $cancelled = true;

                    break;
                }
            }

            // 每 MEMBER_BATCH 筆換一批 member：一次 whereIn 全部會撞 MySQL prepared statement
            // 65535 個 placeholder 上限（2026-09-14 WINTOTO Mega888 六萬多帳號實測 1390），
            // 也讓同時在記憶體裡的 Member（含 agent／eventV2Joins）維持在一批的量
            if ($key % self::MEMBER_BATCH === 0) {
                $memberMap = Member::query()
                    ->with('eventV2Joins')
                    ->with('agent')
                    ->whereIn('id', $accounts->slice($key, self::MEMBER_BATCH)->pluck('member_id'))
                    ->get()
                    ->keyBy('id');
            }

            // 本間隔是 MEMBER_BATCH 的整數倍，取樣點必落在換批當下，換批前後的記憶體差看得出來
            if ($key > 0 && $key % self::MEMORY_SAMPLE_INTERVAL === 0) {
                $this->sampleMemory('處理中', $key, $count, $startedAt);
            }

            $processed++;
            $lastAccountId = (int) $account->id;
            $vendorUsername = $account->username ?? '';
            // 每行進度都帶帳號 id：被硬砍時沒有收尾摘要，後台頁面靠最後一行進度的帳號 id 接續
            $progress = sprintf('進度: %s/%s, 帳號id: %s', $key + 1, $count, $account->id);

            if (!isset($memberMap[$account->member_id])) {
                $log = sprintf('%s, 遊戲帳號: %s, member_id: %s, member 不存在', $progress, $vendorUsername, $account->member_id);
                $this->line($log);
                File::append($filename, $log . "\n");
                $skipped++;

                continue;
            }

            $member = $memberMap[$account->member_id];
            $agent = $member->agent;
            if (!in_array($agentId, $agent->hierarchy_array)) {
                $log = sprintf('%s, 用戶: %s (%s), 遊戲帳號: %s, 非此代理下線', $progress, $member->username, $member->id, $vendorUsername);
                $this->line($log);
                File::append($filename, $log . "\n");
                $skipped++;

                continue;
            }

            // 會員的錢包類型決定 Driver 會拿到哪一種 vendor_info（BaseAbstractVendor 以
            // member->is_event_wallet 取商戶），與本次 --eventV2 不符時會整個做錯對象：
            // 有活動商戶 → 靜默去抽活動商戶的帳號（一般商戶那筆餘額沒抽到卻記成已處理，
            // 活動商戶沒帳號時還會從帳號池綁一個新的）；沒有活動商戶 → VendorInfo not found 失敗。
            // 兩種都不是本次要做的事，在建 Driver（含帳號綁定）與打三方之前就跳過。
            //
            // is_event_wallet 會多一次 exists 查詢（新人專享挑戰那半邊），但 getDriver 內部本來就讀它，
            // 提前判斷不增加查詢，反而省掉後面的帳號綁定與三方往返。
            if ($member->is_event_wallet !== ($eventV2 === 1)) {
                $log = sprintf(
                    '%s, 用戶: %s (%s), 遊戲帳號: %s, 會員錢包類型與本次指定不符（is_event_wallet=%d、--eventV2=%d），略過',
                    $progress,
                    $member->username,
                    $member->id,
                    $vendorUsername,
                    (int) $member->is_event_wallet,
                    $eventV2,
                );
                $this->line($log);
                File::append($filename, $log . "\n");
                $skipped++;
                $walletMismatch++;

                continue;
            }

            // 三方頻率限制：沿用 BetRecordFetchService 的 RateLimiter 作法（cache-based，跨進程共用預算），
            // 預算用罄就原地等到釋放再續跑。等待時間照樣吃 maxSeconds 預算，到點由上面的檢查收尾印續跑起點
            // 不用 Sleep::fake() 那套可測寫法：假睡眠不會讓 RateLimiter 的預算到期，這個 while 會空轉不出來
            while (RateLimiter::tooManyAttempts($limiterKey, $perSecond)) {
                sleep(max(1, RateLimiter::availableIn($limiterKey)));
            }
            // 第二個參數是配額的衰減秒數，預設 60：漏傳會讓上限變成「每分鐘 5 筆」
            // （2026-09-23 WINTOTO 918 執行 #344 實測每分鐘只放行 5 筆，整批要跑 65 小時）
            RateLimiter::hit($limiterKey, self::RATE_LIMIT_DECAY_SECONDS);

            // 🔴 這裡**不包事務**：轉帳要打三方，事務回滾撤不掉已送出的請求。
            // MemberTransferService::transferToLocal() 自己已經切好邊界——交易紀錄先寫在事務外
            // （該方法註解：「避免交易紀錄被 rollback 後刪除沒有失敗紀錄」）、打完三方才開短事務寫餘額、
            // 寫失敗則記轉丟。外面再包一層事務會讓那層邊界整個失效：Laravel 巢狀事務下，內層的
            // beginTransaction 只是 savepoint、commit 只遞減計數，真正提交要等最外層，於是三方呼叫
            // 又回到事務裡，連轉丟紀錄都會被一起回滾。
            try {
                $vendorDriver = $this->vendorService->getDriver($vendorId, $member);
                $result = $this->memberTransferService->transferToLocal($vendorDriver);
                // 三方回應／DB 讀出的金額入境即正規化，再參與加總
                $withdrawalAmount = Common::numAdd('0', (string) ($result['withdrawal_amount'] ?? 0), 4);
                $totalAmount = Common::numAdd($totalAmount, $withdrawalAmount, 4);
                $success++;
                $log = sprintf('%s, 用戶: %s (%s), 遊戲帳號: %s, 轉回金額(平台幣): %s', $progress, $member->username, $member->id, $vendorUsername, $withdrawalAmount);
                $this->line($log);
                File::append($filename, $log . "\n");
            } catch (AccountNotExistsException $e) {
                // 三方已清掉這個玩家（Mega888 37111 等）：沒有餘額可轉，算略過不算失敗，
                // 否則幾千個被清的老帳號會把整批標紅、淹掉真正的失敗
                $log = sprintf('%s, 用戶: %s (%s), 遊戲帳號: %s, 三方查無此帳號，略過', $progress, $member->username, $member->id, $vendorUsername);
                $this->line($log);
                File::append($filename, $log . "\n");
                $skipped++;
                $purged++;
            } catch (\Throwable $e) {
                // 接 Throwable 不是 Exception：三方回了非數字餘額時，金額運算會丟 ValueError
                // （bcdiv(): Argument #1 is not well-formed），它繼承 Error 不是 Exception，
                // 只接 Exception 會讓一筆壞帳號把整批都帶走（2026-10-16 TPower AG 18031 筆停在第 1 筆）
                $log = sprintf('%s, 用戶: %s (%s), 遊戲帳號: %s, 失敗', $progress, $member->username, $member->id, $vendorUsername);
                $this->error($log);
                $this->error($e->getMessage());
                File::append($filenameError, $log . "\n");
                File::append($filenameError, $e . "\n");
                File::append($filenameErrorMemberIds, $member->id . ',');
                $failed++;
                $failedMemberIds[] = $member->id;
            }
        }

        // 收尾摘要：後台（RunAdminOperationJob）只看得到這段輸出，明細檔在 worker 本機
        $this->newLine();
        $this->info(sprintf(
            '處理完成：共 %d 筆，已處理 %d，成功 %d、失敗 %d、略過 %d（其中三方查無帳號 %d、錢包類型不符 %d），轉回總額(平台幣) %s，記憶體 %s，耗時 %d 秒',
            $count,
            $processed,
            $success,
            $failed,
            $skipped,
            $purged,
            $walletMismatch,
            $totalAmount,
            $this->memoryUsage(),
            (int) (microtime(true) - $startedAt),
        ));
        $this->sampleMemory('收尾', $processed, $count, $startedAt);

        if ($failed > 0) {
            $this->error('失敗 member_id：' . implode(',', $failedMemberIds));
            $this->line('請逐一排查後重跑');
        }

        // 沒跑完（預算用盡、人工中斷、或 --limit 之外確實還有帳號）就印續跑起點；跑完全部則不印，頁面據此判斷不需接續
        $hasMore = $hasMore || $budgetExhausted || $cancelled;
        if ($budgetExhausted) {
            $this->warn(sprintf('時間預算 %d 秒用盡，剩餘 %d 筆未處理', $maxSeconds, $count - $processed));
        }
        if ($cancelled) {
            $this->warn(sprintf('收到後台中斷要求，已停在第 %d 筆，剩餘 %d 筆未處理', $processed, $count - $processed));
        }
        if ($hasMore && $lastAccountId > 0) {
            $this->warn(self::RESUME_MARKER . $lastAccountId);
        }

        $this->line('明細檔：' . $filename);

        // 任一玩家失敗即回失敗碼，後台操作紀錄才會標紅提醒；預算用盡與人工中斷屬正常收尾、不算失敗
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 記憶體快照字串：real 是向 OS 實際要走的量（cgroup OOM killer 看的就是這個），peak 是本次執行至今的最高點。
     */
    /** --perSecond 一律夾回 [1, MAX_PER_SECOND]：上限寫死在程式裡，參數只能往下調 */
    public static function resolvePerSecond(int $requested): int
    {
        return max(1, min(self::MAX_PER_SECOND, $requested));
    }

    private function memoryUsage(): string
    {
        return sprintf(
            '%dMB(峰值 %dMB)',
            (int) round(memory_get_usage(true) / self::BYTES_PER_MB),
            (int) round(memory_get_peak_usage(true) / self::BYTES_PER_MB),
        );
    }

    /**
     * 記憶體取樣寫進 log：進程被 SIGKILL 時指令輸出可能還沒 flush，log 才留得住完整的時間序列。
     */
    private function sampleMemory(string $stage, int $processed, int $count, float $startedAt): void
    {
        $elapsed = (int) (microtime(true) - $startedAt);

        $this->line(sprintf('[記憶體] %s %s/%s，%s，已跑 %d 秒', $stage, $processed, $count, $this->memoryUsage(), $elapsed));

        Log::info('【轉回遊戲商餘額】記憶體取樣', [
            'stage' => $stage,
            'processed' => $processed,
            'count' => $count,
            'memory_mb' => (int) round(memory_get_usage(true) / self::BYTES_PER_MB),
            'peak_mb' => (int) round(memory_get_peak_usage(true) / self::BYTES_PER_MB),
            'elapsed_sec' => $elapsed,
        ]);
    }

    private function _getVendorTname(int $vendorId): ?string
    {
        return Vendor::where('id', $vendorId)->first()?->tname;
    }

    private function _getVendorInfo(int $vendorId, int $agentId, int $eventV2 = 0): ?VendorInfo
    {
        return VendorInfo::select('vendor_infos.*', 'agent_vendors.agent_id')
            ->join('agent_vendors', function ($join) use ($agentId) {
                $join->on('vendor_infos.id', '=', 'agent_vendors.vendor_info_id')
                    ->where('agent_vendors.agent_id', $agentId);
            })
            ->when($eventV2 == 1, fn ($query) => $query->where('vendor_infos.type', 2))
            ->when($eventV2 == 0, fn ($query) => $query->where('vendor_infos.type', '<>', 2))
            ->where('vendor_infos.vendor_id', $vendorId)
            ->first();
    }
}
