<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

/**
 * 活動 V2「設定條件」頁籤的十個手寫欄位（event_v2_details 欄位名）。
 *
 * 後台參數說明彈窗（target/tickets#4231 需求 7）以此列舉欄位；
 * 各 handler 宣告「本類型會讀哪些變數／key」時以 case 的 value 當 key，
 * 見 AbstractEventV2::fieldParams()。
 */
enum EventV2FieldEnum: string
{
    case CONDITION_FORMULA = 'condition_formula';

    case CONDITION_CONFIG = 'condition_config';

    case REWARD_FORMULA = 'reward_formula';

    case REWARD_CONFIG = 'reward_config';

    case BALANCE_FORMULA = 'balance_formula';

    case BALANCE_CONFIG = 'balance_config';

    case WITHDRAW_FORMULA = 'withdraw_formula';

    case WITHDRAW_CONFIG = 'withdraw_config';

    case FLOW_FORMULA = 'flow_formula';

    case FLOW_CONFIG = 'flow_config';

    /** 公式欄位（可用變數）；否則為配置欄位（可用 key 或整段陣列） */
    public function isFormula(): bool
    {
        return str_ends_with($this->value, '_formula');
    }
}
