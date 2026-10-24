<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class SetBirthdayRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 生日
             *
             * @example 1978-12-18
             */
            'birthday' => [
                'required',
                Rule::date()->format('Y-m-d'),
                Rule::date()->before(now()->subYears(18)->toDateString()),
            ],
        ];
    }
}
