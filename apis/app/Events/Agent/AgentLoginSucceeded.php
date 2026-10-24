<?php

declare(strict_types=1);

namespace App\Events\Agent;

use Illuminate\Foundation\Events\Dispatchable;

class AgentLoginSucceeded
{
    use Dispatchable;

    public function __construct(
        public readonly int $agentId,
    ) {}
}
