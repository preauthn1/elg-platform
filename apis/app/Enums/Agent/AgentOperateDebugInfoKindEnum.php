<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * agent_operate_logs.debug_info 的顯示種類：讀取端依此決定這一欄怎麼畫。
 *
 * 每個操作類型只會宣告一種（AgentOperateTypeEnum::debugInfoKind()），
 * 寫入時 AgentOperateLogService::log() 會檢查傳入的備註物件與宣告一致。
 */
enum AgentOperateDebugInfoKindEnum: string
{
    /** 狀態從什麼變成什麼：{"old":{"status":1},"attributes":{"status":3},"remark":"..."} */
    case STATUS_CHANGE = 'status_change';

    /** 不檢查形狀，原樣存入 */
    case PLAIN = 'plain';

    /** Excel 匯出：動作、選單名稱、筆數與查詢條件 */
    case EXPORT = 'export';
}
