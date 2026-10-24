<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Enums\DriftSubjectEnum;
use App\Facades\BotNotify;
use App\Models\CashFlowBank;
use App\Models\DriftBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 稽核代付對照表 `cash_flow_banks` 的銀行碼有沒有跟 `banks.code` 脫節，脫節清單有變動才告警。
 *
 * 【分類】Bank — 唯讀稽核，排程常駐（退役條件見下方）。
 *
 * ## 守的是什麼
 *
 * 代付查表以 `(cash_flow_id, currency_id, bank_code)` 為鍵、不 join banks。`banks.code` 改了而
 * 對照表的 `bank_code` 沒跟上，該金流商對這家銀行的代付查表就落空。會改 `banks.code` 的命令
 * 現在都會連帶改對照表，但寫入口不只命令（後台、批次更新、`cash-flow:sync` 的名稱模糊比對
 * 配錯銀行），沒有一條路徑能保證，所以每天把對照表逐列對一次。
 *
 * 一筆「脫節」＝服務中（`status = 1`）的對照表列，其 `bank_code` 與它 `bank_id` 指向的
 * `banks.code` **逐字**不同，或指向的銀行列不存在。比對在 PHP 端做：欄位 collation 不分
 * 大小寫，`MayBank` 與 `MAYBANK` 是不同的碼。銀行列停用或軟刪除不算脫節，那是另一件事。
 *
 * ## 為什麼只在清單變動時告警
 *
 * 各站都有一批早就存在、已經登記過的脫節列（舊碼複本、名稱比對配錯），每天照樣通報等於洗版，
 * 真正新出現的那一筆反而沒人看。做法比照 `cash-flow:audit-fpay-bank-list`：把脫節清單存進
 * `drift_baselines` 當基準，每次跑完與基準比，**多了或少了才告警並把這次的清單存成新基準**。
 * 第一次跑只建基準、不告警。清單沒變的日子不寫任何東西，等同唯讀。
 *
 * 基準只在 `--notify`（排程模式）下寫入。人工執行只印報表、不動基準，否則人工跑一次會把
 * 變動吃掉，當天的排程就不告警了。
 *
 * ## 退役條件
 *
 * `cash-flow:sync` 改為以 `standard_bank_id` 重建對照表、不再靠名稱模糊比對，且對照表的
 * `bank_code` 改由外鍵推導而不是獨立存值時，脫節不可能再發生，本命令即無標的。
 *
 *   php artisan bank:audit-cfb-code            # 人工：印目前的脫節清單與基準差異，不寫入
 *   php artisan bank:audit-cfb-code --notify   # 排程：清單有變動即 BotNotify 並更新基準
 */
class AuditCfbCodeCommand extends Command
{
    /** 一站一份清單，scope_key 固定 */
    private const SCOPE_KEY = 'all';

    /** 報表與告警最多逐列列出幾筆，其餘只給數量 */
    private const LIST_LIMIT = 30;

    protected $signature = 'bank:audit-cfb-code
                            {--notify : 排程模式：脫節清單與基準不同即 BotNotify 並存新基準，且一律回 SUCCESS}';

    protected $description = '稽核代付對照表 cash_flow_banks 的銀行碼是否與 banks.code 脫節，清單有變動才告警（唯讀）';

    public function handle(): int
    {
        $current = $this->collectDrift();
        $baseline = DriftBaseline::query()
            ->forScope(DriftSubjectEnum::CFB_CODE_DRIFT, self::SCOPE_KEY)
            ->first();

        $this->renderCurrent($current);

        if ($baseline === null) {
            if ($this->option('notify')) {
                // 第一次跑：沒有可比的對象，只建基準、不告警——把「沒有歷史」報成異常毫無意義
                $this->storeBaseline($current);
                $this->info('尚無基準，已把這次的清單存為基準；下次起有變動才告警。');
            } else {
                $this->info('尚無基準（基準只在 --notify 模式下建立）。');
            }

            return self::SUCCESS;
        }

        /** @var array<int, array{cfb: string, bank: ?string}> $known */
        $known = (array) $baseline->payload;
        $added = array_diff_key($current, $known);
        $removed = array_diff_key($known, $current);
        $changed = [];

        foreach (array_intersect_key($current, $known) as $id => $item) {
            if ($item !== $known[$id]) {
                $changed[$id] = $item;
            }
        }

        $this->renderDiff($baseline, $added, $removed, $changed);

        if ($added === [] && $removed === [] && $changed === []) {
            return self::SUCCESS;
        }

        if ($this->option('notify')) {
            $this->notify($added, $removed, $changed, count($current));
            $this->storeBaseline($current);

            // 有變動不算命令失敗：排程每天紅會讓真正的執行失敗被淹沒，變動一律交 BotNotify 傳達
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * 目前的脫節清單，鍵是對照表列 id（PHP 會把數字字串鍵轉成整數，JSON 往返後也是整數鍵）。
     *
     * @return array<int, array{cfb: string, bank: ?string}>
     */
    private function collectDrift(): array
    {
        $rows = CashFlowBank::query()
            ->leftJoin('banks', 'banks.id', '=', 'cash_flow_banks.bank_id')
            ->where('cash_flow_banks.status', 1)
            ->orderBy('cash_flow_banks.id')
            ->get([
                'cash_flow_banks.id',
                'cash_flow_banks.bank_code',
                DB::raw('banks.code as bank_current_code'),
            ]);

        $drift = [];

        foreach ($rows as $row) {
            $bankCode = $row->bank_current_code === null ? null : (string) $row->bank_current_code;

            if ($bankCode !== null && $bankCode === (string) $row->bank_code) {
                continue;
            }

            $drift[(int) $row->id] = ['cfb' => (string) $row->bank_code, 'bank' => $bankCode];
        }

        return $drift;
    }

    /**
     * @param  array<int, array{cfb: string, bank: ?string}>  $current
     */
    private function renderCurrent(array $current): void
    {
        $this->line(sprintf('資料庫：%s', DB::connection()->getDatabaseName()));
        $this->line(sprintf('目前脫節 %d 筆（服務中的對照表列，銀行碼與 banks.code 逐字不同或銀行列不存在）', count($current)));

        foreach (array_slice($current, 0, self::LIST_LIMIT, true) as $id => $item) {
            $this->line('  ' . $this->describe((int) $id, $item));
        }

        if (count($current) > self::LIST_LIMIT) {
            $this->line(sprintf('  … 其餘 %d 筆略', count($current) - self::LIST_LIMIT));
        }
    }

    /**
     * @param  array<int, array{cfb: string, bank: ?string}>  $added
     * @param  array<int, array{cfb: string, bank: ?string}>  $removed
     * @param  array<int, array{cfb: string, bank: ?string}>  $changed
     */
    private function renderDiff(DriftBaseline $baseline, array $added, array $removed, array $changed): void
    {
        $this->newLine();
        $this->line(sprintf('與基準比對（基準建立於 %s，%d 筆）', $baseline->created_at?->toDateTimeString() ?? '?', count((array) $baseline->payload)));

        if ($added === [] && $removed === [] && $changed === []) {
            $this->info('清單沒有變動。');

            return;
        }

        foreach (['新出現' => $added, '已消失' => $removed, '內容改變' => $changed] as $label => $items) {
            if ($items === []) {
                continue;
            }

            $this->warn(sprintf('%s %d 筆：', $label, count($items)));

            foreach (array_slice($items, 0, self::LIST_LIMIT, true) as $id => $item) {
                $this->line('  ' . $this->describe((int) $id, $item));
            }
        }
    }

    /**
     * @param  array{cfb: string, bank: ?string}  $item
     */
    private function describe(int $cfbId, array $item): string
    {
        return $item['bank'] === null
            ? sprintf('cfb %d：碼「%s」，指向的銀行列不存在', $cfbId, $item['cfb'])
            : sprintf('cfb %d：碼「%s」，銀行列現碼「%s」', $cfbId, $item['cfb'], $item['bank']);
    }

    /**
     * @param  array<int, array{cfb: string, bank: ?string}>  $added
     * @param  array<int, array{cfb: string, bank: ?string}>  $removed
     * @param  array<int, array{cfb: string, bank: ?string}>  $changed
     */
    private function notify(array $added, array $removed, array $changed, int $total): void
    {
        $lines = [];

        foreach (['新出現' => $added, '已消失' => $removed, '內容改變' => $changed] as $label => $items) {
            if ($items === []) {
                continue;
            }

            $lines[] = sprintf('%s %d 筆', $label, count($items));

            foreach (array_slice($items, 0, self::LIST_LIMIT, true) as $id => $item) {
                $lines[] = '  ' . $this->describe((int) $id, $item);
            }
        }

        BotNotify::send(
            sprintf(
                "⚠️ 代付對照表銀行碼脫節清單有變動（目前共 %d 筆）\n%s\n\n新出現的脫節代表該金流商對這家銀行的代付查表會落空；看全清單：php artisan bank:audit-cfb-code",
                $total,
                implode(PHP_EOL, $lines),
            ),
            [
                'connection' => (string) config('database.default'),
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        );
    }

    /**
     * @param  array<int, array{cfb: string, bank: ?string}>  $current
     */
    private function storeBaseline(array $current): void
    {
        DriftBaseline::query()->create([
            'subject_key' => DriftSubjectEnum::CFB_CODE_DRIFT->value,
            'scope_key' => self::SCOPE_KEY,
            'scope_label' => DB::connection()->getDatabaseName(),
            'item_count' => count($current),
            // 空清單存成物件而不是空陣列，JSON 往返後型別才一致
            'payload' => $current === [] ? new \stdClass : $current,
        ]);
    }
}
