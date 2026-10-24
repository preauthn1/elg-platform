<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GameMaintenance;

use App\Http\Requests\AbstractRequest;

/**
 * 遊戲維護新增／編輯 工單4120
 *
 * 表單的「遊戲商／類型」勾選只是縮小遊戲清單的過濾器，不需送來也不落地；
 * 真正存起來的只有 game_ids。
 */
class SaveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 帶 id 為編輯，不帶為新增。
             *
             * @var int 維護單編號
             *
             * @example 1
             */
            'id' => 'nullable|integer',
            /**
             * 分鐘粒度；秒數由前端固定送 00。
             *
             * @var string 開始時間
             *
             * @example "2026-08-20 00:00:00"
             */
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var string 結束時間（須晚於開始時間）
             *
             * @example "2026-08-21 00:00:00"
             */
            'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
            /**
             * 維護會沿 hierarchy 對該代理的下線一併生效。
             *
             * @var int 維護代理編號
             *
             * @example 1
             */
            'agent_id' => 'required|integer',
            /**
             * @var int 排除代理編號（0 為不排除）
             *
             * @example 0
             */
            'exclude_agent_id' => 'nullable|integer',
            /**
             * @var int 啟用狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * 目前僅支援自動；手動為保留選項，未實作。
             *
             * @var int 解鎖方式 1:自動 2:手動
             *
             * @example 1
             */
            'unlock_type' => 'nullable|in:1,2',
            /**
             * @var string 備註
             *
             * @example "遊戲商公告維護"
             */
            'memo' => 'nullable|string|max:65535',
            /**
             * 可跨遊戲商；送來的即為最終清單，未列出的既有關聯會被移除。
             *
             * @var array<int> 涵蓋的遊戲編號
             *
             * @example [1001, 1002]
             */
            'game_ids' => 'required|array|min:1',
            'game_ids.*' => 'required|integer|exists:games,id',
        ];
    }
}
