<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Services\Agent\MemberContactMaskService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 會員電話遮罩（工單 4495）
 *
 * php artisan test --env=testing tests/Unit/Services/Agent/MemberContactMaskServiceTest.php
 */
class MemberContactMaskServiceTest extends TestCase
{
    public function test_mobile_is_masked_when_flags_not_bound(): void
    {
        $request = Request::create('/');

        $this->assertSame(MemberContactMaskService::MASKED, MemberContactMaskService::mobile($request, '601232109309'));
        $this->assertSame('', MemberContactMaskService::mobile($request, null));
        $this->assertSame('', MemberContactMaskService::mobile($request, ''));
    }

    public function test_mobile_is_revealed_when_flag_true(): void
    {
        $request = Request::create('/');
        $request->attributes->set('reveal_member_phone', true);

        $this->assertSame('601232109309', MemberContactMaskService::mobile($request, '601232109309'));
    }
}
