<?php

declare(strict_types=1);

namespace App\Listeners\Agent;

use App\Events\Agent\AgentLoginFailed;
use App\Services\AgentLoginRestrictionService;

/**
 * 同步執行（不掛 ShouldQueue）：鎖定必須在請求內立即生效，
 * 走 queue 會產生延遲窗口（queue 塞車期間等同不設防）與失敗/歸零亂序問題。
 */
class HandleAgentLoginFailed
{
    public function __construct(
        private readonly AgentLoginRestrictionService $restrictionService,
    ) {}

    public function handle(AgentLoginFailed $event): void
    {
        $this->restrictionService->recordLoginFailure($event->agentId);
    }
}
