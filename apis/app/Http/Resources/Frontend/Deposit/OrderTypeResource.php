<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Deposit;

use App\Enums\General\ContextKeyEnum;
use App\Http\Resources\Frontend\Deposit\Traits\HasOrderTypeInfoParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Context;

class OrderTypeResource extends JsonResource
{
    use HasOrderTypeInfoParser;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        $orderTypeInfo = $this->parseOrderTypeInfo($this->order_type_info);

        return [
            /** @var int */
            'order_type_id' => $this->order_type_id,

            /** @var int */
            'currency_id' => $this->currency_id,

            /** @var string */
            'name' => $this->name,

            /** @var string */
            'type' => $this->type,

            /** @var int */
            'cf_id' => $this->cf_id,

            /** @var int */
            'acf_id' => $this->acf_id ?? 0,

            /** @var string */
            'cf_name' => $this->cf_name,

            /** @var int */
            'min_amount' => $this->min_amount,

            /** @var int */
            'max_amount' => $this->max_amount,

            /** @var int */
            'is_recommend' => $this->is_recommend ?? 0,

            /** @var float */
            'rate' => $this->rate,

            /** @var string */
            'image_path' => $this->image_path,

            /** @var array|string|null */
            'order_type_info' => $orderTypeInfo['data'] ?? [],

            /** @var int */
            'choose_bank' => $this->choose_bank ?? 0,

            /** @var int */
            'crypto_wallet_id' => $this->crypto_wallet_id ?? 0,

            /** @var string */
            'crypto_wallet_address' => $this->crypto_wallet_address ?? '',

            /** @var string */
            'PopupText' => $this->makePopupText($this->settings, $languageId),
        ];
    }
}
