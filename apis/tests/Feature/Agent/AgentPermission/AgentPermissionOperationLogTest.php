<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission;

use App\Models\AgentOperateGeneralLog;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/AgentPermission/AgentPermissionOperationLogTest.php
 *
 * 權限接口搬進 apis 後照舊寫 agent_operate_general_logs（target/tickets#4422）：
 * API_Agent 由中間件對每支接口自動寫入，選單樹（agentMenu/list）排除在外。
 */
class AgentPermissionOperationLogTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgentApi();
    }

    public function test_permission_write_is_logged_with_operator_and_params(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent', 'report_a');
        $target = $this->downline($top);
        $menu = $this->menu('report_a');
        $this->actAs($top);

        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $menu->id => 'view'],
        ])->assertJsonPath('code', 200);

        $log = AgentOperateGeneralLog::query()->where('operator_id', $top->id)->sole();
        $this->assertSame('agent/api/agent-permission/setting', $log->page_path);
        $this->assertSame('POST', $log->request_method);
        $this->assertSame($target->id, $log->content['agent_id']);
    }

    public function test_rejected_request_is_still_logged(): void
    {
        // 照 API_Agent：處理前就寫，權限不足被擋的請求也留紀錄（追查越權嘗試要用）
        $top = $this->topAgent();
        $this->grant($top, 'add_agent');
        $this->actAs($top);

        $this->callApi('agent-permission/setting', ['agent_id' => $this->topAgent()->id, 'menus' => []])
            ->assertJsonPath('message', __('error_message.9003'));

        $this->assertTrue(AgentOperateGeneralLog::query()->where('operator_id', $top->id)->exists());
    }

    public function test_menu_tree_is_not_logged(): void
    {
        $top = $this->topAgent();
        $this->actAs($top);

        $this->callApi('agent-permission/menu-tree', ['agent_id' => $top->id])->assertJsonPath('code', 200);

        $this->assertFalse(AgentOperateGeneralLog::query()->where('operator_id', $top->id)->exists());
    }

    public function test_credentials_in_request_are_not_logged(): void
    {
        $top = $this->topAgent();
        $this->actAs($top);

        $this->callApi('agent-permission/child-accounts', [
            'agent_id' => $top->id,
            'new_password' => 'secret-pass',
            'otp' => '123456',
        ]);

        $content = AgentOperateGeneralLog::query()->where('operator_id', $top->id)->sole()->content;
        $this->assertSame($top->id, $content['agent_id']);
        $this->assertArrayNotHasKey('new_password', $content);
        $this->assertArrayNotHasKey('otp', $content);
    }
}
