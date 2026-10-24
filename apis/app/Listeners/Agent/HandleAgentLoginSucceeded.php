<?php

declare(strict_types=1);

namespace App\Listeners\Agent;

use App\Events\Agent\AgentLoginSucceeded;
use App\Services\AgentLoginRestrictionService;

/**
 * 同步執行（不掛 ShouldQueue）：與 HandleAgentLoginFailed 同時序，
 * 避免失敗累加與成功歸零經 queue 亂序處理留下髒計數。
 */
class HandleAgentLoginSucceeded
{
    public function __construct(
        private readonly AgentLoginRestrictionService $restrictionService,
    ) {}

    public function handle(AgentLoginSucceeded $event): void
    {
        $this->restrictionService->resetLoginFailCount($event->agentId);
    }
}
