<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Advertise;

use App\Enums\Advertise\AdvertiseTypeEnum;
use App\Models\Advertise;
use App\Models\AdvertiseImage;
use App\Models\AdvertiseLanguage;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use Database\Seeders\CountrySeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Advertise/AdvertiseListTest.php
 */
class AdvertiseListTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private Advertise $ad;

    private string $loginToken;

    private int $advertisePathId = 1;

    private int $languageId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountrySeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->ad = Advertise::create([
            'advertisement_path_id' => $this->advertisePathId,
            'agent_id' => $this->agent->id,
            'type' => AdvertiseTypeEnum::NOT_LINK,
            'content' => '0',
            'start_time' => now()->subDays(1)->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'status' => 1,
        ]);

        $image = AdvertiseImage::create([
            'image_url' => 'test.jpg',
        ]);

        $imagePc = AdvertiseImage::create([
            'image_url' => 'test_pc.jpg',
        ]);

        AdvertiseLanguage::create([
            'advertise_id' => $this->ad->id,
            'language_id' => $this->languageId,
            'advertise_image_id' => $image->id,
            'advertise_image_pc_id' => $imagePc->id,
        ]);

    }

    public function test_advertise_list_success_type_not_link()
    {
        // Arrange
        $params = [
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ];

        // Act
        $response = $this->act($params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($data[0]['image']);
        $this->assertNotEmpty($data[0]['image_pc']);
    }

    public function test_advertise_list_success_type_game_type()
    {
        // Arrange
        $this->ad->type = AdvertiseTypeEnum::GAME_TYPE;
        $this->ad->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals($data[0]['game_type_id'], $this->ad->content);
    }

    public function test_advertise_list_success_type_game()
    {
        // Arrange
        $game = Game::factory()->create([
            'vendor_id' => 1,
        ]);

        $this->ad->content = (string) $game->id;
        $this->ad->type = AdvertiseTypeEnum::GAME;
        $this->ad->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals($data[0]['game_id'], $this->ad->content);
        $this->assertEquals($data[0]['vendor_id'], $game->vendor_id);
    }

    public function test_advertise_list_success_type_vendor()
    {
        // Arrange
        $this->ad->type = AdvertiseTypeEnum::VENDOR;
        $this->ad->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals($data[0]['vendor_id'], $this->ad->content);

    }

    public function test_advertise_list_success_type_external_url()
    {
        // Arrange
        $testData = [
            $this->languageId => 'https://example.invalid',
            999 => 'https://wrong.url',
        ];

        $this->ad->type = AdvertiseTypeEnum::EXTERNAL_URL;
        $this->ad->content = json_encode($testData);
        $this->ad->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals($data[0]['url'], $testData[$this->languageId]);
    }

    public function test_advertise_list_success_type_internal_url()
    {
        // Arrange
        $this->ad->type = AdvertiseTypeEnum::INTERNAL_URL;
        $this->ad->content = 'https://example.invalid';
        $this->ad->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'path_id' => $this->advertisePathId,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals($data[0]['url'], $this->ad->content);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/advertise/list',
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
                    'id',
                    'path_id',
                    'type',
                    'game_type_id',
                    'game_id',
                    'vendor_id',
                    'url',
                    'image',
                    'image_pc',
                    'open_window',
                ],
            ],
        ];
    }
}
