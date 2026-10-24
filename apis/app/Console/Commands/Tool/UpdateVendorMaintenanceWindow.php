<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan tool:update-vendor-maintenance-window
 * # 設定維護時段
 * php artisan tool:update-vendor-maintenance-window --start=19:00 --end=20:15
 *
 * # 清除維護時段（不傳 options 會互動式詢問，留空即清除）
 * php artisan tool:update-vendor-maintenance-window --start= --end=
 */
class UpdateVendorMaintenanceWindow extends Command
{
    protected $signature = 'tool:update-vendor-maintenance-window
                            {--start= : 維護開始時間 HH:MM（留空表示清除）}
                            {--end=   : 維護結束時間 HH:MM（留空表示清除）}';

    protected $description = '全站更新 Haolong(4) 與 New4d2026(181) 的 example.invalid 維護時段';

    private const VENDOR_IDS = [
        VendorIdEnum::HAOLONG->value,
        VendorIdEnum::NEW4D2026->value,
    ];

    public function handle(): int
    {
        $start = $this->option('start') ?? $this->ask('維護開始時間 HH:MM（留空表示清除）') ?? '';
        $end = $this->option('end') ?? $this->ask('維護結束時間 HH:MM（留空表示清除）') ?? '';

        $this->info('維護時段：' . ($start !== '' ? $start : '（清除）') . ' ~ ' . ($end !== '' ? $end : '（清除）'));
        $this->newLine();

        /** @var array<string, mixed> $dbs */
        $dbs = require app_path('Console/Commands/CashFlow/Data/site_environments.php');

        foreach (array_keys($dbs) as $connection) {
            $this->line("[{$connection}]");

            foreach (self::VENDOR_IDS as $vendorId) {
                try {
                    $vendor = DB::connection($connection)
                        ->table('vendors')
                        ->where('id', $vendorId)
                        ->first(['id', 'name', 'info']);

                    if (!$vendor) {
                        $this->warn("  vendor {$vendorId} 不存在，跳過");

                        continue;
                    }

                    $decoded = json_decode($vendor->info ?? '{}', true);
                    $info = is_array($decoded) ? $decoded : [];
                    $info['maintenance_start'] = $start;
                    $info['maintenance_end'] = $end;

                    DB::connection($connection)
                        ->table('vendors')
                        ->where('id', $vendorId)
                        ->update(['info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);

                    $this->info("  ✓ vendor {$vendorId} ({$vendor->name}) 更新完成");
                } catch (Throwable $e) {
                    $this->error("  ✗ vendor {$vendorId} 失敗：{$e->getMessage()}");

                    return Command::FAILURE;
                }
            }
        }

        $this->newLine();
        $this->info('全站更新完成');

        return Command::SUCCESS;
    }
}
