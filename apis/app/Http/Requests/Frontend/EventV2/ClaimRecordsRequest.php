<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventV2;

use App\Http\Requests\AbstractRequest;

class ClaimRecordsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 年月
             *
             * @example "2026-04"
             */
            'year_month' => 'nullable|date_format:Y-m',
        ];
    }
}
