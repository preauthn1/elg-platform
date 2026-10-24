<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use App\Data\AgentData;
use App\Data\UserData;
use App\Enums\General\ContextKeyEnum;
use Illuminate\Support\Facades\Context;

trait GetContextTrait
{
    /**
     * @see app/Http/Middleware/Frontend/MergeUserData.php
     */
    public function getUserContext(): ?UserData
    {
        return Context::getHidden(ContextKeyEnum::USER_DATA->value);
    }

    /**
     * @see app/Http/Middleware/Agent/MergeAgentData.php
     */
    public function getAgentContext(): AgentData
    {
        return Context::getHidden(ContextKeyEnum::AGENT_DATA->value);
    }
}
