<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentStatusLog;
use App\Models\Member;
use App\Models\MemberStatusLog;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Support\Facades\DB;

/**
 * 代理後台切換會員狀態（啟用／停用），由 API_Agent `member/edit_status` 移植（target/tickets#4438）。
 *
 * 必須：與舊後台寫同樣三筆紀錄，新舊後台交替操作時紀錄才連得起來——
 *   1. agent_status_logs（target_type＝會員、備註、操作者）：帳號管理「狀態紀錄」彈窗讀這張
 *   2. member_status_logs（type＝後台變更）
 *   3. agent_operate_logs（type＝update_member、reference_type＝member，備註空字串同舊後台）
 * 範疇＝整棵下線（ChecksMemberScope，與會員清單一致）；會員列 lockForUpdate 後才比對與寫入。
 * 舊接口保留給舊後台 WEB_AgentBackend 使用，改規則時兩邊要一起改。
 */
class MemberStatusService
{
    use ChecksMemberScope;

    public function __construct(
        private readonly AgentOperateLogService $operateLogService,
    ) {}

    /**
     * @throws ApiException member not found／member out of scope／member status unchanged
     */
    public function change(Agent $operator, int $memberId, MemberStatusEnum $status, ?string $remark): void
    {
        // 範疇檢查是一般讀，放交易外；交易內第一個讀是鎖定讀，不會先定住快照
        $this->findMemberInScope($operator, $memberId);

        DB::transaction(function () use ($operator, $memberId, $status, $remark): void {
            /** @var Member $member */
            $member = Member::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $before = (int) $member->status;

            if ($before === $status->value) {
                throw new ApiException('member status unchanged');
            }

            $member->status = $status->value;
            $member->save();

            AgentStatusLog::create([
                'target_type' => AgentStatusLog::TARGET_TYPE_MEMBER,
                'target_id' => $member->id,
                'before_status' => $before,
                'after_status' => $status->value,
                'remark' => $remark,
                'admin' => $operator->id,
            ]);

            MemberStatusLog::create([
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'type' => MemberStatusLogTypeEnum::BACKEND_CHANGE,
                'before_status' => $before,
                'after_status' => $status->value,
                'operator' => $operator->id,
            ]);

            $this->operateLogService->log(
                agentId: $operator->id,
                type: AgentOperateTypeEnum::UPDATE_MEMBER,
                debugInfo: new PlainDebugInfo(''),
                // 不用 Context 的 ip：那是 WEB 後台轉發機的 IP，非操作者真實 IP
                ip: Common::getAgentClientIp(),
                referenceId: (string) $member->id,
                referenceType: AgentOperateReferenceTypeEnum::MEMBER,
            );
        });
    }
}
