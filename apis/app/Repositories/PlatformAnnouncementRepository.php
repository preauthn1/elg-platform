<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementRead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// use Illuminate\Support\Facades\DB;

class PlatformAnnouncementRepository extends AbstractRepository
{
    protected string $now;

    public function __construct()
    {
        $this->now = Carbon::now()->toDateTimeString();
    }

    public function getList(array $agentIdList, int $languageId): Collection
    {
        return PlatformAnnouncement::query()
            ->with([
                'language' => function ($query) use ($languageId) {
                    $query->where('language_id', $languageId);
                },
                'language.image',
                'image',
            ])
            ->where('receiver_type', 1) // 對會員
            ->where('status', 1)
            ->where('start_time', '<=', $this->now)
            ->where('end_time', '>=', $this->now)
            ->whereHas('includeAgents', function ($query) use ($agentIdList) {
                $query->whereIn('agent_id', $agentIdList);
            })
            ->whereDoesntHave('excludeAgents', function ($query) use ($agentIdList) {
                $query->whereIn('agent_id', $agentIdList);
            })
            ->orderByDesc('sort')
            ->orderByDesc('created_at')
            ->get()
            // 排除 title, content, image_url 為空的公告
            ->filter(function ($announcement) {
                return !empty($announcement->title) || !empty($announcement->content) || !empty($announcement->image_url);
            });
    }

    public function getUnreadCount(array $agentIdList, string $lastReadTime = ''): int
    {
        return PlatformAnnouncement::where('status', 1)
            ->where('start_time', '<=', $this->now)
            ->where('end_time', '>=', $this->now)
            ->whereHas('includeAgents', function ($query) use ($agentIdList) {
                $query->whereIn('agent_id', $agentIdList);
            })
            ->whereDoesntHave('excludeAgents', function ($query) use ($agentIdList) {
                $query->whereIn('agent_id', $agentIdList);
            })
            ->when(!empty($lastReadTime), function ($query) use ($lastReadTime) {
                $query->where('created_at', '>=', $lastReadTime);
            })
            ->count();
    }

    public function getMemberReadRecord(int $memberId): PlatformAnnouncementRead
    {
        return PlatformAnnouncementRead::createOrFirst(
            ['member_id' => $memberId],
            ['last_read_time' => $this->now]
        );
    }
}
