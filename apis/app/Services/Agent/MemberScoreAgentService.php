<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Services\Agent\Concerns\ChecksMemberScope;
use App\Services\MemberScoreService;
use Illuminate\Support\Facades\DB;

/**
 * 代理後台人工發放積分（target/tickets#4233）。
 *
 * 範疇＝整棵下線（ChecksMemberScope，與會員清單一致）；寫入全部委派 MemberScoreService::grant()，
 * 本 Service 只負責「誰、對誰、在交易內」——不自己碰批次表或錢包列。
 * 操作者寫進 member_score_logs.operator／admin，積分帳本本身就是稽核紀錄。
 *
 * 🔴 範疇檢查（findMemberInScope，一般讀）必須放在 DB::transaction **之外**：RR 下交易內第一個一般讀
 * 就定住快照，之後 MemberScoreService 再鎖也讀舊照片（op#3075 形狀）。交易內第一個 SQL 必須是
 * Service 內的 Member::lockForUpdate()。
 */
class MemberScoreAgentService
{
    use ChecksMemberScope;

    public function __construct(
        private readonly MemberScoreService $scoreService,
    ) {}

    /**
     * 發放一批積分給線下會員。
     *
     * @param  string  $score  正數、最多 2 位小數
     * @param  int|null  $hours  有效期小時數；null ＝ 無限期
     * @return array{batch_id: int, member_id: int, score: string, expired_at: string|null, balance: string}
     */
    public function grant(Agent $agent, int $memberId, string $score, ?int $hours, string $remark): array
    {
        $member = $this->findMemberInScope($agent, $memberId);

        return DB::transaction(function () use ($agent, $member, $score, $hours, $remark): array {
            $batch = $this->scoreService->grant(
                member: $member,
                score: $score,
                hours: $hours,
                source: ScoreSourceTypeEnum::MANUAL,
                remark: $remark,
                operator: $agent->username,
                adminId: $agent->id,
            );

            return $this->present($batch, $this->scoreService->balance($member->id));
        });
    }

    /**
     * 扣除線下會員積分——填總量、系統依到期日由近到遠自動拆批（與活動消耗同一套 FIFO）。
     *
     * 餘額不足由 MemberScoreService 拋 InsufficientScoreException，交易回滾、零殘留。
     *
     * @param  string  $score  正數、最多 2 位小數
     * @return array{member_id: int, score: string, deductions: list<array{batch_id: int, score: string, expired_at: string|null}>, balance: string}
     */
    public function deduct(Agent $agent, int $memberId, string $score, string $remark): array
    {
        $member = $this->findMemberInScope($agent, $memberId);

        return DB::transaction(function () use ($agent, $member, $score, $remark): array {
            $logs = $this->scoreService->deduct(
                member: $member,
                score: $score,
                type: ScoreLogTypeEnum::MANUAL,
                remark: $remark,
                operator: $agent->username,
                adminId: $agent->id,
            );

            // 一次查回被扣批次的到期日供回應顯示，不在迴圈內逐筆查
            $batches = MemberScoreBatch::query()
                ->whereIn('id', $logs->pluck('batch_id'))
                ->get()
                ->keyBy('id');

            return [
                'member_id' => $member->id,
                'score' => $logs->reduce(fn (string $carry, MemberScoreLog $log) => Common::numAdd($carry, (string) $log->score, 4), '0'),
                'deductions' => $logs->map(fn (MemberScoreLog $log) => [
                    'batch_id' => $log->batch_id,
                    'score' => (string) $log->score,
                    'expired_at' => $batches->get($log->batch_id)?->expired_at?->toBackendTime()->toDateTimeString(),
                ])->values()->all(),
                'balance' => $this->scoreService->balance($member->id),
            ];
        });
    }

    /**
     * @return array{batch_id: int, member_id: int, score: string, expired_at: string|null, balance: string}
     */
    private function present(MemberScoreBatch $batch, string $balance): array
    {
        return [
            'batch_id' => $batch->id,
            'member_id' => $batch->member_id,
            'score' => (string) $batch->score,
            'expired_at' => $batch->expired_at?->toBackendTime()->toDateTimeString(),
            'balance' => $balance,
        ];
    }
}
