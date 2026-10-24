<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\Language;
use App\Models\Member;
use App\Models\NewbieChallengeConfig;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/EventV2/ListTest.php
 */
class ListTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private string $apiPath = 'frontend/api/event/list';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent);

        $this->creatEventDetail($this->agent);

        // migration 種子的全站新人挑戰設定預設是停用（後台新建活動的安全預設），
        // 這裡測試自己把它開起來才符合這個測試要驗證「有啟用中活動」的前提；sort 也一併
        // 設高於這個測試自建的 event_v2（預設 0）才會排在前面——排序現在完全交給後台
        // config.sort 決定，不再寫死新人挑戰永遠最前面
        NewbieChallengeConfig::whereNull('agent_id')->update(['status' => 1, 'sort' => 1]);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function creatEventDetail($agent): void
    {
        // $lang = Language::create(['name' => '繁體中文', 'code' => 'zh-tw', 'status' => 1, 'created_at' => null, 'updated_at' => null, 'admin' => 0]);
        $detail = EventV2Detail::factory()->create(['id' => 1, 'event_v2_id' => 1, 'agent_id' => $agent->id, 'condition_formula' => null, 'condition_config' => null, 'reward_formula' => '59', 'reward_config' => null, 'balance_formula' => '600', 'balance_config' => null, 'withdraw_formula' => '30', 'withdraw_config' => null, 'flow_formula' => null, 'flow_config' => null, 'start_time' => '2025-01-01 00:00:00', 'end_time' => '2035-01-01 00:00:00']);
        EventV2DetailLang::create(['event_v2_detail_id' => $detail->id, 'language_id' => LanguageEnum::ZH_TW->value, 'name' => '免費送', 'image' => 'image', 'reward_name' => '免費送', 'content' => '免費送']);
    }

    // 取得列表
    public function test_list_success()
    {
        // Params
        $params = [
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        // data[0] 是新人專享挑戰（NewbieChallengeConfig 全站種子設定 agent_id=null 對任何 agent 都生效，
        // setUp() 把 sort 設成 1 高於這個測試自建的活動 v2（預設 0）），data[1] 才是活動 v2
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'message' => '成功',
                'data' => [
                    [
                        'event_v1_id' => null,
                        'event_v2_id' => null,
                        'name' => '新人專享挑戰',
                        'image' => null,
                        'params' => null,
                        'end_time' => null,
                        'tp_event' => 1,
                    ],
                    [
                        'event_v1_id' => null,
                        'event_v2_id' => 1,
                        'name' => '免費送',
                        'image' => 'image',
                        'params' => null,
                        'end_time' => Carbon::parse('2035-01-01 00:00:00')->toFrontendTime()->toDateTimeString(),
                        'tp_event' => null,
                    ],
                ],
            ]);
    }
}
