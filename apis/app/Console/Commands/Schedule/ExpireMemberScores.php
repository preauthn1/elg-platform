<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Services\MemberScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 積分到期失效排程（target/tickets#4233）。
 *
 * 發生原因：積分批次各帶 expired_at（發放時刻＋N 小時，再進位到下一個 10 分鐘刻度）；到期後剩餘積分
 * 要扣光、寫一筆「到期失效」異動紀錄，並同步 member_wallets 的積分投影列。
 *
 * 觸發時機：routes/console.php 每 10 分鐘（避開整點）；亦可人工執行。
 * MemberScoreService::deduct() 內另有該會員的即時到期，本排程是全站掃尾，不是唯一防線。
 *
 * 冪等性與重跑安全性：只挑 status=ACTIVE 且 expired_at <= 判定時點的批次；每個會員各自一個
 * DB::transaction，內部先鎖會員再鎖批次；已標 EXPIRED 的批次不會再被撈到，重跑不重複寫紀錄。
 * 單一會員失敗只記錯誤、繼續下一個，不讓一筆漂移卡住全站。
 *
 * 用法：
 *   php artisan score:expire                          # 排程用：以 now() 為判定時點
 *   php artisan score:expire --at="2026-09-21 00:00"  # 人工用：模擬「現在」（+8 輸入），只給測試；排程本身不帶
 *   php artisan score:expire --dry-run                # 只列出會過期哪些批次，不寫入
 *   php artisan score:expire --member=12345           # 只處理一個會員
 */
class ExpireMemberScores extends Command
{
    protected $signature = 'score:expire
                            {--at= : 模擬判定時點（後台時區 +8 輸入，如 2026-09-21 00:00）；未給則為現在}
                            {--dry-run : 乾跑模式，只列出將到期的批次，不寫入資料庫}
                            {--member= : 只處理指定會員 id}';

    protected $description = '將已到期的積分批次標為失效、寫入到期紀錄並同步積分錢包列';

    public function __construct(
        private readonly MemberScoreService $scoreService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $at = $this->resolveAt();
        if ($at === null) {
            return self::FAILURE;
        }

        $execute = !$this->option('dry-run');
        $memberId = $this->option('member') !== null ? (int) $this->option('member') : null;

        $dueQuery = MemberScoreBatch::query()
            ->dueAt($at)
            ->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId));

        if (!$execute) {
            $plan = $dueQuery->clone()
                ->orderBy('member_id')
                ->orderBy('expired_at')
                ->get(['id', 'member_id', 'remaining_score', 'expired_at'])
                ->map(fn (MemberScoreBatch $b) => [
                    $b->id,
                    $b->member_id,
                    (string) $b->remaining_score,
                    $b->expired_at?->toBackendTime()->toDateTimeString(),
                ])
                ->all();

            $this->table(['batch_id', 'member_id', 'remaining_score', 'expired_at(+8)'], $plan);
            $this->warn(sprintf(
                'dry-run：判定時點 %s（UTC），共 %d 批將到期失效，移除 --dry-run 實際執行。',
                $at->toDateTimeString(),
                count($plan),
            ));

            return self::SUCCESS;
        }

        $memberIds = $dueQuery->clone()->distinct()->pluck('member_id');

        $processedMembers = 0;
        $expiredBatches = 0;
        $failed = 0;

        foreach ($memberIds as $id) {
            $member = Member::query()->find($id);
            if ($member === null) {
                $this->warn("member {$id} 不存在，略過其到期批次。");

                continue;
            }

            try {
                $logs = DB::transaction(fn () => $this->scoreService->expireMember($member, $at));
                $processedMembers++;
                $expiredBatches += $logs->count();
            } catch (Throwable $e) {
                $failed++;
                $this->error("member {$id} 到期處理失敗：{$e->getMessage()}");
                report($e);
            }
        }

        $this->info(sprintf(
            '判定時點 %s（UTC）：處理 %d 位會員、%d 批到期失效，失敗 %d 位。',
            $at->toDateTimeString(),
            $processedMembers,
            $expiredBatches,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * --at 以後台時區（+8）解析後轉系統時區（UTC）；未給則為現在。格式錯誤回 null 並報錯。
     */
    private function resolveAt(): ?Carbon
    {
        $raw = $this->option('at');

        if ($raw === null || $raw === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse((string) $raw, config('custom.backend.timezone'))->toSystemTime();
        } catch (Throwable) {
            $this->error("--at 格式無法解析：{$raw}（範例：2026-09-21 00:00）");

            return null;
        }
    }
}
