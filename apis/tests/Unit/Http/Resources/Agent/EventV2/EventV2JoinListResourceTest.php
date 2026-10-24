<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources\Agent\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Http\Resources\Agent\EventV2\EventV2JoinListResource;
use App\Models\EventV2UserJoin;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Http/Resources/Agent/EventV2/EventV2JoinListResourceTest.php
 *
 * 後台參加紀錄列表的領取去向：Model 已把 withdraw_type cast 成 Enum，Resource 必須取 ->value；
 * 曾寫成 (int) $enum，PHP 對物件轉 int 恆回 1 並噴 Warning，積分型紀錄全被標成主錢包（target/tickets#4231 第二輪審查）。
 */
class EventV2JoinListResourceTest extends TestCase
{
    public function test_withdraw_type_outputs_enum_backing_value(): void
    {
        foreach (EventV2WithdrawTypeEnum::cases() as $case) {
            $join = new EventV2UserJoin;
            $join->setRawAttributes([
                'id' => 1,
                'event_v2_detail_id' => 1,
                'reward' => '10.0000',
                'withdraw_type' => $case->value,
                'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
                'created_at' => '2026-09-23 00:00:00',
                'end_time' => null,
            ]);

            $array = (new EventV2JoinListResource($join))->toArray(Request::create('/'));

            $this->assertSame($case->value, $array['withdraw_type'], "{$case->name} 的領取去向要回 {$case->value}");
        }
    }
}
