<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\AnnouncementMessageTypeEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\AnnouncementMessage\ReadRequest;
use App\Http\Resources\Frontend\AnnouncementMessage\AnnouncementMessageResource;
use App\Models\AnnouncementMessage;
use App\Models\AnnouncementMessageRead;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

#[Group('AnnouncementMessage (群發公告)')]
class AnnouncementMessageController extends Controller
{
    public function __construct(
    ) {}

    /**
     * 最新一筆群發公告
     *
     * OLD: /api/announcement-messages/list
     */
    public function latest(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $memberId = $member?->id;
        $now = Carbon::now();

        $data = AnnouncementMessage::query()
            ->when($memberId, function ($query) use ($memberId) {
                // 排除已閱讀的公告
                $query->whereDoesntHave('reads', function ($query) use ($memberId) {
                    $query->where('member_id', $memberId);
                });
            })
            ->withWhereHas('participants', function ($query) use ($memberId, $agent) {
                $query->where([
                    ['type', AnnouncementMessageTypeEnum::CHAT_GROUP],
                    ['chat_group_id', $agent->chat_group_id],
                ])
                    ->orWhere(function ($query) use ($agent) {
                        $query->where('type', AnnouncementMessageTypeEnum::AGENT_TREE)
                            ->WhereIn('hierarchy_agent_id', $agent->hierarchy_array);
                    })
                    ->orWhere([
                        ['type', AnnouncementMessageTypeEnum::AGENT],
                        ['single_agent_id', $agent->id],
                    ])
                    ->when($memberId, function ($query) use ($memberId) {
                        $query->orWhere([
                            ['type', AnnouncementMessageTypeEnum::MEMBER],
                            ['member_id', $memberId],
                        ]);
                    })
                    ->orWhere([
                        ['type', AnnouncementMessageTypeEnum::ORGANIZATION],
                        ['organization_id', $agent->organization_id],
                    ]);
            })
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->orderBy('created_at', 'ASC')
            ->first();

        if (!$data) {
            return ApiResponse::success();
        }

        return ApiResponse::success(AnnouncementMessageResource::make($data));
    }

    /**
     * 標記已讀群發公告
     *
     * OLD: /api/announcement-messages/read
     */
    public function read(ReadRequest $request)
    {
        $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;

        if (!$member) {
            return ApiResponse::success();
        }

        $memberId = $member->id;
        AnnouncementMessageRead::query()->updateOrCreate([
            'announcement_message_id' => $request->announcement_message_id,
            'member_id' => $memberId,
        ]);

        return ApiResponse::success();
    }
}
