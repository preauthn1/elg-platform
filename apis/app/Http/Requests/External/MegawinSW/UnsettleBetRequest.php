<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MegawinSW;

use Illuminate\Foundation\Http\FormRequest;

class UnsettleBetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'wagerData' => 'required|array',
            'wagerData.*.gameId' => 'required|numeric',
            'wagerData.*.gameClass' => 'required|string|in:0,1,2',
            'wagerData.*.risk' => 'required|string|in:0,1',
            'wagerData.*.gameRoundId' => 'required|string',
            'wagerData.*.wagerId' => 'required|string|max:64',

            'wagerData.*.gold' => 'required|string',
            'wagerData.*.vgold' => 'required|string',
            'wagerData.*.winGold' => 'required|string',
            'wagerData.*.unusedGold' => 'required|string',
            'wagerData.*.commissionGold' => 'string',

            'wagerData.*.result' => 'required|string|size:1',
            'wagerData.*.orderDate' => 'required|string',
            'wagerData.*.addDate' => 'required|string',
            'wagerData.*.resultDate' => 'required|string',
            'wagerData.*.freegame' => 'string|in:Y,N',
            'wagerData.*.utoken' => 'required|string|size:32',
            'wagerData.*.uid' => 'required|string|max:32',
            'wagerData.*.ip' => 'required|string|ip',
            'wagerData.*.device' => 'required|string|in:PC,MOBILE',
        ];
    }
}
