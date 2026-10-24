<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填 withdrawal_orders.point（歷史代付單 point 結構性為 0，金額在 amount）。
 *
 * 背景：代付建單流程原先未寫入 point（見 WithdrawalController，已補 'point' => $point = amount × rate）。
 * 3967 代付小時匯總取 point，故需將歷史單的 point 依 amount × rate 補齊。
 *
 * 規則：
 *  - 只補 point <= 0 且 amount > 0 的單（不覆蓋已有值）。
 *  - point = amount × rate，走 DB decimal 乘法（精確；withdrawal_orders.point 為 decimal(18,4)）。
 *  - 依 id 分批，避免大表鎖與（極小 rate 四捨五入為 0 造成的）重複掃描。
 *  - 不改 updated_at（代付小時匯總以 updated_at 分桶，動到會位移桶）。
 *  - 可選 --status 限縮（預設全部；nothing 讀 withdrawal_orders.point 除 3967，無 blast radius）。
 *
 * 升級用法：
 *   php artisan patch:backfill-withdrawal-point --dry-run
 *   php artisan patch:backfill-withdrawal-point
 *
 * target/tickets#3967
 */
class BackfillWithdrawalPoint extends Command
{
    protected $signature = 'patch:backfill-withdrawal-point
        {--chunk=5000 : 每批處理的 id 區間大小}
        {--status= : 只補指定 status（預設全部）}
        {--dry-run : 只預覽待補筆數與範例，不寫入}';

    protected $description = '回填 withdrawal_orders.point（= amount × rate），補齊歷史代付單的平台點數';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $status = $this->option('status');
        $status = ($status === null || $status === '') ? null : (int) $status;

        $baseWhere = fn ($q) => $q
            ->where('point', '<=', 0)
            ->where('amount', '>', 0)
            ->when($status !== null, fn ($qq) => $qq->where('status', $status));

        $pending = (int) $baseWhere(DB::table('withdrawal_orders'))->count();
        $this->info(sprintf(
            '待補（point<=0 且 amount>0%s）：%d 筆',
            $status !== null ? "、status={$status}" : '',
            $pending
        ));

        if ($pending === 0) {
            $this->info('無待補資料，略過。');

            return self::SUCCESS;
        }

        // 範例預覽：point = amount × rate
        $samples = $baseWhere(DB::table('withdrawal_orders'))
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'amount', 'rate']);
        foreach ($samples as $s) {
            $this->line(sprintf('  id=%s amount=%s rate=%s → point=%s', $s->id, $s->amount, $s->rate, $s->amount * $s->rate));
        }

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN：不寫入資料庫');

            return self::SUCCESS;
        }

        $maxId = (int) DB::table('withdrawal_orders')->max('id');
        $affected = 0;
        $statusClause = $status !== null ? ' AND status = ?' : '';

        for ($from = 0; $from < $maxId; $from += $chunk) {
            $to = $from + $chunk;
            $bindings = [$from, $to];
            if ($status !== null) {
                $bindings[] = $status;
            }

            // 不動 updated_at（避免位移小時匯總分桶）；point = amount × rate 走 DB decimal 乘法
            $affected += DB::update(
                "UPDATE withdrawal_orders
                 SET point = amount * rate
                 WHERE point <= 0 AND amount > 0 AND id > ? AND id <= ?{$statusClause}",
                $bindings
            );
        }

        $this->info(sprintf('完成：已回填 %d 筆 withdrawal_orders.point。', $affected));

        return self::SUCCESS;
    }
}
