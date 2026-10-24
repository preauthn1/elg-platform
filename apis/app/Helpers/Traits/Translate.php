<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

/**
 * ProcessesBankInfo Trait
 *
 * 將銀行相關的 info 欄位處理（正規化、翻譯）封裝成可重用的 Trait。
 * 其他 Resource 或服務可以 use 此 Trait 以統一處理 bank info 的呈現邏輯。
 *
 * 功能：
 *  - 支援輸入為 JSON 字串 / PHP 物件 / 陣列
 *  - 翻譯欄位的 display 與 placeholder
 *  - 若為 country 欄位，翻譯其 options 的文字，優先使用語系 key，次之使用 config/countries
 */
trait Translate
{
    /**
     * 將原始 info 正規化、翻譯後，回傳供前端使用的物件（或 null）
     *
     * @param  mixed  $info  原始 info（可能為 JSON 字串、物件或陣列）
     */
    protected function translateBankInfo(mixed $info): ?object
    {
        // 將輸入正規化為陣列形式
        $normalizedInfo = $this->normalizeBankInfo($info);

        // 逐欄位處理（支援 key 為欄位名稱或數字索引）
        foreach ($normalizedInfo as $key => $fieldConfig) {
            // 若欄位為物件，轉為陣列以便處理
            if (is_object($fieldConfig)) {
                $fieldConfig = (array) $fieldConfig;
            }

            if (!is_array($fieldConfig)) {
                // 非陣列型態跳過
                continue;
            }

            // 翻譯單一欄位的 display 與 placeholder
            $this->translateBankField($fieldConfig);

            // 若該欄位為 country，翻譯其 options
            if ($this->isBankCountryField($key, $fieldConfig)) {
                $this->translateBankOptions($fieldConfig);
            }

            // 將處理後的 config 放回正規化陣列
            $normalizedInfo[$key] = $fieldConfig;
        }

        // 將處理後的陣列轉成物件回傳（保持輸出結構與原先兼容）
        return $normalizedInfo ? json_decode(json_encode($normalizedInfo), false) : null;
    }

    /**
     * 將可能的 info 型態（JSON 字串 / 物件 / 陣列）轉成陣列
     */
    protected function normalizeBankInfo(mixed $info): array
    {
        // JSON 字串的情況
        if (is_string($info) && $info !== '') {
            $decoded = json_decode($info, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        // 物件的情況
        if (is_object($info)) {
            return (array) $info;
        }

        // 已為陣列或其他情況
        return is_array($info) ? $info : [];
    }

    /**
     * 翻譯單一欄位的 display 與 placeholder
     *
     * @param  array  $fieldConfig  (by reference)
     */
    protected function translateBankField(array &$fieldConfig): void
    {
        if (!isset($fieldConfig['display'])) {
            return;
        }

        $displayKey = strtolower($fieldConfig['display']);
        // placeholder 與 display 都使用 bank_info 的語系 key
        $fieldConfig['placeholder'] = __('bank_info.placeholder.' . $displayKey);
        $fieldConfig['display'] = __('bank_info.display.' . $displayKey);
    }

    /**
     * 判斷某個欄位是否為 country 欄位（可用 key 或 display 判斷）
     */
    protected function isBankCountryField(string|int $key, array $fieldConfig): bool
    {
        // 若 key 為 'country'，直接視為 country 欄位
        if ($key === 'country') {
            return true;
        }

        if (!isset($fieldConfig['display'])) {
            return false;
        }

        $display = $fieldConfig['display'];

        // display 可能為原始字串 'country'，或已由 __() 翻譯後的文字
        return $display === 'country' || $display === __('bank_info.display.country');
    }

    /**
     * 翻譯 country 欄位的 options
     *
     * 處理順序：
     *  1. 先嘗試語系鍵 bank_info.country.options.{value}
     *  2. 若沒有，嘗試從 config('countries') 取對應名稱
     *  3. 否則以 options 中的 text 嘗試翻譯或顯示
     *
     * @param  array  $fieldConfig  (by reference)
     */
    protected function translateBankOptions(array &$fieldConfig): void
    {
        if (!isset($fieldConfig['options']) || !is_array($fieldConfig['options'])) {
            return;
        }

        foreach ($fieldConfig['options'] as &$option) {
            if (!is_array($option)) {
                continue;
            }

            $value = $option['value'] ?? null;
            $text = $option['text'] ?? '';

            // 優先使用 value 對應的翻譯 key（例如 bank_info.country.options.91）
            if ($value !== null) {
                $translationKey = 'bank_info.country.options.' . $value;
                $translationResult = __($translationKey);
                if ($translationResult !== $translationKey) {
                    $option['text'] = $translationResult;

                    continue;
                }
            }

            // 最後以 text 欄位嘗試翻譯（或直接顯示原文字）
            $option['text'] = __($text);
        }
        unset($option);
    }
}
