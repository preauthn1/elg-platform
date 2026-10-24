<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\CashFlowTypeEnum;
use App\Exceptions\BankConvergenceAbortedException;
use App\Helpers\Common;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 同一 agent_group 內「同行不同碼」銀行列收斂（軌1）。
 *
 * 【分類】Bank — 銀行收斂軌①核心寫入工具，⚠️ 條件式非 sync-safe（退役條件見下方）。
 *
 * 與 dedup 家族的分工：dedup 處理同碼重複，本命令處理同行但不同碼；
 * 因為沒有 canonical 欄位可自動判定，配對由呼叫端以 --from/--to 顯式指定。
 *
 * 收斂三層：banks（變體停用並標刪）→ cash_flow_banks（改指 survivor＋同步 bank_code）
 * → available_banks JSON 舊碼改寫，另加會員綁定、提款單與已查證的周邊表改指。
 *
 * ## 為什麼一定要同步 cash_flow_banks.bank_code
 *
 * 會員綁定改指 survivor 後，新提款單帶的是 survivor 碼（WithdrawalController 取
 * $bank->code），而代付查表以 (cash_flow_id, currency_id, bank_code) 為鍵、不看
 * bank_id（WithdrawalPayoutTrait::convertPayoutBankCode()）。碼不同步即查空斷付。
 *
 * ## 但改寫前必須先確認那一列是不是本次的標的
 *
 * cash_flow_banks 沒有 agent_group_id，一列在服務誰只能由 bank_code 判讀。實證的
 * drift 形態正是 bank_id 指 base 列、bank_code 是白標 agent 層碼（cfb 438 SGD0002
 * 之於 g99、cfb 587 WTMY91 之於 g6）。故只改寫碼落在本次收斂碼族（survivor 碼＋
 * 全部變體碼）且幣別相符的列。
 *
 * ## 五種出口
 *
 * 1. 改指  ── 碼族內、幣別相符、唯一鍵位空著。
 * 2. 留置  ── 碼在族外／幣別不符，或唯一鍵位已被 survivor 的 active 列佔住。
 *             一律不動、報表轉人工，對齊 MergeBaseSynonymBanks 的活躍渠道慣例。
 * 3. 阻斷  ── 佔位列已停用（死槽）。收斂會把綁定與 available_banks 切到 survivor 碼，
 *             而查表要求 status=1，結果是斷付而非降級。
 * 4. 阻斷  ── 佔位列與變體列的 provider_bank_code／限額／info 不等價。留置只保住那一列，
 *             攔不住路由搬家，等於未經比對就換掉送單參數。
 * 5. 阻斷  ── available_banks 內兩碼並存且 BankCode_Third 不同。與第 4 點同一件事，
 *             差別只在這是代付查表的舊路徑（未灰度的 provider 仍走它）。
 *
 * 阻斷一律零寫入。
 *
 * 第 4、5 點有一個明示放行：三方碼不同、但新舊兩個碼金流商都收，由我方決定收斂後改送
 * survivor 的三方碼時，操作者以 `--accept-provider-code=<cash_flow_id>:<變體三方碼>:<survivor 三方碼>`
 * 逐筆列出。放行條件：
 *
 * - 三者逐字相同才算列到；同一筆同時涵蓋該渠道的 cfb 與 available_banks 兩層。
 * - 只放行三方碼不同。cfb 的限額或 info 另有不同時照第 4 點阻斷；第 3 點死槽不受此選項影響。
 * - 只要有一筆阻斷沒列到，整組照舊零寫入。
 *
 * 被放行的 cfb 歸留置（不改指、不停用，留在變體身上，殘留檢查以它為白名單）；被放行的
 * available_banks 變體碼項目原樣留在原處，之後用 `bank:remove-acf-bank-entry` 清。
 * 預演會逐筆印出已放行的阻斷，以及「列了但沒用到」的項目（防打錯字）；`--execute` 時每一筆
 * 放行寫一列審計，action 為 `rename_accept_provider_code`。
 *
 * ## ⚠️ dry-run 報表只涵蓋 cash_flow_banks，不等於本次的影響範圍
 *
 * `displayPlan()` 只印 cfb 的改指清單與留置清單。**會員綁定、提款單、available_banks
 * 這三層，預演輸出一個字都不會提，但 `--execute` 一律會做**（`repointSatelliteTables()`
 * 與 `alignAvailableBanks()`，見 execute 段）。核報表核不到真正的影響面。
 *
 * 最會誤導人的是「本次零改指」那句警告。它成立的條件只是「沒有 cfb 要改指」，讀起來
 * 卻像「這次幾乎不動東西」。2026-09-10 VWorld 的實案（g70 的 `UOBSG` 249 收斂到
 * `SGD0001` 1459）——dry-run 從頭到尾就只有那一句，而 `--execute` 的實際影響是：
 *
 * - `member_bank_accounts` 74 列改指（全部走 `repointSoleBindings()`）
 * - `withdrawal_orders` 300 列改指並同步 `bank_code`／`bank_name`
 * - `banks` 1 列停用並標刪
 * - `available_banks` 5 張 ACF 各改寫 1 筆條目（其中 2 張是啟用中的代付渠道）
 * - 逐列審計合計 380 筆
 *
 * ⇒ **執行前請自己查那三張表**：`member_bank_accounts` 與 `withdrawal_orders` 以
 * `bank_id = <loser>` 計數，`available_banks` 以「survivor 的 group＋currency＋
 * `cash_flows.type=WITHDRAW`」列出全部 ACF（`alignAvailableBanks()` 的範圍不篩 ACF
 * status、也不篩 ACF `deleted_at`，停用與軟刪的 ACF 都在內）。命令沒有 ACF 層的參數，
 * 收斂範圍鎖不到單一渠道——**同 group 同幣別的其他代付渠道會被一起改寫**。
 *
 * ## 成功時的完成回報
 *
 * `--execute` 成功後印三行：收斂配對、逐 action 的審計筆數、本次執行代號。
 * 逐 action 印而非只印總數，是因為這支一次動四層（cfb、周邊表、banks、available_banks），
 * 總數對不上時要能一眼看出哪一層沒動到。執行代號一定要印——本命令沒有 rollback，
 * 事後反演唯一的索引就是 `bank_dedup_logs` 的 `detail.run`。
 *
 * ⚠️ 這是 2026-09-10 才補的。**在那之前成功路徑一個字都不印**（失敗路徑反而每條都有輸出），
 * 當天 VWorld 實跑寫進 378 列、畫面卻停在計畫那兩行，操作者分不出「跑完了」與「沒跑」。
 * 讀舊工單或舊終端紀錄時，看到「只有計畫兩行」不代表那次沒有執行。
 *
 * ## ⚠️ preflight 不比對兩列的金額設定
 *
 * `validate()` 只驗 `agent_group_id`／`type`／`currency_id` 一致，加上 survivor 為 active
 * 且未標刪。**`rate`、`tax_type`、`tax` 三個欄位完全不在比對範圍內**，而收斂之後：
 *
 * - 會員新提款單的扣點是 `amount * banks.rate`（`WithdrawalController::store()`）
 * - 手續費是 `tax_type=0` 取 `tax` 固定值、`=1` 取 `tax * amount`
 *
 * ⇒ 兩列若這三個值不同，收斂等於**在會員無感的情況下換掉扣點倍率與手續費**，而命令不會
 * 有任何提示。限額（`min_amount`／`max_amount`）相同**不足以**推論這三個也相同。
 * **執行前請自己 `SELECT id, rate, tax_type, tax FROM banks WHERE id IN (<loser>, <survivor>)`
 * 比對。**（2026-09-10 VWorld 那次兩列剛好完全相同，是運氣不是保護。）
 *
 * ## ⚠️ 沒有 rollback 路徑
 *
 * bank:dedup-rollback 不讀 rename_* action，本命令也未留下足以逐列還原 cfb、
 * available_banks JSON 與周邊表的反向資料（審計的時戳只是定位線索，見 $stamp）。
 * 故 --execute 屬不可逆操作：務必先跑 dry-run 核對報表、事前 SELECT 存查、事後逐筆
 * 複驗。要能真正還原需另立 rollback 命令。
 *
 * ## 退役條件
 *
 * 各站各代理群組的「同行不同碼」全數收斂完畢、且 `cash-flow:sync` 不再建出並存的新碼列
 * 之後即無標的，可連同 `bank:converge-cross-code` 一併刪除。
 *
 * ⚠️ 在那之前是條件式非 sync-safe：base 層仍有同碼 active 列時，收斂可能被後續 sync 推翻。
 */
class RenameBankCommand extends Command
{
    use PreservesRowTimestamps;

    protected $signature = 'bank:rename-bank
                            {--agent-group= : 限定的 agent_group_id}
                            {--from= : 被收斂的 banks.id（loser）}
                            {--to= : 保留的 banks.id（survivor）}
                            {--execute : 實際寫入（預設僅 dry-run）}
                            {--force : 跳過執行前確認}
                            {--allow-unrollbackable : 引用列超過逐列審計上限時仍執行，改以彙總記帳（該次無法回滾）}
                            {--accept-provider-code=* : 放行三方碼不同的阻斷，格式 <cash_flow_id>:<變體三方碼>:<survivor 三方碼>，可重複}';

    protected $description = '同一 agent_group 內同行不同碼銀行收斂：cfb 改指＋bank_code 同步＋loser 停用，留置與阻斷轉人工（預設 dry-run）';

    /** 依 id 批次更新的每批筆數，避免單一 IN 子句塞進數萬個 id。 */
    private const UPDATE_CHUNK = 1000;

    /** detail 欄（text）可容納的字元上限；超出即拋例外，不容截斷的審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /**
     * 單次執行允許寫入的逐列審計上限。
     *
     * 逐列審計是 rollback 的唯一 manifest 來源，但六站實測單一銀行的引用量遠超此數
     * （TPower bank 442＝28,618 綁卡／402,088 出款單、Y2W bank 19＝26,073／240,145、
     * VWorld bank 35＝15,070／300,335、AW77 bank 680＝14,425 綁卡），全數逐列寫入會在
     * 同一交易內灌進數十萬列並拉長鎖定時間。
     *
     * 超限預設中止（交易回滾、零寫入）；高流量銀行的收斂是既有實務（AW77 BCA 14,407
     * 綁卡、TPower TnG 37,006 綁卡都已執行過），故保留 --allow-unrollbackable 明示放行，
     * 該旗標下退回彙總記帳並寫入不可回滾標記。
     */
    private const ROWWISE_AUDIT_LIMIT = 2000;

    /** cfb 分流與等價比對共用的欄位。 */
    private const CFB_COLUMNS = [
        'id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code',
        'provider_bank_code', 'min_amount', 'max_amount', 'info', 'status',
    ];

    /**
     * 已**查證** bank_id 確實指向 banks.id 的周邊表。
     *
     * 納入標準不是「欄位叫 bank_id」——那個名稱在本庫是重載的：
     * - order_images.bank_id 存的是 bank_accounts.id（migration 註解「銀行帳戶id」、
     *   AutoDepositController:181 寫入的是會員選的入款帳戶）。TPower 實測其 328 個相異值
     *   100% 落在 bank_accounts、上限 378（banks 到 2243），誤改會把自動入款訂單指到
     *   錯的收款帳戶，且不可逆。
     * - bank_order_types.bank_id 的註解寫「語言系統編號」。
     * - agent_bank_accounts／agent_real_name_validation_requests／bank_names 兩站皆 0 列、
     *   註解僅寫「銀行ID」，無資料可佐證。
     * - agent_banks 不是「靠 banks.id 定位」的周邊表，是投影表。它的唯一鍵是
     *   (agent_group_id, standard_bank_id)；指向 banks.id 的欄位叫 source_bank_id，
     *   欄位註解自陳「過渡期來源對照⋯權威反轉後退場」。內容由 bank:sync-projection
     *   全刪重建，手動 repoint 會在下一次投影被整批沖掉。⇒ 收斂後要對齊它，正確做法是
     *   跑一次 bank:sync-projection，不是把它加進本清單。
     *   ⚠️ 2026-09-10 更正：本節舊版寫「目前 0 列且帶唯一鍵 (agent_group_id, bank_id,
     *   cash_flow_id)，同 group/渠道同時有兩列時直接 update 必撞鍵」——三處都不符實情。
     *   VWorld 實測 389 列（非 0 列），且該表沒有 bank_id 也沒有 cash_flow_id 這兩個欄位，
     *   所以「撞鍵」那個推論本身不成立。結論（不納入 SATELLITE_TABLES）不變，理由換成上述
     *   投影語意。讀舊終端紀錄或舊工單時，看到「agent_banks 目前 0 列」不代表當時查錯，
     *   而是那句話從一開始就沒有對過 schema。
     *   唯一寫入者是 BankProjectionService，每日排程 bank:sync-projection 會全刪重建
     *   （routes/console.php）。目前沒有其他程式讀這張表；日後若有讀取端，收斂到下一輪
     *   投影之間最多一天是舊資料，要重新評估是否在這裡改指。
     *
     * 以上一律不搬——寧可漏搬（可再補）也不誤改（不可逆）。
     *
     * member_bank_accounts 另走兩案分流、withdrawal_orders 另同步 code/name，故不在此列。
     */
    private const SATELLITE_TABLES = [
        'realname_validation_requests',
    ];

    /** 保留的那一行。preflight 通過後才有值，之後全程唯讀。 */
    private object $survivor;

    /** 寫入目標連線，記進審計供事後辨識是哪一站。 */
    private string $connection;

    /**
     * 本次執行代號，寫進每一列審計的 detail。
     *
     * created_at 只有秒級精度，同一秒對同一 survivor 跑兩次不同變體就分不開；
     * 有了它，rollback 才能精確框出「這一次」動了哪些列。
     */
    private string $runId;

    /** 本次已寫入的逐列審計筆數，用於 {@see self::ROWWISE_AUDIT_LIMIT} 把關。 */
    private int $rowwiseAuditCount = 0;

    /**
     * 本次各 action 實際寫入的審計筆數，供 {@see self::reportCompletion()} 回報。
     *
     * 與 $rowwiseAuditCount 是不同的東西：那個只算「逐列模式」的列數（彙總模式不計），
     * 服務的是上限把關；這個算全部，服務的是給操作者對帳。
     *
     * @var array<string, int>
     */
    private array $auditCounts = [];

    /**
     * 是否改以彙總記帳（引用列超過上限且操作者明示放行）。
     *
     * true 時周邊表改回批次更新、每（表, 變體）只記一列筆數——該次執行無法逐列回滾，
     * 已由 run 級 rename_run_unrollbackable 標記告知 rollback 端。
     */
    private bool $summaryAuditMode = false;

    /**
     * 判為留置的 available_banks entry（格式 `<acf_id>|<碼>`）。
     *
     * 收斂尾端的殘留檢查以此為白名單：這些 entry 是因 survivor 碼已在同一 ACF 內而
     * 刻意不改寫的，與「判讀期間新出現的變體碼」必須分得開。
     *
     * @var list<string>
     */
    private array $skippedAbEntries = [];

    /**
     * 操作者以 --accept-provider-code 列出的放行項目，鍵為 {@see self::acceptanceKey()}。
     *
     * @var array<string, array{cash_flow_id: int, loser_third: string, survivor_third: string}>
     */
    private array $acceptedProviderCodes = [];

    /**
     * 本次執行共用的時戳。
     *
     * 所有異動都蓋同一個值，審計把它寫進 detail 當定位線索（秒級精度、可被後續更新覆蓋，
     * 不足以當 rollback manifest）。
     * 不逐列寫審計：實測 TPower 單一銀行最多 withdrawal_orders 392,943 列、
     * realname_validation_requests 46,069 列、member_bank_accounts 36,279 列、
     * order_images 29,312 列，逐列會在一個交易內灌數萬筆 log 並拉長鎖定時間。
     */
    private Carbon $stamp;

    public function handle(): int
    {
        $this->stamp = now();
        $this->runId = Str::random(12);

        // 同一 process 內重跑（測試、Artisan::call）會沿用同一個 instance：
        // 前次執行留下的計數與白名單若不清空，第二次的把關就從錯誤的起點開始。
        $this->rowwiseAuditCount = 0;
        $this->skippedAbEntries = [];
        $this->summaryAuditMode = false;
        $this->acceptedProviderCodes = [];

        $agentGroupOption = $this->option('agent-group');

        // (int) null 與 (int) 'abc' 都是 0，而 group 0 是平台級 base 標準庫、各白標同步的
        // 模板來源，誤動波及所有站台。故不允許由型別轉換靜默取得。
        if ($agentGroupOption === null || !ctype_digit((string) $agentGroupOption)) {
            $this->error('--agent-group 必填且須為純數字：省略或非數字會落在 group 0（平台級 base 標準庫），影響所有白標同步，故不取預設值。');

            return self::FAILURE;
        }

        $agentGroupId = (int) $agentGroupOption;
        $loserIds = $this->parseLoserIds();

        if ($loserIds === null) {
            $this->error('--from 只接受逗號分隔的純數字 banks.id。');

            return self::FAILURE;
        }

        // --to 同樣不能靠強制轉型：'abc' 與省略都會變成 0，若庫裡存在人工建立的 id 0
        // 就會選到非預期的 survivor。
        $survivorOption = $this->option('to');

        if ($survivorOption === null || !ctype_digit((string) $survivorOption)) {
            $this->error('--to 必填且須為純數字 banks.id。');

            return self::FAILURE;
        }

        $acceptances = $this->parseAcceptedProviderCodes();

        if ($acceptances === null) {
            $this->error('--accept-provider-code 格式須為 <cash_flow_id>:<變體三方碼>:<survivor 三方碼>：cash_flow_id 為純數字，兩個三方碼不可空白、不可含冒號。');

            return self::FAILURE;
        }

        $this->acceptedProviderCodes = $acceptances;

        // 寫入目標由容器的 -e DB_CONNECTION 決定、不在參數裡，而各白標 id 區間重疊，
        // 連錯站時 preflight 仍可能全綠。揭露必須排在所有判定之前：preflight 失敗與撞鍵阻斷
        // 才是最需要核對環境的時刻——「查無此列」「撞鍵」既可能是真的，也可能只是連錯站，
        // 沒有這兩行就分不出來。
        $this->connection = DB::getDefaultConnection();
        $database = (string) DB::connection()->getDatabaseName();

        $this->line(sprintf('寫入目標 connection：%s', $this->connection));
        $this->line(sprintf('寫入目標 database：%s', $database));

        $survivor = DB::table('banks')->where('id', (int) $survivorOption)->first();

        $errors = $this->validate($survivor, $loserIds, $agentGroupId);

        if ($errors !== []) {
            $this->error('preflight 未通過，未寫入任何資料：');

            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        $this->survivor = $survivor;

        // 這份碼只供 dry-run 的計畫與提醒使用；實際寫入時會在交易內鎖後重讀。
        $loserCodes = DB::table('banks')
            ->whereIn('id', $loserIds)
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();

        $plan = $this->buildPlan($loserIds, $loserCodes);

        // 排在阻斷判定之前：被擋下時更需要看到哪些已放行、哪些列了卻沒對上（多半是打錯字）。
        $this->displayAccepted($plan['accepted']);

        if ($this->planIsUnsafe($plan)) {
            $this->reportUnsafe($plan);

            return self::FAILURE;
        }

        $this->displayPlan($loserIds, $plan['repointable']);
        $this->displayRetained($plan['retained']);
        $regrowthCodes = $this->warnAboutRegrowth($agentGroupId, $loserCodes);
        $this->warnAboutMissingTables();

        if (!$this->option('execute')) {
            $this->warn('dry-run：未寫入任何資料，加 --execute 才會實際執行。');

            return self::SUCCESS;
        }

        // 再生提醒併進確認提示：只印在上方報表容易被長輸出淹沒。
        $regrowthNote = $regrowthCodes === []
            ? ''
            : sprintf('；⚠️ base 仍有 active 同碼列（%s），收斂可能被後續 sync 推翻', implode(', ', $regrowthCodes));

        if (!$this->option('force')
            && !$this->confirm(
                sprintf('確認執行收斂？此操作不可逆，寫入目標 database：%s%s', $database, $regrowthNote),
                false,
            )) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        return $this->converge($agentGroupId, $loserIds);
    }

    /**
     * 在交易內重新驗證與分流後才寫入。
     *
     * 上面那份計畫是交易外的無鎖讀，而 sync 隨時在跑：顯示計畫到實際寫入之間，同一批列
     * 可能已被改動，照舊計畫寫下去就是覆蓋別人的變更或撞唯一鍵。故先鎖住 banks，
     * 重跑 preflight 與分流，任一條件不再成立就結束交易（此時尚未寫入任何東西）。
     *
     * @param  list<int>  $loserIds
     */
    private function converge(int $agentGroupId, array $loserIds): int
    {
        $errors = [];
        $plan = null;

        try {
            $this->applyInTransaction($agentGroupId, $loserIds, $errors, $plan);
        } catch (BankConvergenceAbortedException $e) {
            // 交易內的中止條件（超量、殘留引用、改指筆數不符）一律以例外回滾，
            // 到這裡才轉成命令慣例的錯誤輸出＋非零 exit code。
            $this->error('收斂中止，未寫入任何資料：');
            $this->line('  - ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($errors !== []) {
            $this->error('交易內重新驗證未通過（資料在此期間被改動），未寫入任何資料：');

            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        if ($this->planIsUnsafe($plan)) {
            $this->error('交易內重算後撞鍵情境已變（資料在此期間被改動），未寫入任何資料：');
            $this->reportUnsafe($plan);

            return self::FAILURE;
        }

        $this->reportCompletion($loserIds);

        return self::SUCCESS;
    }

    /**
     * 收斂成功後的完成回報。
     *
     * 這支曾經在成功時完全不輸出——每一條失敗路徑都有訊息，唯獨成功直接 return。
     * 2026-09-10 VWorld 實跑寫進 378 列，畫面卻停在計畫那兩行，操作者當場分不出
     * 「跑完了」與「根本沒跑」，只能事後查 DB 才確認。故補上與本家族一致的完成訊息。
     *
     * 逐 action 印筆數而非只印總數：這支一次動四層（cfb、周邊表、banks、
     * available_banks），總數對不上時要能一眼看出是哪一層沒動到。
     *
     * 執行代號一定要印——本命令沒有 rollback，事後反演唯一的索引就是它。
     *
     * @param  list<int>  $loserIds
     */
    private function reportCompletion(array $loserIds): void
    {
        $this->info(sprintf(
            '收斂完成：survivor %d (%s) ← 變體 %s',
            $this->survivor->id,
            $this->survivor->code,
            implode(', ', $loserIds),
        ));

        $counts = $this->auditCounts;
        ksort($counts);

        $breakdown = [];

        foreach ($counts as $action => $count) {
            $breakdown[] = $action . ' ' . $count;
        }

        $this->line(sprintf(
            '  審計 %d 筆（%s）',
            array_sum($counts),
            $breakdown === [] ? '無' : implode('、', $breakdown),
        ));

        $this->line(sprintf(
            '  本次執行代號 detail.run = %s；查 bank_dedup_logs 以它框出精確集合供事後反演。',
            $this->runId,
        ));
    }

    /**
     * 鎖定、重驗、寫入。
     *
     * @param  list<int>  $loserIds
     * @param  list<string>  $errors
     * @param  array{
     *     repointable: list<object>,
     *     retained: list<array{row: object, reason: string}>,
     *     dead_slot: list<object>,
     *     divergent: list<array{row: object, occupant: object, field: string}>,
     *     ab_divergent: list<array{acf_id: int, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>,
     *     accepted: list<array<string, mixed>>,
     * }|null  $plan
     */
    private function applyInTransaction(int $agentGroupId, array $loserIds, ?array &$errors, ?array &$plan): void
    {
        DB::transaction(function () use ($agentGroupId, $loserIds, &$errors, &$plan): void {
            // 鎖住變體並在鎖後重讀碼：dry-run 之後 sync 仍可能改 banks.code，
            // 沿用先前那份會讓碼族閘拿過期的判準去比對。
            $loserBanks = DB::table('banks')
                ->whereIn('id', $loserIds)
                ->lockForUpdate()
                ->pluck('code', 'id')
                ->map(static fn ($code): string => (string) $code)
                ->all();

            $survivor = DB::table('banks')->where('id', $this->survivor->id)->lockForUpdate()->first();

            $errors = $this->validate($survivor, $loserIds, $agentGroupId);

            if ($errors !== []) {
                return;
            }

            $this->survivor = $survivor;
            $plan = $this->buildPlan($loserIds, array_values($loserBanks), lock: true);

            if ($this->planIsUnsafe($plan)) {
                return;
            }

            $this->applyConvergence($loserIds, $loserBanks, $plan);
        });
    }

    /**
     * 把全部變體的 cfb 列分成五類：可改指、留置、死槽、設定不等價、明示放行。
     *
     * 先彙總再分流，不逐個變體處理：否則「誰先佔到 survivor 的唯一鍵位」會取決於
     * --from 的順序，停用列排前面就會搶走位子，讓 survivor 只拿到停用列。
     *
     * accepted 收的是被 --accept-provider-code 放行的 cfb 撞鍵（kind=cfb）與
     * available_banks 衝突（kind=ab），兩者都不寫入、只留審計；cfb 那類不改指、
     * 留在變體身上，等同留置。
     *
     * @param  list<int>  $loserIds
     * @param  list<string>  $loserCodes
     * @param  bool  $lock  交易內重算時為 true，讀取一併上列鎖
     * @return array{
     *     repointable: list<object>,
     *     retained: list<array{row: object, reason: string}>,
     *     dead_slot: list<object>,
     *     divergent: list<array{row: object, occupant: object, field: string}>,
     *     ab_divergent: list<array{acf_id: int, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>,
     *     accepted: list<array{kind: string, id: int, occupant_id: int|null, bank_id: int|null, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>,
     * }
     */
    private function buildPlan(array $loserIds, array $loserCodes, bool $lock = false): array
    {
        $familyCodes = [(string) $this->survivor->code, ...$loserCodes];
        $currencyId = (int) $this->survivor->currency_id;

        $candidates = DB::table('cash_flow_banks')
            ->whereIn('bank_id', $loserIds)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(self::CFB_COLUMNS)
            ->all();

        $owned = [];
        $retained = [];

        foreach ($candidates as $cfb) {
            $isOurs = (int) $cfb->currency_id === $currencyId
                && in_array((string) $cfb->bank_code, $familyCodes, true);

            // 判準取碼族而非「碼存在於本 group」：後者擋不掉 bank_code 指向本 group
            // 另一家銀行的列，改寫下去會把那一行的渠道併吞。
            $isOurs ? $owned[] = $cfb : $retained[] = ['row' => $cfb, 'reason' => 'foreign_code'];
        }

        // active 優先、其次 id 序，讓啟用列先取得鍵位且結果不隨參數順序改變。
        usort($owned, static fn (object $a, object $b): int => [(int) $b->status, (int) $a->id]
            <=> [(int) $a->status, (int) $b->id]);

        // 鍵位起始於 survivor 自身，並隨每列被收斂而累積——否則兩個變體在同一渠道
        // 各有列時，第二個會等到寫入期才撞鍵。
        $occupants = DB::table('cash_flow_banks')
            ->where('bank_id', $this->survivor->id)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(self::CFB_COLUMNS)
            ->mapWithKeys(static fn (object $cfb): array => [
                $cfb->cash_flow_id . '|' . $cfb->currency_id => $cfb,
            ])
            ->all();

        $repointable = [];
        $deadSlot = [];
        $divergent = [];
        $accepted = [];

        foreach ($owned as $cfb) {
            $key = $cfb->cash_flow_id . '|' . $cfb->currency_id;

            if (!isset($occupants[$key])) {
                $occupants[$key] = $cfb;
                $repointable[] = $cfb;

                continue;
            }

            // 本列已停用：留著原樣即可，不影響送單。
            if ((int) $cfb->status !== 1) {
                continue;
            }

            $occupant = $occupants[$key];

            // 唯一鍵不含 status，停用列照樣佔位。此時收斂會把流量導向一個查表撈不到的列。
            if ((int) $occupant->status !== 1) {
                $deadSlot[] = $cfb;

                continue;
            }

            $field = $this->firstDivergentField($occupant, $cfb);

            // 明示放行只認三方碼不同；放行後其餘欄位仍須等價，否則照舊以那個欄位阻斷。
            if ($field === 'provider_bank_code' && $this->isAccepted(
                (int) $cfb->cash_flow_id,
                (string) $cfb->provider_bank_code,
                (string) $occupant->provider_bank_code,
            )) {
                $field = $this->firstDivergentField($occupant, $cfb, ignoreProviderCode: true);

                if ($field === null) {
                    $accepted[] = [
                        'kind' => 'cfb',
                        'id' => (int) $cfb->id,
                        'occupant_id' => (int) $occupant->id,
                        'bank_id' => (int) $cfb->bank_id,
                        'cash_flow_id' => (int) $cfb->cash_flow_id,
                        'code' => (string) $cfb->bank_code,
                        'loser_third' => (string) $cfb->provider_bank_code,
                        'survivor_third' => (string) $occupant->provider_bank_code,
                    ];

                    continue;
                }
            }

            if ($field !== null) {
                $divergent[] = ['row' => $cfb, 'occupant' => $occupant, 'field' => $field];

                continue;
            }

            $retained[] = ['row' => $cfb, 'reason' => 'collision'];
        }

        $abDivergent = [];

        foreach ($this->findAvailableBanksConflicts($loserCodes, $lock) as $conflict) {
            if (!$this->isAccepted($conflict['cash_flow_id'], $conflict['loser_third'], $conflict['survivor_third'])) {
                $abDivergent[] = $conflict;

                continue;
            }

            $accepted[] = [
                'kind' => 'ab',
                'id' => $conflict['acf_id'],
                'occupant_id' => null,
                'bank_id' => null,
                'cash_flow_id' => $conflict['cash_flow_id'],
                'code' => $conflict['code'],
                'loser_third' => $conflict['loser_third'],
                'survivor_third' => $conflict['survivor_third'],
            ];
        }

        return [
            'repointable' => $repointable,
            'retained' => $retained,
            'dead_slot' => $deadSlot,
            'divergent' => $divergent,
            'ab_divergent' => $abDivergent,
            'accepted' => $accepted,
        ];
    }

    /** 這組 (cash_flow_id, 變體三方碼, survivor 三方碼) 是否被 --accept-provider-code 逐字列到。 */
    private function isAccepted(int $cashFlowId, string $loserThird, string $survivorThird): bool
    {
        return isset($this->acceptedProviderCodes[$this->acceptanceKey($cashFlowId, $loserThird, $survivorThird)]);
    }

    /** 放行項目的比對鍵；以 JSON 串接，三方碼含任何字元都不會與分隔符混淆。 */
    private function acceptanceKey(int $cashFlowId, string $loserThird, string $survivorThird): string
    {
        return json_encode([$cashFlowId, $loserThird, $survivorThird], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 三種阻斷條件任一成立就零寫入。
     *
     * @param  array{dead_slot: list<object>, divergent: list<array<string, mixed>>, ab_divergent: list<array<string, mixed>>}  $plan
     */
    private function planIsUnsafe(array $plan): bool
    {
        return $plan['dead_slot'] !== [] || $plan['divergent'] !== [] || $plan['ab_divergent'] !== [];
    }

    /**
     * 找出兩列送單設定第一個不一致的欄位；完全等價則回 null。
     *
     * 查表回傳的是佔位那列的 provider_bank_code 與限額，收斂後會直接套用到原本走
     * 變體那列的會員身上，故必須逐欄比對。
     */
    private function firstDivergentField(object $occupant, object $cfb, bool $ignoreProviderCode = false): ?string
    {
        if (!$ignoreProviderCode && (string) $occupant->provider_bank_code !== (string) $cfb->provider_bank_code) {
            return 'provider_bank_code';
        }

        // 限額是 decimal(20,4)，字串比對會被尾零與精度誤判。
        if (Common::numCompare($occupant->min_amount, $cfb->min_amount, 4) !== 0) {
            return 'min_amount';
        }

        if (Common::numCompare($occupant->max_amount, $cfb->max_amount, 4) !== 0) {
            return 'max_amount';
        }

        if ((string) ($occupant->info ?? '') !== (string) ($cfb->info ?? '')) {
            return 'info';
        }

        return null;
    }

    /**
     * 依計畫實際寫入。呼叫端已包在 transaction 內。
     *
     * @param  list<int>  $loserIds
     * @param  array<int, string>  $loserBanks  變體 banks.id => code
     * @param  array{repointable: list<object>, retained: list<array{row: object, reason: string}>, accepted: list<array<string, mixed>>}  $plan
     */
    private function applyConvergence(array $loserIds, array $loserBanks, array $plan): void
    {
        $this->assertRowwiseAuditWithinLimit($loserIds, $plan, array_values($loserBanks));

        // 放行本身不寫任何業務資料，但它決定了收斂後該渠道改送哪個三方碼，必須留痕。
        // 不標 rowwise：沒有可還原的異動，不佔逐列 manifest 的上限。
        foreach ($plan['accepted'] as $item) {
            $loserBankId = $item['bank_id'] ?? array_search($item['code'], $loserBanks, true);

            $this->audit(
                loserBankId: (int) $loserBankId,
                action: 'rename_accept_provider_code',
                detail: [
                    't' => $item['kind'] === 'cfb' ? 'cash_flow_banks' : 'agent_cash_flows',
                    'id' => $item['id'],
                    'occupant' => $item['occupant_id'],
                    'cf' => $item['cash_flow_id'],
                    'kept' => $item['code'],
                    'loser_third' => $item['loser_third'],
                    'survivor_third' => $item['survivor_third'],
                ],
            );
        }

        foreach ($plan['retained'] as $item) {
            $cfb = $item['row'];

            $this->audit(
                loserBankId: (int) $cfb->bank_id,
                action: 'rename_retain_' . $item['reason'],
                detail: ['t' => 'cash_flow_banks', 'id' => (int) $cfb->id, 'kept' => $cfb->bank_code],
            );
        }

        foreach ($plan['repointable'] as $cfb) {
            DB::table('cash_flow_banks')->where('id', $cfb->id)->update([
                'bank_id' => $this->survivor->id,
                'bank_code' => $this->survivor->code,
                'updated_at' => $this->stamp,
            ]);

            $this->audit(
                loserBankId: (int) $cfb->bank_id,
                action: 'rename_repoint_cfb',
                detail: [
                    't' => 'cash_flow_banks',
                    'id' => (int) $cfb->id,
                    'rowwise' => true,
                    'was' => ['bank_id' => (int) $cfb->bank_id, 'bank_code' => (string) $cfb->bank_code],
                    'aft' => ['bank_id' => (int) $this->survivor->id, 'bank_code' => (string) $this->survivor->code],
                ],
            );
        }

        // 光設 status=0 會被 sync 打回：AgentBankSyncService::syncToAgentGroup() 挑 target
        // 只濾 whereNull('deleted_at')、不看 status，選中後直接把它改回 status=1
        // （TPower AGRO 776/1300 實案）。沿用 BankActiveDedupService:190 的處置。
        //
        // Bank model 無 SoftDeletes，deleted_at 只是清理標記：碼占用查詢
        // （SyncCashFlowsCommand 的 $usedCodes）不帶該過濾，舊碼不會被回收重用。
        // 先取異動前的實值：preflight 只要求 survivor 為 active／未標刪，對 loser 沒有
        // 對應限制（重跑已停用或已標刪的變體是既有支援的用例），寫死 status=1／
        // deleted_at=null 會讓審計謊報前值，事後追溯與反演都被誤導。
        $before = DB::table('banks')
            ->whereIn('id', $loserIds)
            ->lockForUpdate()
            ->get(['id', 'status', 'deleted_at'])
            ->keyBy('id');

        DB::table('banks')->whereIn('id', $loserIds)->update([
            'status' => 0,
            'updated_at' => $this->stamp,
        ]);

        // 分開下 deleted_at 並限 whereNull：重跑已收斂過的變體時要保住原始收斂時間，
        // 否則審計時序與 updated_at 回推鍵都會被刷成當下而失真。
        DB::table('banks')
            ->whereIn('id', $loserIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $this->stamp]);

        foreach ($loserIds as $loserId) {
            $was = $before->get($loserId);

            if ($was === null) {
                throw new BankConvergenceAbortedException(sprintf('變體 %d 於停用前查詢不到，收斂中止。', $loserId));
            }

            $this->audit(
                loserBankId: $loserId,
                action: 'rename_deactivate_bank',
                detail: [
                    't' => 'banks',
                    'id' => $loserId,
                    'rowwise' => true,
                    'was' => ['status' => (int) $was->status, 'deleted_at' => $was->deleted_at],
                    // deleted_at 只在原本為空時才被寫成本次時戳，非空則保留原值。
                    'aft' => [
                        'status' => 0,
                        'deleted_at' => $was->deleted_at ?? $this->stamp->toDateTimeString(),
                    ],
                ],
            );
        }

        $this->repointSatelliteTables($loserIds);
        $this->alignAvailableBanks($loserBanks);

        // 排在全部寫入之後：ACF 改寫也可能是新資料出現的時機，提早檢查證不了最終狀態。
        $this->assertNoActiveReferencesLeft($loserIds, $plan);
        $this->assertNoVariantCodeLeftInAvailableBanks(array_values($loserBanks));
    }

    /**
     * 把仍指向變體的周邊資料改指 survivor。
     *
     * 不是為了避免孤兒——Bank model 無 SoftDeletes、變體列仍在，belongsTo 解析得到；
     * 而是要讓這些資料跟著存活的那一行走，客服、對帳與前台看到的才是仍在服務的銀行。
     *
     * 以 bank_id 為條件，該欄逐列唯一，天然不會跨 group 誤改，與依碼字串全域比對的
     * 做法（ReplaceBank 的地雷）不同。ReplaceBank 整支不移植而改寫本命令，是因為它除了
     * 這顆地雷還會覆寫 cfb.bank_code 斷代付，且它誕生於「cfb.bank_code 是 agent 層碼」這條
     * 契約被證實之前，核心邏輯（把碼對齊 base）方向本身就錯。
     *
     * 實測 TPower／VWorld 只有 member_bank_accounts／order_images／withdrawal_orders／
     * realname_validation_requests 有資料，其餘皆 0 列；空表更新為 no-op，納入是為了
     * 日後若被啟用不致留下孤兒。
     *
     * @param  list<int>  $loserIds
     */
    private function repointSatelliteTables(array $loserIds): void
    {
        $tables = $this->existingSatelliteTables();

        // 逐個變體處理：合併成一次 whereIn($loserIds) 只能寫出一列總數，多變體時會把
        // 全部異動掛在其中一個變體名下。會員綁定另有兩案分流，也需要逐個變體重算子查詢。
        foreach ($loserIds as $loserId) {
            $this->deactivateDuplicateBindings($loserId);
            $this->repointSoleBindings($loserId);

            foreach ($tables as $table) {
                $this->repointByExactIds($table, $loserId, ['id']);
            }

            // 提款單另同步 code/name：客服與對帳看的是訂單上的銀行欄位，留著已停用變體
            // 的值會查無此行。沿用 BankActiveDedupService 的既有慣例。
            // 訂單另比對 agent_group_id，不只看 bank_id：會員綁的是自己 group 的銀行列
            // （前台可見鏈 BankVisibilityChecker step 6 查的就是 agent 自己的 group），
            // base（group 0）只是同步模板、不該有訂單。實測 TPower 有 12 筆訂單指向
            // 非自己 group 的銀行列（含 10 筆指向 base），那是既有 drift——屬別 group
            // 的資料，本命令不得順手改掉。
            $this->repointByExactIds(
                'withdrawal_orders',
                $loserId,
                ['id', 'bank_code', 'bank_name'],
                fn ($query) => $query->where('agent_group_id', $this->survivor->agent_group_id),
                [
                    'bank_code' => (string) $this->survivor->code,
                    'bank_name' => (string) $this->survivor->name,
                ],
            );
        }
    }

    /**
     * 動手前先估算逐列審計總量，超限即中止。
     *
     * 只用 count 不加鎖：若等到 repointByExactIds 逐表鎖定後才發現超量，數十萬列的
     * 鎖已經拿在手上，長時間佔用比中止本身更傷。每張表一次 whereIn 全部變體，
     * 不在變體迴圈內逐表查詢。
     *
     * @param  list<int>  $loserIds
     * @param  array{repointable: list<object>, retained: list<array{row: object, reason: string}>}  $plan
     * @param  list<string>  $loserCodes
     */
    private function assertRowwiseAuditWithinLimit(array $loserIds, array $plan, array $loserCodes): void
    {
        $estimate = count($plan['repointable'])
            + count($loserIds)
            + DB::table('member_bank_accounts')->whereIn('bank_id', $loserIds)->count()
            + $this->withdrawalOrderScope($loserIds)->count()
            + $this->countAvailableBankEntries($loserCodes);

        foreach ($this->existingSatelliteTables() as $table) {
            $estimate += DB::table($table)->whereIn('bank_id', $loserIds)->count();
        }

        if ($estimate <= self::ROWWISE_AUDIT_LIMIT) {
            return;
        }

        if (!$this->option('allow-unrollbackable')) {
            throw new BankConvergenceAbortedException(sprintf(
                '預估逐列審計 %d 筆、超過上限 %d：逐列 manifest 會在單一交易內灌進過多列。'
                . '確認可接受「本次無法回滾」請加 --allow-unrollbackable（將改以彙總記帳）。',
                $estimate,
                self::ROWWISE_AUDIT_LIMIT
            ));
        }

        // 明示放行：退回彙總記帳，並在審計裡留下 run 級不可回滾標記供 rollback 端據以拒絕。
        $this->summaryAuditMode = true;

        $this->warn(sprintf(
            '引用列 %d 筆超過逐列審計上限 %d：本次改以彙總記帳，**該次執行無法回滾**。',
            $estimate,
            self::ROWWISE_AUDIT_LIMIT
        ));

        $this->audit(
            loserBankId: $loserIds[0],
            action: 'rename_run_unrollbackable',
            detail: [
                'reason' => 'rowwise_limit_exceeded',
                'n' => $estimate,
                'limit' => self::ROWWISE_AUDIT_LIMIT,
            ],
        );
    }

    /**
     * 估算 available_banks 內待改寫或待留痕的 entry 數。
     *
     * alignSingleAcf() 對每個命中變體碼的 entry 各寫一列審計（改寫或 skip），那也算
     * 逐列審計；漏算會讓超量的 ACF 直到改寫時才被 audit() 攔下，鎖已在手上。
     * 以碼字串命中數為上限估算（比實際略寬、寧可保守）。
     *
     * @param  list<string>  $loserCodes
     */
    private function countAvailableBankEntries(array $loserCodes): int
    {
        if ($loserCodes === []) {
            return 0;
        }

        // 與 alignAvailableBanks 同一組 ACF：估算範圍必須等於實際改寫範圍。
        $rows = DB::table('agent_cash_flows')
            ->whereIn('id', $this->payoutAcfIds())
            ->pluck('info');

        $entries = 0;

        foreach ($rows as $info) {
            $decoded = json_decode((string) $info, true);

            foreach ($decoded['available_banks'] ?? [] as $entry) {
                if (in_array((string) ($entry['BankCode_ELG'] ?? ''), $loserCodes, true)) {
                    $entries++;
                }
            }
        }

        return $entries;
    }

    /**
     * 出款單的改指範圍。
     *
     * 另比對 agent_group_id、不只看 bank_id：會員綁的是自己 group 的銀行列，base
     * （group 0）只是同步模板、不該有訂單。實測 TPower 有 12 筆訂單指向非自己
     * group 的銀行列（含 10 筆指向 base），那是既有 drift——屬別 group 的資料，
     * 本命令不得順手改掉。
     *
     * @param  int|list<int>  $loserIds
     */
    private function withdrawalOrderScope(int|array $loserIds): Builder
    {
        $query = DB::table('withdrawal_orders')->where('agent_group_id', $this->survivor->agent_group_id);

        return is_array($loserIds)
            ? $query->whereIn('bank_id', $loserIds)
            : $query->where('bank_id', $loserIds);
    }

    /**
     * 收斂後仍指向變體的活躍引用必須為零，否則整批中止。
     *
     * 改指只動「取值時已存在」的列，若期間有新列插入指向變體，它會留在原處；而變體
     * 此時已停用，那些引用就成了指向停用銀行的孤兒。與其回報成功後留下不一致，不如
     * 讓整個交易回滾、重跑一次。
     *
     * 一律以 lockForUpdate() 讀：交易前段的無鎖 count 已建立 consistent-read snapshot，
     * 普通 SELECT 看不到其他連線之後提交的列——那正是本檢查要抓的東西。
     *
     * 兩類刻意留置不計入：停用的重複綁定（status=0），以及計畫判為留置的 cfb 列
     * （含被 --accept-provider-code 放行的撞鍵列）。
     *
     * @param  list<int>  $loserIds
     * @param  array{retained: list<array{row: object, reason: string}>, accepted: list<array<string, mixed>>}  $plan
     */
    private function assertNoActiveReferencesLeft(array $loserIds, array $plan): void
    {
        $bindings = DB::table('member_bank_accounts')
            ->whereIn('bank_id', $loserIds)
            ->where('status', 1)
            ->lockForUpdate()
            ->count();

        $orders = $this->withdrawalOrderScope($loserIds)->lockForUpdate()->count();

        $satellites = 0;

        foreach ($this->existingSatelliteTables() as $table) {
            $satellites += DB::table($table)->whereIn('bank_id', $loserIds)->lockForUpdate()->count();
        }

        // cfb 不能要求歸零：計畫判為留置的列本來就留在變體身上，故以留置 id 當白名單。
        // 只看 status=1：代付查表要求 status=1（WithdrawalPayoutTrait），停用列留在停用的
        // 變體身上沒有 runtime 影響——buildPlan 對「停用變體列撞鍵」也是直接跳過、不歸類。
        $retainedIds = [
            ...array_map(static fn (array $item): int => (int) $item['row']->id, $plan['retained']),
            ...array_map(
                static fn (array $item): int => (int) $item['id'],
                array_values(array_filter($plan['accepted'], static fn (array $item): bool => $item['kind'] === 'cfb')),
            ),
        ];

        $cfbs = DB::table('cash_flow_banks')
            ->whereIn('bank_id', $loserIds)
            ->where('status', 1)
            ->whereNotIn('id', $retainedIds === [] ? [0] : $retainedIds)
            ->lockForUpdate()
            ->count();

        if ($bindings !== 0 || $orders !== 0 || $satellites !== 0 || $cfbs !== 0) {
            throw new BankConvergenceAbortedException(sprintf(
                '收斂後仍有未預期的變體引用（綁定 %d、出款單 %d、周邊表 %d、cfb %d）：'
                . '判讀期間可能有新資料寫入，整批已回滾，請重跑。',
                $bindings,
                $orders,
                $satellites,
                $cfbs
            ));
        }
    }

    /**
     * 以「先鎖定取精確 id、再依 id 更新」的方式改指，並逐列寫 manifest。
     *
     * 不用 `WHERE bank_id = loser` 直接批次更新：取值與更新之間若有新列插入，該列會被
     * 一併改掉卻沒有對應的審計列，manifest 就與實際異動不符。改成只更新已取到的 id，
     * 新插入的列留在原處（由後續批次處理），並以 affected 筆數核對每一批。
     *
     * @param  list<string>  $columns  需要記進 was 的欄位（必含 id）
     * @param  (callable(Builder): mixed)|null  $scope  額外查詢條件
     * @param  array<string, string>  $extraUpdates  除 bank_id 外要一併寫入的欄位
     * @param  bool  $collectIdsFirst  分流條件含自我子查詢、無法直接 UPDATE（errno 1093）
     */
    private function repointByExactIds(
        string $table,
        int $loserId,
        array $columns,
        ?callable $scope = null,
        array $extraUpdates = [],
        bool $collectIdsFirst = false,
    ): void {
        $query = DB::table($table)->where('bank_id', $loserId);

        if ($scope !== null) {
            $scope($query);
        }

        // FK 表（wo／mba／rvr）的 updated_at 一律保留原值：改指是技術搬移而非業務事件。
        // 原本寫 $this->stamp 是為了「一次執行共用同一戳」便於回查，但那會蓋掉業務時序
        // （2026-08-27 生產事故）；回查改依審計表的 created_at，能力不失。
        $updates = $this->preservingUpdatedAt(['bank_id' => $this->survivor->id, ...$extraUpdates]);
        $after = ['bank_id' => (int) $this->survivor->id, ...$extraUpdates];

        // 彙總模式：引用列數已超過逐列 manifest 的規模，只記一列筆數、不寫數十萬筆審計。
        // 能直接 UPDATE 的表就直接下（40 萬列的 id 全載進記憶體才是這個模式要避開的），
        // 只有分流條件含自我子查詢的 member_bank_accounts 必須先取 id——MySQL 禁止
        // UPDATE 的 WHERE 子查詢指向同一張表（errno 1093）。
        if ($this->summaryAuditMode) {
            if ($collectIdsFirst) {
                $moved = 0;

                foreach (array_chunk($query->lockForUpdate()->pluck('id')->all(), self::UPDATE_CHUNK) as $chunk) {
                    $moved += DB::table($table)->whereIn('id', $chunk)->update($updates);
                }
            } else {
                $moved = $query->update($updates);
            }

            if ($moved > 0) {
                $this->audit(
                    loserBankId: $loserId,
                    action: 'rename_repoint_satellite',
                    detail: [
                        't' => $table,
                        'n' => $moved,
                        'was' => ['bank_id' => $loserId],
                        'aft' => $after,
                        'rowwise' => false,
                    ],
                );
            }

            return;
        }

        $rows = $query->lockForUpdate()->get($columns);

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows->chunk(self::UPDATE_CHUNK) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $affected = DB::table($table)->whereIn('id', $ids)->update($updates);

            if ($affected !== count($ids)) {
                throw new BankConvergenceAbortedException(sprintf(
                    '%s 改指筆數不符：預期 %d 列、實際 %d 列，收斂中止。',
                    $table,
                    count($ids),
                    $affected
                ));
            }
        }

        foreach ($rows as $row) {
            $was = ['bank_id' => $loserId];

            foreach ($extraUpdates as $column => $ignored) {
                $was[$column] = (string) $row->{$column};
            }

            $this->audit(
                loserBankId: $loserId,
                action: 'rename_repoint_satellite',
                detail: [
                    't' => $table,
                    'id' => (int) $row->id,
                    'rowwise' => true,
                    'was' => $was,
                    'aft' => $after,
                ],
            );
        }
    }

    /**
     * 各白標的 schema 未必一致，缺表時 DB::table()->update() 會在交易中途拋例外。
     *
     * @return list<string>
     */
    private function existingSatelliteTables(): array
    {
        return array_values(array_filter(self::SATELLITE_TABLES, Schema::hasTable(...)));
    }

    /** 缺表在 dry-run 就講清楚，別讓操作者事後才發現某些引用沒被搬。 */
    private function warnAboutMissingTables(): void
    {
        $missing = array_values(array_diff(self::SATELLITE_TABLES, $this->existingSatelliteTables()));

        if ($missing !== []) {
            $this->warn(sprintf('本站不存在下列周邊表，將略過：%s', implode(', ', $missing)));
        }
    }

    /**
     * base（group 0）仍有同碼 active 列時提醒：AgentBankSyncService::syncToAgentGroup()
     * 之後會照那個模板在本 group 另建一筆新列，變體等於重新長回來、收斂無法定案。
     *
     * 只警告不阻斷：是否會長回來取決於後續有沒有人對該渠道跑 sync，且 base 層的收斂
     * 屬另一條軌，硬擋會讓本命令在主要用例上無法使用。
     *
     * @param  list<string>  $loserCodes
     * @return list<string> base 仍持有的同碼清單（空＝無再生疑慮）
     */
    private function warnAboutRegrowth(int $agentGroupId, array $loserCodes): array
    {
        if ($agentGroupId === 0 || $loserCodes === []) {
            return [];
        }

        $baseCodes = DB::table('banks')
            ->where('agent_group_id', 0)
            ->where('currency_id', $this->survivor->currency_id)
            ->whereIn('code', $loserCodes)
            // 不濾 deleted_at：AgentBankSyncService:31-37 的 base 查詢也沒濾，
            // status=1 但已標刪的 base 列一樣會被拿來當模板，濾掉會漏警告。
            ->where('status', 1)
            ->pluck('code')
            ->all();

        if ($baseCodes === []) {
            return [];
        }

        $this->warn(sprintf(
            'base（group 0）仍有 active 同碼列：%s。後續對本 group 跑 sync 會照該模板另建新列，變體將重新出現——請先收斂 base 或確認不會再跑 sync。',
            implode(', ', $baseCodes),
        ));

        return $baseCodes;
    }

    /**
     * 會員已綁 survivor 時停用其變體綁定，不改指。
     *
     * member_bank_accounts 沒有 (member_id, bank_id) 唯一鍵，直接改指不會報錯、而是
     * 靜默製造重複：前台綁定下拉出現兩筆同銀行，提款帶哪個帳號變成不確定。
     * 沿用 BankActiveDedupService:258-274 的處置。
     *
     * 此處逐列寫審計並填 member_id／member_bank_account_id：集合是 survivor∩loser
     * 交集、遠小於全體綁定，且停用等於把會員的綁定藏起來，爭議時要查得到是哪一筆。
     */
    private function deactivateDuplicateBindings(int $loserId): void
    {
        $bindings = DB::table('member_bank_accounts')
            ->where('bank_id', $loserId)
            ->where('status', 1)
            // 子查詢必須限 status=1：停用的 survivor 綁定不算「已綁 survivor」。不限的話，
            // 只有停用 survivor 綁定的會員會在這裡被停掉唯一有效的變體綁定，又因該列存在
            // 而被 repointSoleBindings 排除，最後零個有效綁定、無法提款。
            ->whereIn('member_id', fn ($query) => $query
                ->select('member_id')
                ->from('member_bank_accounts')
                ->where('bank_id', $this->survivor->id)
                ->where('status', 1))
            // 判讀與更新之間若有併發新增綁定，分流會失準並重新製造重複綁定。
            ->lockForUpdate()
            ->get(['id', 'member_id', 'status']);

        foreach ($bindings as $binding) {
            DB::table('member_bank_accounts')->where('id', $binding->id)->update($this->preservingUpdatedAt([
                'status' => 0,
            ]));

            $this->audit(
                loserBankId: $loserId,
                action: 'rename_deactivate_binding',
                detail: [
                    't' => 'member_bank_accounts',
                    'id' => (int) $binding->id,
                    'rowwise' => true,
                    // 上方查詢已限 status=1，記實值是為了不讓 manifest 依賴遠處的查詢條件。
                    'was' => ['status' => (int) $binding->status],
                    'aft' => ['status' => 0],
                ],
                memberId: (int) $binding->member_id,
                bindingId: (int) $binding->id,
            );
        }
    }

    /**
     * 會員沒有有效的 survivor 綁定時，把變體綁定改指過去。
     *
     * 判準與 deactivateDuplicateBindings 互補，同樣只認 active 的 survivor 綁定：
     * 只有停用 survivor 綁定的會員歸這裡處理，改指後他在 survivor 下會有一停用一啟用
     * 兩列，前台看到的仍是那一筆有效綁定。
     */
    private function repointSoleBindings(int $loserId): void
    {
        // MySQL 禁止 UPDATE 的 WHERE 子查詢指向同一張表（errno 1093），故先取 id
        // 再依 id 批次更新；順帶把數萬列的逐列 UPDATE 收斂成數十次批次。
        $this->repointByExactIds(
            'member_bank_accounts',
            $loserId,
            ['id'],
            fn ($query) => $query->whereNotIn('member_id', fn ($sub) => $sub
                ->select('member_id')
                ->from('member_bank_accounts')
                ->where('bank_id', $this->survivor->id)
                ->where('status', 1)),
            collectIdsFirst: true,
        );
    }

    /**
     * 把代付 ACF 的 available_banks JSON 內舊碼改寫為 survivor 碼。
     *
     * 不改寫則舊路徑（BankCode_ELG 字串比對）會查不到已停用的碼而送單失敗。
     *
     * @param  array<int, string>  $loserBanks  變體 banks.id => code
     */
    private function alignAvailableBanks(array $loserBanks): void
    {
        if ($loserBanks === []) {
            return;
        }

        foreach ($this->payoutAcfIds() as $acfId) {
            $this->alignSingleAcf((int) $acfId, $loserBanks);
        }
    }

    /**
     * 收斂後 available_banks 內不得殘留變體碼，除了刻意留置的那些。
     *
     * 與其他引用面同構：舊路徑代付查表以 BankCode_ELG 命中
     * （WithdrawalPayoutTrait::convertPayoutBankCodeFromJson），殘留的變體碼會把送單
     * 導向一個已停用的銀行列。判讀期間若有人往 JSON 裡新增變體碼 entry，align 掃過
     * 之後才出現的那筆就會留下來。
     *
     * 白名單＝alignSingleAcf 判為 skip 的 (acf_id, 碼)：survivor 碼已在同一 ACF 內時
     * 改寫會製造重複 BankCode_ELG，那些 entry 本來就該原樣留著。
     *
     * @param  list<string>  $loserCodes
     */
    private function assertNoVariantCodeLeftInAvailableBanks(array $loserCodes): void
    {
        if ($loserCodes === []) {
            return;
        }

        // 直接以 locking read 重跑範圍條件，不沿用 payoutAcfIds() 的無鎖 snapshot：
        // 那份 id 集合取得時不含判讀期間新建的 ACF，用它框選等於讓新列逃過檢查。
        $rows = DB::table('agent_cash_flows')
            ->join('cash_flows', 'cash_flows.id', '=', 'agent_cash_flows.cash_flow_id')
            ->where('agent_cash_flows.agent_group_id', $this->survivor->agent_group_id)
            ->where('agent_cash_flows.currency_id', $this->survivor->currency_id)
            ->where('cash_flows.type', CashFlowTypeEnum::WITHDRAW->value)
            ->lockForUpdate()
            ->get(['agent_cash_flows.id', 'agent_cash_flows.info']);

        foreach ($rows as $row) {
            $info = json_decode((string) $row->info, true);

            foreach ($info['available_banks'] ?? [] as $entry) {
                $code = (string) ($entry['BankCode_ELG'] ?? '');

                if (!in_array($code, $loserCodes, true)) {
                    continue;
                }

                if (in_array($this->skippedEntryKey((int) $row->id, $code), $this->skippedAbEntries, true)) {
                    continue;
                }

                throw new BankConvergenceAbortedException(sprintf(
                    'agent_cash_flow %d 的 available_banks 仍殘留變體碼 %s（非留置項）：'
                    . '判讀期間可能有新資料寫入，整批已回滾，請重跑。',
                    $row->id,
                    $code
                ));
            }
        }
    }

    /** 留置 entry 的白名單鍵；碼本身不含分隔符（皆為英數）。 */
    private function skippedEntryKey(int $acfId, string $code): string
    {
        return $acfId . '|' . $code;
    }

    /**
     * 本次收斂會掃到的代付 ACF。
     *
     * 幣別必須一起限定：同 group 另一幣別的 ACF 若恰有相同舊碼，
     * 不限幣別就會把它改成 survivor 的跨幣別代碼。
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    private function payoutAcfIds(): \Illuminate\Support\Collection
    {
        return DB::table('agent_cash_flows')
            ->join('cash_flows', 'cash_flows.id', '=', 'agent_cash_flows.cash_flow_id')
            ->where('agent_cash_flows.agent_group_id', $this->survivor->agent_group_id)
            ->where('agent_cash_flows.currency_id', $this->survivor->currency_id)
            ->where('cash_flows.type', CashFlowTypeEnum::WITHDRAW->value)
            ->pluck('agent_cash_flows.id');
    }

    /**
     * 找出撞碼且送單參數不等價的 available_banks entry。
     *
     * 同一 ACF 內 survivor 碼與變體碼並存時只能留置（改寫會製造重複 BankCode_ELG），
     * 但留置擋不住路由搬家：綁定改指後新提款帶的是 survivor 碼，舊路徑
     * WithdrawalPayoutTrait::convertPayoutBankCodeFromJson 以 BankCode_ELG 命中的就變成
     * survivor 那筆 entry、取到它的 BankCode_Third。兩者不同即靜默換掉送單參數——與
     * cash_flow_banks 的等價閘是同一件事，故同樣阻斷。
     *
     * 只比對 BankCode_Third：那是舊路徑唯一讀取的值。entry 上偶見的 min_amount／
     * max_amount 是遺留殘留，限額的 source of truth 已改為 banks 表
     * （AddAgentCashFlow:1606），拿它們當判準只會在無關資料上誤擋。
     *
     * @param  list<string>  $loserCodes
     * @return list<array{acf_id: int, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>
     */
    private function findAvailableBanksConflicts(array $loserCodes, bool $lock): array
    {
        $targetCode = (string) $this->survivor->code;
        $conflicts = [];

        foreach ($this->payoutAcfIds() as $acfId) {
            $acf = DB::table('agent_cash_flows')
                ->where('id', $acfId)
                ->when($lock, static fn ($query) => $query->lockForUpdate())
                ->first(['cash_flow_id', 'info']);

            $info = json_decode((string) ($acf->info ?? ''), true);

            if (!is_array($info) || !is_array($info['available_banks'] ?? null)) {
                continue;
            }

            // 保留首見的那筆：舊路徑是 foreach 命中即 return，同碼多列時生效的是第一筆。
            $thirdByCode = [];

            foreach ($info['available_banks'] as $entry) {
                $target = is_array($entry) ? (string) ($entry['BankCode_ELG'] ?? '') : '';

                if ($target !== '' && !array_key_exists($target, $thirdByCode)) {
                    $thirdByCode[$target] = (string) ($entry['BankCode_Third'] ?? '');
                }
            }

            if (!array_key_exists($targetCode, $thirdByCode)) {
                continue;
            }

            foreach ($loserCodes as $loserCode) {
                if (!array_key_exists($loserCode, $thirdByCode)
                    || $thirdByCode[$loserCode] === $thirdByCode[$targetCode]) {
                    continue;
                }

                $conflicts[] = [
                    'acf_id' => (int) $acfId,
                    // 放行比對以渠道為單位，與 cfb 層共用同一筆 --accept-provider-code。
                    'cash_flow_id' => (int) $acf->cash_flow_id,
                    'code' => $loserCode,
                    'loser_third' => $thirdByCode[$loserCode],
                    'survivor_third' => $thirdByCode[$targetCode],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * 目標碼已存在於同一 ACF 時略過改寫：直接改會產生重複 BankCode_ELG，
     * 代付查表將取到不確定的一筆，寧可留待人工合併。
     *
     * @param  array<int, string>  $loserBanks  變體 banks.id => code
     */
    private function alignSingleAcf(int $acfId, array $loserBanks): void
    {
        $row = DB::table('agent_cash_flows')->where('id', $acfId)->lockForUpdate()->first();

        $info = json_decode((string) ($row->info ?? ''), true);

        if (!is_array($info) || !is_array($info['available_banks'] ?? null)) {
            return;
        }

        $targetCode = (string) $this->survivor->code;
        $existingCodes = array_column($info['available_banks'], 'BankCode_ELG');
        $changed = false;

        // survivor 碼是否「本來就在」這張 ACF 裡，決定 skip 能不能進殘留白名單。
        // findAvailableBanksConflicts 只在原本就並存時比對過三方碼等價；若 survivor 碼
        // 是本次迴圈改寫第一個變體才產生的，第二個變體的 skip 並未經等價檢查——那種
        // entry 不進白名單，交由收斂尾端的殘留檢查攔下（fail-closed，見該方法註解）。
        $targetCodePreexisted = in_array($targetCode, $existingCodes, true);

        foreach ($info['available_banks'] as $index => $entry) {
            $target = (string) ($entry['BankCode_ELG'] ?? '');

            if (!in_array($target, $loserBanks, true)) {
                continue;
            }

            $loserBankId = (int) array_search($target, $loserBanks, true);

            if (in_array($targetCode, $existingCodes, true)) {
                $this->audit(
                    loserBankId: $loserBankId,
                    action: 'rename_ab_skipped',
                    detail: ['t' => 'agent_cash_flows', 'id' => $acfId, 'kept' => $target],
                );

                // 只有「survivor 碼本來就在」的 skip 才進白名單：那種情況已由
                // findAvailableBanksConflicts 比對過三方碼等價，是刻意留置。
                if ($targetCodePreexisted) {
                    $this->skippedAbEntries[] = $this->skippedEntryKey($acfId, $target);
                }

                continue;
            }

            $info['available_banks'][$index]['BankCode_ELG'] = $targetCode;
            $existingCodes[] = $targetCode;
            $changed = true;

            $this->audit(
                loserBankId: $loserBankId,
                action: 'rename_align_ab',
                detail: [
                    't' => 'agent_cash_flows',
                    'id' => $acfId,
                    'rowwise' => true,
                    // entry 級異動：以 available_banks 內的碼定位，非整列覆寫。
                    'was' => $target,
                    'aft' => $targetCode,
                ],
            );
        }

        if ($changed) {
            DB::table('agent_cash_flows')->where('id', $acfId)->update([
                'info' => json_encode($info, JSON_UNESCAPED_UNICODE),
                'updated_at' => $this->stamp,
            ]);
        }
    }

    /**
     * @param  list<int>  $loserIds
     * @param  list<object>  $repointable
     */
    private function displayPlan(array $loserIds, array $repointable): void
    {
        $this->info(sprintf(
            '收斂計畫：survivor %d (%s) ← 變體 %s',
            $this->survivor->id,
            $this->survivor->code,
            implode(', ', $loserIds),
        ));

        // 全數留置（或本來就沒有 cfb）時仍會停用變體列並標刪，只印「無需改指」容易被讀成
        // 「沒事」，而這正是配對選錯最常見的徵狀——收斂實質為空，卻少了一行銀行。
        if ($repointable === []) {
            $this->warn('本次零改指：無 cash_flow_banks 會被收斂，但變體列仍會被停用並標刪——請確認 --from/--to 配對正確。');

            return;
        }

        $this->table(
            ['cfb id', 'cash_flow_id', 'currency_id', 'bank_code（現行 → 收斂後）'],
            array_map(
                fn (object $cfb): array => [
                    $cfb->id,
                    $cfb->cash_flow_id,
                    $cfb->currency_id,
                    $cfb->bank_code . ' → ' . $this->survivor->code,
                ],
                $repointable,
            ),
        );
    }

    /**
     * 揭露留置清單。只列改指會讓操作者誤以為該變體的 cfb 已全數收斂，
     * 而這些正是最需要人工判讀的列。
     *
     * @param  list<array{row: object, reason: string}>  $retained
     */
    private function displayRetained(array $retained): void
    {
        if ($retained === []) {
            return;
        }

        $reasons = [
            'collision' => '撞唯一鍵（survivor 該渠道已有 active 列）',
            'foreign_code' => '不在本次收斂碼族內（多半正在服務其他 agent group）',
        ];

        $this->warn('以下 cash_flow_banks 一律留置不動、轉人工判斷：');

        $this->table(
            ['留置的 cfb id', 'cash_flow_id', 'currency_id', '該列 bank_code', '原因'],
            array_map(
                static fn (array $item): array => [
                    $item['row']->id,
                    $item['row']->cash_flow_id,
                    $item['row']->currency_id,
                    $item['row']->bank_code,
                    $reasons[$item['reason']],
                ],
                $retained,
            ),
        );
    }

    /**
     * 揭露 --accept-provider-code 的對帳結果：逐筆列出已放行的阻斷，再列出列了卻沒對上任何
     * 阻斷的項目。後者多半是打錯字，而打錯字的那筆阻斷仍在，命令會照舊零寫入，
     * 看到這份清單才知道該改哪個值。
     *
     * @param  list<array{kind: string, id: int, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>  $accepted
     */
    private function displayAccepted(array $accepted): void
    {
        if ($accepted !== []) {
            $this->warn('以下阻斷已由 --accept-provider-code 放行：不改寫、原樣留置，收斂後該渠道改送 survivor 三方碼：');

            $this->table(
                ['層', 'cfb id／agent_cash_flow id', 'cash_flow_id', '變體碼', '變體三方碼', 'survivor 三方碼'],
                array_map(
                    static fn (array $item): array => [
                        $item['kind'] === 'cfb' ? 'cash_flow_banks' : 'available_banks',
                        $item['id'],
                        $item['cash_flow_id'],
                        $item['code'],
                        $item['loser_third'],
                        $item['survivor_third'],
                    ],
                    $accepted,
                ),
            );
        }

        $used = [];

        foreach ($accepted as $item) {
            $used[$this->acceptanceKey($item['cash_flow_id'], $item['loser_third'], $item['survivor_third'])] = true;
        }

        $unused = array_diff_key($this->acceptedProviderCodes, $used);

        if ($unused === []) {
            return;
        }

        $this->warn('以下 --accept-provider-code 沒有對上任何阻斷（請核對是否打錯字）：');

        foreach ($unused as $item) {
            $this->line(sprintf('  - %d:%s:%s', $item['cash_flow_id'], $item['loser_third'], $item['survivor_third']));
        }
    }

    /**
     * @param  array{
     *     dead_slot: list<object>,
     *     divergent: list<array{row: object, occupant: object, field: string}>,
     *     ab_divergent: list<array{acf_id: int, cash_flow_id: int, code: string, loser_third: string, survivor_third: string}>,
     * }  $plan
     */
    private function reportUnsafe(array $plan): void
    {
        $deadSlot = $plan['dead_slot'];
        $divergent = $plan['divergent'];

        $this->error('撞鍵情境不安全，未寫入任何資料：');

        if ($deadSlot !== []) {
            $this->warn('以下渠道的鍵位被 survivor 的「已停用」列佔著，收斂後代付查表（要求 status=1）會落空：');

            $this->table(
                ['變體 cfb id', 'cash_flow_id', 'currency_id', '該列 bank_code'],
                array_map(
                    static fn (object $cfb): array => [
                        $cfb->id, $cfb->cash_flow_id, $cfb->currency_id, $cfb->bank_code,
                    ],
                    $deadSlot,
                ),
            );
        }

        if ($divergent !== []) {
            $this->warn('以下撞鍵列與佔位列的送單設定不等價，收斂會靜默改變代付參數：');

            $this->table(
                ['變體 cfb id', '佔位 cfb id', 'cash_flow_id', '不一致欄位', '變體值', '佔位值'],
                array_map(
                    static fn (array $item): array => [
                        $item['row']->id,
                        $item['occupant']->id,
                        $item['row']->cash_flow_id,
                        $item['field'],
                        (string) $item['row']->{$item['field']},
                        (string) $item['occupant']->{$item['field']},
                    ],
                    $divergent,
                ),
            );
        }

        if ($plan['ab_divergent'] !== []) {
            $this->warn('以下 available_banks 的兩碼並存且三方碼不同，收斂後舊路徑送單會改用 survivor 的三方碼：');

            $this->table(
                ['agent_cash_flow id', '變體碼', '變體三方碼', 'survivor 三方碼'],
                array_map(
                    static fn (array $item): array => [
                        $item['acf_id'],
                        $item['code'],
                        $item['loser_third'],
                        $item['survivor_third'],
                    ],
                    $plan['ab_divergent'],
                ),
            );
        }

        $this->line('請先人工確認該渠道要保留哪一列的設定，或把 survivor 那列補成啟用／設定一致，再重跑。');
    }

    /**
     * 寫入 bank_dedup_logs（與 dedup 家族共用同一本帳，action 以 rename_ 前綴隔離）。
     *
     * detail 存 JSON 而非人話：這支命令不可逆，審計必須能被程式讀回來重放。
     * 用短鍵，且 was／aft 兩側都記——survivor 的 code／name 事後可能再變更，
     * 光憑「現值」反推不出當時實際寫入什麼，那是無法機械驗證的 manifest。
     *
     *   run  本次執行代號   t    被異動的表
     *   id   被異動的列 PK   was  異動前的值（還原就是把它寫回去）
     *   aft  異動後的值（反演前據此驗證現值未被他人改過）
     *   n    批次筆數（僅超量降級的彙總列會有，標記為不可逐列還原）
     *
     * @param  array<string, mixed>  $detail
     */
    private function audit(
        int $loserBankId,
        string $action,
        array $detail,
        ?int $memberId = null,
        ?int $bindingId = null,
    ): void {
        // 彙總模式已由操作者明示放行，不再受逐列上限攔阻：該模式下周邊表改記筆數，
        // 但會員綁定的停用仍逐列記（客訴要查得到是哪一筆被藏起來），量級遠小於出款單。
        if (($detail['rowwise'] ?? false) === true && !$this->summaryAuditMode) {
            $this->rowwiseAuditCount++;

            if ($this->rowwiseAuditCount > self::ROWWISE_AUDIT_LIMIT) {
                throw new BankConvergenceAbortedException(sprintf(
                    '逐列審計筆數超過上限 %d：本次收斂的引用列過多，無法留下可回滾的 manifest。請先評估分批策略。',
                    self::ROWWISE_AUDIT_LIMIT
                ));
            }
        }

        $encoded = json_encode(
            ['run' => $this->runId, ...$detail],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // 截斷的 JSON 比人話更糟——解不開又救不回。寧可整筆失敗讓人查。
        if (mb_strlen($encoded) > self::DETAIL_MAX_LENGTH) {
            throw new BankConvergenceAbortedException(sprintf('審計 detail 超出 %d 字元：%s', self::DETAIL_MAX_LENGTH, $encoded));
        }

        DB::table('bank_dedup_logs')->insert([
            'connection' => $this->connection,
            'agent_group_id' => (int) $this->survivor->agent_group_id,
            'code' => (string) $this->survivor->code,
            'survivor_bank_id' => (int) $this->survivor->id,
            'loser_bank_id' => $loserBankId,
            'member_id' => $memberId,
            'member_bank_account_id' => $bindingId,
            'action' => $action,
            'detail' => $encoded,
            'created_at' => $this->stamp,
        ]);

        $this->auditCounts[$action] = ($this->auditCounts[$action] ?? 0) + 1;
    }

    /**
     * 收斂前的結構防呆。
     *
     * (agent_group_id, type, currency_id) 必須一致才算「同一行的不同碼」。
     * 跨 group 收斂會踩到「碼空間逐 group 獨立」而誤改其他白標，是明確禁止的方向。
     *
     * @param  list<int>  $loserIds
     * @return list<string>
     */
    private function validate(?object $survivor, array $loserIds, int $agentGroupId): array
    {
        if ($survivor === null) {
            return ['survivor 銀行不存在'];
        }

        $errors = [];

        if ((int) $survivor->status !== 1) {
            $errors[] = sprintf('survivor %d 非啟用中（status=%d），不可作為收斂目標', $survivor->id, $survivor->status);
        }

        // AgentBankSyncService、AvailableBanksAlignService、BackfillStandardBanksCommand
        // 都帶 whereNull('deleted_at')，已標刪的列在這些同步鏈上
        // 等同不存在。把全部引用收斂過去等於收斂到一個看不見的目標。
        if ($survivor->deleted_at !== null) {
            $errors[] = sprintf('survivor %d 已標記 deleted_at，多條同步路徑視其為不存在，不可作為收斂目標', $survivor->id);
        }

        if ((int) $survivor->agent_group_id !== $agentGroupId) {
            $errors[] = sprintf(
                'survivor %d 屬 agent_group %d，與指定的 %d 不符',
                $survivor->id,
                $survivor->agent_group_id,
                $agentGroupId,
            );
        }

        if ($loserIds === []) {
            $errors[] = '未指定任何變體（--from）';
        }

        foreach ($loserIds as $loserId) {
            $errors = [...$errors, ...$this->validateVariant($survivor, $loserId, $agentGroupId)];
        }

        return [...$errors, ...$this->validateCodeSpace($survivor, $loserIds, $agentGroupId)];
    }

    /**
     * 碼層面的防呆：變體之間不得同碼，且碼在本 group 同幣別下不得跨 type 歧義。
     *
     * @param  list<int>  $loserIds
     * @return list<string>
     */
    private function validateCodeSpace(object $survivor, array $loserIds, int $agentGroupId): array
    {
        $codes = DB::table('banks')->whereIn('id', $loserIds)->pluck('code')->all();
        $errors = [];

        // available_banks 改寫靠碼反查來源變體，同碼會讓審計固定掛在第一個 id 上。
        // 而且同碼重複本來就是 bank:dedup-active 的範疇，不是本命令的同行不同碼。
        if (count($codes) !== count(array_unique($codes))) {
            $errors[] = '變體之間有重複的 code；同碼重複屬 bank:dedup-active 範疇，本命令只處理同行不同碼';
        }

        // banks 唯一鍵含 type，但 cash_flow_banks 沒有 type 欄位——同一個碼在本 group
        // 同幣別下若還有別的 type，就無從判定某個 cfb 列代表哪個 type，碼族閘會誤判。
        // AvailableBanksAlignService 遇同碼跨 type 同樣判為 conflict。
        // 已標刪（deleted_at 非空）且身上無 active cfb 的列不構成歧義：dedup／rename
        // 清理終態不再服務任何 cfb。標刪但仍掛 active cfb 者歧義仍在，照擋；
        // 停用未標刪列亦照擋（防後台人工翻活場景）。
        $ambiguous = DB::table('banks')
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $survivor->currency_id)
            ->whereIn('code', [$survivor->code, ...$codes])
            ->where('type', '!=', $survivor->type)
            ->where(function ($query): void {
                $query->whereNull('deleted_at')
                    ->orWhereExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('cash_flow_banks')
                            ->whereColumn('cash_flow_banks.bank_id', 'banks.id')
                            ->where('cash_flow_banks.status', 1);
                    });
            })
            ->pluck('code')
            ->unique()
            ->all();

        if ($ambiguous !== []) {
            $errors[] = sprintf(
                '碼 %s 在本 group 同幣別下另有不同 type 的銀行；cash_flow_banks 無 type 欄位可辨別歸屬，須人工處理',
                implode(', ', $ambiguous),
            );
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateVariant(object $survivor, int $loserId, int $agentGroupId): array
    {
        if ($loserId === (int) $survivor->id) {
            return [sprintf('survivor %d 不可同時列為變體', $loserId)];
        }

        $loser = DB::table('banks')->where('id', $loserId)->first();

        if ($loser === null) {
            return [sprintf('變體 %d 不存在', $loserId)];
        }

        $errors = [];

        if ((int) $loser->agent_group_id !== $agentGroupId) {
            $errors[] = sprintf(
                '變體 %d 屬 agent_group %d，與指定的 %d 不符（禁止跨 group 收斂）',
                $loserId,
                $loser->agent_group_id,
                $agentGroupId,
            );
        }

        if ((int) $loser->currency_id !== (int) $survivor->currency_id) {
            $errors[] = sprintf(
                '變體 %d 幣別 %d 與 survivor 的 %d 不符',
                $loserId,
                $loser->currency_id,
                $survivor->currency_id,
            );
        }

        if ((int) $loser->type !== (int) $survivor->type) {
            $errors[] = sprintf('變體 %d type %d 與 survivor 的 %d 不符', $loserId, $loser->type, $survivor->type);
        }

        return $errors;
    }

    /**
     * 解析 --from；含非數字項目時回 null 讓呼叫端 fail-closed。
     *
     * 不能直接 (int) 轉：'abc' 會靜默變成 0 再被當 falsy 丟掉，結果是少收斂一個變體
     * 卻回報成功。順帶保序去重，避免同一變體重複列出時停用與審計各跑兩次。
     *
     * @return list<int>|null
     */
    private function parseLoserIds(): ?array
    {
        $tokens = array_filter(
            array_map(trim(...), explode(',', (string) $this->option('from'))),
            static fn (string $token): bool => $token !== '',
        );

        foreach ($tokens as $token) {
            if (!ctype_digit($token)) {
                return null;
            }
        }

        return array_values(array_unique(array_map(intval(...), $tokens)));
    }

    /**
     * 解析 --accept-provider-code；任一項格式不合即回 null 讓呼叫端 fail-closed。
     *
     * 三方碼不 trim、不轉大小寫：比對是逐字相同，前後空白也算碼的一部分，悄悄修正
     * 等於放行一筆操作者沒列的值。三方碼含冒號時無法切分，一律拒收而非猜測。
     *
     * @return array<string, array{cash_flow_id: int, loser_third: string, survivor_third: string}>|null
     */
    private function parseAcceptedProviderCodes(): ?array
    {
        $parsed = [];

        foreach ((array) $this->option('accept-provider-code') as $raw) {
            $parts = explode(':', (string) $raw);

            if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] === '' || $parts[2] === '') {
                return null;
            }

            $item = [
                'cash_flow_id' => (int) $parts[0],
                'loser_third' => $parts[1],
                'survivor_third' => $parts[2],
            ];

            $parsed[$this->acceptanceKey($item['cash_flow_id'], $item['loser_third'], $item['survivor_third'])] = $item;
        }

        return $parsed;
    }
}
