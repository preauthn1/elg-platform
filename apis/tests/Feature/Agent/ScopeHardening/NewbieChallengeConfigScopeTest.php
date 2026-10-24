<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ScopeHardening;

use App\Enums\LanguageEnum;
use App\Models\NewbieChallengeConfig;
use App\Models\NewbieChallengeConfigLang;
use Tests\TestCase;

/**
 * op_tickets#3584：代理後台「新人專享挑戰活動 > 活動配置頁」持有選單權限後，儲存仍被
 * assertAdmin() 擋下回「無權限操作」。修正為非 admin 僅能改文案／圖片（config_lang），
 * 其餘欄位（活動金額、流水倍數等）維持平台 admin 專屬。
 *
 * php artisan test --env=testing tests/Feature/Agent/ScopeHardening/NewbieChallengeConfigScopeTest.php
 */
class NewbieChallengeConfigScopeTest extends TestCase
{
    use ActsAsScopedAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootScopedAgent();
        $this->createMenus(['newbie_challenge_setting'], grant: true);
    }

    /** @return array<string, mixed> */
    private function updatePayload(int $configId, array $extra = []): array
    {
        return array_merge([
            'config_id' => $configId,
            'agent_id' => null,
            'activity_amount' => '999',
            'challenge_flow_multiple' => '5',
            'bonus_flow_multiple' => '12',
            'min_unlock_amount' => '10',
            'max_unlock_amount' => '1888',
            'bonus_window_hours' => 48,
            'status' => 1,
            'config_lang' => [[
                'language_id' => LanguageEnum::ZH_TW->value,
                'name' => '更新後的文案',
                'content' => '更新後的說明',
                'image' => 'https://example.invalid/new.jpg',
            ]],
        ], $extra);
    }

    private function createConfig(?int $agentId): NewbieChallengeConfig
    {
        return NewbieChallengeConfig::create([
            'agent_id' => $agentId,
            'activity_amount' => '88',
            'challenge_flow_multiple' => '5',
            'bonus_flow_multiple' => '12',
            'min_unlock_amount' => '10',
            'max_unlock_amount' => '1888',
            'bonus_window_hours' => 48,
            'status' => 0,
        ]);
    }

    public function test_admin_can_update_all_fields(): void
    {
        $this->agent->update(['host_id' => 1]);

        $config = $this->createConfig($this->agent->id);

        $this->requestApi('agent/api/newbie-challenge/config-update', $this->updatePayload($config->id))
            ->assertJsonPath('code', 200);

        $config->refresh();
        $this->assertSame('999.0000', $config->activity_amount);
        $this->assertSame(
            '更新後的文案',
            NewbieChallengeConfigLang::where('config_id', $config->id)->value('name'),
        );
    }

    public function test_non_admin_with_permission_can_only_update_lang_for_in_scope_config(): void
    {
        $config = $this->createConfig($this->agent->id);

        $this->requestApi('agent/api/newbie-challenge/config-update', $this->updatePayload($config->id))
            ->assertJsonPath('code', 200);

        $config->refresh();
        // 活動金額等欄位必須維持原值，不被非 admin 的提交改掉
        $this->assertSame('88.0000', $config->activity_amount);
        $this->assertSame(0, $config->status);
        // 文案／圖片正常更新
        $this->assertSame(
            '更新後的文案',
            NewbieChallengeConfigLang::where('config_id', $config->id)->value('name'),
        );
    }

    public function test_non_admin_with_permission_can_update_site_wide_config_lang(): void
    {
        $config = $this->createConfig(null);

        $this->requestApi('agent/api/newbie-challenge/config-update', $this->updatePayload($config->id))
            ->assertJsonPath('code', 200);

        $this->assertSame(
            '更新後的文案',
            NewbieChallengeConfigLang::where('config_id', $config->id)->value('name'),
        );
    }

    public function test_non_admin_with_permission_rejected_for_out_of_scope_config(): void
    {
        $other = $this->createOutsideAgent();
        $config = $this->createConfig($other->id);

        $this->requestApi('agent/api/newbie-challenge/config-update', $this->updatePayload($config->id))
            ->assertJsonPath('message', '無權限操作');

        $this->assertNull(NewbieChallengeConfigLang::where('config_id', $config->id)->value('name'));
    }
}
