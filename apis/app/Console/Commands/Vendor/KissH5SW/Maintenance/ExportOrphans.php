<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\KissH5SW\Maintenance;

use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Console\Command;
use Throwable;

/**
 * 匯出 KissH5SW 孤兒注單（`type=N`、`status=0`、超過指定時間仍未結算）為 CSV。
 *
 * 【分類】Maintenance — 可複用的孤兒偵測工具，純讀、需要時才跑。
 *
 * > 2026-07-28 從 `Patches/PrecisionBug2026May/` 移入本目錄：孤兒**不是**單一事故的殘留，
 * > 而是持續可能發生的狀態（見下），原分類會誤導成「事故結案即可刪」。
 *
 * ## 發生原因
 * 單一錢包的注單分兩段：`Bet` 回調先扣款並寫入 `status=0`，`GameResult` 回調才結算轉 `status=1`。
 * 若第二段從未到達，該筆就永遠停在未結算——**玩家的錢已被扣走且不會自動恢復**。成因有二：
 *
 * 1. **廠商端未發 GameResult**（主因）——KissH5 服務異常時段的部分交易既未結算也未 Rollback。
 *    2026-07-28 盤點的 26 筆孤兒中佔 20 筆，且**仍在新產生**（Yes2Win 2026-07-24）
 * 2. **2026-05 精度 bug 的配對汙染**——17 位 BetId 以 integer 進 `where()` 時 MySQL 轉 float
 *    比對誤命中相鄰注單（已由 `f9e835c72` 修復）；26 筆中佔 6 筆，全在 TPower
 *
 * ## 修正目的
 * 本命令**只匯出不修改**：把孤兒明細（`bet_id` / `member_id` / `bet` / `bet_time` 等）輸出成 CSV，
 * 供 ①向 KissH5 索取對應原始逐筆資料時附上 BetId 清單 ②修復後複驗清零。
 * 實際修復走 {@see RepairOrphans}（同目錄）。
 *
 * ## 觸發時機
 * - 定期巡檢——**穩態下應為 0 筆**；非零即代表廠商端有服務異常，需向 KissH5 反映該時間窗
 * - 客訴「注單沒結算 / 錢被扣了沒下文」
 * - 修復執行前後各跑一次，比對筆數
 *
 * ## 冪等性與重跑安全性
 * **完全安全**：純 `SELECT`，不寫任何資料表；零筆時直接回 SUCCESS 不產檔。
 * 每次執行以時間戳產生新 CSV（`storage/logs/kissh5sw_orphans_<YmdHis>.csv`），不覆蓋舊檔。
 *
 * ## 退役條件
 * **無固定退役條件**——只要 KissH5 仍在線上、孤兒仍可能產生，本命令就該留著。
 * 若日後平台停止接入 KissH5SW（`config/vendor.php` 移除 vendor_id 233），連同整個
 * `Vendor/KissH5SW/` 目錄一併移除。
 *
 * ## 關聯
 * - 機制與處置通則：`docs/integrations/vendors/KissH5/integration.md` §4（大整數精度）／§5（孤兒）
 * - 對帳流程：`docs/reconcile/vendors/KissH5SW.md`
 * - 修復命令：`kissh5sw:repair-orphans`（需 K5 直出的 21 欄位逐筆 CSV）
 *
 * ## 用法
 *   php artisan kissh5sw:export-orphans              # 預設：24 小時前仍未結算者
 *   php artisan kissh5sw:export-orphans --hours=1    # 縮短閾值，抓更即時的孤兒
 */
class ExportOrphans extends Command
{
    protected $signature = 'kissh5sw:export-orphans {--hours=24 : 視為孤兒的閾值（小時），bet_time 距今超過此時數仍未結算的記錄}';

    protected $description = '匯出 KissH5SW 孤兒注單（status=0 未結算）為 CSV，供人工補帳';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours)->format('Y-m-d H:i:s');

        $this->info('========================================');
        $this->info('📦 KissH5SW 孤兒注單匯出');
        $this->info('========================================');
        $this->info('閾值: bet_time < ' . $cutoff . ' (UTC，距今 ' . $hours . ' 小時前)');

        $records = KissH5SWBetRecord::query()
            ->where('type', 'N')
            ->where('status', 0)
            ->where('bet_time', '<', $cutoff)
            ->orderBy('bet_time')
            ->get([
                'id',
                'member_id',
                'username',
                'round_id',
                'bet_id',
                'reference_no',
                'game_code',
                'bet',
                'valid_bet',
                'bet_time',
                'created_at',
            ]);

        $count = $records->count();
        $this->info('找到 ' . $count . ' 筆孤兒記錄');

        if ($count === 0) {
            $this->info('✅ 沒有孤兒記錄需要匯出。');

            return self::SUCCESS;
        }

        $totalBet = $records->sum(fn ($r) => (float) $r->bet);
        $this->info('投注金額總和（已扣未結算）: ' . number_format($totalBet, 4) . ' 本地單位');

        $path = storage_path('logs/kissh5sw_orphans_' . now()->format('YmdHis') . '.csv');

        try {
            $this->writeCsv($path, $records);
        } catch (Throwable $e) {
            $this->error('❌ CSV 寫入失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('📄 已匯出至：' . $path);
        $this->line('   CSV 欄位：id, member_id, username, round_id, bet_id, reference_no, game_code, bet, valid_bet, bet_time, created_at');

        return self::SUCCESS;
    }

    private function writeCsv(string $path, iterable $records): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException('無法開啟檔案：' . $path);
        }

        // UTF-8 BOM，方便 Excel 直接打開
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            'id',
            'member_id',
            'username',
            'round_id',
            'bet_id',
            'reference_no',
            'game_code',
            'bet',
            'valid_bet',
            'bet_time',
            'created_at',
        ]);

        foreach ($records as $r) {
            fputcsv($fp, [
                $r->id,
                $r->member_id,
                $r->username,
                $r->round_id,
                $r->bet_id,
                $r->reference_no,
                $r->game_code,
                $r->bet,
                $r->valid_bet,
                $r->bet_time,
                $r->created_at,
            ]);
        }

        fclose($fp);
    }
}
