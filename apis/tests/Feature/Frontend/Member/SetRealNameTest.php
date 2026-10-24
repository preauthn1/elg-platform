<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetRealNameTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    private const string API_SET_REALNAME = 'frontend/api/member/set-realname';

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
     * 修改真實姓名 (first_name)
     */
    public function test_set_realname_success()
    {
        $realname = '劉硬炮';

        $response = $this->requestApi(self::API_SET_REALNAME, [
            'real_name' => $realname,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertEquals($realname, $this->member->first_name);
    }

    /**
     * 禁用字元
     */
    public function test_set_realname_fails_with_invalid_characters()
    {
        $realname = '@#$%^&*()_+';

        $response = $this->requestApi(self::API_SET_REALNAME, [
            'real_name' => $realname,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }

    /**
     * 已設定真實姓名
     */
    public function test_set_realname_fails_when_already_set()
    {
        $this->member->update([
            'first_name' => '許不了',
        ]);

        $realname = '劉硬炮';

        $response = $this->requestApi(self::API_SET_REALNAME, [
            'real_name' => $realname,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }
}
