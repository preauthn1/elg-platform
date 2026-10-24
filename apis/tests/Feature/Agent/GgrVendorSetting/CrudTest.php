<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrVendorSetting;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\GameType;
use App\Models\GgrVendorSetting;
use App\Models\GgrVendorSettingDetail;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 推廣代理GGR - 遊戲商設置 後台 CRUD（4123）
 *
 * php artisan test tests/Feature/Agent/GgrVendorSetting/CrudTest.php
 */
class CrudTest extends TestCase
{
    private const int CUSTOMIZE_GROUP_ID = 990101;

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private GameType $gameType;

    /** @var array<int, Game> */
    private array $games = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain([
            'vendor_customize_group_id' => self::CUSTOMIZE_GROUP_ID,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        $this->createSupportedVendorChain();
    }

    /** 建立「總代支援」鏈：vendor_customized_items × agent_vendors × vendors × vendor_infos × games。 */
    private function createSupportedVendorChain(): void
    {
        $this->gameType = GameType::create(['parent' => 0, 'name' => 'SLOT', 'status' => 1, 'admin' => 0]);
        $this->vendor = Vendor::factory()->create();
        $this->vendorInfo = VendorInfo::factory()->create(['name' => 'CQ9-SLOT', 'vendor_id' => $this->vendor->id]);

        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $this->vendor->id,
            'vendor_info_id' => $this->vendorInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);
        VendorCustomizedItem::create([
            'agent_group_id' => self::CUSTOMIZE_GROUP_ID,
            'item_type' => 0,
            'vendor_id' => $this->vendor->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->games = [
            Game::factory()->create(['vendor_id' => $this->vendor->id, 'type_id' => $this->gameType->id, 'status' => 1, 'game_type' => 'G1']),
            Game::factory()->create(['vendor_id' => $this->vendor->id, 'type_id' => $this->gameType->id, 'status' => 1, 'game_type' => 'G2']),
        ];
    }

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/ggr/vendor-setting/' . $action, $payload, $this->headers);
    }

    private function detailPayload(array $overrides = [], array $tiers = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor->id,
            'vendor_info_id' => $this->vendorInfo->id,
            'game_type_id' => $this->gameType->id,
            'excluded_game_ids' => [$this->games[0]->id],
            'status' => 1,
            'tiers' => $tiers ?: [
                ['threshold_amount' => '10.00', 'min_winlose' => 1, 'max_ggr' => 55, 'weight_rate' => '100.00'],
                ['threshold_amount' => '200.50', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '80.25'],
            ],
        ], $overrides);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test setting',
            'status' => 1,
            'details' => [$this->detailPayload()],
        ], $overrides);
    }

    public function test_create_setting_with_tiers(): void
    {
        $this->act('create', $this->createPayload())->assertStatus(200)->assertJson(['code' => 200]);

        $setting = GgrVendorSetting::where('name', 'test setting')->first();
        $this->assertNotNull($setting);
        $this->assertSame(1, (int) $setting->status);
        $this->assertSame($this->agent->id, (int) $setting->created_by);
        $this->assertSame($this->agent->id, (int) $setting->admin);

        $rows = GgrVendorSettingDetail::where('setting_id', $setting->id)->orderBy('tier')->get();
        $this->assertCount(2, $rows);

        // tier=1 帶排除遊戲與開關；tier=2 為純階梯列
        $this->assertSame(1, (int) $rows[0]->tier);
        $this->assertSame([$this->games[0]->id], $rows[0]->excluded_game_ids);
        $this->assertSame('10.00', (string) $rows[0]->threshold_amount);
        $this->assertSame(1, (int) $rows[0]->min_winlose);
        $this->assertSame(55, (int) $rows[0]->max_ggr);
        $this->assertSame(2, (int) $rows[1]->tier);
        $this->assertNull($rows[1]->excluded_game_ids);
        // 「無最低限制/無上限」存 0（2026-08-31 工單定案：0=不限制）
        $this->assertSame(0, (int) $rows[1]->min_winlose);
        $this->assertSame(0, (int) $rows[1]->max_ggr);
        $this->assertSame('200.50', (string) $rows[1]->threshold_amount);
    }

    public function test_create_duplicate_combo_rejected(): void
    {
        $payload = $this->createPayload(['details' => [$this->detailPayload(), $this->detailPayload()]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_threshold_not_ascending_rejected(): void
    {
        $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
            ['threshold_amount' => '200.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
            ['threshold_amount' => '100.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
        ])]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_duplicate_threshold_rejected_with_distinct_message(): void
    {
        // 工單需求1（邊界4-7）：同遊戲商兩階同值 → 擋下且訊息明示重複
        $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
            ['threshold_amount' => '200.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
            ['threshold_amount' => '200.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
        ])]]);

        $response = $this->act('create', $payload);
        $response->assertJson(['code' => 400]);
        $this->assertStringContainsString(
            __('validation.distinct', ['attribute' => 'details.0.tiers.1.threshold_amount']),
            $response->json('message'),
        );
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_invalid_amounts_rejected(): void
    {
        // 計算金額 負數/超過兩位小數（0=不限制是合法值，見 zero_means_unlimited 案例）
        foreach (['-1', '1.234'] as $invalid) {
            $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
                ['threshold_amount' => $invalid, 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
            ])]]);
            $this->act('create', $payload)->assertJson(['code' => 400]);
        }

        // 最低輸贏/最大GGR 非整數或負數
        foreach ([['min_winlose' => -1], ['min_winlose' => 1.5], ['max_ggr' => -3], ['min_winlose' => null], ['max_ggr' => null]] as $override) {
            $tier = array_merge(
                ['threshold_amount' => '10.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
                $override,
            );
            $this->act('create', $this->createPayload(['details' => [$this->detailPayload(tiers: [$tier])]]))
                ->assertJson(['code' => 400]);
        }

        // 加權 負數（0=不計算是合法值）
        $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
            ['threshold_amount' => '10.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '-5'],
        ])]]);
        $this->act('create', $payload)->assertJson(['code' => 400]);

        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_zero_means_unlimited_accepted(): void
    {
        // 0＝不限制/不計算（2026-08-31 工單定案）：計算金額 0、加權 0 都可存
        $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
            ['threshold_amount' => '0', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '0'],
            ['threshold_amount' => '100.00', 'min_winlose' => 1, 'max_ggr' => 0, 'weight_rate' => '50.00'],
        ])]]);

        $this->act('create', $payload)->assertJson(['code' => 200]);

        $rows = GgrVendorSettingDetail::query()->orderBy('tier')->get();
        $this->assertSame('0.00', (string) $rows[0]->threshold_amount);
        $this->assertSame('0.00', (string) $rows[0]->weight_rate);
        $this->assertSame(0, (int) $rows[1]->max_ggr);
    }

    public function test_create_unsupported_vendor_rejected(): void
    {
        // 不在 vendor_customized_items 支援鏈上的 vendor_info
        $otherVendor = Vendor::factory()->create();
        $otherInfo = VendorInfo::factory()->create(['name' => 'OTHER', 'vendor_id' => $otherVendor->id]);

        $payload = $this->createPayload(['details' => [$this->detailPayload([
            'vendor_id' => $otherVendor->id,
            'vendor_info_id' => $otherInfo->id,
            'excluded_game_ids' => [],
        ])]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_excluded_game_not_in_vendor_type_rejected(): void
    {
        $foreignGame = Game::factory()->create(['vendor_id' => $this->vendor->id + 1, 'type_id' => $this->gameType->id, 'status' => 1]);

        $payload = $this->createPayload(['details' => [$this->detailPayload([
            'excluded_game_ids' => [$foreignGame->id],
        ])]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_unsupported_game_type_rejected(): void
    {
        // 支援鏈上的 vendor，但該 vendor 沒有這個類型的遊戲 → 不在 vendor-options 的組合裡
        $otherType = GameType::create(['parent' => 0, 'name' => 'FISH', 'status' => 1, 'admin' => 0]);

        $payload = $this->createPayload(['details' => [$this->detailPayload([
            'game_type_id' => $otherType->id,
            'excluded_game_ids' => [],
        ])]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_out_of_range_amounts_rejected(): void
    {
        // 沒有上限規則的話這些值會撞 SQL out-of-range（500），而不是回驗證錯誤
        $oversized = [
            ['threshold_amount' => '10.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '99999999.00'],
            ['threshold_amount' => '9999999999999.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
            ['threshold_amount' => '10.00', 'min_winlose' => null, 'max_ggr' => 9999999999999, 'weight_rate' => '100.00'],
            ['threshold_amount' => '10.00', 'min_winlose' => 9999999999999, 'max_ggr' => null, 'weight_rate' => '100.00'],
        ];

        foreach ($oversized as $tier) {
            $this->act('create', $this->createPayload(['details' => [$this->detailPayload(tiers: [$tier])]]))
                ->assertStatus(400)
                ->assertJson(['code' => 400]);
        }

        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_create_scientific_notation_amount_rejected_without_error(): void
    {
        // is_numeric() 放行但 bcmath 解析不了的字面值：必須由 decimal 規則擋成 400，
        // 不能在 withValidator 的精確比較裡炸成 ValueError（500）
        $payload = $this->createPayload(['details' => [$this->detailPayload(tiers: [
            ['threshold_amount' => '1.0E+5', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
            ['threshold_amount' => '2.0E+5', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '100.00'],
        ])]]);

        $this->act('create', $payload)->assertStatus(400)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrVendorSetting::count());
    }

    public function test_other_general_agent_setting_is_invisible(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $mine = (int) GgrVendorSetting::where('name', 'test setting')->value('id');

        // 別的總代的設置：本總代既不該看到、也不該改得動
        $foreign = GgrVendorSetting::create([
            'general_agent_id' => $this->agent->id + 1000,
            'name' => 'foreign setting',
            'status' => 1,
            'created_by' => 0,
            'admin' => 0,
        ]);

        $items = $this->act('list', ['page' => 1, 'per_page' => 20])->json('data.items');
        $this->assertSame([$mine], array_map('intval', array_column($items, 'id')));

        $this->act('view', ['id' => $foreign->id])->assertJson(['code' => 400]);
        $this->act('update', array_merge($this->createPayload(['name' => 'hijacked']), ['id' => $foreign->id]))
            ->assertJson(['code' => 400]);
        $this->assertSame('foreign setting', GgrVendorSetting::find($foreign->id)->name);
    }

    public function test_update_bumps_updated_at_when_only_details_change(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrVendorSetting::where('name', 'test setting')->value('id');

        $stale = now()->subDay()->toDateTimeString();
        GgrVendorSetting::query()->where('id', $id)->update(['updated_at' => $stale]);

        // 名稱/狀態原封不動，只換明細——主表沒有 dirty 欄位時 updated_at 仍須推進
        $this->act('update', array_merge($this->createPayload([
            'details' => [$this->detailPayload(tiers: [
                ['threshold_amount' => '7.00', 'min_winlose' => 0, 'max_ggr' => 0, 'weight_rate' => '60.00'],
            ])],
        ]), ['id' => $id]))->assertJson(['code' => 200]);

        $this->assertNotSame($stale, (string) GgrVendorSetting::find($id)->updated_at);
    }

    public function test_list_returns_fields_and_filters(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['name' => 'disabled one', 'status' => 0]))->assertJson(['code' => 200]);

        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);
        $response->assertJson(['code' => 200]);
        $row = collect($response->json('data.items'))->firstWhere('name', 'test setting');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['status']);
        $this->assertSame($this->agent->username, $row['created_by']);
        $this->assertSame($this->agent->username, $row['updated_by']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNotEmpty($row['updated_at']);

        $byName = $this->act('list', ['page' => 1, 'per_page' => 20, 'name' => 'disabled']);
        $this->assertCount(1, $byName->json('data.items'));

        $byStatus = $this->act('list', ['page' => 1, 'per_page' => 20, 'status' => 0]);
        $this->assertCount(1, $byStatus->json('data.items'));
        $this->assertSame('disabled one', $byStatus->json('data.items.0.name'));
    }

    public function test_view_returns_nested_details(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrVendorSetting::where('name', 'test setting')->value('id');

        $view = $this->act('view', ['id' => $id]);
        $view->assertJson(['code' => 200]);
        $this->assertSame('test setting', $view->json('data.name'));

        $details = $view->json('data.details');
        $this->assertCount(1, $details);
        $this->assertSame($this->vendorInfo->id, (int) $details[0]['vendor_info_id']);
        $this->assertSame('CQ9-SLOT', $details[0]['vendor_info_name']);
        $this->assertSame('SLOT', $details[0]['game_type_name']);
        $this->assertSame([$this->games[0]->id], $details[0]['excluded_game_ids']);
        $this->assertCount(2, $details[0]['tiers']);
        $this->assertSame(0, (int) $details[0]['tiers'][1]['min_winlose']);
    }

    public function test_view_missing_rejected(): void
    {
        $this->act('view', ['id' => 999999])->assertJson(['code' => 400]);
    }

    public function test_update_replaces_details(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrVendorSetting::where('name', 'test setting')->value('id');
        $oldDetailIds = GgrVendorSettingDetail::where('setting_id', $id)->pluck('id')->all();

        $this->act('update', array_merge($this->createPayload([
            'name' => 'renamed',
            'status' => 0,
            'details' => [$this->detailPayload(['excluded_game_ids' => []], tiers: [
                ['threshold_amount' => '5.00', 'min_winlose' => 0, 'max_ggr' => 99, 'weight_rate' => '50.00'],
            ])],
        ]), ['id' => $id]))->assertJson(['code' => 200]);

        $setting = GgrVendorSetting::find($id);
        $this->assertSame('renamed', $setting->name);
        $this->assertSame(0, (int) $setting->status);

        // 全量替換：舊明細列消失、新列存在
        $rows = GgrVendorSettingDetail::where('setting_id', $id)->get();
        $this->assertCount(1, $rows);
        $this->assertEmpty(array_intersect($oldDetailIds, $rows->pluck('id')->all()));
        $this->assertSame('5.00', (string) $rows[0]->threshold_amount);
        $this->assertSame(99, (int) $rows[0]->max_ggr);
    }

    public function test_update_missing_rejected(): void
    {
        $this->act('update', array_merge($this->createPayload(), ['id' => 999999]))->assertJson(['code' => 400]);
    }

    public function test_delete_endpoint_not_exposed(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrVendorSetting::where('name', 'test setting')->value('id');

        $this->act('delete', ['id' => $id])->assertStatus(404);
        $this->assertNotNull(GgrVendorSetting::find($id));
    }

    /**
     * agent_vendors 有「同一 vendor_info 掛到不同 vendor」的髒資料時（dev 實測 33 組），
     * 組合仍只出一列且 vendor 取 vendor_infos 的權威值——否則 4123 建立頁同一分頁會
     * 出現重複遊戲商、送出時撞明細唯一驗證（QA 2026-09-04 回報 details.N.vendor_info_id）。
     */
    public function test_vendor_options_dedupes_when_agent_vendor_points_to_wrong_vendor(): void
    {
        // 另一個 vendor（有同類型遊戲），被錯誤地與既有 vendor_info 綁在同一列
        $wrongVendor = Vendor::factory()->create();
        Game::factory()->create([
            'vendor_id' => $wrongVendor->id,
            'type_id' => $this->gameType->id,
            'status' => 1,
            'game_type' => 'WRONG1',
        ]);
        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $wrongVendor->id,
            'vendor_info_id' => $this->vendorInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $items = $this->act('vendor-options')->assertJson(['code' => 200])->json('data.items');

        $combos = array_map(fn ($item) => $item['vendor_info_id'] . '-' . $item['game_type_id'], $items);
        $this->assertSame(array_values(array_unique($combos)), $combos, '組合不得重複');

        $rows = array_values(array_filter($items, fn ($item) => (int) $item['vendor_info_id'] === $this->vendorInfo->id));
        $this->assertCount(1, $rows);
        $this->assertSame($this->vendor->id, (int) $rows[0]['vendor_id'], 'vendor 取 vendor_infos.vendor_id 權威值');
    }

    public function test_vendor_options_limited_to_own_agent_vendors(): void
    {
        // 停用的開通不得出現（相當於舊端點回 status 後前端過濾）
        $disabledVendor = Vendor::factory()->create();
        $disabledInfo = VendorInfo::factory()->create(['name' => 'DISABLED', 'vendor_id' => $disabledVendor->id]);
        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $disabledVendor->id,
            'vendor_info_id' => $disabledInfo->id,
            'status' => 0,
            'admin' => 0,
        ]);

        // 別的代理開通的 vendor 不得混入（修掉舊「同 vcg 群組聯集」的過寬）
        $stranger = $this->createBareAgent('/1');
        $strangerVendor = Vendor::factory()->create();
        $strangerInfo = VendorInfo::factory()->create(['name' => 'STRANGER', 'vendor_id' => $strangerVendor->id]);
        AgentVendor::create([
            'agent_id' => $stranger->id,
            'vendor_id' => $strangerVendor->id,
            'vendor_info_id' => $strangerInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->act('vendor-options');
        $response->assertJson(['code' => 200]);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($this->vendorInfo->id, (int) $items[0]['vendor_info_id']);
        $this->assertSame('CQ9-SLOT', $items[0]['vendor_info_name']);
        $this->assertSame($this->gameType->id, (int) $items[0]['game_type_id']);

        $types = $response->json('data.game_types');
        $this->assertCount(1, $types);
        $this->assertSame('SLOT', $types[0]['name']);
    }

    public function test_vendor_options_inherits_upline_when_agent_has_none(): void
    {
        // 自己沒有任何 agent_vendors 設定 → 沿 hierarchy 由近到遠繼承第一個有設定的上線
        AgentVendor::query()->where('agent_id', $this->agent->id)->delete();

        $upline = $this->createBareAgent('/1');
        $this->agent->hierarchy_path = '/1/' . $upline->id . '/' . $this->agent->id;
        $this->agent->save();

        $uplineVendor = Vendor::factory()->create();
        $uplineInfo = VendorInfo::factory()->create(['name' => 'UPLINE-SLOT', 'vendor_id' => $uplineVendor->id]);
        AgentVendor::create([
            'agent_id' => $upline->id,
            'vendor_id' => $uplineVendor->id,
            'vendor_info_id' => $uplineInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);
        Game::factory()->create(['vendor_id' => $uplineVendor->id, 'type_id' => $this->gameType->id, 'status' => 1, 'game_type' => 'GU']);

        $response = $this->act('vendor-options');
        $response->assertJson(['code' => 200]);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($uplineInfo->id, (int) $items[0]['vendor_info_id']);
        $this->assertSame('UPLINE-SLOT', $items[0]['vendor_info_name']);
    }

    /** 建立最小欄位的代理（不建 domain 避免 agent_domains unique 撞名）。 */
    private function createBareAgent(string $parentPath): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'b' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
        ]);
        $agent->hierarchy_path = $parentPath . '/' . $agent->id;
        $agent->save();

        return $agent;
    }

    public function test_game_options_returns_games_and_guards_vendor(): void
    {
        $response = $this->act('game-options', ['vendor_id' => $this->vendor->id, 'game_type_id' => $this->gameType->id]);
        $response->assertJson(['code' => 200]);
        $this->assertCount(2, $response->json('data'));

        // 支援鏈外 vendor → 拒絕
        $otherVendor = Vendor::factory()->create();
        $this->act('game-options', ['vendor_id' => $otherVendor->id, 'game_type_id' => $this->gameType->id])
            ->assertJson(['code' => 400]);

        // 缺參 → 驗證失敗
        $this->act('game-options', ['vendor_id' => $this->vendor->id])->assertJson(['code' => 400]);
    }
}
