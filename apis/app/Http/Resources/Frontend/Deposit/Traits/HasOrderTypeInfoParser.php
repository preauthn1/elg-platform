<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Deposit\Traits;

/**
 * OrderType Info 解析器 Trait
 *
 * 提供通用的 OrderType info 欄位解析和 PopupText 處理方法
 */
trait HasOrderTypeInfoParser
{
    /**
     * 解析 OrderType 的 info 欄位
     *
     * @param  string|null  $orderTypeInfo  JSON 格式的 info 欄位
     * @return array|string|null 解析後的資料或原始字串
     */
    private function parseOrderTypeInfo(?string $orderTypeInfo): array|string|null
    {
        if ($orderTypeInfo === null || $orderTypeInfo === '') {
            return null;
        }

        $json = json_decode($orderTypeInfo, true);

        // 如果 JSON 解析失敗，返回原始字串
        if (!is_array($json)) {
            return $orderTypeInfo;
        }

        // 如果沒有 data 欄位，返回整個 JSON 陣列
        if (!isset($json['data']) || !is_array($json['data'])) {
            return $json;
        }

        // 翻譯 data 陣列中的 display 和 placeholder 欄位
        $json['data'] = array_map(function ($item) {
            if (isset($item['display'])) {
                $displayKey = $item['display'];
                $item['placeholder'] = __('param.placeholder.' . $displayKey);
                $item['display'] = __('param.display.' . $displayKey);
            }

            return $item;
        }, $json['data']);

        return $json;
    }

    /**
     * 生成彈窗文字
     *
     * @param  string|null  $settings  JSON 格式的 settings
     * @param  int  $languageId  語言 ID
     * @return string 彈窗文字
     */
    private function makePopupText(?string $settings, int $languageId): string
    {
        if ($settings === null || $settings === '') {
            return '';
        }

        $settings = json_decode($settings, true);

        // 如果 JSON 解析失敗或沒有 PopupText 欄位
        if (!is_array($settings) || !isset($settings['PopupText'])) {
            return '';
        }

        $popupText = $settings['PopupText'];

        // 如果 PopupText 不是陣列
        if (!is_array($popupText)) {
            return '';
        }

        // 優先返回當前語言的文字
        if (isset($popupText[$languageId]) && $popupText[$languageId] !== '') {
            return $popupText[$languageId];
        }

        // 其次返回預設語言（ID 為 0）的文字
        if (isset($popupText[0]) && $popupText[0] !== '') {
            return $popupText[0];
        }

        return '';
    }
}
