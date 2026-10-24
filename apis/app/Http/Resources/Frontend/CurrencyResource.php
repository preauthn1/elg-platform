<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Currency */
class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $name = $this->name;
        if (count($this->names) > 0) {
            $name = $this->names[0]->name;
        }

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $name,
            'rate' => $this->rate,
        ];
    }
}
