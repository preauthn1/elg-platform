<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Http\Requests\Agent\EventV2\CreateRequest;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 活動 V2 後台 create／update 的領取去向（withdraw_type，target/tickets#4231）。
 *
 * 必須成立：
 * 1. 舊後台相容：舊 WEB_AgentBackend 每次整包送、永遠不送 withdraw_type。
 *    新建落預設主錢包；更新沿用資料庫現值，不得把積分活動翻回主錢包。
 * 2. 積分型現階段強制「參加即領獎」：join_withdraw 送 0 也存 1；有效去向沒送時以資料庫現值判斷。
 * 3. 積分與活動錢包無關：獎勵公式／配置、餘額限制、稽核流水六格必須為空；積分數量填提款限制公式（必填），
 *    有效期與上限填提款限制配置 {"expire":N,"limit":M}；階梯可改用 tiers（與公式二擇一）。主錢包型不吃這三個 key。
 * 5. 領取去向建立後鎖定：Update 送不同值 400，同值或沒送照舊。
 * 4. 積分型不開放每日區間儲值／每週累積儲值／每日儲值組合：以 handler_class 擋，不寫死類型 id。
 *
 * php artisan test tests/Feature/Agent/EventV2/WithdrawTypeRequestTest.php
 */
class WithdrawTypeRequestTest extends TestCase
{
    private const string CREATE = 'agent/api/event-v2/create';

    private const string UPDATE = 'agent/api/event-v2/update';

    private const string DETAIL = 'agent/api/event-v2/detail';

    private const string LIST = 'agent/api/event-v2/list';

    private const string EVENT_LIST = 'agent/api/event-v2/event-list';

    /** event_v2_id=2 每日登入（DailyFreeService）：測試庫由 migration 寫入的類型列 */
    private const int EVENT_V2_ID = 2;

    /** 積分型必須留空的六格，值為「有填」的樣本 */
    private const array SCORE_BLOCKED_FIELDS = [
        'reward_formula' => '10',
        'reward_config' => ['limit' => 300],
        'balance_formula' => 'reward * 3',
        'balance_config' => ['x' => 1],
        'flow_formula' => 'reward * 2',
        'flow_config' => ['x' => 1],
    ];

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // develop 上 #4352 的後台端點讀寫 event_v2_details.is_backend_deposit，但那欄尚無 migration（dev 庫是手動加的）；
        // CI 乾淨庫沒有它時，本檔走 200 路徑的建立／更新／詳情會 500。缺口屬 #4352，這裡偵測到就跳過，
        // 等該 migration 進來自動恢復執行。master 的 CreateRequest 沒這條規則，條件恆為 false、測試照跑。
        if (array_key_exists('is_backend_deposit', (new CreateRequest)->rules())
            && !Schema::hasColumn('event_v2_details', 'is_backend_deposit')) {
            $this->markTestSkipped('event_v2_details.is_backend_deposit 尚無 migration（target/tickets#4352），後台活動 V2 端點無法在乾淨庫執行');
        }

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // create／update 只開放 admin 或 admin 子帳號（agent->id === 1 || host_id === 1）
        $this->agent = $this->createAgentAndDomain(['host_id' => 1]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * 舊後台送的整包欄位（沒有 withdraw_type）：主錢包型、獎勵公式 10。陣列欄位一律帶空陣列：
     * Controller 對它們 foreach，缺 key 會炸，舊後台也是每次都送。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_v2_id' => self::EVENT_V2_ID,
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '10',
            'reward_config' => null,
            'balance_formula' => null,
            'balance_config' => null,
            'flow_formula' => null,
            'flow_config' => null,
            'withdraw_formula' => null,
            'withdraw_config' => null,
            'bonus_vendor_id' => null,
            'ip_block' => null,
            'phone_block' => null,
            'account_block' => null,
            'join_withdraw' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
            'start_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_time' => now()->addMonth()->format('Y-m-d H:i:s'),
            'is_recycle' => 1,
            'status' => 1,
            'detail_lang' => [],
            'exclude_agent' => [],
            'restrict_game_type' => [],
            'restrict_vendor' => [],
            'exclude_vendor_data' => [],
        ], $overrides);
    }

    /**
     * 積分型的合法整包：領取去向積分、獎勵公式空、提款限制公式＝積分數量 10、有效期 720。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scorePayload(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'withdraw_type' => EventV2WithdrawTypeEnum::SCORE->value,
            'reward_formula' => null,
            'withdraw_formula' => '10',
            'withdraw_config' => ['expire' => 720],
        ], $overrides));
    }

    /**
     * 舊後台對積分活動的整包：欄位都是資料庫現值（獎勵空、提款公式 10），但沒有 withdraw_type。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function legacyScorePayload(array $overrides = []): array
    {
        $payload = $this->scorePayload($overrides);
        unset($payload['withdraw_type']);

        return $payload;
    }

    private function createDetail(EventV2WithdrawTypeEnum $type, int $joinWithdraw = 1): EventV2Detail
    {
        $isScore = $type->isScore();

        return EventV2Detail::factory()->create([
            'event_v2_id' => self::EVENT_V2_ID,
            'agent_id' => $this->agent->id,
            'reward_formula' => $isScore ? null : '10',
            'withdraw_formula' => $isScore ? '10' : null,
            'withdraw_config' => $isScore ? ['expire' => 720] : null,
            'withdraw_type' => $type->value,
            'join_withdraw' => $joinWithdraw,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
            'start_time' => now()->addDay(),   // 尚未開始，update 才允許改 start_time／event_v2_id
            'end_time' => now()->addMonth(),
        ]);
    }

    /** 測試庫的 event_v2 類型 id 與 dev／正式不同，一律以 handler_class 反查，沒有就補一列 */
    private function eventV2Id(string $handlerClass, string $name): int
    {
        return EventV2::query()->firstOrCreate(
            ['handler_class' => $handlerClass],
            ['name' => $name, 'schedule' => null],
        )->id;
    }

    private function latestDetail(): EventV2Detail
    {
        return EventV2Detail::query()->where('agent_id', $this->agent->id)->latest('id')->firstOrFail();
    }

    // -------------------------------------------------------
    // create
    // -------------------------------------------------------

    public function test_create_without_withdraw_type_defaults_to_main_wallet(): void
    {
        $this->requestApi(self::CREATE, $this->payload(['join_withdraw' => 0]))->assertOk();

        $detail = $this->latestDetail();
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $detail->withdraw_type, '舊後台不送 withdraw_type → 主錢包');
        $this->assertSame(0, $detail->join_withdraw, '主錢包型 join_withdraw 照送的存');
    }

    public function test_create_score_type_stores_type_and_forces_join_withdraw_one(): void
    {
        $this->requestApi(self::CREATE, $this->scorePayload([
            'join_withdraw' => 0,
            'withdraw_config' => ['expire' => 720, 'limit' => 300],
        ]))->assertOk();

        $detail = $this->latestDetail();
        $this->assertSame(EventV2WithdrawTypeEnum::SCORE, $detail->withdraw_type);
        $this->assertSame(1, $detail->join_withdraw, '積分型現階段強制參加即領獎');
        $this->assertSame('10', $detail->withdraw_formula, '積分數量在提款限制公式');
        $this->assertNull($detail->reward_formula);
        // MySQL JSON 型別會重排 key，只比內容不比順序
        $this->assertEquals(['expire' => 720, 'limit' => 300], $detail->withdraw_config, '有效期與上限同在提款限制配置');
    }

    public function test_create_score_type_rejects_reward_balance_flow_fields(): void
    {
        // 積分與活動錢包無關：六格任一有值就擋
        foreach (self::SCORE_BLOCKED_FIELDS as $field => $value) {
            $this->requestApi(self::CREATE, $this->scorePayload([$field => $value]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.score_fields_not_allowed'), $field);
        }
        $this->assertDatabaseMissing('event_v2_details', ['agent_id' => $this->agent->id]);
    }

    public function test_create_score_type_requires_withdraw_formula(): void
    {
        $this->requestApi(self::CREATE, $this->scorePayload(['withdraw_formula' => null]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_formula_required'));
    }

    public function test_create_score_type_rejects_non_numeric_limit(): void
    {
        $this->requestApi(self::CREATE, $this->scorePayload(['withdraw_config' => ['expire' => 720, 'limit' => 'abc']]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_limit_invalid'));
    }

    public function test_create_main_wallet_type_still_accepts_limits(): void
    {
        // 回歸錨點：主錢包型（含舊後台）帶三道門與獎勵照舊可存
        $this->requestApi(self::CREATE, $this->payload([
            'balance_formula' => 'reward * 3',
            'flow_formula' => 'reward * 2',
            'withdraw_formula' => 'reward * 5',
            'withdraw_config' => ['balance_multiple' => 0.5],
        ]))->assertOk();

        $this->assertDatabaseHas('event_v2_details', [
            'agent_id' => $this->agent->id,
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            'balance_formula' => 'reward * 3',
            'reward_formula' => '10',
        ]);
    }

    public function test_create_rejects_non_positive_expire(): void
    {
        // 專案把 ValidationException 包成 {code:400, message:第一條錯誤}（bootstrap/app.php），沒有 errors 對照表
        $this->requestApi(self::CREATE, $this->scorePayload(['withdraw_config' => ['expire' => 0]]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_expire_invalid'));
        $this->assertDatabaseMissing('event_v2_details', ['agent_id' => $this->agent->id]);
    }

    public function test_create_main_wallet_rejects_score_only_config_keys(): void
    {
        // 主錢包型的提款限制配置只認 balance_multiple：帶 expire／limit／tiers 一律擋，避免運營以為有效
        foreach ([['expire' => 720], ['limit' => 300], ['tiers' => [['condition' => 'deposit >= 1', 'score' => 1]]]] as $config) {
            $this->requestApi(self::CREATE, $this->payload(['withdraw_config' => $config]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.main_wallet_config_keys_not_allowed'), json_encode($config));
        }
        $this->assertDatabaseMissing('event_v2_details', ['agent_id' => $this->agent->id]);
    }

    public function test_create_score_type_accepts_tiers_without_formula(): void
    {
        $tiers = [
            ['condition' => 'deposit >= 300', 'score' => 30],
            ['condition' => 'deposit >= 100', 'score' => 'deposit * 0.1'],
        ];
        $this->requestApi(self::CREATE, $this->scorePayload([
            'withdraw_formula' => null,
            'withdraw_config' => ['expire' => 720, 'limit' => 1000, 'tiers' => $tiers],
        ]))->assertOk();

        $detail = $this->latestDetail();
        $this->assertNull($detail->withdraw_formula);
        $this->assertEquals($tiers, $detail->withdraw_config['tiers'], '階梯原樣落地');
    }

    public function test_create_score_type_rejects_formula_and_tiers_together(): void
    {
        $this->requestApi(self::CREATE, $this->scorePayload([
            'withdraw_config' => ['expire' => 720, 'tiers' => [['condition' => 'deposit >= 100', 'score' => 10]]],
        ]))->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_amount_source_conflict'));
    }

    public function test_create_score_type_rejects_malformed_tiers(): void
    {
        foreach ([
            'not-a-list' => ['condition' => 'deposit >= 100', 'score' => 10],
            'missing-score' => [['condition' => 'deposit >= 100']],
            'empty-condition' => [['condition' => '', 'score' => 10]],
            'blank-score' => [['condition' => 'deposit >= 100', 'score' => '']],
        ] as $case => $tiers) {
            $this->requestApi(self::CREATE, $this->scorePayload([
                'withdraw_formula' => null,
                'withdraw_config' => ['tiers' => $tiers],
            ]))->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.score_tiers_invalid'), $case);
        }
    }

    public function test_create_accepts_empty_expire_as_permanent(): void
    {
        $this->requestApi(self::CREATE, $this->scorePayload(['withdraw_config' => ['expire' => '']]))->assertOk();

        $this->assertSame(EventV2WithdrawTypeEnum::SCORE, $this->latestDetail()->withdraw_type, '留空＝永久，不擋');
    }

    public function test_create_rejects_unknown_withdraw_type(): void
    {
        $this->requestApi(self::CREATE, $this->payload(['withdraw_type' => 9]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
        $this->assertDatabaseMissing('event_v2_details', ['agent_id' => $this->agent->id]);
    }

    public function test_create_rejects_score_type_on_array_reward_event_types(): void
    {
        foreach ([
            ['DailyDepositRangeService', '每日區間儲值'],
            ['WeeklyDepositService', '每週累積儲值'],
            ['DailyDepositComboService', '每日儲值組合'],
        ] as [$handler, $name]) {
            $this->requestApi(self::CREATE, $this->scorePayload(['event_v2_id' => $this->eventV2Id($handler, $name)]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.score_type_not_supported'), $handler);
        }
        $this->assertDatabaseMissing('event_v2_details', ['agent_id' => $this->agent->id]);
    }

    public function test_create_main_wallet_type_still_accepts_array_reward_event_types(): void
    {
        // 回歸錨點：三種陣列型走主錢包（含舊後台不送 withdraw_type）照舊可建
        $this->requestApi(self::CREATE, $this->payload([
            'event_v2_id' => $this->eventV2Id('DailyDepositRangeService', '每日區間儲值'),
            'reward_formula' => null,
            'reward_config' => [['reward' => 5, 'condition' => 'deposit < 50']],
        ]))->assertOk();

        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $this->latestDetail()->withdraw_type);
    }

    // -------------------------------------------------------
    // update
    // -------------------------------------------------------

    public function test_update_without_withdraw_type_keeps_score_type_and_forces_join_withdraw_one(): void
    {
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        // 舊後台整包送資料庫現值、沒有 withdraw_type、還把參加即領獎取消勾選
        $this->requestApi(self::UPDATE, $this->legacyScorePayload([
            'event_detail_id' => $detail->id,
            'join_withdraw' => 0,
        ]))->assertOk();

        $detail->refresh();
        $this->assertSame(EventV2WithdrawTypeEnum::SCORE, $detail->withdraw_type, '沒送 withdraw_type 不得翻回主錢包');
        $this->assertSame(1, $detail->join_withdraw, '有效去向為積分 → 參加即領獎仍為 1');
    }

    public function test_update_without_withdraw_type_on_score_detail_still_rejects_reward_fields(): void
    {
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        $this->requestApi(self::UPDATE, $this->legacyScorePayload([
            'event_detail_id' => $detail->id,
            'reward_formula' => '10',
        ]))->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_fields_not_allowed'));

        $this->assertNull($detail->fresh()->reward_formula, '擋下不寫');
    }

    public function test_update_rejects_changing_withdraw_type_in_either_direction(): void
    {
        // 領取去向建立後鎖定（Max 2026-09-23）：積分→主錢包、主錢包→積分都擋，要改請另建活動
        $score = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);
        $this->requestApi(self::UPDATE, $this->payload([
            'event_detail_id' => $score->id,
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
        ]))->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.withdraw_type_locked'));
        $this->assertSame(EventV2WithdrawTypeEnum::SCORE, $score->fresh()->withdraw_type, '擋下不寫');

        $wallet = $this->createDetail(EventV2WithdrawTypeEnum::MAIN_WALLET);
        $this->requestApi(self::UPDATE, $this->scorePayload(['event_detail_id' => $wallet->id]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.withdraw_type_locked'));
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $wallet->fresh()->withdraw_type, '擋下不寫');
    }

    public function test_update_with_same_withdraw_type_is_allowed(): void
    {
        // 新後台編輯時會把現值原樣送回，同值不算異動
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        $this->requestApi(self::UPDATE, $this->scorePayload([
            'event_detail_id' => $detail->id,
            'withdraw_config' => ['expire' => 48],
        ]))->assertOk();

        $this->assertEquals(['expire' => 48], $detail->fresh()->withdraw_config);
    }

    public function test_update_main_wallet_detail_without_withdraw_type_is_unchanged(): void
    {
        // 回歸錨點：既有主錢包型活動被舊後台只改門檻重存，去向與參加即領獎一字不變
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::MAIN_WALLET, joinWithdraw: 0);

        $this->requestApi(self::UPDATE, $this->payload([
            'event_detail_id' => $detail->id,
            'join_withdraw' => 0,
            'balance_formula' => 'reward * 4',
        ]))->assertOk();

        $detail->refresh();
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $detail->withdraw_type);
        $this->assertSame(0, $detail->join_withdraw);
        $this->assertSame('reward * 4', $detail->balance_formula);
    }

    public function test_update_rejects_switching_score_detail_to_array_reward_event_type(): void
    {
        // 反向：積分活動（未開始）想改成每週累積儲值，沒送 withdraw_type 時有效去向仍是積分 → 也要擋
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        $this->requestApi(self::UPDATE, $this->legacyScorePayload([
            'event_detail_id' => $detail->id,
            'event_v2_id' => $this->eventV2Id('WeeklyDepositService', '每週累積儲值'),
        ]))->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_type_not_supported'));

        $this->assertSame(self::EVENT_V2_ID, $detail->fresh()->event_v2_id, '擋下不寫');
    }

    public function test_update_rejects_non_positive_expire(): void
    {
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        $this->requestApi(self::UPDATE, $this->legacyScorePayload([
            'event_detail_id' => $detail->id,
            'withdraw_config' => ['expire' => -5],
        ]))->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_expire_invalid'));

        $this->assertEquals(['expire' => 720], $detail->fresh()->withdraw_config, '擋下不寫');
    }

    // -------------------------------------------------------
    // detail / list 回傳與篩選
    // -------------------------------------------------------

    public function test_event_list_exposes_handler_class_for_frontend_gating(): void
    {
        // 新後台選積分時要把三種陣列型從「選擇方式」鎖掉，靠這個欄位認類型、不寫死 id
        $comboId = $this->eventV2Id('DailyDepositComboService', '每日儲值組合');

        $items = collect($this->requestApi(self::EVENT_LIST)->assertOk()->json('data'));
        $this->assertSame('DailyDepositComboService', $items->firstWhere('id', $comboId)['handler_class']);
    }

    public function test_list_exposes_event_type_name_and_id(): void
    {
        // 後台列表要顯示「活動分類」（紅包／註冊…），名稱取類型列原名，跟建立活動的分類下拉一致
        $typeId = $this->eventV2Id('AngpowService', '紅包');
        $detail = $this->createDetail(EventV2WithdrawTypeEnum::MAIN_WALLET);
        $detail->forceFill(['event_v2_id' => $typeId])->save();
        $typeName = EventV2::query()->whereKey($typeId)->value('name');
        $this->assertNotEmpty($typeName);

        $row = collect($this->requestApi(self::LIST, [
            'agent_username' => $this->agent->username,
            'page' => 1,
            'per_page' => 50,
        ])->assertOk()->json('data.items'))->firstWhere('event_detail_id', $detail->id);

        $this->assertNotNull($row);
        $this->assertSame($typeId, $row['event_v2_id']);
        $this->assertSame($typeName, $row['event_type_name']);
        $this->assertNotSame($typeName, $row['name'], 'name 仍是活動自己的名稱，不被分類名蓋掉');
    }

    public function test_detail_and_list_expose_and_filter_withdraw_type(): void
    {
        $wallet = $this->createDetail(EventV2WithdrawTypeEnum::MAIN_WALLET);
        $score = $this->createDetail(EventV2WithdrawTypeEnum::SCORE);

        $this->requestApi(self::DETAIL, ['event_detail_id' => $score->id])
            ->assertOk()
            ->assertJsonPath('data.withdraw_type', EventV2WithdrawTypeEnum::SCORE->value);

        $list = $this->requestApi(self::LIST, [
            'agent_username' => $this->agent->username,
            'withdraw_type' => EventV2WithdrawTypeEnum::SCORE->value,
            'page' => 1,
            'per_page' => 50,
        ])->assertOk();

        $items = collect($list->json('data.items'));
        $ids = $items->pluck('event_detail_id')->all();
        $this->assertContains($score->id, $ids);
        $this->assertNotContains($wallet->id, $ids, 'withdraw_type 篩選只留積分活動');
        $this->assertSame(
            EventV2WithdrawTypeEnum::SCORE->value,
            $items->firstWhere('event_detail_id', $score->id)['withdraw_type'],
        );
    }
}
