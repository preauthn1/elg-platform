<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Country;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Mockery;
use Tests\TestCase;

/**
 * RegisterService 測試
 *
 * php artisan test tests/Unit/Services/RegisterServiceTest.php
 */
class RegisterServiceTest extends TestCase
{
    private function service(MemberService $memberService): RegisterService
    {
        return new RegisterService(new EventService, $memberService, new MemberCodeService, new EventInviteService);
    }

    public function test_get_country_id_by_code_returns_id_or_zero(): void
    {
        $dial = random_int(70000, 79999);
        $country = Country::create([
            'code' => 'TC' . $dial,
            'name' => 'TestCountry',
            'country_code' => $dial,
            'phone_rule' => '0',
            'status' => 1,
            'admin' => 0,
        ]);

        $svc = $this->service(Mockery::mock(MemberService::class));

        $this->assertSame($country->id, $svc->getCountryIdByCode($dial));
        $this->assertSame(0, $svc->getCountryIdByCode(999_999_999));
    }

    public function test_find_valid_mobile_token_returns_null_when_no_mobile(): void
    {
        $svc = $this->service(Mockery::mock(MemberService::class));

        $this->assertNull($svc->findValidMobileToken([]));
        $this->assertNull($svc->findValidMobileToken(['mobile' => '']));
    }

    public function test_validate_banned_words_throws_when_username_contains_prohibited(): void
    {
        $agent = Agent::factory()->make();
        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('nicknameProhibitedWords')
            ->once()
            ->with(Mockery::type(Agent::class))
            ->andReturn(['badword']);

        $svc = $this->service($memberService);

        $this->expectException(ApiException::class);
        $svc->validateBannedWords(['username' => 'user_badword_x'], $agent);
    }

    public function test_validate_banned_words_passes_when_clean(): void
    {
        $agent = Agent::factory()->make();
        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('nicknameProhibitedWords')->once()->andReturn(['badword']);

        $svc = $this->service($memberService);

        $svc->validateBannedWords(['username' => 'cleanuser', 'nickname' => 'Nice'], $agent);

        $this->assertTrue(true);
    }
}
