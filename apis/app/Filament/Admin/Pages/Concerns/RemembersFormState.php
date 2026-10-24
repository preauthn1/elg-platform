<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

/**
 * 表單選項記憶：把上次送出的表單狀態存在 session（隨登入存活），再回到同一頁時沿用。
 *
 * 使用頁面需有 `public ?array $data` 與 statePath 為 `data` 的 `form` schema。
 */
trait RemembersFormState
{
    /**
     * 以「表單預設值 → 上次記住的選項 → 網址帶入的參數」順序填表，後者覆蓋前者。
     *
     * @param  array<string, mixed>  $prefill  網址帶入的參數（已濾掉空值）
     */
    protected function fillFormWithMemory(array $prefill = []): void
    {
        $schema = $this->getSchema('form');
        $schema?->fill();

        $remembered = session()->get($this->formMemoryKey(), []);
        $overrides = [...$remembered, ...$prefill];

        if ($overrides !== []) {
            $schema?->fill([...($this->data ?? []), ...$overrides]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  本次送出且通過驗證的表單狀態
     */
    protected function rememberFormState(array $data): void
    {
        session()->put($this->formMemoryKey(), $data);
    }

    /**
     * 網址 query string 裡要帶入表單的參數；0 是合法值，只濾掉 null 與空字串。
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function requestPrefill(array $keys): array
    {
        return array_filter(
            request()->only($keys),
            fn ($value): bool => $value !== null && $value !== '',
        );
    }

    /** 每頁一份、隨登入 session 存活 */
    private function formMemoryKey(): string
    {
        return 'olympus.form.' . static::class;
    }
}
