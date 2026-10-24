<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\WalletEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberWallet;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 積分投影列每日校驗（target/tickets#4233）。
 *
 * 發生原因：member_wallets 的 CODE_SCORE 列只是 member_score_batches 的總額投影；批次表才是真相。
 * 正常路徑三處在同一交易內更新不會漂移，但直接動表（dev 重建 schema、人工補資料、日後舊系統誤寫）
 * 會讓投影對不上——本命令比對「有效批次剩餘加總」與「錢包列餘額」，差異告警，--fix 以批次表為準重算。
 *
 * 口徑：expected = SUM(remaining_score) WHERE status = ACTIVE，**不看 expired_at**——
 * 投影跟著 status 走（到期排程把批次標 EXPIRED 時同步扣錢包列），已到期但排程還沒掃到的批次
 * 兩邊都還算在內，不是差異；用時間過濾會在每 10 分鐘的排程間隔內誤報。
 *
 * 觸發時機：每日排程（routes/console.php），排在 wallet:verify-balance 之後；亦可人工執行。
 * 冪等性：唯讀比對；--fix 在交易內鎖會員與錢包列後重算寫回，重跑無副作用；
 * 單一會員撞鎖／失敗只記錯誤、續跑下一位，結尾回報失敗數。
 * 告警只帶 member_id 與金額，不帶 PII。
 *
 * 用法：
 *   php artisan score:verify-balance                    # 全站比對，只告警
 *   php artisan score:verify-balance --member=12345     # 單一會員
 *   php artisan score:verify-balance --member=12345 --fix   # 以批次表為準重算該會員錢包列
 */
class VerifyMemberScoreBalance extends Command
{
    protected $signature = 'score:verify-balance
                            {--limit=1000 : 每批處理的會員數}
                            {--member= : 只校驗單一會員 id（線上排查與測試隔離用）}
                            {--fix : 差異時以批次表為準重算錢包列（交易內鎖會員與錢包列）}';

    protected $description = '比對積分批次剩餘加總與積分錢包投影列，差異告警，--fix 以批次為準重算';

    public function __construct(
        private readonly WalletService $walletService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $memberId = $this->option('member') !== null ? (int) $this->option('member') : null;

        $checked = 0;
        $diffCount = 0;
        $fixed = 0;
        $fixFailed = 0;
        $samples = [];

        // 會員範圍：有積分錢包列的 ∪ 有批次的（兩邊任一有列就要對）
        $memberIds = MemberWallet::query()
            ->where('wallet_type_id', WalletEnum::CODE_SCORE->value)
            ->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))
            ->select('member_id')
            ->union(
                MemberScoreBatch::query()
                    ->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))
                    ->select('member_id')
            )
            ->pluck('member_id')
            ->unique()
            ->values();

        foreach ($memberIds->chunk((int) $this->option('limit')) as $chunk) {
            $expectedByMember = MemberScoreBatch::query()
                ->whereIn('member_id', $chunk)
                ->where('status', ScoreBatchStatusEnum::ACTIVE->value)
                ->groupBy('member_id')
                ->selectRaw('member_id, SUM(remaining_score) AS expected')
                ->pluck('expected', 'member_id');

            $walletByMember = MemberWallet::query()
                ->whereIn('member_id', $chunk)
                ->where('wallet_type_id', WalletEnum::CODE_SCORE->value)
                ->pluck('balance', 'member_id');

            foreach ($chunk as $id) {
                $checked++;

                $expected = Common::numAdd('0', (string) ($expectedByMember[$id] ?? '0'), 4);
                $actual = Common::numAdd('0', (string) ($walletByMember[$id] ?? '0'), 4);

                if (Common::numCompare($expected, $actual, 4) === 0) {
                    continue;
                }

                $diffCount++;
                if (count($samples) < 20) {
                    $samples[] = "member_id={$id} batches={$expected} wallet={$actual}";
                }

                if ($fix) {
                    $this->fixMember((int) $id) ? $fixed++ : $fixFailed++;
                }
            }
        }

        $this->info(sprintf('已比對 %d 位會員，差異 %d 位%s', $checked, $diffCount, $fix ? "，已重算 {$fixed} 位、失敗 {$fixFailed} 位" : ''));
        foreach ($samples as $sample) {
            $this->line('  ' . $sample);
        }

        if ($diffCount > 0) {
            Log::warning('積分投影列校驗發現差異', [
                'checked' => $checked,
                'diff_count' => $diffCount,
                'fixed' => $fixed,
                'fix_failed' => $fixFailed,
                'samples' => $samples,
            ]);

            if (!$fix) {
                BotNotify::channel('warn')->send(
                    "積分投影列校驗差異 {$diffCount} 位（已比對 {$checked} 位）\n"
                    . implode("\n", array_slice($samples, 0, 5))
                );
            }
        }

        return $fixFailed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 以批次表為準重算單一會員的錢包投影列：鎖會員主鍵列 → 鎖錢包列 → 交易內重算 SUM → 寫回。
     * 不反向修批次。失敗（撞鎖、Deadlock）只記錯誤回 false，由呼叫端續跑下一位，不讓一位卡住整批。
     */
    private function fixMember(int $memberId): bool
    {
        try {
            return $this->recalculateMember($memberId);
        } catch (Throwable $e) {
            $this->error("member {$memberId} 重算失敗：{$e->getMessage()}");
            report($e);

            return false;
        }
    }

    private function recalculateMember(int $memberId): bool
    {
        return DB::transaction(function () use ($memberId): bool {
            $member = Member::query()->lockForUpdate()->find($memberId);
            if ($member === null) {
                $this->warn("member {$memberId} 不存在，略過重算。");

                return false;
            }

            $wallet = $this->walletService->getLockedWallet($member->id, WalletEnum::CODE_SCORE);
            if ($wallet === null) {
                return false;
            }

            $expected = Common::numAdd('0', (string) MemberScoreBatch::query()
                ->where('member_id', $member->id)
                ->where('status', ScoreBatchStatusEnum::ACTIVE->value)
                ->lockForUpdate()
                ->sum('remaining_score'), 4);

            $wallet->balance = $expected;
            $wallet->admin = 0;
            $wallet->save();

            return true;
        });
    }
}
