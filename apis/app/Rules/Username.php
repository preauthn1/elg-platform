<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Username implements ValidationRule
{
    /**
     * 必須是字串。
     * 只能包含英文字母和數字。
     * 必須至少包含一個英文字母。
     */
    private const string PATTERN = '/^[A-Za-z0-9]*[A-Za-z]+[A-Za-z0-9]*$/u';

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::PATTERN, $value)) {
            return;
        }

        $fail(__('error_message.username rule'));
    }
}
