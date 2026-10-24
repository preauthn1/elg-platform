<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\WalletEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 錢包餘額每日校驗（多幣別錢包第一期 1.2）
 *
 * 比對 members.balance 與 member_wallets 一般錢包（type=1）的餘額。觀察期內每日跑，
 * 校驗連續無差異是進入第二期切換寫入的前提（決議⑨）。
 *
 * 未建立影子錢包的會員不算差異——雙寫開關未開啟過時本來就沒有影子資料
 * （inner join 天然排除）。差異的可能來源：stored procedure 或舊系統直寫
 * members.balance（不經 APIs 的派送點）、影子同步 Job 漏派；兩者都靠
 * 覆寫語意的重同步收斂。
 *
 * 告警只帶 member_id 與金額，不帶帳號、姓名、手機等 PII。
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class VerifyMemberWalletBalance extends Command
{
    protected $signature = 'wallet:verify-balance
                            {--limit=1000 : 每批處理的會員數}
                            {--member-id= : 只校驗單一會員（線上排查用；測試亦以此隔離共用測試庫的無關資料）}';

    protected $description = '比對 members.balance 與 member_wallets 一般錢包餘額，差異即告警';

    public function handle(): int
    {
        $diffCount = 0;
        $checked = 0;
        $samples = [];

        Member::query()
            ->select(['members.id', 'members.balance', 'member_wallets.balance as wallet_balance'])
            ->join('member_wallets', function ($join) {
                $join->on('member_wallets.member_id', '=', 'members.id')
                    ->where('member_wallets.wallet_type_id', '=', WalletEnum::CODE_PC->value);
            })
            ->when($this->option('member-id'), fn ($query, $memberId) => $query->where('members.id', $memberId))
            ->chunkById((int) $this->option('limit'), function ($rows) use (&$diffCount, &$checked, &$samples) {
                foreach ($rows as $row) {
                    $checked++;

                    // 外部數值入境即正規化。刻意用 scale 10（不截到舊欄位的 4 位）：
                    // 影子的第 5~10 位若非 0，正是 stored procedure／舊系統直寫的指紋，
                    // 截 4 位會把校驗要抓的東西先磨掉（圓桌審查發現）。
                    $legacy = Common::numAdd('0', (string) $row->balance, 10);
                    $shadow = Common::numAdd('0', (string) $row->wallet_balance, 10);

                    if (Common::numCompare($legacy, $shadow) === 0) {
                        continue;
                    }

                    $diffCount++;

                    if (count($samples) < 20) {
                        $samples[] = "member_id={$row->id} legacy={$legacy} shadow={$shadow}";
                    }
                }
            }, 'members.id', 'id');

        $this->info("已比對 {$checked} 筆，差異 {$diffCount} 筆");

        if ($diffCount > 0) {
            Log::warning('錢包影子餘額校驗發現差異', [
                'checked' => $checked,
                'diff_count' => $diffCount,
                'samples' => $samples,
            ]);

            BotNotify::channel('warn')->send(
                "錢包影子餘額校驗差異 {$diffCount} 筆（已比對 {$checked} 筆）\n"
                . implode("\n", array_slice($samples, 0, 5))
            );
        }

        return self::SUCCESS;
    }
}
