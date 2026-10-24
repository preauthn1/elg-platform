<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use Illuminate\Contracts\Validation\Validator;

/**
 * 限制遊戲商／排除遊戲商互斥檢查：同一組欄位不能兩邊都有值，前端藏欄位擋不住直接打 API。
 */
trait VendorExclusionValidationTrait
{
    private function validateVendorExclusivity(Validator $validator, string $allowedField, string $excludedField): void
    {
        if (filled($this->input($allowedField)) && filled($this->input($excludedField))) {
            $validator->errors()->add($excludedField, __('validation.prohibits', ['attribute' => $excludedField, 'other' => $allowedField]));
        }
    }
}
