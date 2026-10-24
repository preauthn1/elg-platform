<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Guest;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Guest/RegisterTest.php
 *
 * 註冊需要 invitation_code, 共有三種方式產生
 * 1. 使用帳號作為邀請碼 issue#1802
 */
class RegisterTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $apiPath = 'frontend/api/guest/register';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => Str::random(10),
            'password' => Hash::make('123456'),
            'payment_password' => Hash::make('123456'),
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'mobile' => '8860987987987',
            'mobile_validate' => 1,
            'first_name' => 'toyota',
        ]);

        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial();
        $registerServiceMock->expects('triggerCsSyncData')->andReturnNull();
        $registerServiceMock->expects('runRegisterEventStoredProcedures')->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    public function test_register_by_agent_username_success()
    {
        $this->agent->agent_mode = 1;
        $this->agent->username = 'a' . Str::random(6);
        $this->agent->save();

        // 註冊新帳號
        $data = [
            'invitation_code' => $this->agent->username, // Agent username
            'invitation_code_type' => 1, // 必須指定才會使用 username 作為邀請碼
            'username' => Str::random(10),
            'first_name' => '鈴木',
            'password' => '0806449',
            'password_confirmation' => '0806449',
        ];
        $response = $this->requestApi($this->apiPath, $data);

        $response->assertJson([
            'code' => 200,
        ]);

        $memberId = $response['data']['id'] ?? null;

        // 檢查 MemberAgent 紀錄
        $memberAgent = MemberAgent::where('member_id', $memberId)->first();
        $this->assertEquals($this->agent->id, $memberAgent->parent_id);
    }

    public function test_register_by_sponsor_username_success()
    {
        $this->agent->agent_mode = 1;
        $this->agent->save();

        MemberAgent::create([
            'member_id' => $this->member->id,
            'parent_id' => $this->agent->id,
            'topper_member_id' => 0,
            'agent_mode' => 1,
            'agent_level' => 1,
            'agent_relation' => '0,' . $this->member->id . ',',
        ]);

        // 註冊新帳號
        $data = [
            'invitation_code' => $this->member->username, // Member username
            'invitation_code_type' => 1, // 必須指定才會使用 username 作為邀請碼
            'username' => Str::random(10),
            'first_name' => '鈴木',
            'password' => '0806449',
            'password_confirmation' => '0806449',
        ];
        $response = $this->requestApi($this->apiPath, $data);

        $response->assertJson([
            'code' => 200,
        ]);

        $memberId = $response['data']['id'] ?? null;

        // 檢查 MemberAgent 紀錄
        $memberAgent = MemberAgent::where('member_id', $memberId)->first();
        $this->assertEquals($this->member->id, $memberAgent->topper_member_id);

    }
}
