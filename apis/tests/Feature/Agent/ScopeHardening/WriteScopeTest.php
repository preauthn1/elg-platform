<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ScopeHardening;

use App\Enums\LanguageEnum;
use App\Models\Game;
use App\Models\GameMaintenance;
use App\Models\MemberGameRestriction;
use App\Models\MemberMail;
use Tests\TestCase;

/**
 * 工單 4495：遊戲維護、站內信、遊戲限制的寫入端必須限自己與下線。
 *
 * php artisan test --env=testing tests/Feature/Agent/ScopeHardening/WriteScopeTest.php
 */
class WriteScopeTest extends TestCase
{
    use ActsAsScopedAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootScopedAgent();
        $this->createMenus(['risk_game_restriction', 'member_mail_management'], grant: true);
    }

    // ── 遊戲維護 ──

    /** @return array<string, mixed> */
    private function maintenancePayload(int $agentId, array $extra = []): array
    {
        return array_merge([
            'start_time' => '2026-12-01 00:00:00',
            'end_time' => '2026-12-02 00:00:00',
            'agent_id' => $agentId,
            'game_ids' => [Game::factory()->create(['game_type' => 'T' . bin2hex(random_bytes(4))])->id],
        ], $extra);
    }

    public function test_game_maintenance_allows_self_and_downline(): void
    {
        $downline = $this->createDownline();

        $this->requestApi('agent/api/game-maintenance/save', $this->maintenancePayload($this->agent->id))
            ->assertJsonPath('code', 200);
        $this->requestApi('agent/api/game-maintenance/save', $this->maintenancePayload($downline->id))
            ->assertJsonPath('code', 200);

        $this->assertSame(2, GameMaintenance::query()->whereIn('agent_id', [$this->agent->id, $downline->id])->count());
    }

    public function test_game_maintenance_rejects_agent_outside_line(): void
    {
        foreach ([$this->createOutsideAgent(), $this->createPrefixCollidingAgent()] as $other) {
            $this->requestApi('agent/api/game-maintenance/save', $this->maintenancePayload($other->id))
                ->assertJsonPath('message', __('error_message.9003'));

            $this->assertSame(0, GameMaintenance::query()->where('agent_id', $other->id)->count());
        }
    }

    public function test_game_maintenance_rejects_exclude_agent_outside_line(): void
    {
        $other = $this->createOutsideAgent();

        $this->requestApi(
            'agent/api/game-maintenance/save',
            $this->maintenancePayload($this->agent->id, ['exclude_agent_id' => $other->id]),
        )->assertJsonPath('message', __('error_message.9003'));
    }

    // ── 站內信 ──

    /** @return array<string, mixed> */
    private function mailPayload(array $extra): array
    {
        return array_merge([
            'publish_time' => '2026-12-01 10:00:00',
            'languages' => [[
                'language_id' => LanguageEnum::EN->value,
                'title' => 'scope test',
                'content' => 'scope test body',
            ]],
        ], $extra);
    }

    public function test_member_mail_agent_hierarchy_limited_to_own_line(): void
    {
        $downline = $this->createDownline();
        $other = $this->createOutsideAgent();
        $colliding = $this->createPrefixCollidingAgent();

        $this->requestApi('agent/api/member-mail/save', $this->mailPayload([
            'send_type' => 4,
            'hierarchy_agent_id' => $downline->id,
        ]))->assertJsonPath('code', 200);

        foreach ([$other, $colliding] as $blocked) {
            $this->requestApi('agent/api/member-mail/save', $this->mailPayload([
                'send_type' => 4,
                'hierarchy_agent_id' => $blocked->id,
            ]))->assertJsonPath('message', __('error_message.9003'));
        }

        $this->assertSame(1, MemberMail::query()->where('hierarchy_agent_id', $downline->id)->count());
        $this->assertSame(0, MemberMail::query()->whereIn('hierarchy_agent_id', [$other->id, $colliding->id])->count());
    }

    public function test_member_mail_specific_members_limited_to_own_line(): void
    {
        $own = $this->createMember($this->createDownline());
        $outsider = $this->createMember($this->createOutsideAgent());
        $collidingMember = $this->createMember($this->createPrefixCollidingAgent());

        $this->requestApi('agent/api/member-mail/save', $this->mailPayload([
            'send_type' => 1,
            'member_ids' => [$own->id],
        ]))->assertJsonPath('code', 200);

        // 只要夾帶一個線外會員就整筆擋下
        foreach ([$outsider, $collidingMember] as $blocked) {
            $this->requestApi('agent/api/member-mail/save', $this->mailPayload([
                'send_type' => 1,
                'member_ids' => [$own->id, $blocked->id],
            ]))->assertJsonPath('message', __('error_message.9003'));
        }
    }

    public function test_member_mail_organization_rejected_when_it_spans_other_lines(): void
    {
        // 工廠建出的代理預設同屬組織 1，別線代理存在即代表該組織涵蓋線外
        $this->createOutsideAgent();

        $this->requestApi('agent/api/member-mail/save', $this->mailPayload([
            'send_type' => 3,
            'organization_id' => (int) $this->agent->organization_id,
        ]))->assertJsonPath('message', __('error_message.9003'));
    }

    // ── 遊戲限制 ──

    /** @return array<string, mixed> */
    private function restrictionPayload(int $agentId, array $extra = []): array
    {
        return array_merge([
            'restrict_type' => 1,
            'target_scope' => 1,
            'agent_id' => $agentId,
            'target_ids' => [1],
            'member_ids' => [],
        ], $extra);
    }

    public function test_game_restriction_rejects_agent_outside_line(): void
    {
        foreach ([$this->createOutsideAgent(), $this->createPrefixCollidingAgent()] as $other) {
            $this->requestApi('agent/api/member-game-restriction/save', $this->restrictionPayload(
                $other->id,
                ['member_ids' => [$this->createMember($this->agent)->id]],
            ))->assertJsonPath('message', __('error_message.9003'));

            $this->assertSame(0, MemberGameRestriction::query()->where('agent_id', $other->id)->count());
        }
    }

    public function test_game_restriction_rejects_member_outside_line(): void
    {
        $outsider = $this->createMember($this->createOutsideAgent());
        $collidingMember = $this->createMember($this->createPrefixCollidingAgent());

        foreach ([$outsider, $collidingMember] as $blocked) {
            $this->requestApi('agent/api/member-game-restriction/save', $this->restrictionPayload(
                $this->agent->id,
                ['member_ids' => [$blocked->id]],
            ))->assertJsonPath('message', __('error_message.9003'));
        }

        $this->assertSame(0, MemberGameRestriction::query()->where('agent_id', $this->agent->id)->count());
    }
}
