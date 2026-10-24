<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class AbstractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 是否為 decimal 規則允許的金額字面值（最多 $scale 位小數、非負）。
     *
     * is_numeric() 會放行科學記號（"1.0E+15"），但 bcmath 解析不了會丟 ValueError，
     * 因此 withValidator() 內要用 bc* 精確比較金額前，先用本方法過濾。
     */
    protected function isDecimalLiteral(mixed $value, int $scale = 2): bool
    {
        return is_numeric($value)
            && preg_match('/^\d+(\.\d{1,' . $scale . '})?$/', (string) $value) === 1;
    }
}
