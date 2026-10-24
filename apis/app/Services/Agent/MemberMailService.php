<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Member\MemberMailSendTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberMail;
use App\Models\MemberMailLanguage;
use App\Models\MemberMailRecipient;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 站內信後台管理 工單4152
 *
 * 發布時間是唯一的鎖：到點前可改可刪，到點後（前台已看得到）一律鎖定，
 * 內容、對象、狀態、刪除全部擋下——信寄出去就不能收回或竄改。
 *
 * 收件人兩種存法：指定會員／會員群組於存檔時展開進 recipients（固定收件人）；
 * 全平臺／代理及子代理不展開，前台查詢時動態比對（見前台 MemberMailService）。
 */
class MemberMailService
{
    /**
     * 站內信列表
     *
     * @param  array{send_type?: int|string|null, status?: int|string|null, keyword?: string|null, per_page?: int}  $filters
     */
    public function list(Agent $operator, array $filters): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return MemberMail::query()
            ->with('languages')
            ->withCount('reads')
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->when(
                isset($filters['send_type']) && $filters['send_type'] !== '',
                fn (Builder $q) => $q->where('send_type', (int) $filters['send_type'])
            )
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn (Builder $q) => $q->where('status', (int) $filters['status'])
            )
            ->when($keyword !== '', fn (Builder $q) => $q->whereHas(
                'languages',
                fn (Builder $sub) => $sub->where('title', 'like', '%' . $keyword . '%')
            ))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->through(fn (MemberMail $mail) => $this->attachTargetInfo($mail));
    }

    /**
     * 補上「發送對象」的顯示資訊供列表呈現
     *
     * 指定會員：載入收件人帳號（手選的、量小）；會員群組型收件人可達數百筆
     * 刻意不載，前端以 member_group_ids 對照群組名即可。
     * 代理及子代理：反查代理帳號，前端才不用只看到一個數字 id。
     */
    private function attachTargetInfo(MemberMail $mail): MemberMail
    {
        if ((int) $mail->send_type === MemberMailSendTypeEnum::MEMBER->value) {
            $mail->load('recipients.member:id,username');
        }

        if ((int) $mail->send_type === MemberMailSendTypeEnum::AGENT_HIERARCHY->value) {
            $mail->setAttribute(
                'hierarchy_agent_username',
                (string) (Agent::find($mail->hierarchy_agent_id)?->username ?? "#{$mail->hierarchy_agent_id}"),
            );
        }

        if ((int) $mail->send_type === MemberMailSendTypeEnum::ORGANIZATION->value) {
            $mail->setAttribute(
                'organization_name',
                (string) (Organization::find($mail->organization_id)?->name ?? "#{$mail->organization_id}"),
            );
        }

        return $mail;
    }

    /** 單筆詳情（含多語系與收件人，供編輯回填） */
    public function view(Agent $operator, int $id): ?MemberMail
    {
        $mail = MemberMail::query()
            ->with('languages')
            ->withCount('reads')
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->find($id);

        if (!$mail) {
            return null;
        }

        // 指定會員需回填帳號清單（下拉為遠端搜尋，不重查就只剩 id）；
        // 會員群組回填走 member_group_ids 欄位，收件人可能數百筆不需載入
        if ($mail->send_type === MemberMailSendTypeEnum::MEMBER->value) {
            $mail->load('recipients.member:id,username');
        }

        return $mail;
    }

    /**
     * 新增／編輯站內信
     *
     * @param  array{send_type: int, publish_time: string, status?: int, memo?: string|null, languages: array<array{language_id: int, title: string, content: string}>, member_ids?: array<int>, member_group_ids?: array<int>, hierarchy_agent_id?: int}  $input
     *
     * @throws ApiException 已到發布時間的信不可異動
     */
    public function save(Agent $operator, int $adminId, ?int $id, array $input): int
    {
        $sendType = MemberMailSendTypeEnum::from((int) $input['send_type']);

        $this->assertRecipientsInScope($operator, $sendType, $input);

        return DB::transaction(function () use ($operator, $adminId, $id, $input, $sendType) {
            if ($id !== null) {
                $mail = MemberMail::query()
                    ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
                    ->lockForUpdate()
                    ->find($id);

                if (!$mail) {
                    throw new ApiException(__('error_message.1010'));
                }

                // 前台已看得到的信不可竄改——內容、對象、時間、狀態一律鎖定
                if ($mail->isPublished()) {
                    throw new ApiException(__('error_message.member_mail.published_locked'));
                }
            } else {
                $mail = new MemberMail;
                $mail->agent_id = $this->ownerId($operator);
            }

            $memberGroupIds = array_values(array_map('intval', $input['member_group_ids'] ?? []));

            $mail->fill([
                'send_type' => $sendType->value,
                // 後台輸入為前端時區（與 4120 遊戲維護同慣例），入庫前轉系統時區，
                // 否則「發布時間 <= now()」的判定會慢 8 小時
                'publish_time' => Carbon::parseFrontend($input['publish_time'])->toSystemTime()->toDateTimeString(),
                'status' => (int) ($input['status'] ?? 1),
                'memo' => $input['memo'] ?? null,
                'admin' => $adminId,
                // 各類型互斥的對象欄位：切換類型時要清掉另一型別的殘值。
                // 組織由後台下拉選定（比照群發公告），不寫死發信者自己的組織
                'organization_id' => $sendType === MemberMailSendTypeEnum::ORGANIZATION
                    ? (int) ($input['organization_id'] ?? 0) : 0,
                'hierarchy_agent_id' => $sendType === MemberMailSendTypeEnum::AGENT_HIERARCHY
                    ? (int) ($input['hierarchy_agent_id'] ?? 0) : 0,
                'member_group_ids' => $sendType === MemberMailSendTypeEnum::MEMBER_GROUP
                    ? $memberGroupIds : null,
            ]);
            $mail->save();

            $this->syncLanguages($mail, $input['languages']);

            // 收件人展開（快照）：編輯時整批重建——僅發布前可編輯，
            // 尚無已讀紀錄，重建不會影響任何會員的信箱狀態
            $memberIds = match ($sendType) {
                MemberMailSendTypeEnum::MEMBER => array_values(array_unique(array_map('intval', $input['member_ids'] ?? []))),
                MemberMailSendTypeEnum::MEMBER_GROUP => Member::query()
                    ->whereIn('member_group_id', $memberGroupIds)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all(),
                default => [],
            };
            $this->syncRecipients($mail, $memberIds);

            // 觸達率分母：展開型即收件人數；動態型於此時點估算一次
            // （之後註冊的會員本就看不到這封信，快照口徑與可見性一致）
            $mail->recipient_count = $sendType->expandsRecipients()
                ? count($memberIds)
                : $this->countDynamicRecipients($mail, $sendType);
            $mail->save();

            return (int) $mail->id;
        });
    }

    /**
     * 收件對象必須落在操作者自己與下線的範疇內（4495）。
     *
     * 指定會員／會員群組／代理層級／組織四種對象各自驗證；不在範疇內一律 9003，
     * 避免低權限帳號對別線（甚至全站）玩家發信。平台端不限制。
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException
     */
    private function assertRecipientsInScope(Agent $operator, MemberMailSendTypeEnum $sendType, array $input): void
    {
        if ($operator->isPlatformAdmin()) {
            return;
        }

        $visibleAgentIds = Agent::query()->visibleTo($operator)->select('id');

        $inScope = match ($sendType) {
            MemberMailSendTypeEnum::MEMBER => $this->allMembersInScope(
                array_values(array_unique(array_map('intval', $input['member_ids'] ?? []))),
                $visibleAgentIds,
            ),
            MemberMailSendTypeEnum::MEMBER_GROUP => $this->allGroupsInScope(
                array_values(array_unique(array_map('intval', $input['member_group_ids'] ?? []))),
                $visibleAgentIds,
            ),
            MemberMailSendTypeEnum::AGENT_HIERARCHY => $operator->canSeeAgent((int) ($input['hierarchy_agent_id'] ?? 0)),
            // 組織涵蓋的代理全部都在自己下線內才放行（跨線組織等同對別線發信）
            MemberMailSendTypeEnum::ORGANIZATION => !Agent::query()
                ->where('organization_id', (int) ($input['organization_id'] ?? 0))
                ->whereNotIn('id', $visibleAgentIds)
                ->exists(),
        };

        if (!$inScope) {
            throw new ApiException(__('error_message.9003'));
        }
    }

    /**
     * @param  array<int>  $memberIds
     * @param  Builder<Agent>  $visibleAgentIds
     */
    private function allMembersInScope(array $memberIds, Builder $visibleAgentIds): bool
    {
        if ($memberIds === []) {
            return true;
        }

        return Member::query()
            ->whereIn('id', $memberIds)
            ->whereIn('parent', $visibleAgentIds)
            ->count() === count($memberIds);
    }

    /**
     * @param  array<int>  $groupIds
     * @param  Builder<Agent>  $visibleAgentIds
     */
    private function allGroupsInScope(array $groupIds, Builder $visibleAgentIds): bool
    {
        if ($groupIds === []) {
            return true;
        }

        return MemberGroup::query()
            ->whereIn('id', $groupIds)
            ->whereIn('agent_id', $visibleAgentIds)
            ->count() === count($groupIds);
    }

    /**
     * 刪除站內信（含子表）
     *
     * @throws ApiException 已到發布時間的信不可刪除
     */
    public function delete(Agent $operator, int $id): void
    {
        DB::transaction(function () use ($operator, $id) {
            $mail = MemberMail::query()
                ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
                ->lockForUpdate()
                ->find($id);

            if (!$mail) {
                throw new ApiException(__('error_message.1010'));
            }

            if ($mail->isPublished()) {
                throw new ApiException(__('error_message.member_mail.published_locked'));
            }

            $mail->languages()->delete();
            $mail->recipients()->delete();
            $mail->reads()->delete();
            $mail->delete();
        });
    }

    /**
     * 表單用：依關鍵字搜尋可選會員（限操作者下線）
     *
     * 前端為遠端搜尋下拉，未輸入關鍵字不查——下線動輒數萬筆，全量回傳會凍死彈窗。
     *
     * @return Collection<int, Member>
     */
    public function searchMembers(Agent $operator, string $keyword, int $limit = 50): Collection
    {
        if (trim($keyword) === '') {
            return collect();
        }

        $path = $this->effectiveHierarchyPath($operator);

        return Member::query()
            ->select(['id', 'username'])
            ->where('username', 'like', '%' . trim($keyword) . '%')
            ->when(!$this->isAdmin($operator), fn (Builder $q) => $q->whereHas(
                'agent',
                fn (Builder $sub) => $sub->where(function (Builder $inner) use ($operator, $path) {
                    $inner->where('id', $this->ownerId($operator));
                    if ($path !== null && $path !== '') {
                        // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                        $inner->orWhere('hierarchy_path', 'like', $path . '/%');
                    }
                })
            ))
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }

    /**
     * 多語系內容同步：更新既有語系、補新語系、移除被取消的語系
     *
     * @param  array<array{language_id: int, title: string, content: string}>  $languages
     */
    private function syncLanguages(MemberMail $mail, array $languages): void
    {
        $keepIds = [];

        foreach ($languages as $row) {
            $language = MemberMailLanguage::query()->updateOrCreate(
                ['member_mail_id' => $mail->id, 'language_id' => (int) $row['language_id']],
                ['title' => trim((string) $row['title']), 'content' => (string) $row['content']],
            );
            $keepIds[] = (int) $language->id;
        }

        $mail->languages()->whereNotIn('id', $keepIds)->delete();
    }

    /**
     * 收件人快照同步：發布前編輯採整批重建（無已讀紀錄，重建無副作用）
     *
     * @param  array<int>  $memberIds
     */
    private function syncRecipients(MemberMail $mail, array $memberIds): void
    {
        $mail->recipients()->delete();

        if (empty($memberIds)) {
            return;
        }

        // 群組可達數百人，分批寫入避免單筆過大的 insert
        foreach (array_chunk($memberIds, 500) as $chunk) {
            MemberMailRecipient::query()->insert(array_map(
                fn (int $memberId) => ['member_mail_id' => $mail->id, 'member_id' => $memberId],
                $chunk,
            ));
        }
    }

    /**
     * 動態型（全平臺／代理及子代理）的應收人數估算
     *
     * 供觸達率分母。以「存檔當下、註冊時間早於發布時間」為口徑，
     * 與前台可見性判定一致；發布時間在未來時等同當下全體。
     */
    private function countDynamicRecipients(MemberMail $mail, MemberMailSendTypeEnum $sendType): int
    {
        $query = Member::query()->where('created_at', '<=', $mail->publish_time);

        if ($sendType === MemberMailSendTypeEnum::ORGANIZATION) {
            $query->whereHas('agent', fn (Builder $q) => $q
                ->where('organization_id', $mail->organization_id));
        } else {
            $target = Agent::find($mail->hierarchy_agent_id);
            if (!$target) {
                return 0;
            }
            $query->whereHas('agent', fn (Builder $q) => $q
                ->where(function (Builder $inner) use ($target) {
                    $inner->where('id', $target->id)
                        // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                        ->orWhere('hierarchy_path', 'like', $target->hierarchy_path . '/%');
                }));
        }

        return $query->count();
    }

    /**
     * 可見範圍：admin 不設限，其餘限自己與下線建立的信
     * （與 MemberGameRestrictionService 一致）
     */
    private function applyVisibilityScope(Builder $query, Agent $operator): void
    {
        if ($this->isAdmin($operator)) {
            return;
        }

        $ownerId = $this->ownerId($operator);
        $path = $this->effectiveHierarchyPath($operator);

        if ($path === null || $path === '') {
            $query->where('agent_id', $ownerId);

            return;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('agent_id', $ownerId)
                ->orWhereIn('agent_id', function ($sub) use ($path) {
                    // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                    $sub->select('id')->from('agents')->where('hierarchy_path', 'like', $path . '/%');
                });
        });
    }

    /** 子帳號（host_id != 0）的資料歸屬於其主帳號 */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        if ($agent->host_id != 0) {
            return Agent::find($agent->host_id)?->hierarchy_path;
        }

        return $agent->hierarchy_path;
    }

    private function ownerId(Agent $agent): int
    {
        return $agent->host_id != 0 ? (int) $agent->host_id : (int) $agent->id;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }
}
