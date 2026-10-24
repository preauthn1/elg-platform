<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\BankConvergenceAbortedException;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\WithdrawalOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 把 ACF `available_banks` 中某個死項目整個移除。
 *
 * 【分類】Bank — 過渡期工具，死項目清完即無標的（退役條件見下方）。
 *
 * ## 補的是一個「兩道 gate 互為前提」的死結
 *
 * 實例（Alfawin77 TopPay／cf94）：同一張 ACF 裡，服務中的「Bank OCBC NISP」項目掛著
 * 母行伊斯蘭事業單位的碼 `0280`，而正確的母行碼 `028` 被另一個**死項目**（`IDR0060`，
 * 該群組的銀行列已停用軟刪）佔著。於是：
 *
 * - 要把服務中那個項目改成 `028` ⇒ `bank:fix-acf-bank-entry` 擋下「目標三方碼已存在於同一 ACF」
 * - 要刪掉那個死項目 ⇒ `cash-flow:align-available-banks --prune-duplicate-entries` 要求
 *   兩個項目的三方碼**相同**才肯刪，而它們正好不同
 *
 * 兩道 gate 各自都是對的，不該為了這個場景放寬任何一道；缺的是「單獨移除一個死項目」
 * 這個動作本身。本命令只做這件事，把碼讓出來，後續要不要有人承接由呼叫端另行決定。
 *
 * ## 只移除「已經沒有服務中銀行列對應」的項目
 *
 * 這是本命令唯一的安全前提，不設放行旗標：項目對應的銀行列若仍在服務中，刪掉 entry 會
 * 讓會員在前台選得到那家銀行、送單時卻轉不出三方碼（`convertPayoutBankCodeFromJson()`
 * 回空字串）而擋單。真要下架一家銀行，該做的是停用它的 `banks` 列，不是抽掉 entry。
 *
 * ## 攔阻中的舊碼單會擋下移除
 *
 * 沿用 `AvailableBanksAlignService` 的 prune gate 3 判準：in-flight（21/22）與可重發
 * （29）的提款單以 `bank_code` 快照回查 entry，entry 刪了它們會查不到碼（23 已由 MR !2235
 * 退役，見 `BLOCKING_WO_STATUSES`）。判定用 `BINARY`
 * 比對——欄位 collation 大小寫不敏感，不加會把別的碼算進來。
 *
 * ## 預設不寫入
 *
 * 與 bank 家族其他命令一致：不帶 `--execute` 只輸出預檢報表。多張 ACF 一律 all-or-nothing。
 * 無 rollback 命令，還原靠 `available_banks_align_logs`（`action=manual_entry_remove`）的
 * detail——它完整記下被移除項目的兩個碼與位置。
 *
 * ⚠️ 這是為存量修正寫的工具，用完應依過渡期代碼清單的紀律退役。
 *
 * ## 退役條件
 *
 * 死項目的來源是「銀行列停用或收斂之後，ACF 的 JSON 沒有跟著調整」。
 * 當 `available_banks` 全面改由 `cash_flow_banks` 單一來源重算（`agent:add-agent-cash-flow`
 * 已是這個形態），或代付查表全面開閘、JSON 路徑退場之後，本命令即無標的。
 */
class RemoveAcfBankEntryCommand extends Command
{
    protected $signature = 'bank:remove-acf-bank-entry
                            {--acf=* : 要處理的 agent_cash_flows.id（可指定多個）}
                            {--entry-third= : 以三方碼（BankCode_Third）定位要移除的項目}
                            {--from= : 該項目的 BankCode_ELG（與三方碼並用定位）}
                            {--reason= : 移除理由，寫入審計供日後追溯（必填）}
                            {--execute : 實際寫入（預設只輸出預檢報表）}
                            {--force : 跳過執行前的環境確認}';

    protected $description = '移除 ACF available_banks 中已無服務中銀行列對應的死項目（預設只預演）';

    /** 審計 detail 的長度上限，超出即中止——不可逆寫入不能失去審計。 */
    private const DETAIL_MAX_LENGTH = 8192;

    /** --reason 的長度上限，留足空間給 detail 的其餘欄位。 */
    private const REASON_MAX_LENGTH = 2000;

    /**
     * 會擋下移除的提款單狀態：in-flight（21/22）＋可重發（29，非終態、重發會再查碼）。
     * 與 `AvailableBanksAlignService::PRUNE_BLOCKING_WO_STATUSES` 同一套判準，兩處要一起改
     * （23 已由 MR !2235 退役，理由見該常數）。
     */
    private const BLOCKING_WO_STATUSES = [
        WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
        WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
    ];

    public function handle(): int
    {
        $rawAcfIds = array_map(static fn ($v): string => trim((string) $v), (array) $this->option('acf'));
        $invalidIds = array_values(array_filter(
            $rawAcfIds,
            static fn (string $v): bool => preg_match('/^\d+$/', $v) !== 1
        ));

        if ($invalidIds !== []) {
            $this->error(sprintf(
                '--acf 必須是純數字的 agent_cash_flows.id，收到「%s」。多個請重複旗標（--acf=65 --acf=68），不可用逗號分隔。',
                implode('」「', $invalidIds)
            ));

            return self::FAILURE;
        }

        $acfIds = array_values(array_unique(array_map('intval', $rawAcfIds)));
        $third = trim((string) $this->option('entry-third'));
        $from = trim((string) $this->option('from'));
        $reason = trim((string) $this->option('reason'));

        $invalid = $this->validateOptions($acfIds, $third, $from, $reason);

        if ($invalid !== null) {
            $this->error($invalid);

            return self::FAILURE;
        }

        $this->discloseWriteTarget();

        ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($acfIds, $third, $from);

        foreach ($targets as $target) {
            $this->line(sprintf(
                '  [移除] acf=%d  代理群組 %d／幣別 %d  第 %d 個項目  銀行碼 %s／三方碼 %s',
                $target['acf_id'],
                $target['agent_group_id'],
                $target['currency_id'],
                $target['index'] + 1,
                $from,
                $third
            ));
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
            $this->info('沒有需要移除的項目。');

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

        $removed = $this->apply($acfIds, $third, $from, $reason);

        $this->info(sprintf(
            '移除完成：%d 個項目（審計見 available_banks_align_logs，action=manual_entry_remove）。',
            $removed
        ));

        return self::SUCCESS;
    }

    /**
     * 參數層的靜態檢查——不碰資料庫，純粹擋下自相矛盾的呼叫。
     *
     * @param  list<int>  $acfIds
     */
    private function validateOptions(array $acfIds, string $third, string $from, string $reason): ?string
    {
        if ($acfIds === [] || $third === '' || $from === '') {
            return '--acf、--entry-third、--from 皆為必填：定位鍵少一個就無法指定要移除哪個項目。';
        }

        if ($reason === '') {
            return '--reason 必填：移除理由會寫入審計，供日後追溯與還原。';
        }

        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '--reason 含無效的 UTF-8 位元組：審計無法編碼，而移除不可逆——寧可先擋下。';
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return sprintf('--reason 超過 %d 字元，請精簡；審計 detail 有長度上限。', self::REASON_MAX_LENGTH);
        }

        return null;
    }

    /**
     * 逐 ACF 分流成「可移除／阻擋」兩類。
     *
     * $lock=true 時以 `lockForUpdate()` 讀取，供交易內重驗使用：預檢到人工確認之間是分鐘級的
     * 窗口，期間對齊命令或後台都可能改動同一份 JSON。
     *
     * @param  list<int>  $acfIds
     * @return array{targets: list<array{acf_id: int, agent_group_id: int, currency_id: int, index: int}>, blockers: list<string>}
     */
    private function classifyRows(array $acfIds, string $third, string $from, bool $lock = false): array
    {
        $targets = [];
        $blockers = [];

        foreach ($acfIds as $acfId) {
            $query = AgentCashFlow::query()->where('id', $acfId);

            if ($lock) {
                $query->lockForUpdate();
            }

            $acf = $query->first();

            if ($acf === null) {
                $blockers[] = sprintf('agent_cash_flows 沒有 id=%d 的列。', $acfId);

                continue;
            }

            $entries = $acf->info['available_banks'] ?? [];
            $matched = [];

            foreach ($entries as $index => $entry) {
                if ((string) ($entry['BankCode_ELG'] ?? '') === $from
                    && (string) ($entry['BankCode_Third'] ?? '') === $third) {
                    $matched[] = $index;
                }
            }

            if ($matched === []) {
                $blockers[] = sprintf('acf=%d 找不到「%s／%s」的項目。', $acfId, $from, $third);

                continue;
            }

            if (count($matched) > 1) {
                $blockers[] = sprintf(
                    'acf=%d 有 %d 個「%s／%s」項目：移除哪一個都是猜，請先人工釐清。',
                    $acfId,
                    count($matched),
                    $from,
                    $third
                );

                continue;
            }

            $liveBank = Bank::query()
                ->where('agent_group_id', $acf->agent_group_id)
                ->where('currency_id', $acf->currency_id)
                ->where('code', $from)
                ->where('status', 1)
                ->exists();

            if ($liveBank) {
                $blockers[] = sprintf(
                    'acf=%d 的代理群組 %d／幣別 %d 仍有服務中的「%s」銀行列：'
                    . '移除 entry 會讓會員選得到卻轉不出碼而擋單。要下架該行請改停用它的 banks 列。',
                    $acfId,
                    (int) $acf->agent_group_id,
                    (int) $acf->currency_id,
                    $from
                );

                continue;
            }

            $targets[] = [
                'acf_id' => $acfId,
                'agent_group_id' => (int) $acf->agent_group_id,
                'currency_id' => (int) $acf->currency_id,
                'index' => $matched[0],
            ];
        }

        $blocking = $this->countBlockingOrders($from);

        if ($blocking > 0) {
            $blockers[] = sprintf(
                '有 %d 筆帶「%s」的 in-flight／可重發提款單（status 21/22/29）：'
                . '這些單以 bank_code 快照回查 entry，移除後會查不到碼，待結清再移除。',
                $blocking,
                $from
            );
        }

        return ['targets' => $targets, 'blockers' => $blockers];
    }

    /**
     * 欄位 collation 大小寫不敏感，不加 `BINARY` 會把別的碼算進來。
     */
    private function countBlockingOrders(string $code): int
    {
        return WithdrawalOrder::query()
            ->whereIn('status', self::BLOCKING_WO_STATUSES)
            ->whereRaw('BINARY bank_code = ?', [$code])
            ->count();
    }

    /**
     * 在交易內重驗後移除項目並寫審計。
     *
     * @param  list<int>  $acfIds
     */
    private function apply(array $acfIds, string $third, string $from, string $reason): int
    {
        $removed = 0;

        DB::transaction(function () use ($acfIds, $third, $from, $reason, &$removed): void {
            ['targets' => $targets, 'blockers' => $blockers] = $this->classifyRows($acfIds, $third, $from, lock: true);

            if ($blockers !== []) {
                throw new BankConvergenceAbortedException('鎖內重驗未通過，整批回滾：' . implode('；', $blockers));
            }

            $connection = (string) config('database.default');

            foreach ($targets as $target) {
                $acf = AgentCashFlow::query()->where('id', $target['acf_id'])->first();
                $info = $acf->info;
                $entries = $info['available_banks'];

                unset($entries[$target['index']]);
                // 移除後索引會斷號，重排以免 json_encode 把 list 變成物件
                $info['available_banks'] = array_values($entries);

                $acf->info = $info;
                $acf->save();

                DB::table('available_banks_align_logs')->insert([
                    'connection' => $connection,
                    'agent_cash_flow_id' => $target['acf_id'],
                    'cash_flow_id' => (int) $acf->cash_flow_id,
                    'agent_group_id' => $target['agent_group_id'],
                    'currency_id' => $target['currency_id'],
                    'old_code' => mb_substr($from, 0, 64),
                    // 移除沒有承接碼，留空才不會被誤讀成「改指到某碼」
                    'new_code' => null,
                    'action' => 'manual_entry_remove',
                    'detail' => $this->encodeAuditDetail([
                        'third' => $third,
                        'idx' => $target['index'],
                        'reason' => $reason,
                    ]),
                ]);

                $removed++;
            }
        });

        return $removed;
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
     * 目標由容器的 `-e DB_CONNECTION` 決定、不在命令參數裡，而各白標的 ACF id 區間會重疊，
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
