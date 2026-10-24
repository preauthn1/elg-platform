<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Models\LivechatBot;
use App\Services\LivechatBot\LivechatBotContext;
use Tests\TestCase;

/**
 * LivechatBotContext 單元測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/LivechatBotContextTest.php
 */
class LivechatBotContextTest extends TestCase
{
    public function test_typed_getters_return_expected_values(): void
    {
        $bot = $this->makeBot([
            'name' => 'brand_a',
            'agent_id' => 12345,
            'vip_url' => 'https://example.invalid/vip',
        ]);

        $context = new LivechatBotContext;
        $context->setBot($bot);

        $this->assertSame((int) $bot->id, $context->id());
        $this->assertSame(12345, $context->agentId());
        $this->assertSame('brand_a', $context->name());
        $this->assertSame('https://example.invalid/vip', $context->vipUrl());
    }

    public function test_vip_url_returns_null_when_unset(): void
    {
        $bot = $this->makeBot(['vip_url' => null]);

        $context = new LivechatBotContext;
        $context->setBot($bot);

        $this->assertNull($context->vipUrl());
    }

    public function test_throws_when_accessed_before_set_bot(): void
    {
        $context = new LivechatBotContext;

        $this->expectException(\RuntimeException::class);
        $context->agentId();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBot(array $overrides = []): LivechatBot
    {
        $defaults = [
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => 1,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ];

        return LivechatBot::create(array_merge($defaults, $overrides));
    }
}
