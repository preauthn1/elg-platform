<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->countryNames[0]->name ?? $this->name,
            'country_code' => $this->country_code,
            'phone_rule' => $this->phone_rule,
            'has_whatsapp' => (bool) $this->has_whatsapp,
            'has_sms' => (bool) $this->has_sms,
        ];
    }
}
