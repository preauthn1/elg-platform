<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\GameMaintenance\GameListRequest;
use App\Http\Requests\Agent\GameMaintenance\IndexRequest;
use App\Http\Requests\Agent\GameMaintenance\SaveRequest;
use App\Http\Requests\Agent\GameMaintenance\ViewRequest;
use App\Http\Resources\Agent\GameMaintenance\GameMaintenanceResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\GameMaintenanceService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 遊戲維護（4120）：系統管理 → 遊戲商維護 → 遊戲維護。
 *
 * 與遊戲商維護（vendor_maintenances，整商關閉）平行的另一套，粒度到單一遊戲，
 * 一張單可跨遊戲商。兩者互不影響，可同時生效。
 *
 * 生效不靠排程：前台每次請求即時比對時間區間，時間到自動生效／恢復。
 */
#[Group('game-maintenance (遊戲維護)')]
class GameMaintenanceController extends Controller
{
    public function __construct(
        private readonly GameMaintenanceService $service,
    ) {}

    /**
     * 遊戲維護列表
     *
     * 遊戲商篩選採「涵蓋即列出」：一張單可跨商，只要其中有任一款屬於該商的
     * 遊戲就會列出——營運查某商是為了看「有哪些維護影響到它」。
     */
    public function list(IndexRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $paginator = $this->service->list($operator, $request->validated());

        return ApiResponse::successWithPagination(
            data: GameMaintenanceResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 遊戲維護詳情
     *
     * 回傳涵蓋的完整遊戲清單供編輯表單回填。
     */
    public function view(ViewRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $maintenance = $this->service->view($operator, (int) $request->input('id'));

        if (!$maintenance) {
            throw new ApiException(__('error_message.1010'));
        }

        return ApiResponse::success(GameMaintenanceResource::make($maintenance));
    }

    /**
     * 新增／編輯遊戲維護
     *
     * 帶 id 為編輯，不帶為新增。遊戲清單整批覆寫，主表與子表在同一交易內完成。
     */
    public function save(SaveRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;
        $input = $request->validated();

        $id = $this->service->save(
            operator: $operator,
            id: isset($input['id']) ? (int) $input['id'] : null,
            payload: $input,
            gameIds: $input['game_ids'],
        );

        return ApiResponse::success(['id' => $id]);
    }

    /**
     * 表單用的可勾選遊戲清單
     *
     * 依勾選的遊戲商（可多選）與類型篩出遊戲；這兩層只是過濾器，
     * 存檔時只有勾選的 game_id 會落地。
     */
    public function gameList(GameListRequest $request): JsonResponse
    {
        $games = $this->service->selectableGames(
            vendorIds: $request->input('vendor_ids', []),
            typeIds: $request->input('type_ids', []),
            keyword: $request->input('keyword'),
        );

        return ApiResponse::success(
            $games->map(fn ($game) => [
                'id' => (int) $game->id,
                'name' => $game->name,
                'vendor_id' => (int) $game->vendor_id,
                'vendor_name' => $game->vendor?->name,
                'type_id' => (int) $game->type_id,
            ])->all()
        );
    }
}
