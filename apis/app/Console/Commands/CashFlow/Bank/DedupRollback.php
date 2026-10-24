<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Facades\BotNotify;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 依 bank_dedup_logs 反向回滾 bank:dedup-active 的清理。
 *
 * 【分類】Bank — 只服務 bank:dedup-active 的回滾出口，與它同進退（退役條件見下方）。
 *
 * 反向重放（id DESC / LIFO）：deactivate_bank → 復活 loser；deactivate_binding → 復活綁定；
 * repoint → 綁定改回 loser + 出款訂單還原（bank_id/bank_name 回 loser；code 同鍵不變）；
 * repoint_realname_request → 待審核實名件 bank_id 改回 loser。
 * 若 active 唯一約束（active_uk）已上，必須先卸除否則 loser 復活會撞 unique。
 *
 * 🔴 **卸了不會重建**：本命令會 `DROP INDEX uk_banks_active` 並 `DROP COLUMN active_uk`，
 * 但全程沒有任何重建動作——跑完之後「同鍵不得有第二筆 active」這道防線就一直是關的，
 * 必須另行跑 bank:guard-active-banks 把 generated column 與唯一索引掛回，否則重複列會無聲再生。
 *
 * 🔴 **禁止全量跑**：本命令沒有任何範圍選項，`--execute` 就是把 bank_dedup_logs 裡
 * 整段 dedup 歷史一次反轉——那是總帳，不是單次操作的紀錄。要回滾特定範圍，
 * 先確認清楚要動哪幾列再逐列處理，不要直接跑這支。
 *
 * 🔴 **多 loser 時訂單歸屬有歧義**：repoint 的反向是「該會員在 survivor 底下的所有出款訂單
 * 一次改回單一 loser_bank_id」。同一組若有兩個以上 loser 且同一會員都綁過，回滾必把部分訂單
 * 指到錯的 loser。跑之前先查 bank_dedup_logs 確認該組是不是一對一。
 *
 * 預設 dry-run；flagged_manual_review 列無實際變更、不處理。
 *
 * ## 退役條件
 *
 * 隨 `bank:dedup-active` 一併退役——沒有那支的清理，就沒有本支要回滾的東西。
 *
 * ⚠️ 刪除前要留意：本命令是 active 唯一約束「在無人知情下消失」的兩條路徑之一
 * （回滾時主動卸鎖且不復原，migration 已記為已執行故 migrate 不會補回）。
 * `bank:verify-guard` 與 `bank:guard-active-banks` 的存在理由有一半來自這裡，
 * 三支的退役要一起評估。
 */
class DedupRollback extends Command
{
    use PreservesRowTimestamps;

    protected $signature = 'bank:dedup-rollback
                            {--execute : 實際執行回滾（預設僅 dry-run）}
                            {--force : 跳過確認}';

    protected $description = '依 bank_dedup_logs 反向回滾 bank:dedup-active 的清理（必要時先卸 active 唯一約束）';

    public function handle(): int
    {
        $logs = DB::table('bank_dedup_logs')
            ->whereIn('action', ['repoint', 'deactivate_binding', 'deactivate_bank', 'soft_delete_bank', 'repoint_realname_request'])
            ->orderByDesc('id')
            ->get();

        // column／index 各自判存在（半套狀態也能收乾淨；無條件 DROP 在半套下會炸 1091）
        $guardHasColumn = Schema::hasColumn('banks', 'active_uk');
        $guardHasIndex = count(DB::select("SHOW INDEX FROM `banks` WHERE Key_name = 'uk_banks_active'")) > 0;
        $guardExists = $guardHasColumn || $guardHasIndex;

        if ($logs->isEmpty() && !$guardExists) {
            $this->info('無可回滾的清理紀錄，且未發現 active 唯一約束。');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '待回滾：%d 筆審計動作%s',
            $logs->count(),
            $guardExists ? '；偵測到 active 唯一約束（將先卸除）' : ''
        ));

        if (!$this->option('execute')) {
            $this->info('dry-run 模式，未寫入。加 --execute 以實際回滾。');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認回滾？此操作將復原合併並卸除 active 唯一約束', false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        // 先卸唯一約束（DDL 不可在交易內），否則 loser 復活會撞 unique
        if ($guardHasIndex) {
            DB::statement('ALTER TABLE banks DROP INDEX uk_banks_active');
        }

        if ($guardHasColumn) {
            DB::statement('ALTER TABLE banks DROP COLUMN active_uk');
        }

        $reversed = ['repoint' => 0, 'deactivate_binding' => 0, 'deactivate_bank' => 0, 'soft_delete_bank' => 0, 'repoint_realname_request' => 0];

        DB::transaction(function () use ($logs, &$reversed): void {
            foreach ($logs as $log) {
                match ($log->action) {
                    'deactivate_bank' => $this->reverseDeactivateBank($log),
                    'deactivate_binding' => $this->reverseDeactivateBinding($log),
                    'repoint' => $this->reverseRepoint($log),
                    'soft_delete_bank' => $this->reverseSoftDeleteBank($log),
                    'repoint_realname_request' => $this->reverseRepointRealnameRequest($log),
                    default => null,
                };
                $reversed[$log->action]++;
            }
        });

        $this->info(sprintf(
            '回滾完成：repoint %d、停用綁定 %d、停用銀行 %d、還原標記刪除 %d、實名件 re-point %d%s。',
            $reversed['repoint'],
            $reversed['deactivate_binding'],
            $reversed['deactivate_bank'],
            $reversed['soft_delete_bank'],
            $reversed['repoint_realname_request'],
            $guardExists ? '（已卸 active 唯一約束）' : ''
        ));

        // 卸鎖不會自動復原，且 migration 已記錄為已執行＝後續 migrate 也不會補回；
        // 防線消失若無告警，發現量級是「週」——這裡主動通報並給出重上鎖路徑。
        if ($guardExists) {
            $databaseName = DB::connection()->getDatabaseName();
            $this->warn('⚠ active 唯一約束（uk_banks_active）已卸除且不會自動復原：請於資料清理完成後重跑 bank:guard-active-banks 重新上鎖。');
            BotNotify::channel('warn')->send(sprintf(
                '[%s] banks 的 active 唯一約束（uk_banks_active）已隨 bank:dedup-rollback 卸除、尚未復原，請清理後重跑 bank:guard-active-banks',
                $databaseName,
            ));
        }

        return self::SUCCESS;
    }

    private function reverseDeactivateBank(object $log): void
    {
        DB::table('banks')->where('id', $log->loser_bank_id)->update(['status' => 1]);
    }

    private function reverseSoftDeleteBank(object $log): void
    {
        DB::table('banks')->where('id', $log->loser_bank_id)->update(['deleted_at' => null]);
    }

    private function reverseDeactivateBinding(object $log): void
    {
        DB::table('member_bank_accounts')->where('id', $log->member_bank_account_id)->update($this->preservingUpdatedAt(['status' => 1]));
    }

    private function reverseRepointRealnameRequest(object $log): void
    {
        // detail 格式固定 'request_id=<id>'（writeAudit repoint_realname_request 唯一寫入點）
        $requestId = (int) str_replace('request_id=', '', (string) $log->detail);

        DB::table('realname_validation_requests')
            ->where('id', $requestId)
            ->update($this->preservingUpdatedAt(['bank_id' => $log->loser_bank_id]));
    }

    private function reverseRepoint(object $log): void
    {
        DB::table('member_bank_accounts')
            ->where('id', $log->member_bank_account_id)
            ->update($this->preservingUpdatedAt(['bank_id' => $log->loser_bank_id]));

        $loserName = (string) DB::table('banks')->where('id', $log->loser_bank_id)->value('name');

        DB::table('withdrawal_orders')
            ->where('member_id', $log->member_id)
            ->where('bank_id', $log->survivor_bank_id)
            ->update($this->preservingUpdatedAt([
                'bank_id' => $log->loser_bank_id,
                'bank_name' => $loserName,
            ]));
    }
}
