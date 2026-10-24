<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 把 `cash_flow_banks` 某一列的 `provider_bank_code` 改成正確的三方碼。
 *
 * 【分類】Bank — ⚠️ 非 sync-safe 存量修正工具，過渡期（退役條件見下方）。
 *
 * ## 為什麼既有命令都碰不到這個欄位
 *
 * 寫這個欄位的只有兩處，兩處都修不了既有的錯值：
 *
 * - `cash-flow:sync`：全量重建（先 `disableAllCashFlowBanks` 再依銀行**名稱模糊匹配**寫回）。
 *   它不是修復工具，而正是錯配的來源——重跑只會把同一個錯再寫一次。
 * - `cash-flow:backfill-tpower-payout-bankcodes`：硬編碼 TPower 的四組對映，且**只補缺漏、
 *   不覆寫既有值**（冪等設計），對「已經有值但值是錯的」完全無效。
 *
 * ## 錯配長什麼樣
 *
 * 實例（Alfawin77 TopPay／cf94）：該通道的碼表同時有 `028`＝OCBC Bank 與 `0280`＝OCBC Bank UUS
 * （UUS＝Unit Usaha Syariah，伊斯蘭事業單位）。sync 的名稱模糊匹配把 `0280` 配到了我方的
 * 「Bank OCBC NISP」列上，於是該行的送款指向母行的伊斯蘭事業單位而非母行本身。
 * 同批還有 Bank Kaltim `1240`、Bank DKI `778`、Bank NTB `1280` 三組同型。
 *
 * 這類錯不會被任何既有稽核抓到：`bank:verify-payout-bank-parity` 比的是「兩張表有沒有對應列」，
 * 對映**存在**且格式正確，只是指向錯的收款目標。
 *
 * ## 定位鍵是「cfb id ＋ 現行三方碼」兩者並用
 *
 * 只憑 id 無法防止重跑誤傷：這是不可逆寫入，而預檢到執行之間、以及兩次執行之間，該列都可能
 * 已被 sync 或他人改過。要求呼叫端明寫現值，改的才確定是當初看到的那一列那個值。
 *
 * ## 目標碼撞號預設擋下
 *
 * 同一個 `(cash_flow_id, currency_id)` 底下若已有**服務中**的列在用目標碼，代表改完會有兩列
 * 指向同一個三方收款目標。這在「兩列本來就是同一家銀行、正要收斂掉其中一列」時是過渡期的
 * 正常狀態，但在其他情況下是新的錯配，所以預設擋下、要 `--allow-duplicate` 明示放行。
 * 停用的列不算佔用——代付查表要求 `status=1`，取不到它。
 *
 * ## 一律寫人工覆寫標記
 *
 * 錯配的來源正是 `cash-flow:sync` 的名稱比對：不留標記的話，下次 sync 重建比對到這一列時，
 * 會把三方碼改回設定檔的值。所以寫入時一併填 `manual_override_at` 與 `manual_override_note`
 * （內容為命令名加 `--reason`，上限 255 字）；該列原本已有標記時一樣改寫，舊值記進審計。
 * 有標記的列 sync 不會改它的 `provider_bank_code`（見 `SyncCashFlowsCommand::createCashFlowBankRelation()`）。
 * 代價是這一列之後不再跟著設定檔更新；sync 遇到設定檔的值與現值不同時會印出提示，不是靜默略過。
 *
 * ⚠️ 標記只保護三方碼這一個欄位，不保護「列被停用」：sync 起手會把該金流商該幣別的對照列全部設為
 * `status=0`，重建時只重新啟用名稱比對得到的基底層銀行列。這一列若綁的是代理群組層的銀行列，
 * 或名稱比對不中，sync 後會停在停用（三方碼仍是修正後的值），要用 `bank:reactivate-cfb-rows` 開回來。
 *
 * ## 預設不寫入
 *
 * 與 bank 家族其他命令一致：不帶 `--execute` 只輸出預檢報表。無 rollback 命令，
 * 還原靠 `bank_dedup_logs`（`action=fix_cfb_provider_code`）的 detail 手工回寫。
 *
 * ⚠️ 這是為存量修正寫的工具，用完應依過渡期代碼清單的紀律退役。真正的根治是讓
 * `cash-flow:sync` 不再靠 name matching 重建（改以 `standard_bank_id` 或外鍵）。
 *
 * ## 退役條件
 *
 * 本檔上方已寫明「用完應依過渡期代碼清單的紀律退役」，具體條件＝`cash-flow:sync` 不再以
 * 銀行名稱模糊比對重建 `provider_bank_code`（改以 `standard_bank_id`／外鍵）。
 * 在那之前它不是 sync-safe：人工覆寫標記讓 sync 不再改回三方碼，但列仍可能在 sync 後停在停用
 * （見上方「一律寫人工覆寫標記」）。對該金流商跑過 sync 之後要確認這一列仍啟用，
 * 見 `docs/未完成計劃/金流商架構重構/銀行收斂/sync全量重建面與補償命令.md`。
 */
class FixCfbProviderCodeCommand extends Command
{
    protected $signature = 'bank:fix-cfb-provider-code
                            {--cfb= : 要修正的 cash_flow_banks.id}
                            {--from= : 該列現行的 provider_bank_code（定位用，必填）}
                            {--to= : 要改成的 provider_bank_code}
                            {--reason= : 修正理由，寫入審計供日後追溯（必填）}
                            {--allow-duplicate : 目標碼已被同渠道其他服務中的列使用時仍執行}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '修正 cash_flow_banks 配錯的三方銀行碼（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** `cash_flow_banks.manual_override_note` 的欄位長度。 */
    private const NOTE_MAX_LENGTH = 255;

    public function handle(): int
    {
        $cfbId = trim((string) $this->option('cfb'));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $reason = trim((string) $this->option('reason'));

        $invalid = $this->validateOptions($cfbId, $from, $to, $reason);

        if ($invalid !== null) {
            $this->error($invalid);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        $blocker = $this->inspect((int) $cfbId, $from, $to);

        if ($blocker !== null) {
            $this->newLine();
            $this->error('預檢未通過，未寫入任何資料：');
            $this->error('  - ' . $blocker);

            return self::FAILURE;
        }

        if (!(bool) $this->option('execute')) {
            $this->info('dry-run：未寫入任何資料，加 --execute 才會實際執行。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $this->apply((int) $cfbId, $from, $to, $reason);

        $this->info('修正完成：1 列（審計見 bank_dedup_logs，action=fix_cfb_provider_code；已寫人工覆寫標記）。');

        return self::SUCCESS;
    }

    /**
     * 參數層的靜態檢查——不碰資料庫，純粹擋下自相矛盾的呼叫。
     */
    private function validateOptions(string $cfbId, string $from, string $to, string $reason): ?string
    {
        if (preg_match('/^\d+$/', $cfbId) !== 1) {
            return '--cfb 必須是純數字的 cash_flow_banks.id。';
        }

        if ($from === '' || $to === '') {
            return '--from 與 --to 皆為必填：現行碼是定位鍵的一半，少了它無法確認改的是哪一列的哪個值。';
        }

        if ($from === $to) {
            return '--from 與 --to 相同，沒有可執行的變更。';
        }

        if ($reason === '') {
            return '--reason 必填：修正理由會寫入審計，供日後追溯與還原。';
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入不可逆——寧可先擋下。';
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH);
        }

        return null;
    }

    /**
     * 預檢並印出計畫；回傳阻擋原因，`null` 表示可執行。
     *
     * `$lock=true` 時以 `lockForUpdate()` 讀取，供交易內重驗使用：預檢到人工確認之間是
     * 分鐘級的窗口，期間 sync 或他人都可能改動這一列。
     */
    private function inspect(int $cfbId, string $from, string $to, bool $lock = false): ?string
    {
        $query = DB::table('cash_flow_banks')->where('id', $cfbId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first([
            'id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code', 'provider_bank_code', 'status',
            'manual_override_at', 'manual_override_note',
        ]);

        if ($row === null) {
            return sprintf('cash_flow_banks 沒有 id=%d 的列。', $cfbId);
        }

        if ((string) $row->provider_bank_code !== $from) {
            return sprintf(
                'cfb %d 的現行三方碼是「%s」而非 --from 指定的「%s」：可能指錯列，或這列已被改過。',
                $cfbId,
                (string) $row->provider_bank_code,
                $from
            );
        }

        $duplicate = DB::table('cash_flow_banks')
            ->where('cash_flow_id', $row->cash_flow_id)
            ->where('currency_id', $row->currency_id)
            ->where('provider_bank_code', $to)
            ->where('status', 1)
            ->where('id', '!=', $cfbId)
            ->first(['id', 'bank_code']);

        if ($duplicate !== null && !(bool) $this->option('allow-duplicate')) {
            return sprintf(
                '同渠道已有服務中的 cfb %d（銀行碼 %s）在用「%s」，改下去會有兩列指向同一個三方目標；'
                . '確認這是收斂過渡期的預期狀態才加 --allow-duplicate。',
                (int) $duplicate->id,
                (string) $duplicate->bank_code,
                $to
            );
        }

        if (!$lock) {
            $this->line(sprintf(
                '  [修正] cfb=%d  金流商 %d／幣別 %d  銀行碼 %s  三方碼 %s → %s%s',
                $cfbId,
                (int) $row->cash_flow_id,
                (int) $row->currency_id,
                (string) $row->bank_code,
                $from,
                $to,
                $duplicate !== null ? sprintf('（已明示放行與 cfb %d 同碼）', (int) $duplicate->id) : ''
            ));

            $this->line($row->manual_override_at === null
                ? '         人工覆寫標記：會寫入（之後 cash-flow:sync 不再改這一列的三方碼）'
                : sprintf('         人工覆寫標記：已有（原因：%s），會改寫成本次理由', (string) ($row->manual_override_note ?? '未註明')));
        }

        return null;
    }

    /**
     * 在交易內重驗後改值、寫人工覆寫標記、寫審計。
     */
    private function apply(int $cfbId, string $from, string $to, string $reason): void
    {
        DB::transaction(function () use ($cfbId, $from, $to, $reason): void {
            $blocker = $this->inspect($cfbId, $from, $to, lock: true);

            if ($blocker !== null) {
                throw new BankConvergenceAbortedException('鎖內重驗未通過，整批回滾：' . $blocker);
            }

            $row = DB::table('cash_flow_banks')->where('id', $cfbId)
                ->first(['bank_id', 'bank_code', 'cash_flow_id', 'currency_id', 'manual_override_at', 'manual_override_note']);

            // cash_flow_banks 沒有 agent_group_id，審計的群組欄位只能由它指向的 banks 列取得
            $agentGroupId = (int) (DB::table('banks')->where('id', $row->bank_id)->value('agent_group_id') ?? 0);

            $stamp = Carbon::now();
            $note = mb_substr(sprintf('bank:fix-cfb-provider-code：%s', $reason), 0, self::NOTE_MAX_LENGTH);

            DB::table('cash_flow_banks')->where('id', $cfbId)->update([
                'provider_bank_code' => $to,
                'manual_override_at' => $stamp,
                'manual_override_note' => $note,
                'updated_at' => $stamp,
            ]);

            DB::table('bank_dedup_logs')->insert([
                'connection' => (string) config('database.default'),
                'agent_group_id' => $agentGroupId,
                // code 欄記我方銀行碼：反查「這個碼的送單參數被改過什麼」時以它為索引
                'code' => (string) $row->bank_code,
                // 單列改值沒有 survivor／loser 之分：自我參照表示「本列即標的」
                'survivor_bank_id' => (int) $row->bank_id,
                'loser_bank_id' => (int) $row->bank_id,
                'action' => 'fix_cfb_provider_code',
                'detail' => $this->encodeAuditDetail([
                    't' => 'cash_flow_banks',
                    'id' => $cfbId,
                    'cf' => (int) $row->cash_flow_id,
                    'cur' => (int) $row->currency_id,
                    // 標記的舊值一併記下：該列原本就有標記時會被改寫，還原要靠這裡
                    'was' => [
                        'provider_bank_code' => $from,
                        'manual_override_at' => $row->manual_override_at,
                        'manual_override_note' => $row->manual_override_note,
                    ],
                    'aft' => [
                        'provider_bank_code' => $to,
                        'manual_override_at' => $stamp->toDateTimeString(),
                        'manual_override_note' => $note,
                    ],
                    'reason' => $reason,
                ]),
                'created_at' => $stamp,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function encodeAuditDetail(array $detail): string
    {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (mb_strlen($encoded) > self::DETAIL_MAX_LENGTH) {
            throw new BankConvergenceAbortedException(
                sprintf('審計 detail 超出 %d 字元，中止：%s', self::DETAIL_MAX_LENGTH, $encoded)
            );
        }

        return $encoded;
    }

    /**
     * 印出本次的寫入目標連線與資料庫名。
     *
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 cfb id 區間會重疊，
     * 光看命令列分不出改的是哪一站——所以每次執行都先把目標印出來。
     */
    private function discloseWriteTarget(): void
    {
        $this->line(sprintf('寫入目標 connection：%s', (string) config('database.default')));
        $this->line(sprintf('寫入目標 database：%s', (string) DB::connection()->getDatabaseName()));
    }

    private function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');

        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->newLine();
        $this->warn(sprintf('⚠️  當前資料庫連線: %s', (string) $dbConnection));
        $this->newLine();

        return $this->confirm('確定要在此環境執行？', false);
    }
}
