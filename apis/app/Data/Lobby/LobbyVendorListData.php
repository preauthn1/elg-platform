<?php

declare(strict_types=1);

namespace App\Data\Lobby;

use Spatie\LaravelData\Data;

class LobbyVendorListData extends Data
{
    public function __construct(
        public int $item_type,
        public int $id,
        public int $game_id,
        public int $type_id,
        public string $path,
        public ?string $name,
        public ?string $image,
        public ?string $front_page_image,
        public ?string $app_icon,
        public ?bool $is_new,
        public ?bool $is_hot,
        public ?bool $is_event,
        public ?bool $only_one_game,
        public ?bool $need_login,
        public int $giftcode,
        public int $status,
        public ?string $maintenance_end_time,
        public int $sorts,
        public string $currency_name = '',
        public float $rate = 1.0,
    ) {}
}
