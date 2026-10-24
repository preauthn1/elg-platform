<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class EventCodeData extends Data
{
    public function __construct(
        public int $agentId,
        public int $eventId,
        public int $memberId,
    ) {}
}
