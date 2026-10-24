<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class EventV2ListItemData extends Data
{
    public function __construct(
        public ?int $event_v1_id,
        public ?int $event_v2_id,
        public string $name,
        public ?string $image,
        public ?array $params,
        public array $game_type_ids,
        public int $sort,
        public ?string $created_at,
        public ?string $end_time,
        public ?int $tp_event = null,
        /** 活動 V2 的類型 handler（如 AngpowService），前端據此分辨紅包等要走專屬畫面的類型；V1／其他系統為 null */
        public ?string $handler_class = null,
    ) {}
}
