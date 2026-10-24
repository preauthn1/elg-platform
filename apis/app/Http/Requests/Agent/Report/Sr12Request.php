<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Http\Requests\AbstractRequest;

/**
 * @knowledge rules/report-sr12-payout
 */
class Sr12Request extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'required|digits_between:0,10',
            'game_type' => 'required|digits_between:0,10',
            'agent_username' => 'alpha_dash|between:0,30',
            'member_username' => 'alpha_dash|between:0,30',
            'game_no' => 'alpha_num|between:0,50',
            // 'game_id' => 'required|digits_between:0,10',
            'sort_by' => 'in:1,2',
            'which_time' => 'in:1,2',
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'page' => 'required|numeric',
            'per_page' => 'required|numeric',
        ];
    }
}
