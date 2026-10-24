<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Version;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class VersionTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_VERSION = '/frontend/api/versions';

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

    public function test_version_success()
    {
        Version::create([
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'agent_id' => $this->agent->id,
            'type' => 1,
            'ios_version' => '1.0.0',
            'ios_link' => 'https://example.invalid',
            'android_version' => '1.0.0',
            'android_link' => 'https://example.invalid',
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_VERSION, [
            'domain' => $this->domain,
        ]);
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'ios' => [
                        'version',
                        'downloadLink',
                    ],
                    'android' => [
                        'version',
                        'downloadLink',
                    ],
                ],
            ]);
    }
}
