<?php

namespace App\DTOs;

use App\Enums\ChatMessages\ChatMessagesTypeEnum;

readonly class CoverMediaDTO
{
    public function __construct(
        public string $type,
        public string $url
    ) {}

    public function getChatMessagesTypeEnum(): ChatMessagesTypeEnum
    {
        if (empty($this->url)) {
            return ChatMessagesTypeEnum::TEXT;
        }

        return match ($this->type) {
            'photo', 'image' => ChatMessagesTypeEnum::IMAGE,
            'animation' => ChatMessagesTypeEnum::ANIMATION,
            default => ChatMessagesTypeEnum::TEXT,
        };
    }

    public function getMediaUrl(): string
    {
        return $this->url;
    }
}
