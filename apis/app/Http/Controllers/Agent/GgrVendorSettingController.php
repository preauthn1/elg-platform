<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\GgrVendorSetting\GameOptionsRequest;
use App\Http\Requests\Agent\GgrVendorSetting\IndexRequest;
use App\Http\Requests\Agent\GgrVendorSetting\StoreRequest;
use App\Http\Requests\Agent\GgrVendorSetting\UpdateRequest;
use App\Http\Requests\Agent\GgrVendorSetting\ViewRequest;
use App\Http\Resources\Agent\GgrVendorSetting\GgrVendorSettingResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\GgrVendorSettingService;
use Dedoc\Scramble\Attributes\Group;

#[Group('ggr/vendor-setting (推廣代理GGR遊戲商設置)')]
class GgrVendorSettingController extends Controller
{
    public function __construct(
        protected GgrVendorSettingService $ggrVendorSettingService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->ggrVendorSettingService->paginate($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: GgrVendorSettingResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->ggrVendorSettingService->find($agent, (int) $request->id));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $setting = $this->ggrVendorSettingService->create($agent, $request->validated());

        return ApiResponse::success(['id' => $setting->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        $this->ggrVendorSettingService->update($agent, (int) $data['id'], $data);

        return ApiResponse::success();
    }

    public function vendorOptions()
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->ggrVendorSettingService->vendorOptions($agent));
    }

    public function gameOptions(GameOptionsRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        return ApiResponse::success(
            $this->ggrVendorSettingService->gameOptions($agent, (int) $data['vendor_id'], (int) $data['game_type_id']),
        );
    }
}
