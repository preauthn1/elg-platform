<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

/**
 * 「設定條件」某一格可填的參數屬於哪一種（後台參數說明彈窗用，target/tickets#4231 需求 7）。
 *
 * - VARIABLES：公式欄位可用的變數（deposit／reward／invite／win_lose／rand）。
 * - KEYS：JSON 物件配置可用的 key（max_join／limit／balance_multiple…）。
 * - ARRAY_TEMPLATE：整格是一段陣列（三種陣列型的獎勵配置等），彈窗直接給整段範例、不列 key。
 * - NONE：這一格目前沒有任何類型會讀，彈窗明確顯示「此欄位目前無可用參數」（不是空彈窗）。
 *
 * value 對應 resources/lang/{locale}/event_v2.php 的 params 子目錄名（none 除外）。
 */
enum EventV2FieldParamKindEnum: string
{
    case VARIABLES = 'variables';

    case KEYS = 'keys';

    case ARRAY_TEMPLATE = 'arrays';

    case NONE = 'none';
}
