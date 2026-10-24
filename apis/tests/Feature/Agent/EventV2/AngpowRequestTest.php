<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2PeriodEnum;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 活動 V2 後台 create／update 的紅包規則與兩個週期 member_period／total_period（target/tickets#4234）。
 *
 * 必須成立：
 * 1. 紅包必須在條件配置設 score（正數）；其他 key 有填才驗形狀（time_window 兩個 HH:MM、次數／秒數正整數、金額正數）。
 * 2. member_period／total_period 只有紅包能設非 0；其他類型送了擋下。舊後台不送：Create 落 0、Update 沿用現值。
 * 3. score 只有紅包能設，其他類型帶了擋下。
 * 4. 紅包只發主錢包：領取去向送積分被 SCORE_UNSUPPORTED_HANDLERS 擋。
 *
 * php artisan test tests/Feature/Agent/EventV2/AngpowRequestTest.php
 */
class AngpowRequestTest extends TestCase
{
    private const string CREATE = 'agent/api/event-v2/create';

    private const string UPDATE = 'agent/api/event-v2/update';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // 同 WithdrawTypeRequestTest：#4352 的 is_backend_deposit 在乾淨庫缺 migration 時，後台端點會 500
        if (array_key_exists('is_backend_deposit', (new CreateRequest)->rules())
            && !Schema::hasColumn('event_v2_details', 'is_backend_deposit')) {
            $this->markTestSkipped('event_v2_details.is_backend_deposit 尚無 migration（target/tickets#4352），後台活動 V2 端點無法在乾淨庫執行');
        }

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        // create／update 只開放 admin 或 admin 子帳號
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

    public function test_create_angpow_with_period_and_full_config(): void
    {
        // 這條只驗「七個 key 原樣進 DB」；time_window 會依後台時區轉成 UTC 存（另一條專測），
        // CI 的 BACKEND_TIMEZONE 是 Asia/Taipei、本機測試庫是 UTC，釘成 UTC 讓存入值＝輸入值、不隨環境變
        config(['custom.backend.timezone' => 'UTC']);

        $config = [
            'score' => 10,
            'max_join' => 1000,
            'max_amount' => 20000,
            'member_times' => 5,
            'member_amount' => 388,
            'interval' => 30,
            'time_window' => ['20:00', '22:00'],
        ];
        $this->create($this->angpowPayload([
            'condition_config' => $config,
            'member_period' => EventV2PeriodEnum::DAILY->value,
            'total_period' => EventV2PeriodEnum::WEEKLY->value,
        ]))->assertOk()->assertJsonPath('code', 200);

        $detail = $this->latestDetail();
        $this->assertSame(EventV2PeriodEnum::DAILY, $detail->member_period, '每日可重複參加');
        $this->assertSame(EventV2PeriodEnum::WEEKLY, $detail->total_period, '包括期間每週：全站上限每週重新計');
        $this->assertEquals($config, $detail->condition_config, 'JSON 存進 DB 後 key 順序可能變，只比內容');
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $detail->withdraw_type);
    }

    public function test_create_angpow_without_period_defaults_to_none(): void
    {
        $this->create($this->angpowPayload())->assertOk()->assertJsonPath('code', 200);
        $this->assertSame(EventV2PeriodEnum::NONE, $this->latestDetail()->member_period);
        $this->assertSame(EventV2PeriodEnum::NONE, $this->latestDetail()->total_period);
    }

    public function test_create_angpow_requires_score(): void
    {
        foreach ([null, [], ['max_join' => 10], ['score' => 0], ['score' => 'abc'], ['score' => -5]] as $config) {
            $this->create($this->angpowPayload(['condition_config' => $config]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.angpow_score_required'), json_encode($config));
        }
    }

    /** 超過 2 位小數不擋、執行時捨去；只有截完變 0（0.001）才視同沒設 */
    public function test_create_angpow_accepts_extra_decimals_but_rejects_score_truncated_to_zero(): void
    {
        $this->create($this->angpowPayload(['condition_config' => ['score' => 10.555]]))->assertOk()->assertJsonPath('code', 200);

        $this->create($this->angpowPayload(['condition_config' => ['score' => 0.001]]))
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.angpow_score_required'));
    }

    /** 沒有獎勵公式＝抽到 0 元但積分照扣，建立與更新都要擋 */
    public function test_angpow_requires_reward_formula(): void
    {
        foreach ([null, ''] as $formula) {
            $this->create($this->angpowPayload(['reward_formula' => $formula]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.angpow_reward_formula_required'));
        }

        $this->create($this->angpowPayload(['reward_formula' => '20']))->assertOk()->assertJsonPath('code', 200);
        $detail = $this->latestDetail();
        $this->update($this->angpowPayload(['event_detail_id' => $detail->id, 'reward_formula' => null]))
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.angpow_reward_formula_required'));
        $this->assertSame('20', $detail->fresh()->reward_formula, '擋下不寫');
    }

    /** 沒選成本紀錄類型時，錯誤訊息要用後台欄位名，不能露出 cost type 這種欄位代碼 */
    public function test_missing_cost_type_message_uses_readable_field_name(): void
    {
        $payload = $this->angpowPayload();
        unset($payload['cost_type']);

        $message = $this->create($payload)->assertStatus(400)->json('message');
        $this->assertSame(__('validation.required', ['attribute' => __('validation.attributes.cost_type')]), $message);
        $this->assertStringNotContainsString('cost type', $message);
    }

    public function test_create_angpow_validates_optional_keys_shape(): void
    {
        $cases = [
            'time_window' => ['08:00'],
            'interval' => 0,
            'member_times' => '5.5',
            'max_amount' => -1,
            'member_amount' => 'many',
        ];
        foreach ($cases as $key => $bad) {
            $this->create($this->angpowPayload(['condition_config' => ['score' => 10, $key => $bad]]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.angpow_config_invalid', ['key' => $key]), $key);
        }
        // 跨夜時段合法
        $this->create($this->angpowPayload(['condition_config' => ['score' => 10, 'time_window' => ['22:00', '02:00']]]))
            ->assertOk()->assertJsonPath('code', 200);
    }

    public function test_period_rejected_on_non_angpow_types(): void
    {
        foreach (EventV2PeriodEnum::FIELDS as $field) {
            $this->create($this->dailyFreePayload([$field => EventV2PeriodEnum::DAILY->value]))
                ->assertStatus(400)
                ->assertJsonPath('code', 400)
                ->assertJsonPath('message', __('event_v2.validation.period_not_supported'), $field);
        }

        // 送 0 或不送都可以
        $this->create($this->dailyFreePayload(['member_period' => 0, 'total_period' => 0]))->assertOk()->assertJsonPath('code', 200);
        $this->create($this->dailyFreePayload())->assertOk()->assertJsonPath('code', 200);
    }

    public function test_score_key_rejected_on_non_angpow_types(): void
    {
        $this->create($this->dailyFreePayload(['condition_config' => ['max_join' => 10, 'score' => 10]]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.angpow_score_only'));
    }

    public function test_angpow_cannot_pay_out_as_score(): void
    {
        $this->create($this->angpowPayload([
            'withdraw_type' => EventV2WithdrawTypeEnum::SCORE->value,
            'reward_formula' => null,
            'withdraw_formula' => '10',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', __('event_v2.validation.score_type_not_supported'));
    }

    public function test_update_keeps_period_when_not_sent_and_rejects_changing_it(): void
    {
        $this->create($this->angpowPayload([
            'member_period' => EventV2PeriodEnum::WEEKLY->value,
            'total_period' => EventV2PeriodEnum::MONTHLY->value,
        ]))->assertOk();
        $detail = $this->latestDetail();

        // 舊後台整包送但沒有週期欄位：兩欄都沿用現值
        $this->update($this->angpowPayload(['event_detail_id' => $detail->id]))->assertOk()->assertJsonPath('code', 200);
        $this->assertSame(EventV2PeriodEnum::WEEKLY, $detail->fresh()->member_period);
        $this->assertSame(EventV2PeriodEnum::MONTHLY, $detail->fresh()->total_period);

        // 兩個週期建立後鎖定（Max 2026-10-01，比照 withdraw_type）：送不同值擋下、不寫
        $this->update($this->angpowPayload(['event_detail_id' => $detail->id, 'member_period' => EventV2PeriodEnum::MONTHLY->value]))
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.period_locked'));
        $this->assertSame(EventV2PeriodEnum::WEEKLY, $detail->fresh()->member_period, '擋下不寫');

        $this->update($this->angpowPayload(['event_detail_id' => $detail->id, 'total_period' => EventV2PeriodEnum::NONE->value]))
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.period_locked'));
        $this->assertSame(EventV2PeriodEnum::MONTHLY, $detail->fresh()->total_period, '設回 0 也算改，一樣擋');

        // 送相同值（新後台編輯是讀詳情原樣送回）通過
        $this->update($this->angpowPayload([
            'event_detail_id' => $detail->id,
            'member_period' => EventV2PeriodEnum::WEEKLY->value,
            'total_period' => EventV2PeriodEnum::MONTHLY->value,
        ]))->assertOk()->assertJsonPath('code', 200);
        $this->assertSame(EventV2PeriodEnum::WEEKLY, $detail->fresh()->member_period);
        $this->assertSame(EventV2PeriodEnum::MONTHLY, $detail->fresh()->total_period);

        // 更新時把 score 拿掉一樣擋
        $this->update($this->angpowPayload(['event_detail_id' => $detail->id, 'condition_config' => ['max_join' => 5]]))
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.angpow_score_required'));
    }

    public function test_detail_returns_period_so_editing_does_not_reset_it(): void
    {
        // 後台編輯是「讀詳情 → 原樣送回」：詳情漏回週期欄位，編輯一次就會把紅包週期洗成 0
        $this->create($this->angpowPayload([
            'member_period' => EventV2PeriodEnum::WEEKLY->value,
            'total_period' => EventV2PeriodEnum::MONTHLY->value,
        ]))->assertOk();
        $detail = $this->latestDetail();

        $this->postJson('agent/api/event-v2/detail', ['event_detail_id' => $detail->id], $this->headers)
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.member_period', EventV2PeriodEnum::WEEKLY->value)
            ->assertJsonPath('data.total_period', EventV2PeriodEnum::MONTHLY->value);
    }

    /**
     * time_window 比照 start_time／end_time：後台以後台時區（+8）填，存 UTC，detail 轉回 +8。
     * 測試環境預設 UTC 看不出轉換，這裡設成 Asia/Taipei。
     */
    public function test_time_window_stored_as_system_time_and_shown_back_in_backend_time(): void
    {
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $this->create($this->angpowPayload(['condition_config' => ['score' => 10, 'time_window' => ['08:00', '23:00']]]))->assertOk();
        $detail = $this->latestDetail();
        $this->assertSame(['00:00', '15:00'], $detail->condition_config['time_window'], '台灣 08:00～23:00 存成 UTC 00:00～15:00');

        $this->postJson('agent/api/event-v2/detail', ['event_detail_id' => $detail->id], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.condition_config.time_window', ['08:00', '23:00']);

        // 台灣 06:00～10:00 換成 UTC 會跨夜（22:00～02:00），判斷端已支援起 > 迄
        $this->update($this->angpowPayload(['event_detail_id' => $detail->id, 'condition_config' => ['score' => 10, 'time_window' => ['06:00', '10:00']]]))
            ->assertOk();
        $this->assertSame(['22:00', '02:00'], $detail->fresh()->condition_config['time_window']);
    }

    // -------------------------------------------------------

    private function typeId(string $handlerClass, string $name): int
    {
        return EventV2::query()->firstOrCreate(['handler_class' => $handlerClass], ['name' => $name, 'schedule' => null])->id;
    }

    /**
     * 後台整包欄位；陣列欄位一律帶空陣列（Controller 對它們 foreach）。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $eventV2Id, array $overrides = []): array
    {
        return array_merge([
            'event_v2_id' => $eventV2Id,
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function angpowPayload(array $overrides = []): array
    {
        return $this->payload($this->typeId('AngpowService', '紅包'), array_merge([
            'condition_config' => ['score' => 10],
            'reward_formula' => 'rand(10, 500)',
            'reward_config' => ['limit' => 300],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dailyFreePayload(array $overrides = []): array
    {
        return $this->payload($this->typeId('DailyFreeService', '每日登入'), $overrides);
    }

    private function create(array $payload): TestResponse
    {
        return $this->postJson(self::CREATE, $payload, $this->headers);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson(self::UPDATE, $payload, $this->headers);
    }

    private function latestDetail(): EventV2Detail
    {
        return EventV2Detail::query()->where('agent_id', $this->agent->id)->latest('id')->firstOrFail();
    }
}
