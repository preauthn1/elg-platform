<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GameMaintenance;

use App\Http\Requests\AbstractRequest;

/**
 * 表單用的可勾選遊戲清單 工單4120
 *
 * 遊戲商與類型為過濾條件，不落地；營運勾完遊戲後只有 game_id 會被存起來。
 */
class GameListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 可多選，跨遊戲商勾選遊戲即由此而來。
             *
             * @var array<int> 遊戲商編號
             *
             * @example [248, 92]
             */
            'vendor_ids' => 'required|array|min:1',
            'vendor_ids.*' => 'required|integer',
            /**
             * @var array<int> 遊戲類型編號（game_types.id），不帶表示全部類型
             *
             * @example [1]
             */
            'type_ids' => 'nullable|array',
            'type_ids.*' => 'required|integer',
            /**
             * @var string 遊戲名稱關鍵字（模糊）
             *
             * @example "Baccarat"
             */
            'keyword' => 'nullable|string|max:191',
        ];
    }
}
