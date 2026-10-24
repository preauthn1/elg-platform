<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Realname implements ValidationRule
{
    private const string PATTERN = '/^[A-Za-z0-9-_\s]+$/u';

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::PATTERN, $value)) {
            return;
        }

        $fail(__('error_message.realname rule'));
    }
}
