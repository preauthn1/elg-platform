<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Game;

use App\Http\Requests\AbstractRequest;

class GetUrlRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => 'integer|digits_between:0,10|required_without:game_id',
            'game_id' => 'integer|digits_between:0,10|required_without:vendor_id',

            /** 要有 schema(http or https)。有些遊戲商會需要 exit_url 參數，在玩家點按上一頁時轉跳到 exit_url，這裡請前端提供不要在後端繼續寫死 */
            'exit_url' => 'required|url',
        ];
    }
}
