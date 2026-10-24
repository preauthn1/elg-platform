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
 * 把 ACF `available_banks` 中某個配錯的項目改指到正確的碼——銀行碼或三方碼二選一。
 *
 * 【分類】Bank — ⚠️ 存量修正工具，過渡期（退役條件見下方）。
 *
 * ## 這支補的是對齊命令補不到的洞
 *
 * `cash-flow:align-available-banks` 的判定是「類型＋銀行名」name-hop：某個項目的
 * `BankCode_ELG` 只要對得上該群組一列服務中的銀行，它就算已對齊、不會被動。所以
 * 「這個項目本身指向一家真實存在的銀行，但它掛的三方碼其實屬於另一家銀行」這種
 * 錯誤，對齊命令完全看不到；它的 `--prune-duplicate-entries` 也只處理「三方碼相同」
 * 的死項目，三方碼不同時會判 conflict 擋下（那個 gate 是對的，不該為此放寬）。
 *
 * 實例（Alfawin77）：某代付通道的兩個 ACF 各有一個項目，銀行碼寫成 Bank Nagari、
 * 三方碼卻是 ShopeePay 的。後果不是送錯款——舊路徑 `convertPayoutBankCodeFromJson()`
 * 是 foreach 第一個匹配就 return，同碼重複時只有排在前面的那個會生效——而是
 * **ShopeePay 在該通道實質缺席**：它沒有自己的項目，會員綁了也轉不出碼。
 *
 * ## 定位鍵是「現有銀行碼 ＋ 三方碼」兩者並用
 *
 * 只憑銀行碼無法指定要改哪一個：同一個銀行碼重複出現正是這類錯誤的表徵（那也是
 * 發現它的線索）。兩者並用後若仍匹配到多個項目，一律拒絕而非猜一個，因為改錯的
 * 那個項目會讓另一家銀行也跟著缺席。
 *
 * ## 兩種修法互斥：改銀行碼（`--to`）還是改三方碼（`--to-third`）
 *
 * 定位到同一個項目後，錯的可能是任一邊，判準是「哪一邊才是原本的意圖」：
 *
 * - `--to`（改 `BankCode_ELG`）：三方碼指的銀行是對的，掛在它底下的我方銀行碼卻是
 *   另一家的。上述 Alfawin77 案即此形態。
 * - `--to-third`（改 `BankCode_Third`）：我方銀行碼是對的，三方碼抄成了另一家的。
 *   實例（TPower／Yes2Win，YeePay 通道）：Public Bank Vietnam Limited 送 `970412`
 *   ＝PVcomBank、Nam A 送 `970434`＝Indovina Bank。判準是「該銀行在該通道只有這一個
 *   項目、正確碼完全不存在，且各站另有獨立的 PVcomBank／Indovina 列」⇒ 意圖是原銀行、
 *   碼抄錯。
 *
 * 🔴 判定三方碼對錯只能比對**外部權威清單**（越南＝越南央行 BIN／NAPAS 碼），不可拿
 * 站內兩個三方碼互比——三方碼本身就會配錯，上面同一個 `970434` 在兩站掛了不同銀行
 * 即是實例。
 *
 * 兩種修法的審計 `action` 不同（`manual_entry_fix` / `manual_entry_third_fix`），因為
 * `available_banks_align_logs` 的 `old_code`／`new_code` 欄位註解寫的是 `BankCode_ELG`；
 * 沒有這個開關，日後還原會回錯欄位。
 *
 * ## 預設不寫入
 *
 * 與 bank 家族其他命令一致：不帶 `--execute` 只輸出預檢報表。改的是生產 ACF 的
 * JSON、且無 rollback 命令，還原要靠 `available_banks_align_logs` 的 `old_code`
 * 與 detail 手工回寫。
 *
 * ⚠️ 這是為存量修正寫的一次性工具，用完應依過渡期代碼清單的紀律退役。
 *
 * ## 退役條件
 *
 * 錯配的來源是「`cash-flow:sync` 以銀行名稱模糊比對建 `cash_flow_banks`」加上人工建檔，
 * 而 `available_banks` 又由 `agent:add-agent-cash-flow` 依 cfb 重算 ⇒ 根在同一處。
 * 當 sync 改以 `standard_bank_id`／外鍵重建、不再靠名稱模糊比對之後不會再產生新錯配，
 * 存量修完即可刪除。
 */
class FixAcfBankEntryCommand extends Command
{
    protected $signature = 'bank:fix-acf-bank-entry
                            {--acf=* : 要修正的 agent_cash_flows.id（可指定多個）}
                            {--entry-third= : 以三方碼（BankCode_Third）定位要改的項目}
                            {--from= : 該項目現有的 BankCode_ELG}
                            {--to= : 要改成的 BankCode_ELG（與 --to-third 互斥）}
                            {--to-third= : 要改成的 BankCode_Third（與 --to 互斥）}
                            {--reason= : 修正理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '修正 ACF available_banks 中配錯銀行碼的項目（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    public function handle(): int
    {
        $rawAcfIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('acf'));
        $invalid = array_values(array_filter(
            $rawAcfIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalid !== []) {
            $this->error(sprintf(
                '--acf 必須是純數字的 agent_cash_flows.id，收到「%s」。多個請重複旗標（--acf=57 --acf=58），不可用逗號分隔。',
                implode('」「', $invalid)
            ));

            return self::FAILURE;
        }

        $acfIds = array_values(array_unique(array_map('intval', $rawAcfIds)));
        $third = trim((string) $this->option('entry-third'));
        $from = trim((string) $this->option('from'));
        $to = $this->trimmedOption('to');
        $toThird = $this->trimmedOption('to-third');
        $reason = trim((string) $this->option('reason'));

        $invalidOptions = $this->validateOptions($acfIds, $third, $from, $to, $toThird, $reason);

        if ($invalidOptions !== null) {
            $this->error($invalidOptions);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($acfIds, $third, $from, $to, $toThird);

        $this->reportPlan($targets, $from, $to, $third, $toThird);

        if ($blockers !== []) {
            $this->newLine();
            $this->error('預檢未通過，整批零寫入：');

            foreach ($blockers as $line) {
                $this->error('  - ' . $line);
            }

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('沒有需要修正的項目。');

            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->info(sprintf(
                '預演完成：%d 個項目可修正，未寫入任何資料。確認上方報表無誤後加 --execute 實際執行。',
                count($targets)
            ));

            return self::SUCCESS;
        }

        if (!(bool) $this->option('force') && !$this->confirmExecutionEnvironment()) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $written = $this->apply($acfIds, $third, $from, $to, $toThird, $reason);

        $this->info(sprintf(
            '修正完成：%d 個項目（審計見 available_banks_align_logs，action=%s）。',
            $written,
            $this->auditAction($to)
        ));

        return self::SUCCESS;
    }

    /**
     * 參數層驗證：定位鍵齊全、目標值二選一、reason 寫得進審計。
     *
     * 回傳第一個不合格的原因；null 代表通過。集中在此是為了讓 handle() 只剩流程，
     * 也讓「哪些組合會被擋下」有單一可讀的清單。
     *
     * @param  list<int>  $acfIds
     */
    private function validateOptions(array $acfIds, string $third, string $from, ?string $to, ?string $toThird, string $reason): ?string
    {
        if ($acfIds === [] || $third === '' || $from === '') {
            return '--acf、--entry-third、--from 皆為必填：定位鍵少一個就無法指定要改哪個項目。';
        }

        // 兩種修法的判準不同（見類別 docblock），同時套用等於沒有判準
        if ($to !== null && $toThird !== null) {
            return '--to 與 --to-third 互斥：改銀行碼與改三方碼是兩種不同的錯誤形態，一次只能修一種。';
        }

        if ($to === null && $toThird === null) {
            return '--to 與 --to-third 必須擇一：--to 改 BankCode_ELG、--to-third 改 BankCode_Third。';
        }

        if ($from === $to) {
            return '--from 與 --to 相同，沒有可執行的變更。';
        }

        if ($third === $toThird) {
            return '--entry-third 與 --to-third 相同，沒有可執行的變更。';
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

    /** 未給或給空字串一律視為未指定，讓「兩者擇一」的判定不必再區分 null 與 ''。 */
    private function trimmedOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    /**
     * 審計 action 是判讀 old_code／new_code 指哪個欄位的唯一開關——欄位註解寫的是
     * BankCode_ELG，三方碼修正必須另立 action，否則日後還原會回錯欄位。
     */
    private function auditAction(?string $to): string
    {
        return $to !== null ? 'manual_entry_fix' : 'manual_entry_third_fix';
    }

    /**
     * 逐 ACF 分流成「可修正／不合格」兩類。
     *
     * $lock=true 時以 lockForUpdate() 讀取，供交易內重驗使用：預檢到人工確認之間的
     * 窗口是分鐘級，期間代理後台或對齊命令都可能改動同一份 JSON。
     *
     * @param  list<int>  $acfIds
     * @return array{targets: list<array{acf: AgentCashFlow, index: int}>, blockers: list<string>}
     */
    private function classifyRows(array $acfIds, string $third, string $from, ?string $to, ?string $toThird, bool $lock = false): array
    {
        // --to 模式改的是 target 碼，要驗的服務中列是目標碼；--to-third 模式 target 不動，
        // 要驗的是這個項目本身指向的列還在服務中（停用的列改了三方碼也一樣選不到）。
        $codeMustBeLive = $to ?? $from;

        // 同理，會製造重複的是「即將寫進去的那個值」，所以要比對的鍵隨模式而異
        $duplicateKey = $to !== null ? 'BankCode_ELG' : 'BankCode_Third';
        $duplicateValue = $to ?? $toThird;

        $query = AgentCashFlow::query()->whereIn('id', $acfIds);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get()->keyBy('id');

        $targets = [];
        $blockers = [];

        foreach ($acfIds as $id) {
            $acf = $rows->get($id);

            if ($acf === null) {
                $blockers[] = sprintf('acf=%d 不存在', $id);

                continue;
            }

            $entries = $this->readEntries($acf);

            // 定位鍵兩者並用：同一個銀行碼重複出現正是這類錯誤的表徵，只憑它無法指定
            $matched = [];
            foreach ($entries as $i => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ((string) ($entry['BankCode_ELG'] ?? '') === $from
                    && (string) ($entry['BankCode_Third'] ?? '') === $third) {
                    $matched[] = (int) $i;
                }
            }

            if ($matched === []) {
                $blockers[] = sprintf('acf=%d 找不到「%s／三方碼 %s」的項目', $id, $from, $third);

                continue;
            }

            if (count($matched) > 1) {
                $blockers[] = sprintf(
                    'acf=%d 有 %d 個項目同時符合「%s／三方碼 %s」，定位有歧義；改錯會讓另一家銀行也缺席，請先釐清',
                    $id,
                    count($matched),
                    $from,
                    $third
                );

                continue;
            }

            // 該碼在該群組沒有服務中的列 ⇒ 改了會員一樣選不到，等於把問題換個位置
            $hasActiveRow = Bank::query()
                ->where('agent_group_id', (int) $acf->agent_group_id)
                ->where('currency_id', (int) $acf->currency_id)
                ->where('code', $codeMustBeLive)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->exists();

            if (!$hasActiveRow) {
                $blockers[] = sprintf(
                    'acf=%d 的代理群組 %d／幣別 %d 沒有服務中的「%s」銀行列，改了會員仍選不到',
                    $id,
                    (int) $acf->agent_group_id,
                    (int) $acf->currency_id,
                    $codeMustBeLive
                );

                continue;
            }

            // 目標值已在同一份 JSON 內 ⇒ 改下去就製造出新的同碼重複，正是本案要消除的形態。
            // 被定位的項目自己不會命中：handle 已擋下 --from===--to 與 --entry-third===--to-third。
            foreach ($entries as $entry) {
                if (is_array($entry) && (string) ($entry[$duplicateKey] ?? '') === $duplicateValue) {
                    $blockers[] = sprintf(
                        'acf=%d 已存在「%s」的項目（%s），改碼會製造新的同碼重複',
                        $id,
                        $duplicateValue,
                        $duplicateKey
                    );

                    continue 2;
                }
            }

            $targets[] = ['acf' => $acf, 'index' => $matched[0]];
        }

        return ['targets' => $targets, 'blockers' => $blockers];
    }

    /**
     * @param  list<array{acf: AgentCashFlow, index: int}>  $targets
     */
    private function reportPlan(array $targets, string $from, ?string $to, string $third, ?string $toThird): void
    {
        // 印出來的必須是「哪個欄位從什麼變成什麼、哪個不動」——這份報表是 --execute
        // 前唯一的人工核對點，兩種模式看起來一樣就失去核對價值
        [$changed, $oldValue, $newValue, $kept] = $to !== null
            ? ['銀行碼', $from, $to, sprintf('三方碼 %s', $third)]
            : ['三方碼', $third, (string) $toThird, sprintf('銀行碼 %s', $from)];

        foreach ($targets as $target) {
            $acf = $target['acf'];
            $this->line(sprintf(
                '  [修正] acf=%d  金流商 %d／代理群組 %d  第 %d 個項目  %s %s → %s（%s 不動）',
                (int) $acf->id,
                (int) $acf->cash_flow_id,
                (int) $acf->agent_group_id,
                $target['index'] + 1,
                $changed,
                $oldValue,
                $newValue,
                $kept
            ));
        }
    }

    /**
     * @param  list<int>  $acfIds
     */
    private function apply(array $acfIds, string $third, string $from, ?string $to, ?string $toThird, string $reason): int
    {
        $connection = (string) config('database.default');
        $stamp = Carbon::now();
        $written = 0;

        DB::transaction(function () use ($acfIds, $third, $from, $to, $toThird, $reason, $connection, $stamp, &$written): void {
            // 鎖後重驗：預檢到人工確認之間的窗口是分鐘級，期間他人可能改動同一份 JSON。
            // 判定必須用鎖到的值重算，否則鎖只做了序列化、沒關掉 TOCTOU 窗口。
            $recheck = $this->classifyRows($acfIds, $third, $from, $to, $toThird, lock: true);

            if ($recheck['blockers'] !== []) {
                throw new BankConvergenceAbortedException(
                    '鎖定後重驗未通過，整批回滾：' . implode('；', $recheck['blockers'])
                );
            }

            foreach ($recheck['targets'] as $target) {
                $acf = $target['acf'];
                $index = $target['index'];

                $info = $this->readInfo($acf);
                $entries = $info['available_banks'] ?? [];

                // 只動這一個項目的其中一個欄位；另一個欄位與其餘鍵原樣保留
                $entries[$index][$to !== null ? 'BankCode_ELG' : 'BankCode_Third'] = $to ?? $toThird;
                $info['available_banks'] = array_values($entries);

                $acf->update(['info' => $info]);
                $written++;

                DB::table('available_banks_align_logs')->insert([
                    'connection' => $connection,
                    'agent_cash_flow_id' => (int) $acf->id,
                    'cash_flow_id' => (int) $acf->cash_flow_id,
                    'agent_group_id' => (int) $acf->agent_group_id,
                    'currency_id' => (int) $acf->currency_id,
                    // old/new 一律記「被改動的那個欄位」的前後值，指哪個欄位由 action 決定
                    'old_code' => $to !== null ? $from : $third,
                    'new_code' => $to ?? $toThird,
                    'action' => $this->auditAction($to),
                    'detail' => $this->encodeAuditDetail($to !== null
                        ? ['third' => $third, 'idx' => $index, 'reason' => $reason]
                        : ['field' => 'BankCode_Third', 'target' => $from, 'idx' => $index, 'reason' => $reason]),
                    'created_at' => $stamp,
                ]);
            }
        });

        return $written;
    }

    /**
     * 讀 ACF 的 available_banks 項目清單。
     *
     * @return array<int, mixed>
     */
    private function readEntries(AgentCashFlow $acf): array
    {
        return $this->readInfo($acf)['available_banks'] ?? [];
    }

    /**
     * 讀 ACF info；容忍 double-encoded（info 讀回 string 的 legacy 情況）。
     *
     * @return array<string, mixed>
     */
    private function readInfo(AgentCashFlow $acf): array
    {
        $info = $acf->info;

        if (is_string($info)) {
            $info = json_decode($info, true);
        }

        return is_array($info) ? $info : [];
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 ACF id 區間
     * 重疊——連錯站時預檢照樣可能全綠。呼叫點必須排在所有業務判定之前：預檢阻斷才是
     * 最需要核對環境的時刻，「找不到該項目」既可能是真的，也可能只是連錯站。
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

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));

        return $this->confirm('確定要在此環境執行？', false);
    }
}
