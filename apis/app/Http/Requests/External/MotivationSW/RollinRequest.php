<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MotivationSW;

use App\Http\Requests\AbstractRequest;

class RollinRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'account' => 'required|string|max:36',
            'eventTime' => 'required|string|max:35',
            'gamehall' => 'required|string|max:36',
            'gamecode' => 'required|string|max:36',
            'roundid' => 'required|string|max:50',

            /**
             * ※老虎機/街機/魚機有效投注為0，請直接使用參數bet
             * ※此欄位值用於牌桌/真人及體彩類遊戲
             */
            'validbet' => 'required|numeric|min:0',

            /** ※因應牌桌/真人遊戲的規則，此欄位的值有可能為0 */
            'bet' => 'required|numeric|min:0',

            /** ※因應牌桌/真人遊戲的規則，此欄位必須能接受負值 */
            'win' => 'required|numeric',

            /** ※此欄位為牌桌遊戲使用 */
            'roomfee' => 'required|numeric|min:0',

            /** ※金額不得為負值 */
            'amount' => 'required|numeric|min:0',
            'mtcode' => 'required|string|max:70',
            'createTime' => 'required|string|max:35',
            'rake' => 'required|numeric|min:0',
            'gametype' => 'required|string|max:36',

            /** ※廠商文件為選填：牌桌類才有桌號，魚機／街機送 null，必須放行 */
            'tableid' => 'nullable|string|max:36',
        ];
    }
}
