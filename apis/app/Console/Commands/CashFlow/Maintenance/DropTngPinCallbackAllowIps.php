<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 清掉 TngPin 渠道 `agent_cash_flows.info` 裡已停用的 `CallbackAllowIps`（工單 target/tickets#4449）。
 *
 * 【分類】Maintenance — 可複用維運工具，需要時才跑。
 *
 * ## 發生原因
 * TngPin 的回調來源 IP 白名單原本讀 `info['CallbackAllowIps']`（而且**未設定就放行**）。
 * 需求 3 改成讀 `cash_flows.whitelist` 且 fail-closed 之後，`guardCallbackIp()` 連同這個鍵的
 * 讀取端一起移除，殘留的值變成沒有人讀的死設定——留著的代價是下次有人要改 IP 時改錯地方，
 * 看起來有設、實際上不生效。
 *
 * ## 🔴 執行前必須確認：該站點的 APIs 已經上到含 target/tickets#4449 的版本
 * 舊版的判斷是「未設定 CallbackAllowIps 就放行」，所以**在還沒上版的站點清掉這個鍵，
 * 等於把 TngPin 的來源 IP 防線直接拿掉**（簽章以外就沒有第二道了）。
 * 判斷方式：該站的 `TngPin\DepositCallback` 若還呼叫 `guardCallbackIp()` 就是舊版，不可執行。
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 只在 `info` 真的有該鍵時才改寫，其餘鍵與值一律不動。
 *
 * ## 退役條件
 * 所有站點都清完之後即可刪除本命令。
 *
 * 用法：
 *   php artisan fix:drop-tngpin-callback-allow-ips --dry-run   # 預覽
 *   php artisan fix:drop-tngpin-callback-allow-ips             # 實際寫入
 */
class DropTngPinCallbackAllowIps extends Command
{
    private const string INFO_KEY = 'CallbackAllowIps';

    protected $signature = 'fix:drop-tngpin-callback-allow-ips {--dry-run : 只預覽變更，不寫入資料庫}';

    protected $description = '清除 TngPin 渠道 info 裡已停用的 CallbackAllowIps（白名單改讀 cash_flows.whitelist）';

    public function handle(): int
    {
        $cashFlowIds = CashFlow::query()
            ->where('third_party_code', 'like', '%TNGPIN%')
            ->pluck('id');

        if ($cashFlowIds->isEmpty()) {
            $this->warn('本站沒有 TngPin 渠道，無事可做');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        foreach (AgentCashFlow::query()->whereIn('cash_flow_id', $cashFlowIds)->get() as $record) {
            $info = is_array($record->info) ? $record->info : json_decode((string) $record->info, true);

            if (!is_array($info) || !array_key_exists(self::INFO_KEY, $info)) {
                continue;
            }

            $this->line(sprintf(
                'acf#%d（cash_flow_id=%d）移除 %s = %s',
                $record->id,
                $record->cash_flow_id,
                self::INFO_KEY,
                is_scalar($info[self::INFO_KEY]) ? (string) $info[self::INFO_KEY] : '[' . gettype($info[self::INFO_KEY]) . ']'
            ));

            unset($info[self::INFO_KEY]);
            $changed++;

            if ($dryRun) {
                continue;
            }

            // 只動這一欄，避免 model 的其他 cast／observer 介入改到別的值
            DB::table('agent_cash_flows')
                ->where('id', $record->id)
                ->update(['info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }

        $this->info($dryRun
            ? "=== 乾跑模式：共 {$changed} 筆含該鍵，未寫入 ==="
            : "已清除 {$changed} 筆的 " . self::INFO_KEY);

        return self::SUCCESS;
    }
}
