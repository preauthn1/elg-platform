<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class AllowJoinRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int newbie_challenge_block_joins.id
             *
             * @example 1
             */
            'block_join_id' => 'required|integer|min:1',
        ];
    }
}
