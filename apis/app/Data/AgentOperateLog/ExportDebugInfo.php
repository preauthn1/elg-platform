<?php

declare(strict_types=1);

namespace App\Data\AgentOperateLog;

use App\Enums\Agent\AgentOperateDebugInfoKindEnum;

/**
 * Excel 匯出備註：{"action":"...","menu_name":"...","record_count":128,"conditions":{...}}
 *
 * conditions 必須是已經過 AgentOperateLogService::sanitizeConditions() 過濾的結果，
 * 本類別只負責格式與長度上限，不再過濾敏感欄位。
 */
readonly class ExportDebugInfo implements AgentOperateDebugInfo
{
    /** debug_info 欄位為 TEXT(65535 bytes)，取保守上限避免 MySQL 非 strict mode 靜默截斷造成 JSON 破損 */
    private const int DEBUG_INFO_MAX_BYTES = 8000;

    /** menu_name 長度上限（字元） */
    private const int MENU_NAME_MAX_LENGTH = 100;

    /**
     * @param  array<string, string>  $conditions
     */
    public function __construct(
        public string $action,
        public string $menuName,
        public array $conditions,
        public int $recordCount,
    ) {}

    public function kind(): AgentOperateDebugInfoKindEnum
    {
        return AgentOperateDebugInfoKindEnum::EXPORT;
    }

    /**
     * 超過長度上限時整段丟棄 conditions，確保結果永遠是合法 JSON。
     *
     * 不在字串層做 substr：那會切在 UTF-8 多位元組中間並破壞 JSON 結構，
     * 讀取端 json_decode 得到 null，備註欄整格空白，反而失去稽核價值。
     */
    public function toDebugInfo(): string
    {
        $payload = [
            'action' => $this->action,
            'menu_name' => mb_substr($this->menuName, 0, self::MENU_NAME_MAX_LENGTH),
            'record_count' => $this->recordCount,
            'conditions' => (object) $this->conditions,
        ];

        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (strlen($json) <= self::DEBUG_INFO_MAX_BYTES) {
            return $json;
        }

        $payload['conditions'] = (object) [];
        $payload['conditions_truncated'] = true;
        $payload['conditions_original_bytes'] = strlen($json);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
