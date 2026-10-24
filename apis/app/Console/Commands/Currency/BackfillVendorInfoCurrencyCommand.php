<?php

declare(strict_types=1);

namespace App\Console\Commands\Currency;

use App\Enums\StatusEnum;
use App\Models\EventVendorInfo;
use App\Models\VendorInfo;
use App\Services\Currency\CurrencyCodeResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 依幣別 alias 契約，把商戶主檔的 currency 字串對到 currencies 主檔並回填 currency_id。
 *
 * 對象是 vendor_infos 與 event_vendor_infos 兩張表（決議⑲：活動商戶與遊戲商戶同批技術對齊），
 * 只動 currency_id = 0（尚未回填的 sentinel）的列；解析走 CurrencyCodeResolver，
 * 與 currency:audit-vendor-info 同一條鏈，兩邊對同一個字串必得同一個 id。
 *
 * 🔴 這是多幣別第二期第一支會寫生產資料的命令，三條紅線：
 *   1. 預設 dry-run，實跑必須明確加 --execute。
 *   2. 寫入包 transaction、逐列 lockForUpdate 後再判定一次、逐列 Log::info 記下改了什麼。
 *   3. 對不到標準幣別的一律跳過不寫，並在輸出列出；不猜最像的、不填主幣別。
 *
 * 退出碼與稽核命令同口徑：只要有「啟用中且對不到」的列就回非零；停用中商戶對不到只列出
 * 不轉紅（決議㉔：MSC 商戶已停用、不阻斷回填）。
 *
 * ⚠️ updated_at 明寫保留原值：兩張表的 updated_at 都是 ON UPDATE CURRENT_TIMESTAMP，
 *    不明寫就會把全站商戶的「最後異動時間」推到同一秒，後台與稽核以它判「近期改過」
 *    會整批假陽性（withdrawal_orders 曾因批次改指銀行踩過同一個坑）。異動軌跡由 Log 承擔。
 *
 * 使用範例：
 *   php artisan currency:backfill-vendor-info                       # 兩張表都預覽
 *   php artisan currency:backfill-vendor-info --table=vendor_infos  # 只看一張表
 *   php artisan currency:backfill-vendor-info --vendor-id=54        # 只看某遊戲商
 *   php artisan currency:backfill-vendor-info --execute             # 實跑（逐站執行、逐站以唯讀 SQL 複驗）
 *   docker exec -e DB_CONNECTION=VWorld php-fpm8 bash -c "cd /var/www/APIs && php artisan currency:backfill-vendor-info"
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class BackfillVendorInfoCurrencyCommand extends Command
{
    /**
     * 回填對象：表名 → Model。兩張表的 currency／currency_id／vendor_id／status 欄位語意相同。
     *
     * @var array<string, class-string<Model>>
     */
    private const array TABLES = [
        'vendor_infos' => VendorInfo::class,
        'event_vendor_infos' => EventVendorInfo::class,
    ];

    /** 尚未回填的 sentinel，與欄位 DEFAULT 一致 */
    private const int UNFILLED = 0;

    /** 一次上鎖與寫入的列數上限；商戶主檔六站合計不到千列，只是避免 whereIn 無上限 */
    private const int WRITE_CHUNK = 500;

    protected $signature = 'currency:backfill-vendor-info
                            {--execute : 實際寫入；未指定時只做預覽（dry-run）}
                            {--table= : 只處理指定資料表（vendor_infos 或 event_vendor_infos），未指定時兩張都處理}
                            {--vendor-id= : 只處理指定遊戲商編號（vendors.id）}';

    protected $description = '依幣別 alias 契約把 vendor_infos／event_vendor_infos 的 currency 對到 currency_id 並回填（預設 dry-run，實跑加 --execute）';

    public function handle(): int
    {
        $tables = $this->targetTables();

        if ($tables === null || !$this->columnsPresent($tables)) {
            return self::FAILURE;
        }

        $vendorId = $this->vendorIdOption();

        if ($vendorId === false) {
            return self::FAILURE;
        }

        try {
            $resolver = CurrencyCodeResolver::fromContract();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        $this->line('連線：' . DB::connection()->getName());
        $this->line($execute ? '模式：--execute（寫入）' : '模式：dry-run（預覽，不寫入）');

        $blocked = false;

        foreach ($tables as $table => $model) {
            $plan = $this->plan($model, $resolver, $vendorId);
            $this->renderPlan($table, $plan);

            if ($plan['active_skipped'] > 0) {
                $blocked = true;
            }

            if (!$execute) {
                continue;
            }

            $result = $this->write($model, $table, $plan['planned_ids'], $resolver);
            $this->info(sprintf('  %s：已回填 %d 筆', $table, $result['written']));

            if ($result['lost'] > 0) {
                $this->warn(sprintf('  %s：%d 筆在預覽後被改動（已填值或字串改成對不到），本次未寫', $table, $result['lost']));
                $blocked = true;
            }
        }

        if (!$execute) {
            $this->info('  [dry-run] 未寫入。確認上表無誤後加 --execute 實跑');
        }

        return $blocked ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 預覽階段：把待回填的列逐一解析，依原始字串彙總成「可回填」與「跳過」兩群。
     *
     * 這裡只讀不鎖；寫入階段會對每一列重新上鎖、重新解析，預覽結果只用來給人看與決定退出碼。
     *
     * @param  class-string<Model>  $model
     * @return array{
     *     rows: array<string, array{currency_id: int|null, status: string, total: int, active: int, ids: array<int, int>}>,
     *     planned_ids: array<int, int>,
     *     skipped_rows: int,
     *     active_skipped: int,
     * }
     */
    private function plan(string $model, CurrencyCodeResolver $resolver, ?int $vendorId): array
    {
        $rows = [];
        $plannedIds = [];
        $skippedRows = 0;
        $activeSkipped = 0;

        $query = $model::query()
            ->where('currency_id', self::UNFILLED)
            ->when($vendorId !== null, static fn ($builder) => $builder->where('vendor_id', $vendorId))
            ->orderBy('id');

        foreach ($query->cursor() as $row) {
            $raw = (string) $row->getAttribute('currency');
            $active = (int) $row->getAttribute('status') === StatusEnum::ENABLE->value;
            $resolution = $resolver->resolve($raw);

            // 預覽表以原始字串彙總，大小寫敏感：kIDR 與 KIDR 各自一列，才看得出髒寫法。
            $rows[$raw] ??= [
                'currency_id' => $resolution->currencyId,
                'status' => $resolution->status->value,
                'total' => 0,
                'active' => 0,
                'ids' => [],
            ];
            $rows[$raw]['total']++;
            $rows[$raw]['active'] += $active ? 1 : 0;
            $rows[$raw]['ids'][] = (int) $row->getKey();

            if ($resolution->currencyId === null) {
                $skippedRows++;
                $activeSkipped += $active ? 1 : 0;

                continue;
            }

            $plannedIds[] = (int) $row->getKey();
        }

        ksort($rows, SORT_STRING);

        return [
            'rows' => $rows,
            'planned_ids' => $plannedIds,
            'skipped_rows' => $skippedRows,
            'active_skipped' => $activeSkipped,
        ];
    }

    /**
     * 寫入階段：分批上鎖、逐列重新判定後才更新。
     *
     * 預覽到寫入之間可能有人改了字串或別的路徑先填了值，所以不信任預覽結果：
     * 鎖住的列若已非 sentinel、或字串現在對不到，一律跳過並計入 lost。
     * UPDATE 再帶 currency_id = 0 條件，讓寫入本身也是「只填尚未填的」，重跑安全。
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int>  $ids
     * @return array{written: int, lost: int}
     */
    private function write(string $model, string $table, array $ids, CurrencyCodeResolver $resolver): array
    {
        $written = 0;
        $lost = 0;

        foreach (array_chunk($ids, self::WRITE_CHUNK) as $chunk) {
            DB::transaction(function () use ($model, $table, $chunk, $resolver, &$written, &$lost): void {
                $locked = $model::query()
                    ->whereIn('id', $chunk)
                    ->lockForUpdate()
                    ->get();

                foreach ($locked as $row) {
                    $raw = (string) $row->getAttribute('currency');
                    $resolution = $resolver->resolve($raw);

                    if ((int) $row->getAttribute('currency_id') !== self::UNFILLED || $resolution->currencyId === null) {
                        $lost++;

                        continue;
                    }

                    $values = ['currency_id' => $resolution->currencyId];
                    $updatedAt = $row->getRawOriginal('updated_at');

                    if (is_string($updatedAt)) {
                        $values['updated_at'] = $updatedAt;
                    }

                    $affected = $model::query()
                        ->toBase()
                        ->where('id', $row->getKey())
                        ->where('currency_id', self::UNFILLED)
                        ->update($values);

                    if ($affected !== 1) {
                        $lost++;

                        continue;
                    }

                    $written++;

                    Log::info('幣別編號回填', [
                        'table' => $table,
                        'id' => (int) $row->getKey(),
                        'currency' => $raw,
                        'currency_id' => $resolution->currencyId,
                    ]);
                }
            });
        }

        return ['written' => $written, 'lost' => $lost];
    }

    /**
     * @param  array{
     *     rows: array<string, array{currency_id: int|null, status: string, total: int, active: int, ids: array<int, int>}>,
     *     planned_ids: array<int, int>,
     *     skipped_rows: int,
     *     active_skipped: int,
     * }  $plan
     */
    private function renderPlan(string $table, array $plan): void
    {
        $this->newLine();
        $this->line(sprintf('── %s ──', $table));

        if ($plan['rows'] === []) {
            $this->info('  沒有 currency_id = 0 的列，無事可做');

            return;
        }

        $this->table(
            ['原始值', 'currency_id', '判定', '筆數', '啟用中'],
            array_map(static fn (string $raw, array $row): array => [
                $raw,
                $row['currency_id'] === null ? '—' : (string) $row['currency_id'],
                $row['status'],
                (string) $row['total'],
                (string) $row['active'],
            ], array_keys($plan['rows']), $plan['rows']),
        );

        $this->line(sprintf(
            '  待回填 %d 筆：可回填 %d、跳過 %d（其中啟用中 %d）',
            count($plan['planned_ids']) + $plan['skipped_rows'],
            count($plan['planned_ids']),
            $plan['skipped_rows'],
            $plan['active_skipped'],
        ));

        foreach ($plan['rows'] as $raw => $row) {
            if ($row['currency_id'] !== null) {
                continue;
            }

            $this->warn(sprintf(
                '  跳過 currency=%s（%s）：%d 筆，id=%s%s',
                $raw,
                $row['status'],
                $row['total'],
                implode(',', array_slice($row['ids'], 0, 10)),
                count($row['ids']) > 10 ? '…' : '',
            ));
        }
    }

    /**
     * 加欄 migration 還沒部署到目標站時，第一句查詢就會炸 QueryException；
     * 先查欄位存在，讓 ops 看到的是「該先部署什麼」而不是 stack trace。
     *
     * @param  array<string, class-string<Model>>  $tables
     */
    private function columnsPresent(array $tables): bool
    {
        foreach (array_keys($tables) as $table) {
            if (!Schema::hasColumn($table, 'currency_id')) {
                $this->error(sprintf(
                    '資料表 %s 尚無 currency_id 欄位，請先部署 2026_09_07 的加欄 migration 再跑本命令',
                    $table,
                ));

                return false;
            }
        }

        return true;
    }

    /**
     * 解析 --table：未指定回全部；指定了不在清單內的表名回 null 並印錯誤。
     *
     * @return array<string, class-string<Model>>|null
     */
    private function targetTables(): ?array
    {
        $table = $this->option('table');

        if ($table === null || $table === '') {
            return self::TABLES;
        }

        if (!is_string($table) || !array_key_exists($table, self::TABLES)) {
            $this->error(sprintf(
                '不支援的資料表「%s」，--table 只接受：%s',
                is_string($table) ? $table : gettype($table),
                implode('、', array_keys(self::TABLES)),
            ));

            return null;
        }

        return [$table => self::TABLES[$table]];
    }

    /**
     * 解析 --vendor-id：未指定回 null；不是正整數回 false 並印錯誤。
     */
    private function vendorIdOption(): int|null|false
    {
        $vendorId = $this->option('vendor-id');

        if ($vendorId === null || $vendorId === '') {
            return null;
        }

        if (!is_string($vendorId) || !ctype_digit($vendorId) || (int) $vendorId <= 0) {
            $this->error('--vendor-id 必須是正整數的遊戲商編號');

            return false;
        }

        return (int) $vendorId;
    }
}
