<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Agent;
use Spatie\LaravelData\Data;

/**
 * @see app/Http/Middleware/Agent/MergeAgentData.php
 */
class AgentData extends Data
{
    public function __construct(
        public ?string $token,
        public ?Agent $agent,
        public int $languageId,
        public string $ip,
    ) {}
}
