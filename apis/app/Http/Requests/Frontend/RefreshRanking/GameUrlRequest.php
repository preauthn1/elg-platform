<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\RefreshRanking;

use App\Http\Requests\AbstractRequest;

class GameUrlRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 遊戲編號
             *
             * @example 1
             */
            'game_id' => 'required|integer|min:1',

            /** 要有 schema(http or https)。有些遊戲商會需要 exit_url 參數，在玩家點按上一頁時轉跳到 exit_url */
            'exit_url' => 'required|url',
        ];
    }
}
