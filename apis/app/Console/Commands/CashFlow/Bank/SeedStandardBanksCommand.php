<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Helpers\Common;
use App\Models\StandardBank;
use Database\Seeders\SyncStandardBanksSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 手動維運入口；同步邏輯單一來源＝SyncStandardBanksSeeder（app:upgrade 隨部署自動執行同一支）。
 *
 * 【分類】Bank — 手動維運入口，長期保留。
 *
 * ## 退役條件
 *
 * **不設退役條件。** 同步邏輯的單一來源是 `SyncStandardBanksSeeder`（`app:upgrade` 隨部署
 * 執行同一支），本命令只是「不等部署就先跑一次」的手動入口。
 *
 * 🔴 注意：跑本命令只是更新 `standard_banks` registry，**不會**改動 `banks.standard_bank_id`；
 * 要讓新條目真正生效必須另外跑 `bank:sync-mappings`。
 *
 * @knowledge rules/standard-bank-list
 */
class SeedStandardBanksCommand extends Command
{
    protected $signature = 'cash-flow:seed-standard-banks {--dry-run : 僅預覽不寫入}';

    protected $description = '從各幣別標準銀行分檔（app/Console/Commands/CashFlow/Data/banks）冪等 upsert standard_banks（rich schema）';

    public function handle(SyncStandardBanksSeeder $seeder): int
    {
        ['rows' => $rows, 'skipped' => $skipped] = $seeder->buildRows();

        $this->info(sprintf(
            'config 條目 %d，可 seed %d（其中 %d 筆已併入別家、會設成停用），略過 %d',
            count($rows) + count($skipped),
            count($rows),
            count(array_filter($rows, static fn (array $row): bool => $row['retired'])),
            count($skipped),
        ));
        foreach ($skipped as $s) {
            $this->warn("  略過 {$s}");
        }

        if ($this->option('dry-run')) {
            $this->info('  [dry-run] 未寫入');

            return self::SUCCESS;
        }

        try {
            $changed = $seeder->sync();
        } catch (Throwable $e) {
            Log::error('seed-standard-banks failed', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return self::FAILURE;
        }

        $this->info(sprintf('  upsert %d 筆（實際變更 %d）；standard_banks 現有 %d 筆', count($rows), $changed, StandardBank::query()->count()));

        return self::SUCCESS;
    }
}
