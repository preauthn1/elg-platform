<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_member_update_password_success()
    {
        // Arrange
        $oldPassword = [REDACTED]';
        $newPassword = [REDACTED]';
        $member = Member::factory()->create([
            'parent' => $this->agent->id,
            'password' => Hash::make($oldPassword),
            'status' => 1,
        ]);

        $this->loginToken = [REDACTED];

        // Act
        $response = $this->act([
            'password' => $oldPassword,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ]);
        $member->refresh();

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure($this->basicResponseDataStructure());
        $this->assertTrue(Hash::check($newPassword, $member->password));
    }

    public function test_member_update_password_failed_with_old_password_incorrect()
    {
        // Arrange
        $oldPassword = [REDACTED]';
        $newPassword = [REDACTED]';
        $member = Member::factory()->create([
            'parent' => $this->agent->id,
            'password' => Hash::make($oldPassword),
            'status' => 1,
        ]);

        $this->loginToken = [REDACTED];

        // Act
        $response = $this->act([
            'password' => $oldPassword . '11',
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ]);
        $member->refresh();

        // Assert
        $response->assertStatus(400);
        $response->assertJsonStructure($this->basicResponseDataStructure());
        $this->assertTrue(Hash::check($oldPassword, $member->password));
        $this->assertEquals(__('error_message.1018'), $response->json('message'));
    }

    public function test_member_update_password_failed_with_new_password_same_as_payment_password()
    {
        // Arrange
        $oldPassword = [REDACTED]';
        $newPassword = [REDACTED]';
        $member = Member::factory()->create([
            'parent' => $this->agent->id,
            'password' => Hash::make($oldPassword),
            'payment_password' => Hash::make($newPassword),
            'status' => 1,
        ]);

        $this->loginToken = [REDACTED];

        // Act
        $response = $this->act([
            'password' => $oldPassword,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ]);
        $member->refresh();

        // Assert
        $response->assertStatus(400);
        $response->assertJsonStructure($this->basicResponseDataStructure());
        $this->assertTrue(Hash::check($oldPassword, $member->password));
        $this->assertEquals(__('error_message.9019'), $response->json('message'));
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/member/change-password',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
        ];
    }
}
