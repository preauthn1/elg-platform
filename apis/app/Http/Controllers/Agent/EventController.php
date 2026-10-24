<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\Agent\EventListSortFieldEnum;
use App\Enums\SortDirectionEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Event\ListRequest;
use App\Http\Resources\Agent\Event\EventListResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\EventListService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Agent Event (後台活動管理 V1)')]
class EventController extends Controller
{
    public function __construct(
        protected EventListService $eventListService,
    ) {}

    /**
     * 活動列表（後端分頁＋排序，對應舊系統 API_Agent 的 EventController@list；target/tickets#4320）
     */
    public function list(ListRequest $request)
    {
        $context = $this->getAgentContext();

        $data = $this->eventListService->paginate(
            agent: $context->agent,
            languageId: $context->languageId,
            filters: [
                'id' => $request->filled('id') ? (int) $request->input('id') : null,
                'username' => $request->input('username'),
                'title' => $request->input('title'),
                'status' => $request->filled('status') ? (int) $request->input('status') : null,
            ],
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
            sortField: $request->enum('sort_field', EventListSortFieldEnum::class),
            direction: $request->enum('sort_direction', SortDirectionEnum::class) ?? SortDirectionEnum::ASC,
        );

        return ApiResponse::successWithPagination(
            data: EventListResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }
}
