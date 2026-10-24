<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\InformationTypeEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\Information\InformationListResource;
use App\Services\AdvertiseService;
use App\Services\AgentService;
use App\Services\InformationService;
use App\Services\VideoTiktokService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Information (大廳資訊)')]
class InformationController extends Controller
{
    public function __construct(
        protected AdvertiseService $advertiseService,
        protected AgentService $agentService,
        protected VideoTiktokService $videoService,
        protected InformationService $informationService
    ) {}

    /**
     * 大廳資訊按鈕列表
     *
     * OLD: /api/Information
     */
    public function list(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;

        if (!$agent) {
            return ApiResponse::success();
        }

        // 取得大廳資訊按鈕列表
        $data = $this->informationService->getInformationList(
            $agent->customized_setting_group_id,
            $languageId
        );

        // 是否需要登入三方
        $needLoginTiktok = false;
        foreach ($data as $row) {
            // 處理 description 多語系
            $descriptions = json_decode($row->description, true);
            $row->description = $descriptions[$languageId] ?? '';

            if ($row->type == InformationTypeEnum::INTERNAL_URL->value
                && $this->videoService->isValidVideoCode($row->url)) {
                $needLoginTiktok = true;
            }
        }

        // 登入三方取得 session id
        $videoSessionId = '';
        if ($needLoginTiktok && $member) {
            $videoSessionId = $this->videoService->getVideoSessionId($member);
        }

        // 處理外部連結多語系
        foreach ($data as $row) {
            if ($row->type == InformationTypeEnum::URL->value) {
                $urls = json_decode($row->url, true);
                // 如果不是json格式，則直接設定為內容
                if (!empty($urls)) {
                    $row->url = $urls[$languageId] ?? '';
                }
            }
        }

        // 將內部連結轉換為外部連結
        foreach ($data as $row) {
            if ($row->type == InformationTypeEnum::INTERNAL_URL->value
                && $this->videoService->isValidVideoCode($row->url)) {
                $row->type = InformationTypeEnum::URL->value;
                $row->url = $this->videoService->getVideoUrl($row->url, $videoSessionId);
            }
        }

        return ApiResponse::success(InformationListResource::collection($data));
    }
}
