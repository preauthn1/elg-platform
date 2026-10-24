<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Vendor;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NonAuth_GameTypesTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->agent = $this->createAgentAndDomain();
        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
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
