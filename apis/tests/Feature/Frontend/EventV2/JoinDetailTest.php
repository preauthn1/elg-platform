<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Language;
use App\Models\Member;
use App\Models\MemberWallet;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/EventV2/JoinDetailTest.php
 */
class JoinDetailTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private string $apiPath = 'frontend/api/event-v2/join-detail';

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

    // 取得活動詳細
    public function test_success()
    {
        MemberWallet::create(['member_id' => $this->member->id, 'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET, 'status' => 1, 'balance' => 19]);
        EventV2UserJoin::insert(['event_v2_detail_id' => 2, 'member_id' => $this->member->id, 'reward' => 19, 'balance_limit' => 300,  'withdraw_limit' => 10, 'status' => EventV2UserJoinStatusEnum::JOIN->value, 'created_at' => now(), 'updated_at' => now()]);

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
                'data' => [
                    'balance_limit' => 300,
                    'flow_limit' => 0,
                    'balance' => 19,
                    'flow' => 0,
                ],
            ]);
    }

    public function test_error()
    {
        // Params
        $params = [
            'event_detail_id' => 2,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 501,
                'message' => '您尚未參加任何活動',
            ]);
    }
}
