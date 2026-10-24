<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Facades\BotNotify;
use App\Services\CashFlow\Bank\BankActiveDedupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 唯讀稽核：banks 的 active 唯一約束（active_uk 欄位 + uk_banks_active 索引）是否完整存在。
 *
 * 【分類】Bank — 唯讀稽核，排程常駐（退役條件見下方）。
 *
 * 防線可能在無人知情下消失的兩條路徑，皆無其他偵測手段：
 * 1. bank:dedup-rollback 回滾時主動卸鎖且不復原（migration 已記錄為已執行＝migrate 不會補回）；
 * 2. 建立途中第二句 ALTER 失敗留下「有欄位、無索引」半套（唯一性靠 index，半套＝防線不在）。
 *
 * 缺席／半套時 BotNotify 告警並回非零，復原方式＝清理重複後重跑 bank:guard-active-banks。
 * 排程於 routes/console.php 每日執行（各站排程器各自跑＝逐站自查）。
 *
 * ## 退役條件
 *
 * 本命令守的是「active 唯一約束在無人知情下消失」，而兩條消失路徑都來自 dedup 家族
 * （`bank:dedup-rollback` 主動卸鎖不復原、建立途中半套）⇒ **與 `bank:dedup-active`／
 * `bank:dedup-rollback`／`bank:guard-active-banks` 四支同進退，要退一起退。**
 */
class VerifyActiveGuard extends Command
{
    protected $signature = 'bank:verify-guard';

    protected $description = '稽核 banks 的 active 唯一約束是否完整存在（唯讀；缺席或半套即告警）';

    public function handle(BankActiveDedupService $service): int
    {
        $hasColumn = Schema::hasColumn('banks', 'active_uk');
        $hasIndex = count(DB::select("SHOW INDEX FROM `banks` WHERE Key_name = 'uk_banks_active'")) > 0;

        if ($hasColumn && $hasIndex) {
            $this->info('active 唯一約束（uk_banks_active）完整存在。');

            return self::SUCCESS;
        }

        $state = ($hasColumn || $hasIndex)
            ? sprintf('半套（欄位 %s、索引 %s）', $hasColumn ? '存在' : '缺', $hasIndex ? '存在' : '缺')
            : '缺席（欄位與索引皆無）';
        $duplicateGroups = $service->detectDuplicateGroups()->count();

        $message = sprintf(
            '[%s] banks 的 active 唯一約束%s，active 同鍵重複現存 %d 組；請清理後重跑 bank:guard-active-banks 重新上鎖',
            DB::connection()->getDatabaseName(),
            $state,
            $duplicateGroups,
        );

        $this->error($message);
        BotNotify::channel('warn')->send($message);

        return self::FAILURE;
    }
}
