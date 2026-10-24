<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Marquee;

use App\Enums\MarqueeTextLocationEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class TextsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
            'location' => ['nullable', 'integer', new Enum(MarqueeTextLocationEnum::class)],
        ];
    }
}
