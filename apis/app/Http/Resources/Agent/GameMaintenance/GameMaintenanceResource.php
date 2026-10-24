<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\GameMaintenance;

use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 遊戲維護單 工單4120
 */
class GameMaintenanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $games = $this->whenLoaded('games', fn () => $this->games, collect());

        return [
            /** @var int 維護單編號 */
            'id' => (int) $this->id,
            /** @var int 關閉類型 1:關閉遊戲（固定，介面不給編輯） */
            'type' => (int) $this->type,
            /** @var string 開始時間 */
            'start_time' => (string) $this->start_time,
            /** @var string 結束時間 */
            'end_time' => (string) $this->end_time,
            /** @var int 維護代理編號 */
            'agent_id' => (int) $this->agent_id,
            /** @var int 排除代理編號 */
            'exclude_agent_id' => (int) $this->exclude_agent_id,
            /** @var int 啟用狀態 1:啟用 0:停用 */
            'status' => (int) $this->status,
            /** @var int 解鎖方式 1:自動 2:手動 */
            'unlock_type' => (int) $this->unlock_type,
            /**
             * 由狀態與時間推導，不存 DB——存了就得靠排程維持正確，
             * 排程掛掉狀態即失真且無法自我修復。
             *
             * @var string 執行狀態 disabled:停用 pending:尚未開始 running:維護中 finished:維護結束
             */
            'run_state' => $this->resolveRunState(),
            /** @var string|null 備註 */
            'memo' => $this->memo,
            /**
             * 一張單可跨遊戲商，故以清單呈現。
             *
             * @var array<int, array{id: int, name: string, vendor_id: int, vendor_name: string|null, type_id: int}> 涵蓋的遊戲
             */
            'games' => $games->map(fn ($row) => [
                'id' => (int) $row->game_id,
                'name' => $row->game?->name ?? '',
                'vendor_id' => (int) ($row->game?->vendor_id ?? 0),
                'vendor_name' => $row->game?->vendor?->name,
                'type_id' => (int) ($row->game?->type_id ?? 0),
            ])->values()->all(),
            /** @var int 涵蓋的遊戲數 */
            'game_count' => $games->count(),
            /** @var array<int, string> 涵蓋的遊戲商名稱（去重，供列表顯示） */
            'vendor_names' => $games
                ->map(fn ($row) => $row->game?->vendor?->name)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            /** @var string 建立日期 */
            'created_at' => (string) $this->created_at,
            /** @var string 更新日期 */
            'updated_at' => (string) $this->updated_at,
        ];
    }

    /**
     * 執行狀態：停用 → 尚未開始 → 維護中 → 維護結束
     */
    private function resolveRunState(): string
    {
        if ((int) $this->status !== StatusEnum::ENABLE->value) {
            return 'disabled';
        }

        $now = now();

        if ($now->lt($this->start_time)) {
            return 'pending';
        }

        return $now->lt($this->end_time) ? 'running' : 'finished';
    }
}
