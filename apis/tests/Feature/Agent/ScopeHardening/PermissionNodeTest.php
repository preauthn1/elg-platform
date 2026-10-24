<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ScopeHardening;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 工單 4495：寫入端點補上選單權限節點。
 *
 * 無節點的代理一律回 9003；持有節點後不得再被中間件擋（業務／驗證層的回應不在此斷言）。
 *
 * php artisan test --env=testing tests/Feature/Agent/ScopeHardening/PermissionNodeTest.php
 */
class PermissionNodeTest extends TestCase
{
    use ActsAsScopedAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootScopedAgent();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guardedEndpoints(): array
    {
        return [
            'game-maintenance/list' => ['agent/api/game-maintenance/list', 'risk_game_restriction'],
            'game-maintenance/save' => ['agent/api/game-maintenance/save', 'risk_game_restriction'],
            'member-game-restriction/save' => ['agent/api/member-game-restriction/save', 'risk_game_restriction'],
            'member-mail/save' => ['agent/api/member-mail/save', 'member_mail_management'],
            'social-link/save' => ['agent/api/social-link/save', 'social_link_management'],
            'newbie-challenge/config-update' => ['agent/api/newbie-challenge/config-update', 'newbie_challenge_setting'],
            'newbie-challenge/update-wallet-balance' => ['agent/api/newbie-challenge/update-wallet-balance', 'newbie_challenge_record'],
            'newbie-challenge/quit' => ['agent/api/newbie-challenge/quit', 'newbie_challenge_record'],
            'newbie-challenge/allow-join' => ['agent/api/newbie-challenge/allow-join', 'newbie_challenge_block'],
            'member/referral-info' => ['agent/api/member/referral-info', 'member_referral_info'],
        ];
    }

    #[DataProvider('guardedEndpoints')]
    public function test_rejects_agent_without_menu_permission(string $uri, string $menuCode): void
    {
        $this->createMenus([$menuCode], grant: false);

        $this->requestApi($uri)->assertJsonPath('message', __('error_message.9003'));
    }

    #[DataProvider('guardedEndpoints')]
    public function test_menu_permission_lets_request_pass_middleware(string $uri, string $menuCode): void
    {
        $this->createMenus([$menuCode], grant: true);

        // 空 payload 會在驗證層失敗，但不應再是權限不足
        $this->assertNotSame(
            __('error_message.9003'),
            $this->requestApi($uri)->json('message'),
        );
    }

    public function test_permission_of_other_page_does_not_unlock_endpoint(): void
    {
        // 持有「社群連結」權限不能用來操作站內信
        $this->createMenus(['member_mail_management'], grant: false);
        $this->createMenus(['social_link_management'], grant: true);

        $this->requestApi('agent/api/member-mail/save')->assertJsonPath('message', __('error_message.9003'));
    }
}
