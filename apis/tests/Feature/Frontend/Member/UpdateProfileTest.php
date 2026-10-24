<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentPersonalSetting;
use App\Models\Member;
use App\Services\FileStorageService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/UpdateProfileTest.php
 */
class UpdateProfileTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    private const string API_UPDATE_PROFILE = 'frontend/api/member/update-profile';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'keroro',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);
    }

    public function test_update_nickname_success()
    {
        // Arrange
        $nickname = 'xcgdfghdfg';

        $params = [
            'nickname' => $nickname,
        ];
        $response = $this->requestApi(self::API_UPDATE_PROFILE, $params);
        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertEquals($nickname, $this->member->nickname);
    }

    public function test_update_nickname_failed_default_guard()
    {
        // Arrange
        $unusableWords = 'service';
        $nickname = "{$unusableWords}_123";

        // Act
        $response = $this->requestApi(self::API_UPDATE_PROFILE, [
            'nickname' => $nickname,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.not allowed words', ['attribute' => 'nickname', 'word' => $unusableWords]),
        ]);
    }

    public function test_update_nickname_failed_agent_setting_guard()
    {
        // Arrange
        $unusableWords = 'customized';
        $nickname = "{$unusableWords}_adsadlfkhg";

        AgentPersonalSetting::create([
            'agent_id' => $this->agent->id,
            'setting_id' => AgentCustomizedSettingKeyEnum::NICKNAME_PROHIBITED_WORDS,
            'value' => $unusableWords,
            'created_by' => 0,
        ]);

        // Act
        $response = $this->requestApi(self::API_UPDATE_PROFILE, [
            'nickname' => $nickname,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.not allowed words', ['attribute' => 'nickname', 'word' => $unusableWords]),
        ]);
    }

    public function test_update_image_success()
    {
        // Arrange
        $base64String = 'data:image/jpeg;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $mock = Mockery::mock(FileStorageService::class)->makePartial();
        $mock->shouldReceive('storeBase64Image')->andReturn($this->member->id . '.jpeg');
        $this->app->instance(FileStorageService::class, $mock);

        // Act
        $response = $this->requestApi(self::API_UPDATE_PROFILE, [
            'image' => $base64String,
        ]);
        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertStringEndsWith($this->member->id . '.jpeg', $this->member->image);
    }
}
