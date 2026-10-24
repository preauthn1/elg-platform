<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CheckinService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;

#[Group('Checkin (簽到)')]
class CheckinController extends Controller
{
    public function __construct(
        private CheckinService $checkinService
    ) {}

    /**
     * 簽到紀錄
     */
    public function checkinRecords()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $list = $this->checkinService->checkinRecords($member);

        return ApiResponse::success($list);
    }

    /**
     * 簽到
     */
    public function checkin()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = DB::transaction(function () use ($member) {
            return $this->checkinService->checkin($member);
        });

        return ApiResponse::success($data);
    }
}
