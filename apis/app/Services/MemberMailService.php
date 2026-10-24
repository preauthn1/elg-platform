<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LanguageEnum;
use App\Enums\Member\MemberMailSendTypeEnum;
use App\Enums\StatusEnum;
use App\Models\Member;
use App\Models\MemberMail;
use App\Models\MemberMailRead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 站內信前台查詢 工單4152
 *
 * 可見性依 send_type 分兩條路徑：
 *   指定會員／會員群組 → recipients 有這個會員（存檔時已展開，收件人固定，
 *     會員轉出群組仍看得到已寄出的信）
 *   全平臺／代理及子代理 → 動態比對組織或代理 hierarchy，並排除發信後才
 *     註冊的會員（信寄出時他還不存在，不該收到，否則新人一登入就收到
 *     過去所有群發信、未讀數爆量）
 *
 * 多語系：取當前語系，未填 fallback 英文（英文於後台為必填，必有內容）。
 */
class MemberMailService
{
    /**
     * 會員的站內信列表（新到舊）
     */
    public function list(Member $member, int $languageId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->visibleQuery($member)
            ->with(['languages' => fn ($q) => $q->whereIn(
                'language_id',
                array_unique([$languageId, LanguageEnum::EN->value]),
            )])
            ->withExists(['reads as is_read' => fn ($q) => $q->where('member_id', $member->id)])
            ->orderByDesc('publish_time')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** 未讀數（供前台鈴鐺加總） */
    public function unreadCount(Member $member): int
    {
        return $this->visibleQuery($member)
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('member_id', $member->id))
            ->count();
    }

    /**
     * 標記單封已讀
     *
     * 先驗證該信對此會員可見，避免被拿來對任意 mail_id 灌已讀紀錄。
     * 重複標記直接視為成功（冪等）。
     */
    public function markRead(Member $member, int $mailId): bool
    {
        $visible = $this->visibleQuery($member)->where('member_mails.id', $mailId)->exists();

        if (!$visible) {
            return false;
        }

        MemberMailRead::query()->firstOrCreate(
            ['member_mail_id' => $mailId, 'member_id' => $member->id],
        );

        return true;
    }

    /**
     * 全部標記已讀（進入站內信頁時呼叫，與系統通知的 read-all 行為一致）
     *
     * 仍逐封寫入 reads——觸達率的分母分子照常累計，只是觸發時機從
     * 「點開單封」變成「進入頁面」。已讀過的不重複寫入（冪等）。
     */
    public function markAllRead(Member $member): void
    {
        $mailIds = $this->visibleQuery($member)
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('member_id', $member->id))
            ->pluck('member_mails.id');

        foreach ($mailIds->chunk(500) as $chunk) {
            MemberMailRead::query()->insert(
                $chunk->map(fn ($id) => [
                    'member_mail_id' => (int) $id,
                    'member_id' => $member->id,
                    'read_at' => now(),
                ])->all()
            );
        }
    }

    /**
     * 該會員可見的站內信（已發布且啟用）
     */
    private function visibleQuery(Member $member): Builder
    {
        $agent = $member->agent;

        return MemberMail::query()
            ->where('status', StatusEnum::ENABLE->value)
            ->where('publish_time', '<=', now())
            ->where(function (Builder $query) use ($member, $agent) {
                // 展開型：收件人於存檔時固定，直接比對名單
                $query->where(function (Builder $q) use ($member) {
                    $q->whereIn('send_type', [
                        MemberMailSendTypeEnum::MEMBER->value,
                        MemberMailSendTypeEnum::MEMBER_GROUP->value,
                    ])->whereHas('recipients', fn (Builder $r) => $r->where('member_id', $member->id));
                });

                // 動態型：比對組織／代理線，並排除發信後才註冊的會員
                $query->orWhere(function (Builder $q) use ($member, $agent) {
                    $q->where('send_type', MemberMailSendTypeEnum::ORGANIZATION->value)
                        ->where('organization_id', $agent->organization_id)
                        ->where('publish_time', '>=', $member->created_at);
                });

                $query->orWhere(function (Builder $q) use ($member, $agent) {
                    $q->where('send_type', MemberMailSendTypeEnum::AGENT_HIERARCHY->value)
                        ->whereIn('hierarchy_agent_id', $agent->hierarchy_array)
                        ->where('publish_time', '>=', $member->created_at);
                });
            });
    }
}
