<?php

declare(strict_types=1);

namespace App\Console\Commands\Currency;

use App\Enums\StatusEnum;
use App\Models\VendorInfo;
use App\Services\Currency\CurrencyCodeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 稽核 vendor_infos.currency 的幣別代碼能否對應到標準幣別。
 *
 * 這支命令回答一個問題：後續要把 vendor_infos.currency_id 填起來時，
 * 有哪些代碼會找不到對應、導致回填落空。輸出即該批作業的工作清單。
 *
 * 「對得到」的定義與判定值（mapped／enum_only／id_mismatch…）由 CurrencyCodeResolver 決定，
 * 回填命令 currency:backfill-vendor-info 走同一個解析器，兩邊對同一個字串必得同一個 id。
 *
 * ⚠️ 本命令是「某個時間點的快照」，不是鎖。vendor_infos.currency 是自由字串
 *    （varchar(5)，無外鍵、無 CHECK、無列舉約束），稽核跑完到實際回填之間，
 *    只要有人新增或修改商戶帶進新代碼，這份綠燈就過期了。因此回填端必須自己
 *    再判定一次、對不到的列跳過不寫並以非零退出碼回報，不可把本命令的綠燈
 *    當成回填期間的保證。
 *
 * 使用範例：
 *   php artisan currency:audit-vendor-info
 *   php artisan currency:audit-vendor-info --json
 *   docker exec -e DB_CONNECTION=VWorld php-fpm8 bash -c "cd /var/www/APIs && php artisan currency:audit-vendor-info"
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class AuditVendorInfoCurrencyCommand extends Command
{
    protected $signature = 'currency:audit-vendor-info
                            {--json : 以 JSON 輸出，供程式解析}';

    protected $description = '稽核 vendor_infos.currency 能否對到標準幣別，列出會讓 currency_id 回填落空的代碼';

    public function handle(): int
    {
        try {
            $resolver = CurrencyCodeResolver::fromContract();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = [];
        $blockedCodes = 0;
        $unmappableRows = 0;

        foreach ($this->countByRawCode() as $row) {
            $raw = (string) $row->currency;
            $total = (int) $row->total;
            $active = (int) $row->active;

            $resolution = $resolver->resolve($raw);

            $report[] = [
                'raw' => $raw,
                'resolved' => $resolution->resolved,
                'currency_id' => $resolution->currencyId,
                'status' => $resolution->status->value,
                'total' => $total,
                'active' => $active,
            ];

            // 阻斷與否只看有沒有 currency_id，不看分類：站台點數語意上不是幣別，
            // 但它在 CurrencyEnum 有 id、主檔也有那一列、回填得到值，不該擋；反過來
            // 主檔還沒補到的幣別即使 Enum 已有 case、語意上是正常幣別，也會落空。
            if ($resolution->currencyId === null) {
                $unmappableRows += $total;

                if ($active > 0) {
                    $blockedCodes++;
                }
            }
        }

        return $this->option('json')
            ? $this->renderJson($report, $blockedCodes, $unmappableRows)
            : $this->renderTable($report, $blockedCodes, $unmappableRows);
    }

    /**
     * 統計每個幣別代碼的商戶數與啟用中商戶數。
     *
     * ⚠️ 用資料庫分組，因此依預設 collation，**大小寫或尾隨空白不同的寫法會被
     *    合併成同一組**（`kIDR` 併進 `KIDR`、`'MYR '` 併進 `'MYR'`）。這對本命令
     *    的判定沒有影響——同一組的成員經正規化後對到同一個 currency_id，回填
     *    結果一致。
     *
     * 🔴 但這也表示**本命令看不出資料裡有哪些髒寫法**。那是另一件事，由第二期的
     *    幣別代碼正規化作業負責找出並修正；修完之後後台的輸入端也要補上驗證，
     *    否則新的髒寫法會再進來而無人察覺（目前後台該欄是自由文字、只有必填檢查）。
     *
     * @return array<int, object{currency: string, total: int, active: int}>
     */
    private function countByRawCode(): array
    {
        return VendorInfo::query()
            ->toBase()
            ->selectRaw('currency, COUNT(*) AS total, SUM(status = ?) AS active', [StatusEnum::ENABLE->value])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $report
     */
    private function renderJson(array $report, int $blockedCodes, int $unmappableRows): int
    {
        $this->line(json_encode([
            'connection' => DB::connection()->getName(),
            'rows' => $report,
            'blocked_codes' => $blockedCodes,
            'unmappable_rows_total' => $unmappableRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $blockedCodes === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $report
     */
    private function renderTable(array $report, int $blockedCodes, int $unmappableRows): int
    {
        $this->line('連線：' . DB::connection()->getName());

        $this->table(
            ['原始值', '對應標準幣別', 'currency_id', '判定', '商戶數', '啟用中'],
            array_map(static fn (array $row): array => [
                (string) $row['raw'],
                (string) $row['resolved'],
                $row['currency_id'] === null ? '—' : (string) $row['currency_id'],
                (string) $row['status'],
                (string) $row['total'],
                (string) $row['active'],
            ], $report),
        );

        // 停用中商戶的代碼不計入阻斷，但它們的 currency_id 一樣會回填不到。
        // 只看退出碼會漏掉這批，所以獨立列出列數。
        if ($unmappableRows > 0) {
            $this->comment(sprintf('共 %d 列（含停用中商戶）對不到 currency_id', $unmappableRows));
        }

        if ($blockedCodes === 0) {
            $this->info('所有啟用中商戶的幣別代碼都能對到 currency_id');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '有 %d 種代碼在啟用中商戶上找不到 currency_id，後續回填會落空',
            $blockedCodes,
        ));

        return self::FAILURE;
    }
}
