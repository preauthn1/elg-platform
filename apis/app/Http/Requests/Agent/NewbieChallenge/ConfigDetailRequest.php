<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class ConfigDetailRequest extends AbstractRequest
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
        ];
    }
}
