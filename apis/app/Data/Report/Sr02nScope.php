<?php

declare(strict_types=1);

namespace App\Data\Report;

use App\Models\Agent;

/**
 * SR02N 的資料可見範圍（沿用 SR02 的登入視角，由 Sr02nScopeResolver 解析）。
 */
readonly class Sr02nScope
{
    /**
     * @param  string|null  $scopePath  會員兩段 `a.hierarchy_path like ?%` 的前綴；Admin 線且未指定代理時為 null（不限）
     * @param  string  $hostPath  代理兩段操作者 `f.hierarchy_path like ?%` 的前綴（登入者主帳號路徑，Admin 為 `/1`）
     * @param  int  $hostId  登入者主帳號 id（子帳號取 host_id）
     * @param  Agent|null  $targetAgent  agent_username 指定且在線下的代理
     * @param  bool  $isEmpty  agent_username 查無或不在線下：結果必為空，不必執行查詢
     */
    public function __construct(
        public ?string $scopePath,
        public string $hostPath,
        public int $hostId,
        public ?Agent $targetAgent,
        public bool $isEmpty = false,
    ) {}

    public static function empty(): self
    {
        return new self(scopePath: null, hostPath: '', hostId: 0, targetAgent: null, isEmpty: true);
    }
}
