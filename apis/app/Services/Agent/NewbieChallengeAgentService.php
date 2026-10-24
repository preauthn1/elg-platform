<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\NewbieChallenge\NewbieChallengeBlockJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeOperatorTypeEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\NewbieChallengeBlockJoin;
use App\Models\NewbieChallengeBlockRecord;
use App\Models\NewbieChallengeConfig;
use App\Models\NewbieChallengeConfigExcludeAgent;
use App\Models\NewbieChallengeConfigLang;
use App\Models\NewbieChallengeProgress;
use App\Models\WalletTransactionLog;
use App\Services\NewbieChallengeService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 新人專享挑戰活動代理後台管理：設定 CRUD、參加紀錄／操作日誌查詢、監控彙總。
 */
class NewbieChallengeAgentService
{
    public function __construct(
        private NewbieChallengeService $newbieChallengeService,
        private WalletService $walletService,
    ) {}

    public function configList(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseConfigQuery($agent);

        if (!empty($filters['agent_username'])) {
            $query->where('a.username', $filters['agent_username']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('c.status', $filters['status']);
        }

        $total = (clone $query)->count('c.id');

        $rows = $query->select([
            'c.id', 'c.agent_id', 'a.username as agent_username', 'c.activity_amount',
            'c.challenge_flow_multiple', 'c.bonus_flow_multiple',
            'c.min_unlock_amount', 'c.max_unlock_amount', 'c.bonus_window_hours',
            'c.restrict_new_member', 'c.sort', 'c.status', 'c.start_time', 'c.end_time', 'c.created_at',
        ])
            ->orderBy('c.sort', 'desc')
            ->orderBy('c.created_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    public function configDetail(Agent $agent, int $configId): NewbieChallengeConfig
    {
        $config = NewbieChallengeConfig::with(['configLangs', 'configExcludeAgents'])->find($configId);
        if ($config === null) {
            throw new ApiException('Config not found');
        }

        if (!$this->isAdmin($agent) && !$this->agentInConfigScope($agent, $config->agent_id)) {
            throw new ApiException('No permission');
        }

        return $config;
    }

    public function configCreate(Agent $agent, array $data, array $langs, array $excludeAgentIds): NewbieChallengeConfig
    {
        $this->assertAdmin($agent);
        $data = $this->convertConfigTimeFields($data);

        return DB::transaction(function () use ($data, $langs, $excludeAgentIds) {
            $config = NewbieChallengeConfig::create($data);
            $this->syncConfigLangs($config, $langs);
            $this->syncConfigExcludeAgents($config, $excludeAgentIds);

            return $config->load(['configLangs', 'configExcludeAgents']);
        });
    }

    /**
     * 平台 admin 可改全部欄位；持有選單權限（路由層 CheckAgentPermission）的一般代理僅能改
     * 文案與圖片（config_lang），活動金額／流水倍數／遊戲商限制等其餘設定維持 admin 專屬，
     * 由路由層權限碼把關不了的部分在這裡二次擋下（OWASP API5，同本檔 assertAdmin 的精神）。
     */
    public function configUpdate(Agent $agent, int $configId, array $data, array $langs, array $excludeAgentIds): NewbieChallengeConfig
    {
        $config = NewbieChallengeConfig::find($configId);
        if ($config === null) {
            throw new ApiException('Config not found');
        }

        if (!$this->isAdmin($agent)) {
            if (!$this->agentInConfigScope($agent, $config->agent_id)) {
                throw new ApiException('無權限操作');
            }

            return DB::transaction(function () use ($config, $langs) {
                $this->syncConfigLangs($config, $langs);

                return $config->load(['configLangs', 'configExcludeAgents']);
            });
        }

        $data = $this->convertConfigTimeFields($data);

        return DB::transaction(function () use ($config, $data, $langs, $excludeAgentIds) {
            $config->update($data);
            $this->syncConfigLangs($config, $langs);
            $this->syncConfigExcludeAgents($config, $excludeAgentIds);

            return $config->load(['configLangs', 'configExcludeAgents']);
        });
    }

    public function configUpdateStatus(Agent $agent, int $configId, int $status): void
    {
        $this->assertAdmin($agent);

        $config = NewbieChallengeConfig::find($configId);
        if ($config === null) {
            throw new ApiException('Config not found');
        }

        $config->update(['status' => $status]);
    }

    public function progressList(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseProgressQuery($agent);
        // 活動錢包餘額：跟會員端 getProgress() 共用同一顆 WalletEnum::CODE_EVENT_WALLET，
        // 後台調整餘額（updateWalletBalance）前先讓列表看得到目前餘額；member_wallets 每個
        // member_id+wallet_type_id 組合最多一列（createOrFirst 唯一鍵保證），LEFT JOIN 不會炸列數
        $query->leftJoin('member_wallets as mw', function ($join) {
            $join->on('mw.member_id', '=', 'p.member_id')
                ->where('mw.wallet_type_id', WalletEnum::CODE_EVENT_WALLET->value);
        });

        if (!empty($filters['stage'])) {
            $query->where('p.stage', $filters['stage']);
        }
        if (!empty($filters['member_username'])) {
            $query->where('m.username', $filters['member_username']);
        }
        if (!empty($filters['agent_username'])) {
            $query->where('a.username', $filters['agent_username']);
        }
        if (!empty($filters['start_time']) && !empty($filters['end_time'])) {
            $query->whereBetween('p.joined_at', $this->parseDateRange($filters['start_time'], $filters['end_time']));
        }

        $total = (clone $query)->count('p.id');

        $rows = $query->select([
            'p.id', 'p.config_id', 'p.member_id', 'm.username as member_username',
            'a.username as agent_username', 'p.stage', 'p.activity_reward',
            'p.unlocked_bonus_amount', 'p.claimed_bonus_amount', 'p.principal_total',
            'p.bonus_window_expire_at', 'p.joined_at', 'p.challenge_completed_at',
            'p.bonus_completed_at', 'p.quit_at', 'p.created_at', 'mw.balance as event_wallet_balance',
        ])
            ->orderBy('p.created_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        // p.principal_total 在 BONUS_COMPLETED 前固定是 0（只有撥款當下才寫回歷史快照），
        // 這裡跟會員端 getProgress() 共用同一套即時計算，才不會後台顯示 0 但玩家端顯示實際本金
        foreach ($rows as $row) {
            $row->principal_total = $this->newbieChallengeService->calculatePrincipalTotal(
                (int) $row->member_id,
                $row->challenge_completed_at !== null ? Carbon::parse($row->challenge_completed_at) : null,
            );
        }

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    public function progressDetail(Agent $agent, int $progressId): NewbieChallengeProgress
    {
        $progress = NewbieChallengeProgress::with([
            'config', 'member', 'challengeFlowLimit', 'bonusFlowLimit',
        ])->find($progressId);

        if ($progress === null) {
            throw new ApiException('Progress not found');
        }

        if (!$this->isAdmin($agent) && !$this->memberInScope($agent, (int) $progress->member->parent)) {
            throw new ApiException('No permission');
        }

        // 跟 progressList() 同理：BONUS_COMPLETED 前 DB 的 principal_total 固定是 0，即時算給後台看
        $progress->principal_total = $this->newbieChallengeService->calculatePrincipalTotal(
            $progress->member_id,
            $progress->challenge_completed_at,
        );

        return $progress;
    }

    /**
     * 後台調整會員活動錢包餘額（WalletEnum::CODE_EVENT_WALLET，Event V2 與新人挑戰共用同一顆錢包，
     * 見 NewbieChallengeService::join()）。balance 是帶正負號的異動量，比照
     * EventV2Controller::updateWalletBalance() 的既有慣例；下限鎖 0，不會讓餘額變負數。
     */
    public function updateWalletBalance(Agent $agent, int $progressId, string $balance): void
    {
        DB::transaction(function () use ($agent, $progressId, $balance) {
            $progress = NewbieChallengeProgress::with('member')->find($progressId);
            if ($progress === null) {
                throw new ApiException('Progress not found');
            }

            if (!$this->isAdmin($agent) && !$this->memberInScope($agent, (int) $progress->member->parent)) {
                throw new ApiException('No permission');
            }

            $member = $progress->member;
            $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);

            // 外部 float 入境即正規化，避免直接 (string) 帶著精度誤差進金額運算
            $adjustment = Common::numAdd('0', $balance, 4);
            $newBalance = Common::numAdd($wallet->balance, $adjustment, 4);
            if (Common::numCompare($newBalance, '0', 4) < 0) {
                $newBalance = '0';
            }

            WalletTransactionLog::create([
                'transaction_no' => Common::getTransactionNo(true),
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
                'vendor_id' => 0,
                'order_id' => 0,
                'point' => $adjustment,
                'tax' => 0,
                'vendor_point' => 0,
                'rate' => 0,
                'before_balance' => $wallet->balance,
                'balance' => $newBalance,
                'type' => WalletTransactionEnum::NEWBIE_CHALLENGE_OPERATOR_CHANGE->value,
                'operator' => $agent->username,
                'remark' => '新人專享挑戰-後台活動錢包操作異動',
                'checksum' => '',
                'result' => '',
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'admin' => $agent->id,
            ]);

            $wallet->balance = $newBalance;
            $wallet->save();
        });
    }

    /**
     * 代理後台強制取消會員參加中的挑戰（比照 EventV2Controller::quit() 的權限模型：代理階層看得到
     * 這個會員就能取消，不是 admin-only）。核心回收邏輯（活動錢包歸零、未完成流水鎖取消稽核、
     * BONUS_COMPLETED 之後不可取消）共用會員主動放棄的同一套 NewbieChallengeService::quit()，
     * 只是 operator_type 改記 AGENT，操作日誌看得出是代理強制取消還是會員自己放棄。
     */
    public function quit(Agent $agent, int $progressId): void
    {
        $progress = NewbieChallengeProgress::with('member')->find($progressId);
        if ($progress === null) {
            throw new ApiException('Progress not found');
        }

        if (!$this->isAdmin($agent) && !$this->memberInScope($agent, (int) $progress->member->parent)) {
            throw new ApiException('No permission');
        }

        $this->newbieChallengeService->quit($progress->member, NewbieChallengeOperatorTypeEnum::AGENT, $agent->id);
    }

    /**
     * 重複阻擋名單（IP/手機/真實姓名），依代理階層範疇；依最新一筆阻擋紀錄時間排序，最近被擋下的
     * 排最前面，方便後台優先處理還在等放行的。
     */
    public function blockList(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('newbie_challenge_block_joins as bj')
            ->join('members as m', 'm.id', '=', 'bj.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('newbie_challenge_block_records as br', 'br.id', '=', 'bj.last_block_record_id');

        $this->scopeToHierarchy($query, $agent);

        if (!empty($filters['member_username'])) {
            $query->where('m.username', $filters['member_username']);
        }
        if (!empty($filters['agent_username'])) {
            $query->where('a.username', $filters['agent_username']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('bj.status', $filters['status']);
        }
        if (!empty($filters['start_time']) && !empty($filters['end_time'])) {
            $query->whereBetween('br.updated_at', $this->parseDateRange($filters['start_time'], $filters['end_time']));
        }

        $total = (clone $query)->count('bj.id');

        $rows = $query->select([
            'bj.id', 'bj.config_id', 'bj.member_id', 'm.username as member_username',
            'a.username as agent_username', 'bj.status', 'bj.allowed_time',
            'br.block_type', 'br.ip', 'br.message', 'br.updated_at as block_updated_at',
            'bj.created_at',
        ])
            ->orderBy('br.updated_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * 單一阻擋名單的完整歷史紀錄（每次觸發的原因/IP，新到舊）
     */
    public function blockDetail(Agent $agent, int $blockJoinId): Collection
    {
        $blockJoin = $this->findBlockJoinInScope($agent, $blockJoinId);

        return NewbieChallengeBlockRecord::where('block_join_id', $blockJoin->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * 後台放行：一次性豁免，讓玩家下次 join() 直接跳過重複阻擋檢查；join 成功後這筆會被消耗
     * 掉（回填 progress_id），不是永久白名單。
     */
    public function allowJoin(Agent $agent, int $blockJoinId): void
    {
        $blockJoin = $this->findBlockJoinInScope($agent, $blockJoinId);

        if ($blockJoin->progress_id !== null) {
            throw new ApiException('此筆已經參加成功，不需要放行');
        }

        $blockJoin->update([
            'allowed_time' => now(),
            'status' => NewbieChallengeBlockJoinStatusEnum::ALLOW_JOIN->value,
        ]);
    }

    /**
     * 取回落在本代理可視範疇內的阻擋名單；範疇比照 memberInScope()（會員所屬代理）。
     * 查無與越權都回同一個訊息，避免用回應差異枚舉他人代理線的 block_join id。
     */
    private function findBlockJoinInScope(Agent $agent, int $blockJoinId): NewbieChallengeBlockJoin
    {
        $blockJoin = NewbieChallengeBlockJoin::with('member')->find($blockJoinId);

        if ($blockJoin === null || (!$this->isAdmin($agent) && !$this->memberInScope($agent, (int) $blockJoin->member->parent))) {
            throw new ApiException('No permission');
        }

        return $blockJoin;
    }

    public function operationLogList(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('newbie_challenge_operation_logs as l')
            ->join('newbie_challenge_progress as p', 'p.id', '=', 'l.progress_id')
            ->join('members as m', 'm.id', '=', 'p.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent');

        $this->scopeToHierarchy($query, $agent);

        if (!empty($filters['progress_id'])) {
            $query->where('l.progress_id', $filters['progress_id']);
        }
        if (!empty($filters['member_username'])) {
            $query->where('m.username', $filters['member_username']);
        }
        if (!empty($filters['agent_username'])) {
            $query->where('a.username', $filters['agent_username']);
        }
        if (!empty($filters['start_time']) && !empty($filters['end_time'])) {
            $query->whereBetween('l.created_at', $this->parseDateRange($filters['start_time'], $filters['end_time']));
        }

        $total = (clone $query)->count('l.id');

        $rows = $query->select([
            'l.id', 'l.progress_id', 'm.username as member_username', 'a.username as agent_username',
            'l.operator_type', 'l.operator_id', 'l.action', 'l.from_stage', 'l.to_stage',
            'l.remark', 'l.created_at',
        ])
            ->orderBy('l.created_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * 監控頁彙總：依「邏輯狀態」分組，不是直接依 stage 分組——BONUS_PENDING 還要再拆成
     * 「待領取彩金」(未過期) 與「已失效」(48hr 視窗已過期)，因為系統設計上沒有獨立的
     * 已失效狀態值，過期與否一律即時比對 bonus_window_expire_at（見 NewbieChallengeStageEnum）。
     * quit（會員自己放棄）跟 operator_quit（代理後台強制取消）分開統計；前台「已放棄/已失效」
     * 若要合併成一個數字，自行加總 quit + operator_quit + expired 三組。
     */
    public function monitor(Agent $agent): Collection
    {
        $query = DB::table('newbie_challenge_progress as p')
            ->join('members as m', 'm.id', '=', 'p.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent');

        $this->scopeToHierarchy($query, $agent);

        $challenging = NewbieChallengeStageEnum::CHALLENGING->value;
        $bonusPending = NewbieChallengeStageEnum::BONUS_PENDING->value;
        $bonusWagering = NewbieChallengeStageEnum::BONUS_WAGERING->value;
        $bonusCompleted = NewbieChallengeStageEnum::BONUS_COMPLETED->value;
        $quit = NewbieChallengeStageEnum::QUIT->value;
        $operatorQuit = NewbieChallengeStageEnum::OPERATOR_QUIT->value;

        return $query->selectRaw(
            "CASE
                WHEN p.stage = {$challenging} THEN 'challenging'
                WHEN p.stage = {$bonusPending} AND p.bonus_window_expire_at >= NOW() THEN 'bonus_pending'
                WHEN p.stage = {$bonusPending} AND p.bonus_window_expire_at < NOW() THEN 'expired'
                WHEN p.stage = {$bonusWagering} THEN 'bonus_wagering'
                WHEN p.stage = {$bonusCompleted} THEN 'completed'
                WHEN p.stage = {$quit} THEN 'quit'
                WHEN p.stage = {$operatorQuit} THEN 'operator_quit'
                ELSE 'unknown'
            END as status_group,
            COUNT(*) as member_count,
            SUM(p.activity_reward) as total_activity_reward,
            SUM(CASE WHEN p.stage = {$bonusCompleted} THEN p.claimed_bonus_amount ELSE 0 END) as total_bonus_paid"
        )
            ->groupBy('status_group')
            ->get();
    }

    private function syncConfigLangs(NewbieChallengeConfig $config, array $langs): void
    {
        foreach ($langs as $lang) {
            NewbieChallengeConfigLang::updateOrCreate(
                ['config_id' => $config->id, 'language_id' => $lang['language_id']],
                ['name' => $lang['name'] ?? null, 'image' => $lang['image'] ?? null, 'content' => $lang['content'] ?? null],
            );
        }
    }

    /**
     * 全刪重建排除代理清單，比照 EventV2Controller::updateExcludeAgents() 的既有慣例
     */
    private function syncConfigExcludeAgents(NewbieChallengeConfig $config, array $excludeAgentIds): void
    {
        NewbieChallengeConfigExcludeAgent::where('config_id', $config->id)->delete();

        foreach ($excludeAgentIds as $agentId) {
            NewbieChallengeConfigExcludeAgent::insert([
                'config_id' => $config->id,
                'agent_id' => $agentId,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseDateRange(string $startTime, string $endTime): array
    {
        return [
            Carbon::parseFrontend($startTime . ':00')->toSystemTime()->toDateTimeString(),
            Carbon::parseFrontend($endTime . ':59')->toSystemTime()->toDateTimeString(),
        ];
    }

    /**
     * 活動設定的 start_time/end_time 是後台輸入的時間，跟 parseDateRange() 一樣要先轉成系統時區
     * 再存 DB，不然讀取端 toBackendTime() 顯示時會多轉一次、對不上輸入值（曾實測多了8小時）。
     */
    private function convertConfigTimeFields(array $data): array
    {
        foreach (['start_time', 'end_time'] as $key) {
            if (!empty($data[$key])) {
                $data[$key] = Carbon::parseFrontend($data[$key])->toSystemTime()->toDateTimeString();
            }
        }

        return $data;
    }

    /** newbie_challenge_progress 為主的查詢（join members/agents）之階層範疇套用，admin 不限。 */
    private function scopeToHierarchy(Builder $query, Agent $agent): void
    {
        if ($this->isAdmin($agent)) {
            return;
        }

        $ownerId = $this->ownerId($agent);
        $path = $this->effectiveHierarchyPath($agent);

        if ($path === null || $path === '') {
            $query->where('a.id', $ownerId);

            return;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('a.id', $ownerId)
                ->orWhere('a.hierarchy_path', 'like', $path . '/%');
        });
    }

    private function baseProgressQuery(Agent $agent): Builder
    {
        $query = DB::table('newbie_challenge_progress as p')
            ->join('members as m', 'm.id', '=', 'p.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent');

        $this->scopeToHierarchy($query, $agent);

        return $query;
    }

    /** newbie_challenge_configs 為主的查詢：agent_id 為 null(全站) 一律可見，否則比照階層範疇。 */
    private function baseConfigQuery(Agent $agent): Builder
    {
        $query = DB::table('newbie_challenge_configs as c')
            ->leftJoin('agents as a', 'a.id', '=', 'c.agent_id');

        if ($this->isAdmin($agent)) {
            return $query;
        }

        $ownerId = $this->ownerId($agent);
        $path = $this->effectiveHierarchyPath($agent);

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->whereNull('c.agent_id')->orWhere('a.id', $ownerId);
            if ($path !== null && $path !== '') {
                $q->orWhere('a.hierarchy_path', 'like', $path . '/%');
            }
        });

        return $query;
    }

    private function agentInConfigScope(Agent $agent, ?int $configAgentId): bool
    {
        if ($configAgentId === null) {
            return true;
        }

        if ($configAgentId === $this->ownerId($agent)) {
            return true;
        }

        $path = $this->effectiveHierarchyPath($agent);
        if ($path === null || $path === '') {
            return false;
        }

        $configAgent = Agent::find($configAgentId);

        return $configAgent !== null && str_starts_with((string) $configAgent->hierarchy_path, $path . '/');
    }

    /** 與 baseProgressQuery 同語意：直屬會員 OR 下線代理的會員；無階層路徑時 fail-closed 只允許直屬。 */
    private function memberInScope(Agent $agent, int $memberParentAgentId): bool
    {
        if ($memberParentAgentId === $this->ownerId($agent)) {
            return true;
        }

        $path = $this->effectiveHierarchyPath($agent);
        if ($path === null || $path === '') {
            return false;
        }

        $memberAgent = Agent::find($memberParentAgentId);

        return $memberAgent !== null && str_starts_with((string) $memberAgent->hierarchy_path, $path . '/');
    }

    /** 子帳號（host_id != 0）以其主帳號的階層為準。 */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upper = Agent::find($agent->host_id);
            $path = $upper->hierarchy_path ?? $path;
        }

        return $path;
    }

    private function ownerId(Agent $agent): int
    {
        return $agent->host_id != 0 ? (int) $agent->host_id : (int) $agent->id;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }

    private function assertAdmin(Agent $agent): void
    {
        if (!$this->isAdmin($agent)) {
            throw new ApiException('無權限操作');
        }
    }
}
