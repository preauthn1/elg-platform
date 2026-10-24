<?php

declare(strict_types=1);

namespace App\Data\AgentOperateLog;

use App\Enums\Agent\AgentOperateDebugInfoKindEnum;

/**
 * 狀態變更備註：{"old":{"status":21},"attributes":{"status":22},"remark":"..."}
 *
 * old／attributes 的鍵名與意思沿用 spatie/laravel-activitylog 記模型變更的格式，
 * API_Agent 的存款單三個類型也寫這個格式，兩邊共用同一套讀法。
 *
 * remark 是操作員在後台填的備註，有填才帶這個鍵；空白視同沒填。
 */
readonly class StatusChangeDebugInfo implements AgentOperateDebugInfo
{
    public function __construct(
        public int $oldStatus,
        public int $newStatus,
        public ?string $remark = null,
    ) {}

    public function kind(): AgentOperateDebugInfoKindEnum
    {
        return AgentOperateDebugInfoKindEnum::STATUS_CHANGE;
    }

    public function toDebugInfo(): string
    {
        $payload = [
            'old' => ['status' => $this->oldStatus],
            'attributes' => ['status' => $this->newStatus],
        ];

        if ($this->remark !== null && trim($this->remark) !== '') {
            $payload['remark'] = $this->remark;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
