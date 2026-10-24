<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Vendor;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GameTypesTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private string $memberPassword = [REDACTED]';

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->agent = $this->createAgentAndDomain();
        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();

        $this->member = Member::create([
            'balance' => 0.00,
            'username' => 'testuser',
            'nickname' => 'testuser',
            'parent' => $this->agent->id,
            'status' => 1,
            'password' => Hash::make($this->memberPassword),
            'admin' => 0,
        ]);

        MemberAccessToken:[REDACTED]
            'member_id' => $this->member->id,
            'token' => $this->loginToken,
            'expire_date' => now()->addDays(1),
            'admin' => 0,
        ]);
    }

    public function test_get_game_types_success()
    {
        // Arrange
        $vendor = $this->createBasicVendorGames($this->agent);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJsonStructure($this->basicResponseDataStructure());
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/vendor/game-types',
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
            'data' => [
                [
                    'vendor_id',
                    'type_id',
                    'root_type',
                ],
            ],
        ];
    }
}
