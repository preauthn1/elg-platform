<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/LogoutTest.php
 */
class LogoutTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $apiPath = 'frontend/api/member/logout';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent, [
            // 'username' => 'yamaha',
            'password' => Hash::make('123456'),
            'payment_password' => Hash::make('123456'),
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'mobile' => '8860987987987',
            'mobile_validate' => 1,
            'first_name' => 'toyota',
        ]);
    }

    public function test_member_update_password_success()
    {
        // 登出
        $response = $this->requestApi($this->apiPath);
        $response->assertJson([
            'code' => 200,
        ]);

        // 嘗試請求其他接口
        $response = $this->requestApi('frontend/api/member/detail');
        $response->assertJson([
            'code' => 319,
        ]);
    }
}
