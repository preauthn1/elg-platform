<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/CheckProfileTest.php
 */
class CheckProfileTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $apiPath = 'frontend/api/member/check-profile';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
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

    public function test_need_realname_verify()
    {
        // Arrange
        $this->member->first_name = '';
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFIED_VISIBLE, 1);

        // Request
        $response = $this->requestApi($this->apiPath);

        // Assert
        $response->assertJson([
            'code' => 325,
        ]);
    }

    public function test_need_payment_password_verify()
    {
        // Arrange
        $this->member->payment_password = '';
        $this->member->save();

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE, 1);

        // Request
        $response = $this->requestApi($this->apiPath);

        // Assert
        $response->assertJson([
            'code' => 323,
        ]);
    }

    public function test_need_mobile_verify()
    {
        // Arrange
        $this->member->mobile_validate = 0;
        $this->member->save();

        $this->agent->mobile_validate = 1;
        $this->agent->save();

        // Request
        $response = $this->requestApi($this->apiPath);

        // Assert
        $response->assertJson([
            'code' => 324,
        ]);
    }

    public function test_need_email_verify()
    {
        // Arrange
        $this->member->email_validate = 0;
        $this->member->save();

        $this->agent->email_validate = 1;
        $this->agent->save();

        // Request
        $response = $this->requestApi($this->apiPath);

        // Assert
        $response->assertJson([
            'code' => 326,
        ]);
    }
}
