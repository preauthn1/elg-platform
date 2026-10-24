<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class ProgressDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int newbie_challenge_progress.id
             *
             * @example 1
             */
            'progress_id' => 'required|integer|min:1',
        ];
    }
}
