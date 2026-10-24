<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetBirthdayTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    private const string API_SET_REALNAME = 'frontend/api/member/set-birthday';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'keroro',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);
    }

    /**
     * 成功修改生日
     */
    public function test_set_birthday_success()
    {
        $birthday = now()->subYears(20)->toDateString();

        $response = $this->requestApi(self::API_SET_REALNAME, [
            'birthday' => $birthday,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertEquals($birthday, $this->member->birthday);
    }

    /**
     * 錯誤的日期格式
     */
    public function test_set_birthday_fails_with_invalid_format()
    {
        $response = $this->requestApi(self::API_SET_REALNAME, [
            'birthday' => now()->subYears(20)->format('Ymd'),
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }

    /**
     * 未成年
     */
    public function test_set_birthday_fails_for_underage()
    {
        $response = $this->requestApi(self::API_SET_REALNAME, [
            'birthday' => now()->subYears(10)->toDateString(),
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }

    /**
     * 已設定生日
     */
    public function test_set_birthday_fails_when_already_set()
    {
        $this->member->update([
            'birthday' => now()->subYears(20)->toDateString(),
        ]);

        $response = $this->requestApi(self::API_SET_REALNAME, [
            'birthday' => now()->subYears(21)->toDateString(),
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }
}
