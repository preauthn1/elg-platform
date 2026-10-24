<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\General\DeviceTypeEnum;
use App\Enums\General\OrientationEnum;
use App\Models\Agent;
use App\Models\Member;
use Spatie\LaravelData\Data;

/**
 * @see app/Http/Middleware/Frontend/MergeUserData.php
 */
class UserData extends Data
{
    public function __construct(
        public ?string $token,
        public ?Member $member,
        public ?Agent $agent, // might empty in some case, like currency/list
        public int $languageId,
        public DeviceTypeEnum $deviceType, // PC=1, 手機=2, 平板=3
        public string $ip,
        public OrientationEnum $orientation = OrientationEnum::UNKNOWN, // 前端 X-Orientation，未帶為 UNKNOWN
    ) {}
}
