<?php

declare(strict_types=1);

namespace App\Events\Agent;

use Illuminate\Foundation\Events\Dispatchable;

class AgentLoginFailed
{
    use Dispatchable;

    public function __construct(
        public readonly int $agentId,
    ) {}
}
