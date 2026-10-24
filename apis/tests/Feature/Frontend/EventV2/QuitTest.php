<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Language;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/EventV2/QuitTest.php
 */
class QuitTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private string $apiPath = 'frontend/api/event-v2/quit';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent);

        $this->creatEventDetail($this->agent);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function creatEventDetail($agent): void
    {
        $lang = Language::create(['name' => '繁體中文', 'code' => 'zh-TW', 'admin' => 0]);
        $detail = EventV2Detail::factory()->create(['id' => 2, 'event_v2_id' => 2, 'agent_id' => $agent->id, 'condition_formula' => null, 'condition_config' => null, 'reward_formula' => '19', 'reward_config' => null, 'balance_formula' => '300', 'balance_config' => null, 'withdraw_formula' => '10', 'withdraw_config' => null, 'flow_formula' => null, 'flow_config' => null]);
        EventV2DetailLang::create(['event_v2_detail_id' => $detail->id, 'language_id' => $lang->id, 'name' => '每日 免费 AUD 19', 'image' => 'image', 'reward_name' => '每日 免费 AUD 19', 'content' => '每日 免费 AUD 19']);
    }

    public function test_quit_success()
    {
        EventV2UserJoin::insert(['event_v2_detail_id' => 2, 'reward' => 10, 'member_id' => $this->member->id, 'status' => EventV2UserJoinStatusEnum::JOIN->value, 'created_at' => now(), 'updated_at' => now()]);

        // Params
        $params = [
            'event_detail_id' => 2,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'message' => '成功',
                'data' => [],
            ]);
    }

    public function test_quit_no_join_error()
    {
        // Params
        $params = [
            'event_detail_id' => 2,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(501)
            ->assertJson([
                'code' => 501,
                'message' => '您尚未參加任何活動',
                'data' => [],
            ]);
    }
}
