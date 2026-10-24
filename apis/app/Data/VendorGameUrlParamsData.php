<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\General\DeviceTypeEnum;
use App\Enums\General\OrientationEnum;
use App\Enums\LanguageEnum;
use Spatie\LaravelData\Data;

class VendorGameUrlParamsData extends Data
{
    public function __construct(
        public string $gameCode,
        public DeviceTypeEnum $deviceType,
        public OrientationEnum $orientation = OrientationEnum::UNKNOWN, // 螢幕方向, 前端未帶 X-Orientation 時為 UNKNOWN
        public LanguageEnum $language = LanguageEnum::EN,
        public string $exitUrl = '',
        public string $ip = '',
        public ?string $remark = '', // 遊戲商自定義參數
        public ?string $info = null, // example.invalid, 遊戲商自定義參數, 大多為 json 格式但也有單純字串
    ) {}
}
