<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class VendorGameUrlResultData extends Data
{
    /**
     * at least one of (pc, mobile, $extra, app_infos) is required
     */
    public function __construct(
        /** 網頁遊戲網址 */
        public string $pc = '',

        /** 手機遊戲網址 */
        public string $mobile = '',

        /** app 開啟資訊 */
        public ?VendorGameUrlResultAppInfoData $app_infos = null,

        /** 給非網址也非app的遊戲商使用, 例如NPT(41) */
        public array $extra = [],
    ) {}
}
