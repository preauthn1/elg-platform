<?php

declare(strict_types=1);

namespace App\Console\Commands\Score;

use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Exceptions\InsufficientScoreException;
use App\Models\Member;
use App\Models\MemberScoreLog;
use App\Services\MemberScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 人工發放／扣除會員積分（target/tickets#4233）。
 *
 * 發生原因：工單的測試步驟寫「請開發給一個會員建三批積分、扣 250、跑到期、扣 999」，
 * 後台開洗分 › 積分頁籤與活動消耗（#4231）落地前，dev／QA 沒有任何入口能碰到 FIFO 扣點。
 * 本命令是那段「請開發協助操作」的正式載體——走 MemberScoreService 同一套邏輯，
 * 不是繞過 Service 的臨時 SQL（whitelabel-container-exec 禁止裸寫）。
 *
 * 用途：dev／QA 環境驗收 FIFO、到期、餘額不足；正式環境僅限人工補正且需明確確認。
 *
 * 冪等性：每次執行都是一筆新的異動（發放建新批次、扣除寫新 log），重跑會重複生效——
 * 這是人工操作的本意，不是排程。--dry-run 在交易內跑完後回滾，只看結果不落庫。
 *
 * 紀錄：source_type／type 皆為 MANUAL，operator 寫 --operator（預設 cli），admin 為 0。
 *
 * 用法：
 *   php artisan score:adjust grant 12345 100 --hours=384 --remark="QA 批次 A"   # 發 100 分、384 小時後到期
 *   php artisan score:adjust grant 12345 50                                   # 發 50 分、無限期
 *   php artisan score:adjust deduct 12345 250 --remark="QA 扣點"               # FIFO 扣 250
 *   php artisan score:adjust deduct testuser 999 --dry-run                     # 帳號也可；只看不寫
 *   php artisan score:adjust grant 12345 10 --yes                              # 略過環境確認（腳本用）
 */
class AdjustMemberScore extends Command
{
    private const string ACTION_GRANT = 'grant';

    private const string ACTION_DEDUCT = 'deduct';

    /** 高風險環境：正式白標的 DB_CONNECTION 名稱，確認提示預設 No */
    private const array PRODUCTION_CONNECTIONS = ['VWorld', 'Tpower', 'TPower', 'Me77', 'Yes2Win', 'Alfawin77', 'VPlus33'];

    protected $signature = 'score:adjust
                            {action : grant（發放）或 deduct（扣除）}
                            {member : 會員 id（純數字）或會員帳號}
                            {score : 積分數量，正數、最多 2 位小數}
                            {--hours= : 有效期小時數，僅 grant 使用；不給＝無限期}
                            {--remark= : 備註，寫進 member_score_logs.remark}
                            {--operator=cli : 操作者帳號，寫進 member_score_logs.operator}
                            {--dry-run : 在交易內執行後回滾，只顯示結果不落庫}
                            {--yes : 略過環境確認提示（正式環境仍會顯示警告）}';

    protected $description = '人工發放／扣除會員積分（走 MemberScoreService，FIFO 與到期規則一致）';

    public function __construct(
        private readonly MemberScoreService $scoreService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (!in_array($action, [self::ACTION_GRANT, self::ACTION_DEDUCT], true)) {
            $this->error("action 只接受 grant 或 deduct，收到：{$action}");

            return self::FAILURE;
        }

        $member = $this->resolveMember((string) $this->argument('member'));
        if ($member === null) {
            return self::FAILURE;
        }

        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        if ($action === self::ACTION_DEDUCT && $hours !== null) {
            $this->warn('--hours 只對 grant 有意義，deduct 忽略。');
            $hours = null;
        }

        $dryRun = (bool) $this->option('dry-run');
        $connection = (string) config('database.default');

        if (!$this->confirmEnvironment($connection, $dryRun)) {
            $this->warn('已取消執行。');

            return self::FAILURE;
        }

        $score = (string) $this->argument('score');
        $remark = $this->option('remark') !== null ? (string) $this->option('remark') : null;
        $operator = (string) $this->option('operator');

        $this->line(sprintf(
            '環境 %s · 會員 #%d %s · %s %s 分%s%s',
            $connection,
            $member->id,
            $member->username,
            $action === self::ACTION_GRANT ? '發放' : '扣除',
            $score,
            $hours !== null ? "（{$hours} 小時後到期）" : ($action === self::ACTION_GRANT ? '（無限期）' : ''),
            $dryRun ? ' [dry-run]' : '',
        ));

        DB::beginTransaction();

        try {
            $before = $this->scoreService->balance($member->id);

            $logs = $action === self::ACTION_GRANT
                ? $this->scoreService->grant($member, $score, $hours, ScoreSourceTypeEnum::MANUAL, 0, $remark, $operator)->logs()->get()
                : $this->scoreService->deduct($member, $score, ScoreLogTypeEnum::MANUAL, remark: $remark, operator: $operator);

            $after = $this->scoreService->balance($member->id);

            $this->renderLogs($logs);
            $this->info(sprintf('可用積分 %s → %s', $before, $after));

            if ($dryRun) {
                DB::rollBack();
                $this->warn('dry-run：已回滾，資料庫未變動。');
            } else {
                DB::commit();
                $this->info('已寫入。');
            }

            return self::SUCCESS;
        } catch (InsufficientScoreException $e) {
            DB::rollBack();
            $this->error(sprintf('餘額不足，整筆擋下：可用 %s、要求 %s。資料庫未變動。', $e->available, $e->requested));

            return self::FAILURE;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("執行失敗，已回滾：{$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * 純數字當 members.id，否則當帳號查。
     */
    private function resolveMember(string $identifier): ?Member
    {
        $member = ctype_digit($identifier)
            ? Member::query()->find((int) $identifier)
            : Member::query()->where('username', $identifier)->first();

        if ($member === null) {
            $this->error("找不到會員：{$identifier}");
        }

        return $member;
    }

    /**
     * 正式白標預設 No、其餘預設 Yes；--yes 略過提示但正式環境仍印警告。dry-run 不落庫，免確認。
     */
    private function confirmEnvironment(string $connection, bool $dryRun): bool
    {
        $isProduction = in_array($connection, self::PRODUCTION_CONNECTIONS, true);

        if ($isProduction) {
            $this->warn("⚠️  警告：{$connection} 是正式環境！");
        }

        if ($dryRun || $this->option('yes')) {
            return true;
        }

        return $this->confirm(sprintf('確認要在 %s 環境執行此命令嗎？', $connection), !$isProduction);
    }

    /**
     * @param  Collection<int, MemberScoreLog>  $logs
     */
    private function renderLogs(Collection $logs): void
    {
        $this->table(
            ['log_id', 'batch_id', 'type', 'direction', 'score', 'before', 'after', 'expired_at(+8)'],
            $logs->map(fn (MemberScoreLog $log) => [
                $log->id,
                $log->batch_id,
                $log->type->name,
                $log->direction->name,
                (string) $log->score,
                (string) $log->before_score,
                (string) $log->after_score,
                $log->expired_at?->toBackendTime()->toDateTimeString() ?? '無限期／—',
            ])->all(),
        );
    }
}
