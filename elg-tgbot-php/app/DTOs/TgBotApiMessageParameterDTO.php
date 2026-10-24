<?php

namespace App\DTOs;

use App\Enums\ChatMessages\ChatMessagesTypeEnum;

class TgBotApiMessageParameterDTO
{
    public array $buttonRows = [];

    public function __construct(
        public readonly ChatMessagesTypeEnum $type,
        public readonly string $content = '',
        public readonly string $mediaUrl = ''
    ) {}
}
