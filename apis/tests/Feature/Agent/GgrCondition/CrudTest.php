<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrCondition;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\GgrCondition;
use App\Models\GgrConditionTier;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 推廣代理GGR - 條件設置 後台 CRUD（4124）
 *
 * php artisan test tests/Feature/Agent/GgrCondition/CrudTest.php
 */
class CrudTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/ggr/condition-setting/' . $action, $payload, $this->headers);
    }

    private function tierPayload(array $overrides = []): array
    {
        return array_merge([
            'user_count' => 1,
            'deposit_amount' => '100.00',
            'total_bet_amount' => '0',
            'game_revenue_amount' => '0',
            'valid_bet_amount' => '0',
            'percentage' => '1.00',
        ], $overrides);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '60% Winloss Comm',
            'status' => 1,
            'logic_type' => 1,
            'active_user_only' => 0,
            'tiers' => [
                $this->tierPayload(),
                $this->tierPayload(['user_count' => 5, 'percentage' => '2.50']),
                $this->tierPayload(['user_count' => 7, 'percentage' => '9.00']),
            ],
        ], $overrides);
    }

    public function test_create_condition_with_tiers(): void
    {
        $this->act('create', $this->createPayload())->assertStatus(200)->assertJson(['code' => 200]);

        $condition = GgrCondition::where('name', '60% Winloss Comm')->first();
        $this->assertNotNull($condition);
        $this->assertSame(1, (int) $condition->logic_type);
        $this->assertSame(0, (int) $condition->active_user_only);
        $this->assertSame($this->agent->id, (int) $condition->created_by);
        $this->assertSame($this->agent->id, (int) $condition->admin);

        $tiers = GgrConditionTier::where('condition_id', $condition->id)->orderBy('tier')->get();
        $this->assertCount(3, $tiers);
        $this->assertSame([1, 2, 3], $tiers->pluck('tier')->map(fn ($tier) => (int) $tier)->all());
        $this->assertSame([1, 5, 7], $tiers->pluck('user_count')->map(fn ($count) => (int) $count)->all());
        $this->assertSame('100.00', (string) $tiers[0]->deposit_amount);
        $this->assertSame('9.00', (string) $tiers[2]->percentage);
    }

    public function test_create_percentage_not_ascending_rejected(): void
    {
        $payload = $this->createPayload(['tiers' => [
            $this->tierPayload(['user_count' => 1, 'percentage' => '5.00']),
            $this->tierPayload(['user_count' => 5, 'percentage' => '5.00']),
        ]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrCondition::count());
    }

    public function test_create_user_count_not_ascending_allowed(): void
    {
        // 2026-09-01 定案：排序約束只綁 percentage，人數可平可降
        $payload = $this->createPayload(['tiers' => [
            $this->tierPayload(['user_count' => 5, 'percentage' => '1.00']),
            $this->tierPayload(['user_count' => 5, 'percentage' => '2.00']),
            $this->tierPayload(['user_count' => 3, 'percentage' => '3.00']),
        ]]);

        $this->act('create', $payload)->assertJson(['code' => 200]);
        $this->assertSame(1, GgrCondition::count());
    }

    public function test_create_user_count_zero_means_unlimited_accepted(): void
    {
        // 0=不限制人數（2026-09-01 定案，邊界8-15：全 0 =無條件達成也放行）
        $payload = $this->createPayload(['tiers' => [
            $this->tierPayload(['user_count' => 0, 'deposit_amount' => '0']),
        ]]);

        $this->act('create', $payload)->assertJson(['code' => 200]);
        $this->assertSame(0, (int) GgrConditionTier::query()->value('user_count'));
    }

    public function test_create_duplicate_name_rejected(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);

        // 同總代同名（含複製後未改名情境）擋下，錯誤訊息指向標題重複
        $response = $this->act('create', $this->createPayload());
        $response->assertJson(['code' => 400]);
        $this->assertStringContainsString(__('error_message.ggr_setting.name_duplicated'), $response->json('message'));
        $this->assertSame(1, GgrCondition::count());
    }

    public function test_update_name_unique_scoped_correctly(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['name' => 'another']))->assertJson(['code' => 200]);
        $other = GgrCondition::where('name', 'another')->first();

        // 沿用自己原本的名稱不誤擋
        $this->act('update', array_merge($this->createPayload(['name' => 'another']), ['id' => $other->id]))
            ->assertJson(['code' => 200]);

        // 撞到別筆的名稱要擋
        $this->act('update', array_merge($this->createPayload(), ['id' => $other->id]))
            ->assertJson(['code' => 400]);
        $this->assertSame('another', GgrCondition::find($other->id)->name);
    }

    public function test_create_invalid_inputs_rejected(): void
    {
        // user_count 缺（0 是合法的「不限制」，缺欄位仍擋）／負數／小數
        $noCount = $this->tierPayload();
        unset($noCount['user_count']);
        $this->act('create', $this->createPayload(['tiers' => [$noCount]]))->assertJson(['code' => 400]);
        $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload(['user_count' => -1])]]))
            ->assertJson(['code' => 400]);
        $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload(['user_count' => 1.5])]]))
            ->assertJson(['code' => 400]);

        // logic_type 非法
        $this->act('create', $this->createPayload(['logic_type' => 3]))->assertJson(['code' => 400]);

        // 門檻負數/超過兩位小數（必填，0 為合法的「無要求」）
        $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload(['deposit_amount' => '-1'])]]))
            ->assertJson(['code' => 400]);
        $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload(['valid_bet_amount' => '1.234'])]]))
            ->assertJson(['code' => 400]);
        $noDeposit = $this->tierPayload();
        unset($noDeposit['deposit_amount']);
        $this->act('create', $this->createPayload(['tiers' => [$noDeposit]]))->assertJson(['code' => 400]);

        // percentage 超過 100
        $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload(['percentage' => '100.01'])]]))
            ->assertJson(['code' => 400]);

        $this->assertSame(0, GgrCondition::count());
    }

    public function test_create_all_zero_thresholds_allowed(): void
    {
        // 門檻全 0（=無要求）但 user_count 有值 → 必要條件語意成立，可過
        $payload = $this->createPayload(['tiers' => [
            $this->tierPayload(['deposit_amount' => '0']),
        ]]);

        $this->act('create', $payload)->assertJson(['code' => 200]);
        $this->assertSame(1, GgrCondition::count());
    }

    public function test_create_out_of_range_values_rejected(): void
    {
        // 沒有上限規則的話這些值會撞 SQL out-of-range（500），而不是回驗證錯誤
        $oversized = [
            ['user_count' => 9999999999],
            ['deposit_amount' => '9999999999999.00'],
            ['total_bet_amount' => '9999999999999.00'],
            ['game_revenue_amount' => '9999999999999.00'],
            ['valid_bet_amount' => '9999999999999.00'],
        ];

        foreach ($oversized as $override) {
            $this->act('create', $this->createPayload(['tiers' => [$this->tierPayload($override)]]))
                ->assertStatus(400)
                ->assertJson(['code' => 400]);
        }

        $this->assertSame(0, GgrCondition::count());
    }

    public function test_create_scientific_notation_percentage_rejected_without_error(): void
    {
        // 同上：percentage 的精確比較不得被 bcmath 解析不了的字面值炸成 500
        $payload = $this->createPayload(['tiers' => [
            $this->tierPayload(['percentage' => '1.0E+1']),
            $this->tierPayload(['user_count' => 5, 'percentage' => '2.0E+1']),
        ]]);

        $this->act('create', $payload)->assertStatus(400)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrCondition::count());
    }

    public function test_other_general_agent_condition_is_invisible(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $mine = (int) GgrCondition::where('name', '60% Winloss Comm')->value('id');

        // 別的總代的條件：本總代既不該看到、也不該改得動
        $foreign = GgrCondition::create([
            'general_agent_id' => $this->agent->id + 1000,
            'name' => 'foreign condition',
            'logic_type' => 1,
            'active_user_only' => 0,
            'status' => 1,
            'created_by' => 0,
            'admin' => 0,
        ]);

        $items = $this->act('list', ['page' => 1, 'per_page' => 20])->json('data.items');
        $this->assertSame([$mine], array_map('intval', array_column($items, 'id')));

        $this->act('view', ['id' => $foreign->id])->assertJson(['code' => 400]);
        $this->act('update', array_merge($this->createPayload(['name' => 'hijacked']), ['id' => $foreign->id]))
            ->assertJson(['code' => 400]);
        $this->assertSame('foreign condition', GgrCondition::find($foreign->id)->name);
    }

    public function test_update_bumps_updated_at_when_only_tiers_change(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrCondition::where('name', '60% Winloss Comm')->value('id');

        $stale = now()->subDay()->toDateTimeString();
        GgrCondition::query()->where('id', $id)->update(['updated_at' => $stale]);

        // 標題/類型/狀態原封不動，只換階梯——主表沒有 dirty 欄位時 updated_at 仍須推進
        $this->act('update', array_merge($this->createPayload([
            'tiers' => [$this->tierPayload(['user_count' => 3, 'percentage' => '4.00'])],
        ]), ['id' => $id]))->assertJson(['code' => 200]);

        $this->assertNotSame($stale, (string) GgrCondition::find($id)->updated_at);
    }

    public function test_list_returns_fields_and_filters(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['name' => 'AND rule', 'logic_type' => 2, 'status' => 0]))
            ->assertJson(['code' => 200]);

        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);
        $response->assertJson(['code' => 200]);
        $row = collect($response->json('data.items'))->firstWhere('name', '60% Winloss Comm');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['logic_type']);
        $this->assertSame(0, (int) $row['active_user_only']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertSame($this->agent->username, $row['created_by']);
        $this->assertSame($this->agent->username, $row['updated_by']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNotEmpty($row['updated_at']);

        $byName = $this->act('list', ['page' => 1, 'per_page' => 20, 'name' => 'AND']);
        $this->assertCount(1, $byName->json('data.items'));

        $byStatus = $this->act('list', ['page' => 1, 'per_page' => 20, 'status' => 0]);
        $this->assertCount(1, $byStatus->json('data.items'));

        $byLogic = $this->act('list', ['page' => 1, 'per_page' => 20, 'logic_type' => 2]);
        $this->assertCount(1, $byLogic->json('data.items'));
        $this->assertSame('AND rule', $byLogic->json('data.items.0.name'));
    }

    public function test_view_returns_tiers_in_order(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrCondition::where('name', '60% Winloss Comm')->value('id');

        $view = $this->act('view', ['id' => $id]);
        $view->assertJson(['code' => 200]);
        $this->assertSame('60% Winloss Comm', $view->json('data.name'));

        $tiers = $view->json('data.tiers');
        $this->assertCount(3, $tiers);
        $this->assertSame([1, 5, 7], array_map(fn ($tier) => (int) $tier['user_count'], $tiers));
    }

    public function test_view_missing_rejected(): void
    {
        $this->act('view', ['id' => 999999])->assertJson(['code' => 400]);
    }

    public function test_update_replaces_tiers(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrCondition::where('name', '60% Winloss Comm')->value('id');
        $oldTierIds = GgrConditionTier::where('condition_id', $id)->pluck('id')->all();

        $this->act('update', array_merge($this->createPayload([
            'name' => 'renamed rule',
            'logic_type' => 2,
            'active_user_only' => 1,
            'status' => 0,
            'tiers' => [$this->tierPayload(['user_count' => 3, 'percentage' => '7.77'])],
        ]), ['id' => $id]))->assertJson(['code' => 200]);

        $condition = GgrCondition::find($id);
        $this->assertSame('renamed rule', $condition->name);
        $this->assertSame(2, (int) $condition->logic_type);
        $this->assertSame(1, (int) $condition->active_user_only);
        $this->assertSame(0, (int) $condition->status);

        // 全量替換：舊階梯列消失、新列存在
        $tiers = GgrConditionTier::where('condition_id', $id)->get();
        $this->assertCount(1, $tiers);
        $this->assertEmpty(array_intersect($oldTierIds, $tiers->pluck('id')->all()));
        $this->assertSame(3, (int) $tiers[0]->user_count);
        $this->assertSame('7.77', (string) $tiers[0]->percentage);
    }

    public function test_update_missing_rejected(): void
    {
        $this->act('update', array_merge($this->createPayload(), ['id' => 999999]))->assertJson(['code' => 400]);
    }

    public function test_delete_endpoint_not_exposed(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrCondition::where('name', '60% Winloss Comm')->value('id');

        $this->act('delete', ['id' => $id])->assertStatus(404);
        $this->assertNotNull(GgrCondition::find($id));
    }
}
