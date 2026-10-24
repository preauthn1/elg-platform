<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\IpRestriction;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\IpRestriction;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 風控管理 - IP限制 後台 CRUD（4096）
 *
 * php artisan test tests/Feature/Agent/IpRestriction/CrudTest.php
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
        return $this->postJson('agent/api/ip-restriction/' . $action, $payload, $this->headers);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'ip' => '0.0.0.0',
            'block_items' => ['login'],
            'remark' => 'test remark',
            'status' => 1,
        ], $overrides);
    }

    public function test_create_rule(): void
    {
        $response = $this->act('create', $this->createPayload());

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $rule = IpRestriction::where('ip', '0.0.0.0')->first();
        $this->assertNotNull($rule);
        $this->assertSame('test remark', $rule->remark);
        $this->assertSame(1, (int) $rule->status);
        $this->assertSame(0, (int) $rule->repeat_count);
        $this->assertSame(0, (int) $rule->block_register);
        $this->assertSame(1, (int) $rule->block_login);
        $this->assertSame(0, (int) $rule->block_sms);
        $this->assertSame($this->agent->id, (int) $rule->admin);
    }

    public function test_create_duplicate_rejected(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);

        $response = $this->act('create', $this->createPayload(['remark' => 'again']));

        $response->assertJson(['code' => 400]);
        $this->assertSame(1, IpRestriction::where('ip', '0.0.0.0')->count());
    }

    public function test_create_ipv6_stored_canonical_and_variant_duplicate_rejected(): void
    {
        $this->act('create', $this->createPayload(['ip' => '2001:DB8:0000::1']))->assertJson(['code' => 200]);

        // 存 canonical form
        $this->assertNotNull(IpRestriction::where('ip', '2001:db8::1')->first());

        // 縮寫/大小寫變體視為同一 IP
        $response = $this->act('create', $this->createPayload(['ip' => '2001:db8:0:0:0:0:0:1']));
        $response->assertJson(['code' => 400]);
        $this->assertSame(1, IpRestriction::count());
    }

    public function test_create_invalid_ip_rejected(): void
    {
        foreach (['0.0.0.0', 'not-an-ip', '1.2.3'] as $invalid) {
            $this->act('create', $this->createPayload(['ip' => $invalid]))->assertJson(['code' => 400]);
        }
        $this->assertSame(0, IpRestriction::count());
    }

    public function test_list_returns_fields_and_filters(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['ip' => '0.0.0.0', 'status' => 0]))->assertJson(['code' => 200]);

        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);
        $response->assertJson(['code' => 200]);
        $row = collect($response->json('data.items'))->firstWhere('ip', '0.0.0.0');
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['repeat_count']);
        $this->assertSame('test remark', $row['remark']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertSame($this->agent->username, $row['updated_by']);
        $this->assertNotEmpty($row['updated_at']);

        // 依 IP 關鍵字過濾
        $byIp = $this->act('list', ['page' => 1, 'per_page' => 20, 'ip' => '5.6']);
        $this->assertCount(1, $byIp->json('data.items'));
        $this->assertSame('0.0.0.0', $byIp->json('data.items.0.ip'));

        // 依狀態過濾
        $byStatus = $this->act('list', ['page' => 1, 'per_page' => 20, 'status' => 0]);
        $this->assertCount(1, $byStatus->json('data.items'));
        $this->assertSame('0.0.0.0', $byStatus->json('data.items.0.ip'));
    }

    public function test_view_and_update_flow(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) IpRestriction::where('ip', '0.0.0.0')->value('id');

        $view = $this->act('view', ['id' => $id]);
        $view->assertJson(['code' => 200]);
        $this->assertSame('0.0.0.0', $view->json('data.ip'));

        // IP 可改（無 delete，打錯要能修正）
        $this->assertSame(['login'], $view->json('data.block_items'));

        // IP 與阻擋項目都可改
        $this->act('update', [
            'id' => $id,
            'ip' => '0.0.0.0',
            'block_items' => ['register', 'sms'],
            'remark' => 'updated',
            'status' => 0,
        ])->assertJson(['code' => 200]);

        $rule = IpRestriction::find($id);
        $this->assertSame('0.0.0.0', $rule->ip);
        $this->assertSame('updated', $rule->remark);
        $this->assertSame(0, (int) $rule->status);
        // 取消勾選的項目要回寫 0，不能殘留
        $this->assertSame(1, (int) $rule->block_register);
        $this->assertSame(0, (int) $rule->block_login);
        $this->assertSame(1, (int) $rule->block_sms);
    }

    public function test_view_missing_rejected(): void
    {
        $this->act('view', ['id' => 999999])->assertJson(['code' => 400]);
    }

    public function test_update_to_existing_ip_rejected_and_self_ip_allowed(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['ip' => '0.0.0.0']))->assertJson(['code' => 200]);
        $id = (int) IpRestriction::where('ip', '0.0.0.0')->value('id');

        // 改成既存他筆 IP → duplicate
        $this->act('update', ['id' => $id, 'ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1])->assertJson(['code' => 400]);

        // 改回自己原 IP（僅改備註）→ 不算重複
        $this->act('update', ['id' => $id, 'ip' => '0.0.0.0', 'block_items' => ['login'], 'remark' => 'self', 'status' => 1])
            ->assertJson(['code' => 200]);
        $this->assertSame('self', IpRestriction::find($id)->remark);
    }

    public function test_update_does_not_touch_repeat_count(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $rule = IpRestriction::where('ip', '0.0.0.0')->first();
        $rule->newQuery()->toBase()->where('id', $rule->id)->update(['repeat_count' => 7]);

        $this->act('update', ['id' => $rule->id, 'ip' => '0.0.0.0', 'block_items' => ['login'], 'remark' => 'x', 'status' => 1])
            ->assertJson(['code' => 200]);

        $this->assertSame(7, (int) $rule->refresh()->repeat_count);
    }

    public function test_delete_endpoint_not_exposed(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) IpRestriction::where('ip', '0.0.0.0')->value('id');

        $this->act('delete', ['id' => $id])->assertStatus(404);
        $this->assertNotNull(IpRestriction::find($id));
    }

    public function test_block_items_required_and_validated(): void
    {
        // 未帶阻擋項目 → 驗證失敗（規則至少要擋一項才有意義）
        $payload = $this->createPayload();
        unset($payload['block_items']);
        $this->act('create', $payload)->assertJson(['code' => 400]);

        // 空陣列同樣不接受
        $this->act('create', $this->createPayload(['block_items' => []]))->assertJson(['code' => 400]);

        // 非定義中的項目
        $this->act('create', $this->createPayload(['block_items' => ['withdrawal']]))->assertJson(['code' => 400]);

        // 多選全勾 → 三個旗標都寫 1
        $this->act('create', $this->createPayload(['block_items' => ['register', 'login', 'sms']]))
            ->assertJson(['code' => 200]);
        $rule = IpRestriction::where('ip', '0.0.0.0')->first();
        $this->assertSame(1, (int) $rule->block_register);
        $this->assertSame(1, (int) $rule->block_login);
        $this->assertSame(1, (int) $rule->block_sms);
    }
}
