<?php

declare(strict_types=1);

namespace App\Data\AgentOperateLog;

use App\Enums\Agent\AgentOperateDebugInfoKindEnum;

/**
 * 不檢查形狀的備註：字串原樣存，陣列以 json_encode() 預設選項轉成 JSON 存。
 *
 * 給內容各自不同、既不是狀態變更也不是匯出的類型使用。
 * 陣列刻意不加 JSON_UNESCAPED_UNICODE：這些類型既有的紀錄都是用預設選項寫的，
 * 改了會讓同一類型的新舊紀錄字串不同。
 */
readonly class PlainDebugInfo implements AgentOperateDebugInfo
{
    /**
     * @param  string|array<array-key, mixed>  $content
     */
    public function __construct(
        public string|array $content,
    ) {}

    public function kind(): AgentOperateDebugInfoKindEnum
    {
        return AgentOperateDebugInfoKindEnum::PLAIN;
    }

    public function toDebugInfo(): string
    {
        return is_string($this->content) ? $this->content : (string) json_encode($this->content);
    }
}
