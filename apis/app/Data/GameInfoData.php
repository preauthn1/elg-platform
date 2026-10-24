<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class GameInfoData extends Data
{
    public function __construct(
        public string $gameCode,
        public string $gameName,
        public string $imageUrl,
        public int $gameType,
        public int $status,
        public ?string $info = null,
        public ?string $remark = null,
        public ?string $memo = null,
        public ?int $openWindow = 0,
        public array $otherNames = [],
        public array $gameNames = [],
        public ?int $dbId = null,
    ) {}

    public function setGameNames(int $languageId, string $gameName): void
    {
        $this->gameNames[$languageId] = $gameName;
    }
}
