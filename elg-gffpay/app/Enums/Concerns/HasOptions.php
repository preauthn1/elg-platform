<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /**
     * Get options array for select components
     * Returns array with enum values as keys and translated labels as values
     */
    public static function getOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    /**
     * Get options array with custom key-value mapping
     * Useful when you need different keys than enum values
     */
    public static function getOptionsWithKeys(callable $keyMapper): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $key = $keyMapper($case);
            $options[$key] = $case->getLabel();
        }

        return $options;
    }

    /**
     * Get options array for Filament select components
     * Returns array compatible with Filament's Select component
     */
    public static function getFilamentOptions(): array
    {
        return self::getOptions();
    }
}
