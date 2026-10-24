<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Exceptions\BankConvergenceAbortedException;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 把停用的 `cash_flow_banks`（金流商的銀行碼對照資料表，以下簡稱 cfb）列改回啟用，可選配改三方碼。
 *
 * 【分類】Bank — 過渡期工具，退役條件見下方。
 *
 * ## 這支補的洞
 *
 * 代付要送給三方的銀行碼有兩條路徑：舊的讀代付通道（`agent_cash_flows`）的 `available_banks`
 * JSON，新的查 cfb 表，由 `PAYOUT_BANK_LOOKUP_PROVIDERS` 逐家灰度切換。cfb 查表只取
 * `status=1` 的列。所以一列被停用、而通道清單那邊還有這家銀行時，舊路徑照常出款、
 * 新路徑一開閘就查無碼而擋單。
 *
 * 實例：TPower FPay 的 BCA（銀行碼 `TPID03`）。`cash-flow:sync` 先把該金流商該幣別的對照列
 * 整批停用、再依銀行名稱重建，重建配到基底層另一列「Bank BCA」，原本正確的這一列就一直停用，
 * 三方碼還停在換碼前的舊值；之後改名收斂只處理啟用中的列，它又被漏掉。要救它得做兩件事：
 * 三方碼改成現行值，然後把列改回啟用。既有命令只會新增列（`bank:add-cfb-rows`）或停用列
 * （`bank:deactivate-cfb-rows`），沒有一支往「重新啟用」的方向走。
 *
 * ⚠️ 本檔提到的 `bank:add-cfb-rows`、`bank:deactivate-cfb-rows`、`bank:fix-cfb-provider-code`
 * 都在 `refactor/payment-bank-convergence` 分支（銀行收斂長期分支），尚未進 master；本命令
 * 刻意不依賴它們，只沿用同樣的結構與關卡命名，好讓四支放在一起讀得通。
 *
 * ## 為什麼不直接下 SQL
 *
 * 一句 UPDATE 就能改回來，但會少掉三樣：審計（`bank_dedup_logs` 記改前改後與理由，還原靠它）、
 * 預檢（下方五道關卡）、人工覆寫標記（沒有標記，下次 sync 會把三方碼沖回設定檔的值）。
 * 這三樣都是「改完當下看不出少了什麼、幾個月後才知道」的東西。
 *
 * ## 五道預檢，整批中只要有一列不合格即零寫入
 *
 * 1. **綁的 `banks` 列必須在服務中**（`status=1` 且未軟刪除）——與 `bank:deactivate-cfb-rows`
 *    的第一道關卡互為鏡像：上游死了還把下游開回來，會員選不到銀行，這列沒有意義。
 * 2. **同 `(cash_flow_id, currency_id, bank_code)` 不得已有啟用中的列**。runtime 同鍵多列時
 *    取 id 最早的那列，把舊列開回來可能反過來遮住現役列，所以直接擋、不任選。比對逐字相同
 *    （`BINARY`），欄位 collation 大小寫不敏感，不加會把別的碼算進來。
 * 3. **改三方碼時 `--from-third` 必須等於現值**——定位鍵是「id ＋ 現行三方碼」兩者並用，與
 *    `bank:fix-cfb-provider-code` 同一個理由：預檢到執行之間該列可能已被 sync 或他人改過，
 *    要求呼叫端明寫現值，改的才確定是當初看到的那一列那個值。
 * 4. **重新啟用後的三方碼不得與同渠道其他啟用列共用**，除非 `--allow-alias` 明示放行。
 *    同一家銀行有兩個銀行碼（基底層一個、代理群組層一個）時共用三方碼是正常的，
 *    但其他情況是新的錯配。
 * 5. **與通道清單不得矛盾**：同金流商同幣別、啟用中的代付通道，清單裡若有這個銀行碼，
 *    它的三方碼必須等於重新啟用後的三方碼，否則兩條路徑會送不同的碼。清單裡沒有這個碼
 *    只警告不擋——那代表沒有消費者，重新啟用無害，也可能是接著才要補清單。
 *
 * ## 一律寫人工覆寫標記
 *
 * 人工重新啟用本身就是人為決定，寫入時一併填 `manual_override_at` 與 `manual_override_note`
 * （內容取 `--reason`，上限 255 字）。有標記的列 `cash-flow:sync` 不會改它的 `provider_bank_code`
 * （見 `SyncCashFlowsCommand::createCashFlowBankRelation()`）。⚠️ 標記只保護三方碼這一個欄位，
 * 不保護「列被停用」：sync 起手仍會把該金流商該幣別的關聯全設為 `status=0` 再重建，本命令
 * 開回來的列若綁的是代理群組層的銀行列，重建不會再認得它。所以本命令**不是 sync-safe**，
 * 對該金流商跑過 sync 之後要重跑一次；遇到已啟用的列會跳過，重跑是冪等的。
 *
 * ## 預設不寫入
 *
 * 與 bank 家族其他命令一致：不帶 `--execute` 只輸出預檢報表。無 rollback 命令，
 * 還原靠 `bank_dedup_logs`（`action=reactivate_cfb`）的 detail 手工回寫。
 *
 * ## 退役條件
 *
 * `cash-flow:sync` 不再以銀行名稱模糊比對重建對照列（改以 `standard_bank_id`／外鍵）之後，
 * 不會再有「重建配錯、正確列被停用」的情況，本命令可與 `bank:deactivate-cfb-rows` 一併刪除。
 *
 * @knowledge rules/payout-bank-code-mapping
 */
class ReactivateCfbRowsCommand extends Command
{
    protected $signature = 'bank:reactivate-cfb-rows
                            {--cfb=* : 要重新啟用的 cash_flow_banks.id（可指定多個）}
                            {--from-third= : 該列現行的三方碼（要改三方碼時必填，防改錯列）}
                            {--to-third= : 重新啟用後的三方碼（不給就維持現值）}
                            {--allow-alias : 明示放行「與同渠道其他啟用列共用同一個三方碼」}
                            {--reason= : 重新啟用的理由與依據，寫入審計與人工覆寫標記（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '把停用的 cash_flow_banks 列改回啟用，可選配改三方碼（預設只預演）';

    public const AUDIT_ACTION = 'reactivate_cfb';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /** `cash_flow_banks.manual_override_note` 的欄位長度。 */
    private const NOTE_MAX_LENGTH = 255;

    public function handle(): int
    {
        $rawIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('cfb'));
        $invalidIds = array_values(array_filter(
            $rawIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalidIds !== []) {
            $this->error(sprintf(
                '--cfb 必須是純數字的 cash_flow_banks.id，收到「%s」。多個請重複旗標（--cfb=360 --cfb=361），不可用逗號分隔。',
                implode('」「', $invalidIds)
            ));

            return self::FAILURE;
        }

        $cfbIds = array_values(array_unique(array_map('intval', $rawIds)));
        $reason = trim((string) $this->option('reason'));
        $fromThird = $this->optionalString('from-third');
        $toThird = $this->optionalString('to-third');

        $invalid = $this->validateOptions($cfbIds, $reason, $fromThird, $toThird);

        if ($invalid !== null) {
            $this->error($invalid);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'skipped' => $skipped, 'warnings' => $warnings, 'blockers' => $blockers]
            = $this->classifyRows($cfbIds, $fromThird, $toThird);

        foreach ($targets as $target) {
            $this->line(sprintf(
                '  [重新啟用] cfb=%d  金流商 %d／幣別 %d  銀行碼 %s  三方碼 %s',
                $target['id'],
                $target['cash_flow_id'],
                $target['currency_id'],
                $target['bank_code'],
                $target['third_was'] === $target['third_after']
                    ? $target['third_was'] . '（不動）'
                    : $target['third_was'] . ' → ' . $target['third_after']
            ));
        }

        foreach ($skipped as $line) {
            $this->line('  [跳過] ' . $line);
        }

        foreach ($warnings as $line) {
            $this->warn('  [注意] ' . $line);
        }

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要重新啟用的列。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info('dry-run：未寫入任何資料，加 --execute 才會實際執行。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($cfbIds, $fromThird, $toThird, $reason);

        $this->info(sprintf(
            '重新啟用完成：%d 列（審計見 bank_dedup_logs，action=%s；已寫人工覆寫標記）。',
            $written,
            self::AUDIT_ACTION
        ));

        return self::SUCCESS;
    }

    private function optionalString(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<int>  $cfbIds
     */
    private function validateOptions(array $cfbIds, string $reason, ?string $fromThird, ?string $toThird): ?string
    {
        if ($cfbIds === []) {
            return '--cfb 必填：至少要指定一列 cash_flow_banks.id。';
        }

        if ($reason === '') {
            return '--reason 必填：理由會寫入審計與人工覆寫標記，供日後追溯與還原。';
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '--reason 含無效的 UTF-8 位元組：審計無法編碼，而寫入影響代付查表——寧可先擋下。';
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH);
        }

        if (($toThird === null) !== ($fromThird === null)) {
            return '要改三方碼請同時給 --from-third（現值）與 --to-third（新值）；只給其中一個無法確認改的是哪一列的哪個值。';
        }

        if ($toThird !== null && $fromThird === $toThird) {
            return '--from-third 與 --to-third 相同，沒有東西要改；只重新啟用請把這兩個旗標拿掉。';
        }

        return null;
    }

    /**
     * 逐列分流成「可重新啟用／已啟用跳過／警告／阻擋」四類。
     *
     * $lock=true 時以 `lockForUpdate()` 讀取，供交易內重驗使用。
     *
     * @param  list<int>  $cfbIds
     * @return array{targets: list<array{id: int, cash_flow_id: int, currency_id: int, bank_id: int, bank_code: string, third_was: string, third_after: string, agent_group_id: int}>, skipped: list<string>, warnings: list<string>, blockers: list<string>}
     */
    private function classifyRows(array $cfbIds, ?string $fromThird, ?string $toThird, bool $lock = false): array
    {
        $targets = [];
        $skipped = [];
        $warnings = [];
        $blockers = [];

        foreach ($cfbIds as $cfbId) {
            $query = DB::table('cash_flow_banks')->where('id', $cfbId);

            if ($lock) {
                $query->lockForUpdate();
            }

            $row = $query->first(['id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code', 'provider_bank_code', 'status']);

            if ($row === null) {
                $blockers[] = sprintf('cash_flow_banks 沒有 id=%d 的列。', $cfbId);

                continue;
            }

            if ((int) $row->status === 1) {
                $skipped[] = sprintf('cfb=%d 已是啟用狀態，無事可做；本命令不改啟用中列的三方碼。', $cfbId);

                continue;
            }

            $thirdWas = (string) $row->provider_bank_code;

            if ($toThird !== null && $thirdWas !== $fromThird) {
                $blockers[] = sprintf(
                    'cfb=%d 的現行三方碼是「%s」而非 --from-third 指定的「%s」：可能指錯列，或這列已被改過。',
                    $cfbId,
                    $thirdWas,
                    (string) $fromThird
                );

                continue;
            }

            $thirdAfter = $toThird ?? $thirdWas;

            $blocker = $this->inspectRow($row, $thirdAfter);

            if ($blocker !== null) {
                $blockers[] = $blocker;

                continue;
            }

            $warning = $this->inspectAcfLists($row, $thirdAfter);

            if ($warning !== null) {
                if ($warning['block']) {
                    $blockers[] = $warning['message'];

                    continue;
                }

                $warnings[] = $warning['message'];
            }

            $ownBank = Bank::query()->where('id', $row->bank_id)->first(['id', 'agent_group_id']);

            $targets[] = [
                'id' => (int) $row->id,
                'cash_flow_id' => (int) $row->cash_flow_id,
                'currency_id' => (int) $row->currency_id,
                'bank_id' => (int) $row->bank_id,
                'bank_code' => (string) $row->bank_code,
                'third_was' => $thirdWas,
                'third_after' => $thirdAfter,
                'agent_group_id' => (int) ($ownBank->agent_group_id ?? 0),
            ];
        }

        return ['targets' => $targets, 'skipped' => $skipped, 'warnings' => $warnings, 'blockers' => $blockers];
    }

    /**
     * 第 1、2、4 道關卡；回傳阻擋原因，`null` 表示通過。
     */
    private function inspectRow(object $row, string $thirdAfter): ?string
    {
        $ownBankAlive = Bank::query()
            ->where('id', $row->bank_id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->exists();

        if (!$ownBankAlive) {
            return sprintf(
                'cfb=%d 綁的銀行列（banks id=%d）不在服務中（不存在、已停用或已軟刪除）：上游死了，把對照列開回來會員也選不到，先處理銀行列。',
                (int) $row->id,
                (int) $row->bank_id
            );
        }

        $activeSameCode = DB::table('cash_flow_banks')
            ->where('cash_flow_id', $row->cash_flow_id)
            ->where('currency_id', $row->currency_id)
            ->whereRaw('BINARY bank_code = ?', [(string) $row->bank_code])
            ->where('status', 1)
            ->where('id', '!=', $row->id)
            ->orderBy('id')
            ->first(['id', 'provider_bank_code']);

        if ($activeSameCode !== null) {
            return sprintf(
                'cfb=%d 的銀行碼「%s」在同金流商同幣別已有啟用中的列（cfb %d，三方碼 %s）：runtime 同碼多列只取 id 最早的，開回來可能反過來遮住現役列，不任選。',
                (int) $row->id,
                (string) $row->bank_code,
                (int) $activeSameCode->id,
                (string) $activeSameCode->provider_bank_code
            );
        }

        $aliasRow = DB::table('cash_flow_banks')
            ->where('cash_flow_id', $row->cash_flow_id)
            ->where('currency_id', $row->currency_id)
            ->where('provider_bank_code', $thirdAfter)
            ->where('status', 1)
            ->where('id', '!=', $row->id)
            ->orderBy('id')
            ->first(['id', 'bank_code']);

        if ($aliasRow !== null && !(bool) $this->option('allow-alias')) {
            return sprintf(
                'cfb=%d 重新啟用後的三方碼「%s」已被同渠道啟用中的 cfb %d（銀行碼 %s）使用；確認兩個銀行碼確實是同一家銀行才加 --allow-alias 放行。',
                (int) $row->id,
                $thirdAfter,
                (int) $aliasRow->id,
                (string) $aliasRow->bank_code
            );
        }

        return null;
    }

    /**
     * 第 5 道關卡：與同金流商同幣別、啟用中的代付通道清單比對。
     *
     * 清單有這個碼且三方碼不同 → 阻擋；清單都沒有這個碼 → 只警告。
     *
     * @return array{block: bool, message: string}|null
     */
    private function inspectAcfLists(object $row, string $thirdAfter): ?array
    {
        $acfs = AgentCashFlow::query()
            ->where('cash_flow_id', $row->cash_flow_id)
            ->where('currency_id', $row->currency_id)
            ->where('status', 1)
            ->get(['id', 'agent_group_id', 'info']);

        $seen = 0;

        foreach ($acfs as $acf) {
            $entries = $acf->info['available_banks'] ?? [];

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry) || (string) ($entry['BankCode_ELG'] ?? '') !== (string) $row->bank_code) {
                    continue;
                }

                $seen++;
                $listThird = (string) ($entry['BankCode_Third'] ?? '');

                if ($listThird !== $thirdAfter) {
                    return [
                        'block' => true,
                        'message' => sprintf(
                            'cfb=%d 重新啟用後的三方碼「%s」與通道清單不一致：代付通道 %d（代理群組 %d）的清單說銀行碼「%s」對應「%s」。兩條路徑會送不同的碼，先把其中一邊改對。',
                            (int) $row->id,
                            $thirdAfter,
                            (int) $acf->id,
                            (int) $acf->agent_group_id,
                            (string) $row->bank_code,
                            $listThird
                        ),
                    ];
                }
            }
        }

        if ($seen === 0) {
            return [
                'block' => false,
                'message' => sprintf(
                    'cfb=%d 的銀行碼「%s」沒有任何啟用中的通道清單含它：重新啟用後暫時沒有消費者，無害；若接著要補清單，記得三方碼要一致。',
                    (int) $row->id,
                    (string) $row->bank_code
                ),
            ];
        }

        return null;
    }

    /**
     * 在交易內重驗後重新啟用、寫標記、寫審計。
     *
     * @param  list<int>  $cfbIds
     */
    private function apply(array $cfbIds, ?string $fromThird, ?string $toThird, string $reason): int
    {
        $written = 0;

        DB::transaction(function () use ($cfbIds, $fromThird, $toThird, $reason, &$written): void {
            ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($cfbIds, $fromThird, $toThird, lock: true);

            if ($blockers !== []) {
                throw new BankConvergenceAbortedException('鎖內重驗未通過，整批回滾：' . implode('；', $blockers));
            }

            $connection = (string) config('database.default');
            $stamp = Carbon::now();
            $note = mb_substr(sprintf('bank:reactivate-cfb-rows：%s', $reason), 0, self::NOTE_MAX_LENGTH);

            foreach ($targets as $target) {
                DB::table('cash_flow_banks')->where('id', $target['id'])->update([
                    'status' => 1,
                    'provider_bank_code' => $target['third_after'],
                    'manual_override_at' => $stamp,
                    'manual_override_note' => $note,
                    'updated_at' => $stamp,
                ]);

                DB::table('bank_dedup_logs')->insert([
                    'connection' => $connection,
                    'agent_group_id' => $target['agent_group_id'],
                    'code' => $target['bank_code'],
                    // 單列重新啟用沒有 survivor／loser 之分：自我參照表示「本列即標的」
                    'survivor_bank_id' => $target['bank_id'],
                    'loser_bank_id' => $target['bank_id'],
                    'action' => self::AUDIT_ACTION,
                    'detail' => $this->encodeAuditDetail([
                        't' => 'cash_flow_banks',
                        'id' => $target['id'],
                        'cf' => $target['cash_flow_id'],
                        'cur' => $target['currency_id'],
                        'was' => ['status' => 0, 'third' => $target['third_was']],
                        'aft' => ['status' => 1, 'third' => $target['third_after']],
                        'marked' => true,
                        'reason' => $reason,
                    ]),
                    'created_at' => $stamp,
                ]);

                $written++;
            }
        });

        return $written;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function encodeAuditDetail(array $detail): string
    {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (mb_strlen($encoded) > self::DETAIL_MAX_LENGTH) {
            throw new BankConvergenceAbortedException(
                sprintf('審計 detail 超出 %d 字元，整批中止：%s', self::DETAIL_MAX_LENGTH, $encoded)
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
