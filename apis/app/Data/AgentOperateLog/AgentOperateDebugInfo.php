<?php

declare(strict_types=1);

namespace App\Data\AgentOperateLog;

use App\Enums\Agent\AgentOperateDebugInfoKindEnum;

/**
 * agent_operate_logs.debug_info 的備註物件：一種顯示種類對應一個實作類別。
 *
 * 存進資料表的字串格式只由實作類別決定，寫入端不得自行組 JSON，
 * 否則讀取端會長出無法辨識的新寫法。
 */
interface AgentOperateDebugInfo
{
    public function kind(): AgentOperateDebugInfoKindEnum;

    /** 寫進 debug_info 欄位的字串 */
    public function toDebugInfo(): string;
}
