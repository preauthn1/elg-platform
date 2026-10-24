<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Exceptions\ApiException;
use App\Models\LivechatBot;
use App\Models\Member;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use Tests\TestCase;

/**
 * MemberAgentGuard 單元測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/MemberAgentGuardTest.php
 */
class MemberAgentGuardTest extends TestCase
{
    public function test_passes_when_bot_agent_matches_member_agent(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $member->load('agent');

        $guard = new MemberAgentGuard;
        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($agent->id)));

        $guard->assert($member, $context);

        $this->expectNotToPerformAssertions(); // 沒丟例外即通過
    }

    public function test_passes_when_bot_agent_is_ancestor_in_hierarchy(): void
    {
        // 建立直接代理
        $directAgent = $this->createAgentAndDomain();

        // 直接代理的「祖父」代理：將 directAgent 的 hierarchy_path 改寫為 /99/{directAgent->id}
        $ancestorId = 99999;
        $directAgent->hierarchy_path = "/{$ancestorId}/{$directAgent->id}";
        $directAgent->save();

        $member = $this->createMember($directAgent);
        $member->load('agent');

        // bot 綁在祖代上，hierarchy_array 含此 id → 應通過
        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($ancestorId)));

        $this->expectNotToPerformAssertions();
        (new MemberAgentGuard)->assert($member, $context);
    }

    public function test_throws_404_when_bot_agent_is_descendant_not_in_hierarchy(): void
    {
        // member 在 root agent 下；bot 綁在另一個獨立 agent（視為 root 的子代/旁系）
        $rootAgent = $this->createAgentAndDomain();
        $member = $this->createMember($rootAgent);
        $member->load('agent');

        // 重置 domain 避免 unique 衝突
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $unrelatedAgent = $this->createAgentAndDomain();
        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($unrelatedAgent->id)));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        (new MemberAgentGuard)->assert($member, $context);
    }

    public function test_throws_404_when_bot_agent_completely_unrelated(): void
    {
        $rootAgent = $this->createAgentAndDomain();
        $member = $this->createMember($rootAgent);
        $member->load('agent');

        // bot 綁不存在的 agent_id（完全不在 hierarchy 上）
        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot(8888888)));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        (new MemberAgentGuard)->assert($member, $context);
    }

    public function test_throws_404_when_member_agent_is_null(): void
    {
        // 故意建立一個 parent 指向不存在 agent_id 的 member（模擬資料異常）
        $member = Member::factory()->create([
            'parent' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
        // 清空關聯，模擬 agent 為 null
        $member->setRelation('agent', null);

        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot(1)));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        (new MemberAgentGuard)->assert($member, $context);
    }

    private function makeBot(int $agentId): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ]);
    }
}
