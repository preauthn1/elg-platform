<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class UpdateWalletBalanceRequest extends AbstractRequest
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
            /**
             * @var string 活動錢包餘額異動量，帶正負號；正數增加、負數扣除
             *
             * @example "100"
             */
            'balance' => 'required|numeric',
        ];
    }
}
