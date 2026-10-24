<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberRestriction;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberRestrictionRule;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 風控管理 - 會員限制 後台 CRUD（4118）
 *
 * php artisan test tests/Feature/Agent/MemberRestriction/CrudTest.php
 */
class CrudTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->newMember($this->agent);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /** factory 預設 username 為固定值，需自帶唯一帳號避免撞 members_username_unique */
    private function newMember(Agent $agent, array $data = []): Member
    {
        return $this->createMember($agent, array_merge(['username' => 'mr' . bin2hex(random_bytes(6))], $data));
    }

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/member-restriction/' . $action, $payload, $this->headers);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 1,
            'username' => $this->member->username,
            'remark' => 'test remark',
            'status' => 1,
            'is_disabled' => 0,
            'withdrawal_locked' => 1,
        ], $overrides);
    }

    public function test_create_member_rule(): void
    {
        $response = $this->act('create', $this->createPayload());

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $rule = MemberRestrictionRule::where('target_type', 'member')
            ->where('target_id', $this->member->id)
            ->first();
        $this->assertNotNull($rule);
        $this->assertSame($this->agent->id, (int) $rule->agent_id);
        $this->assertSame($this->agent->id, (int) $rule->admin);
        $this->assertSame(1, (int) $rule->withdrawal_locked);
    }

    public function test_create_duplicate_rejected(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);

        $response = $this->act('create', $this->createPayload());

        $response->assertJson(['code' => 400]);
        $this->assertSame(1, MemberRestrictionRule::where('target_id', $this->member->id)
            ->where('target_type', 'member')->count());
    }

    public function test_create_group_rule(): void
    {
        $group = MemberGroup::create(['agent_id' => $this->agent->id, 'name' => 'g-' . uniqid(), 'status' => 1]);

        $response = $this->act('create', $this->createPayload([
            'type' => 2,
            'username' => null,
            'member_group_id' => $group->id,
        ]));

        $response->assertJson(['code' => 200]);
        $this->assertNotNull(MemberRestrictionRule::where('target_type', 'member_group')
            ->where('target_id', $group->id)->first());
    }

    public function test_list_returns_target_and_agent_names(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);

        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);

        $response->assertJson(['code' => 200]);
        $items = $response->json('data.items');
        $row = collect($items)->firstWhere('target_name', $this->member->username);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['type']);
        $this->assertSame($this->agent->username, $row['agent_username']);
        $this->assertSame($this->agent->username, $row['updated_by']);
        $this->assertSame(1, (int) $row['withdrawal_locked']);
    }

    public function test_view_update_delete_flow(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) MemberRestrictionRule::where('target_id', $this->member->id)->value('id');

        $view = $this->act('view', ['id' => $id]);
        $view->assertJson(['code' => 200]);
        $this->assertSame($this->member->username, $view->json('data.target_name'));

        $this->act('update', [
            'id' => $id,
            'remark' => 'updated',
            'status' => 0,
            'is_disabled' => 1,
            'withdrawal_locked' => 0,
        ])->assertJson(['code' => 200]);

        $rule = MemberRestrictionRule::find($id);
        $this->assertSame('updated', $rule->remark);
        $this->assertSame(0, (int) $rule->status);
        $this->assertSame(1, (int) $rule->is_disabled);
        $this->assertSame(0, (int) $rule->withdrawal_locked);

        $this->act('delete', ['id' => $id])->assertJson(['code' => 200]);
        $this->assertNull(MemberRestrictionRule::find($id));
    }

    public function test_cannot_view_other_line_rule(): void
    {
        // 另一條線的代理與其規則
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $other = $this->createAgentAndDomain();
        $otherMember = $this->newMember($other);
        $rule = MemberRestrictionRule::create([
            'agent_id' => $other->id,
            'target_type' => 'member',
            'target_id' => $otherMember->id,
            'status' => 1,
            'is_disabled' => 0,
            'withdrawal_locked' => 0,
            'admin' => $other->id,
        ]);

        $this->act('view', ['id' => $rule->id])->assertJson(['code' => 400]);
        $this->act('delete', ['id' => $rule->id])->assertJson(['code' => 400]);
        $this->assertNotNull(MemberRestrictionRule::find($rule->id));
    }

    public function test_create_out_of_scope_member_rejected(): void
    {
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $other = $this->createAgentAndDomain();
        $outsideMember = $this->newMember($other);

        $response = $this->act('create', $this->createPayload(['username' => $outsideMember->username]));

        $response->assertJson(['code' => 400]);
        $this->assertSame(0, MemberRestrictionRule::where('target_id', $outsideMember->id)
            ->where('target_type', 'member')->count());
    }
}
