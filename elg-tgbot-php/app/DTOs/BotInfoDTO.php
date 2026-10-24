<?php

namespace App\DTOs;

readonly class BotInfoDTO
{
    public function __construct(
        public string $id,
        public string $code,
        public string $token,
        public string $inviteCode
    ) {}
}
