<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Information;

use App\Enums\InformationTypeEnum;
use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Information;
use App\Models\InformationLanguage;
use App\Models\Member;
use App\Models\TiktokAccount;
use App\Services\ThirdParty\VideoTiktok;
use Database\Seeders\LanguageSeeder;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Information/ListTest.php
 */
class ListTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Game $game;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->game = $this->createGame();
        $this->createTiktokAccount();
        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function test_video_success()
    {
        $this->createInformationVideo();
        // mock 呼叫三方的部分
        $mock = Mockery::mock(VideoTiktok::class)->makePartial();
        $mock->shouldReceive('login')->andReturn([
            'sessionID' => '1234567890',
            'clientID' => '1234567890',
        ]);

        $this->app->instance(VideoTiktok::class, $mock);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/information/list',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    [
                        'type' => InformationTypeEnum::URL->value,
                        'game_type_id' => null,
                        'game_id' => null,
                        'vendor_id' => null,
                        'url' => 'https://example.invalid/session/1234567890',
                        'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'description' => '繁體中文描述',
                        'open_window' => 1,
                    ],
                ],
            ]);
    }

    /**
     * 未登入
     * php artisan test tests/Feature/Frontend/Information/ListTest.php --filter=test_not_login_video_success
     */
    public function test_not_login_video_success()
    {
        $this->createInformationVideo();
        // mock 呼叫三方的部分
        $mock = Mockery::mock(VideoTiktok::class)->makePartial();
        $mock->shouldReceive('login')->andReturn([
            'code' => 1000,
            'message' => 'success',
            'data' => [
                'sessionID' => '1234567890',
                'clientID' => '1234567890',
            ],
        ]);

        $this->app->instance(VideoTiktok::class, $mock);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/information/list',
            data: [
                'domain' => $this->domain,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    [
                        'type' => InformationTypeEnum::URL->value,
                        'game_type_id' => null,
                        'game_id' => null,
                        'vendor_id' => null,
                        'url' => null,
                        'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'description' => '繁體中文描述',
                        'open_window' => 1,
                    ],
                ],
            ]);
    }

    /**
     * 遊戲成功
     * php artisan test tests/Feature/Frontend/Information/ListTest.php --filter=test_game_success
     */
    public function test_game_success()
    {
        $this->createInformationGame();

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/information/list',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    [
                        'type' => InformationTypeEnum::GAME->value,
                        'game_type_id' => null,
                        'game_id' => $this->game->id,
                        'vendor_id' => $this->game->vendor_id,
                        'url' => null,
                        'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'description' => '',
                        'open_window' => 1,
                    ],
                ],
            ]);
    }

    /**
     * 內部連結
     * php artisan test tests/Feature/Frontend/Information/ListTest.php --filter=test_internal_url_success
     */
    public function test_internal_url_success()
    {
        $this->createInformationInternalUrl();

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/information/list',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    [
                        'type' => InformationTypeEnum::INTERNAL_URL->value,
                        'game_type_id' => null,
                        'game_id' => null,
                        'vendor_id' => null,
                        'url' => 'just internal url',
                        'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
                        'description' => '',
                        'open_window' => 1,
                    ],
                ],
            ]);
    }

    public function createTiktokAccount()
    {
        // INSERT INTO `xsgcasino`.`tiktok_accounts` (`id`, `media_id`, `username`, `password`, `member_id`, `created_at`, `updated_at`, `admin`) VALUES (190952, 1, 'qa00000235', '89816bd46ded', 0, '2025-01-10 06:46:15', '2025-01-10 06:46:16', 1);
        TiktokAccount::query()->create([
            'media_id' => 1,
            'username' => 'qa00000235',
            'password' => '89816bd46ded',
            'member_id' => $this->member->id,
            'admin' => 1,
        ]);
    }

    public function createInformationInternalUrl()
    {
        // INSERT INTO `xsgcasino`.`informations` (`id`, `agent_group_id`, `type`, `content`, `open_window`, `sort`, `status`, `description`, `remark`, `created_at`, `updated_at`, `admin`) VALUES (6, 1, 5, 'vmovie', 0, 1, 1, NULL, '', '2023-08-18 09:24:01', '2023-08-21 01:32:10', 4386);
        $information = Information::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'type' => InformationTypeEnum::INTERNAL_URL->value,
            'content' => 'just internal url',
            'open_window' => 1,
            'sort' => 1,
            'status' => 1,
            'description' => null,
            'remark' => '',
            'admin' => 0,
        ]);

        // INSERT INTO `xsgcasino`.`information_languages` (`id`, `information_id`, `language`, `image`, `image_pc`, `created_at`, `updated_at`, `admin`) VALUES (6, 6, 1, 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png', 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png', '2023-08-18 09:24:01', '2023-08-20 12:36:59', 1);
        // INSERT INTO `xsgcasino`.`information_languages` (`id`, `information_id`, `language`, `image`, `image_pc`, `created_at`, `updated_at`, `admin`) VALUES (7, 6, 2, 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png', 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png', '2023-08-18 09:24:01', '2023-08-20 12:36:59', 1);
        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::EN->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'admin' => 1,
        ]);

        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::ZH_TW->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'admin' => 1,
        ]);
    }

    public function createInformationVideo()
    {
        // INSERT INTO `xsgcasino`.`informations` (`id`, `agent_group_id`, `type`, `content`, `open_window`, `sort`, `status`, `description`, `remark`, `created_at`, `updated_at`, `admin`) VALUES (6, 1, 5, 'vmovie', 0, 1, 1, NULL, '', '2023-08-18 09:24:01', '2023-08-21 01:32:10', 4386);
        $information = Information::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'type' => InformationTypeEnum::INTERNAL_URL->value,
            'content' => 'vmovie',
            'open_window' => 1,
            'sort' => 1,
            'status' => 1,
            'description' => json_encode([
                LanguageEnum::EN->value => 'english description',
                LanguageEnum::ZH_TW->value => '繁體中文描述',
            ]),
            'remark' => '',
            'admin' => 0,
        ]);

        // INSERT INTO `xsgcasino`.`information_languages` (`id`, `information_id`, `language`, `image`, `image_pc`, `created_at`, `updated_at`, `admin`) VALUES (6, 6, 1, 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png', 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png', '2023-08-18 09:24:01', '2023-08-20 12:36:59', 1);
        // INSERT INTO `xsgcasino`.`information_languages` (`id`, `information_id`, `language`, `image`, `image_pc`, `created_at`, `updated_at`, `admin`) VALUES (7, 6, 2, 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png', 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png', '2023-08-18 09:24:01', '2023-08-20 12:36:59', 1);
        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::EN->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'admin' => 1,
        ]);

        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::ZH_TW->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'admin' => 1,
        ]);
    }

    public function createInformationGame()
    {
        // INSERT INTO `xsgcasino`.`informations` (`id`, `agent_group_id`, `type`, `content`, `open_window`, `sort`, `status`, `description`, `remark`, `created_at`, `updated_at`, `admin`) VALUES (6, 1, 5, 'vmovie', 0, 1, 1, NULL, '', '2023-08-18 09:24:01', '2023-08-21 01:32:10', 4386);
        $information = Information::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'type' => InformationTypeEnum::GAME->value,
            'content' => $this->game->id,
            'open_window' => 1,
            'sort' => 1,
            'status' => 1,
            'description' => null,
            'remark' => '',
            'admin' => 0,
        ]);
        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::EN->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099486822.png',
            'admin' => 1,
        ]);

        InformationLanguage::query()->create([
            'information_id' => $information->id,
            'language' => LanguageEnum::ZH_TW->value,
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'image_pc' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/169099484153.png',
            'admin' => 1,
        ]);
    }

    public function createGame(): Game
    {
        // INSERT INTO `xsgcasino`.`games` (`id`, `vendor_id`, `type_id`, `join_bet_event`, `join_win_event`, `game_type`, `name`, `lottery_pool`, `image`, `is_new`, `is_hot`, `open_window`, `status`, `rtp`, `sort`, `info`, `remark`, `memo`, `created_at`, `updated_at`, `admin`, `valid_bet_rate`, `calc_flow_limit`) VALUES (15260, 148, 2, 0, 0, '17357', 'Buffalo Link', 0.000, 'https://example.invalid/resources/sitepicstbs/xgames/game_img_6/BuffaloLink.jpg', 0, 0, 0, 1, 0.00, 0, NULL, NULL, NULL, '2025-05-01 04:23:05', '2025-05-01 04:23:05', 1, 1.00, 1);
        $game = Game::query()->create([
            'vendor_id' => 148,
            'type_id' => 2,
            'join_bet_event' => 0,
            'join_win_event' => 0,
            'game_type' => '17357',
            'name' => 'Buffalo Link',
            'image' => 'https://example.invalid/resources/sitepicstbs/xgames/game_img_6/BuffaloLink.jpg',
            'is_new' => 0,
            'is_hot' => 0,
            'open_window' => 0,
            'status' => 1,
            'rtp' => 0,
            'sort' => 0,
            'info' => null,
            'remark' => null,
            'memo' => null,
            'admin' => 1,
            'valid_bet_rate' => 1.00,
            'calc_flow_limit' => 1,
            'lottery_pool' => 0.000,
        ]);

        return $game;
    }
}
