<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TiktokAccount;
use App\Services\ThirdParty\VideoTiktok;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Mockery;
use Tests\TestCase;

class VideosTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_VIDEOS = '/frontend/api/videos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_videos_success()
    {
        TiktokAccount::create([
            'media_id' => 1,
            'member_id' => 0,
            'username' => 'test',
            'password' => 'test',
            'admin' => 0,
        ]);

        $mock = Mockery::mock(VideoTiktok::class, function ($mock) {
            $mock->shouldReceive('login')->andReturn([
                'sessionID' => 'test',
                'clientID' => 'test',
            ]);
        });
        $this->app->instance(VideoTiktok::class, $mock);

        $response = $this->requestApi(self::API_VIDEOS, [
            'domain' => $this->domain,
        ]);
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    '*' => [
                        'username',
                        'password',
                        'ios_download',
                        'ios_open',
                        'android_download',
                        'android_open',
                        'app_icon',
                    ],
                ],
            ]);
    }
}
