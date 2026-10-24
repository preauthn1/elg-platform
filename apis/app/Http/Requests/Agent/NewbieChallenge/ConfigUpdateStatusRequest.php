<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class ConfigUpdateStatusRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int newbie_challenge_configs.id
             *
             * @example 1
             */
            'config_id' => 'required|integer|min:1',
            /**
             * @var int 狀態; 0:停用, 1:啟用
             *
             * @example 1
             */
            'status' => 'required|integer|in:0,1',
        ];
    }
}
