<?php

declare(strict_types=1);

namespace App\Vendors\VplusSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $GAME_LAUNCH_URL,
        public string $APP_ID,
        public string $APP_SECRET,
    ) {}
}
