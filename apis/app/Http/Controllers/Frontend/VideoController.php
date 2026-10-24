<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\Video\VideoResource;
use App\Services\VideoTiktokService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Video')]
class VideoController extends Controller
{
    public function __construct(
        private VideoTiktokService $tiktokService,
    ) {}

    public function __invoke(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $loginInfo = $this->tiktokService->getVideoSessionId($member);
        $account = $this->tiktokService->getAccount($member);

        $baseData = [
            'username' => $account->username,
            'password' => $account->password,
            'session_id' => $loginInfo,
        ];

        $data = [];
        foreach (VideoTiktokService::VIDEO_URLS as $url) {
            $data[] = $baseData + ['tiktok_url' => $url];
        }

        return ApiResponse::success(VideoResource::collection($data));
    }
}
