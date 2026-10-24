<?php

declare(strict_types=1);

namespace App\Console\Commands\Member;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填 member_login_ip_logs.ip_address（完整 IP 字串）。
 *
 * ip_address 為 3963 新增的統一 IP 欄位（IPv4/IPv6 通用）。舊資料僅有四段
 * ip_1~ip_4（皆 IPv4），需組成點分字串補進 ip_address。
 *
 * 冪等、可斷可續：只更新 ip_address IS NULL 的列（已回填者不碰），分批以主鍵游標
 * 前進，千萬級大表不一次鎖表；中斷後重跑接續未完成部分。六站各跑一次。
 *
 * 用法：
 *   php artisan member:backfill-ip-address                    # dry-run（僅報待回填筆數）
 *   php artisan member:backfill-ip-address --execute          # 實際回填
 *   php artisan member:backfill-ip-address --execute --chunk=10000
 *   php artisan member:backfill-ip-address --execute --connection=TPower
 *
 * target/tickets#3963
 */
class BackfillMemberLoginIpAddress extends Command
{
    protected $signature = 'member:backfill-ip-address
                            {--execute : 實際執行回填（預設僅 dry-run）}
                            {--chunk=5000 : 每批處理筆數}
                            {--connection= : 指定 DB 連線（預設當前連線）}';

    protected $description = '回填 member_login_ip_logs.ip_address（由四段 ip_1~ip_4 組字串，僅補空值、冪等可續）';

    public function handle(): int
    {
        $conn = $this->option('connection') ?: null;
        $chunk = max(100, (int) $this->option('chunk'));
        $db = DB::connection($conn);

        $pending = $db->table('member_login_ip_logs')->whereNull('ip_address')->count();
        $this->info('連線：' . ($conn ?: '(預設)') . '　待回填（ip_address 為空）：' . number_format($pending) . ' 筆');

        if ($pending === 0) {
            $this->info('✅ 無需回填。');

            return self::SUCCESS;
        }

        if (!$this->option('execute')) {
            $this->warn('dry-run：未加 --execute，不寫入。');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($pending);
        $bar->start();

        $updated = 0;
        // 以主鍵游標前進，只掃 ip_address 為空的列；每批一句 UPDATE（避免逐筆往返）
        $lastId = 0;
        while (true) {
            $ids = $db->table('member_login_ip_logs')
                ->whereNull('ip_address')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // 一句 UPDATE 處理整批：ip_address = 四段以 '.' 串接（DB 端 CONCAT_WS）
            $updated += $db->table('member_login_ip_logs')
                ->whereIn('id', $ids->all())
                ->whereNull('ip_address')  // 再次確認空值，避免併發重複覆寫
                ->update([
                    'ip_address' => DB::raw("CONCAT_WS('.', ip_1, ip_2, ip_3, ip_4)"),
                ]);

            $lastId = $ids->last();
            $bar->advance($ids->count());
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ 完成，回填 ' . number_format($updated) . ' 筆。');

        return self::SUCCESS;
    }
}
