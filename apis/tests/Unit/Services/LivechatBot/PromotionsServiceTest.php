<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\LivechatBot;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use App\Services\LivechatBot\PromotionsService;
use App\Services\LivechatBot\PromotionV1Builder;
use App\Services\LivechatBot\PromotionV2Builder;
use Mockery;
use Tests\TestCase;

/**
 * Livechat Bot PromotionsService 測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/PromotionsServiceTest.php
 */
class PromotionsServiceTest extends TestCase
{
    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id)));
        $this->guard = new MemberAgentGuard;
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

    public function test_throws_404_when_member_not_found(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service = new PromotionsService(
            Mockery::mock(PromotionV1Builder::class),
            Mockery::mock(PromotionV2Builder::class),
            $this->context,
            $this->guard,
        );

        $service->getPromotions('nonexistent_user_999');
    }

    public function test_aggregates_v1_and_v2_results_into_three_categories(): void
    {
        $member = $this->createMember($this->agent);

        $v1 = Mockery::mock(PromotionV1Builder::class);
        $v1->shouldReceive('buildClaimable')->andReturn([
            ['promotionSource' => 'v1', 'applyId' => 1, 'promotionName' => 'V1 Claim', 'rewardAmount' => '10.0000'],
        ]);
        $v1->shouldReceive('buildConditioned')->andReturn([
            ['promotionSource' => 'v1', 'promotionName' => 'V1 Cond', 'nonQualify' => ['x'], 'progress' => []],
        ]);

        $v2 = Mockery::mock(PromotionV2Builder::class);
        $v2->shouldReceive('buildActive')->andReturn([
            ['promotionSource' => 'v2', 'promotionId' => 5, 'promotionName' => 'V2 Active'],
        ]);
        $v2->shouldReceive('buildClaimable')->andReturn([
            ['promotionSource' => 'v2', 'promotionId' => 6, 'promotionName' => 'V2 Claim'],
        ]);
        $v2->shouldReceive('buildConditioned')->andReturn([
            ['promotionSource' => 'v2', 'promotionId' => 7, 'promotionName' => 'V2 Cond'],
        ]);

        $service = new PromotionsService($v1, $v2, $this->context, $this->guard);
        $result = $service->getPromotions($member->username);

        $this->assertArrayHasKey('activePromotions', $result);
        $this->assertArrayHasKey('claimablePromotions', $result);
        $this->assertArrayHasKey('conditionedPromotions', $result);

        $this->assertCount(1, $result['activePromotions']);
        $this->assertSame('v2', $result['activePromotions'][0]['promotionSource']);

        // claimable: v2 + v1 各 1 筆
        $this->assertCount(2, $result['claimablePromotions']);
        $sources = array_column($result['claimablePromotions'], 'promotionSource');
        $this->assertContains('v1', $sources);
        $this->assertContains('v2', $sources);

        // conditioned: v2 + v1 各 1 筆
        $this->assertCount(2, $result['conditionedPromotions']);
    }

    public function test_returns_empty_arrays_when_no_promotions(): void
    {
        $member = $this->createMember($this->agent);

        $v1 = Mockery::mock(PromotionV1Builder::class);
        $v1->shouldReceive('buildClaimable')->andReturn([]);
        $v1->shouldReceive('buildConditioned')->andReturn([]);

        $v2 = Mockery::mock(PromotionV2Builder::class);
        $v2->shouldReceive('buildActive')->andReturn([]);
        $v2->shouldReceive('buildClaimable')->andReturn([]);
        $v2->shouldReceive('buildConditioned')->andReturn([]);

        $service = new PromotionsService($v1, $v2, $this->context, $this->guard);
        $result = $service->getPromotions($member->username);

        $this->assertSame([], $result['activePromotions']);
        $this->assertSame([], $result['claimablePromotions']);
        $this->assertSame([], $result['conditionedPromotions']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        $service = new PromotionsService(
            Mockery::mock(PromotionV1Builder::class),
            Mockery::mock(PromotionV2Builder::class),
            $otherContext,
            $this->guard,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getPromotions($member->username);
    }
}
