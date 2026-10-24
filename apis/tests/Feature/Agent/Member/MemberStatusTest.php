<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentOperateGeneralLog;
use App\Models\AgentOperateLog;
use App\Models\AgentPermission;
use App\Models\AgentStatusLog;
use App\Models\Member;
use App\Models\MemberStatusLog;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員狀態切換（target/tickets#4438，由 API_Agent member/edit_status 移植）。
 *
 * 必須成立：
 * 1. 成功時改會員狀態，並照舊後台寫三筆紀錄：agent_status_logs、member_status_logs、agent_operate_logs（update_member）；
 *    另照舊寫一筆 agent_operate_general_logs（新舊後台交替操作時紀錄連得起來）。
 * 2. 與現值相同擋下、不寫任何紀錄。
 * 3. 範圍外會員擋下。
 * 4. 沒有 member_disabled_permissiom 權限擋下。
 *
 * php artisan test tests/Feature/Agent/Member/MemberStatusTest.php
 */
class MemberStatusTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'ms' . bin2hex(random_bytes(5)),
            'status' => MemberStatusEnum::VERIFIED->value,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => '2099-01-01 00:00:00',
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_disable_member_writes_the_same_logs_as_legacy_backend(): void
    {
        $this->changeStatus($this->member->id, MemberStatusEnum::UNVERIFIED->value, '風控停用')
            ->assertOk()
            ->assertJsonPath('code', 200);

        $this->assertSame(MemberStatusEnum::UNVERIFIED->value, (int) $this->member->fresh()->status);

        $statusLog = AgentStatusLog::query()->where('target_type', AgentStatusLog::TARGET_TYPE_MEMBER)->where('target_id', $this->member->id)->sole();
        $this->assertSame(MemberStatusEnum::VERIFIED->value, (int) $statusLog->before_status);
        $this->assertSame(MemberStatusEnum::UNVERIFIED->value, (int) $statusLog->after_status);
        $this->assertSame('風控停用', $statusLog->remark);
        $this->assertSame($this->agent->id, (int) $statusLog->admin);

        $memberLog = MemberStatusLog::query()->where('member_id', $this->member->id)->sole();
        $this->assertSame(MemberStatusLogTypeEnum::BACKEND_CHANGE, $memberLog->type);
        $this->assertSame($this->member->parent, (int) $memberLog->agent_id);
        $this->assertSame($this->agent->id, (int) $memberLog->operator);

        $operateLog = AgentOperateLog::query()->where('agent_id', $this->agent->id)->where('reference_id', (string) $this->member->id)->sole();
        $this->assertSame(AgentOperateTypeEnum::UPDATE_MEMBER->value, $operateLog->type);
        $this->assertSame(AgentOperateReferenceTypeEnum::MEMBER->value, $operateLog->reference_type);

        $this->assertTrue(
            AgentOperateGeneralLog::query()->where('operator_id', $this->agent->id)->where('page_path', 'like', '%member/status%')->exists(),
            '照舊後台寫一般操作紀錄',
        );
    }

    public function test_same_status_is_rejected_without_writing_logs(): void
    {
        $this->changeStatus($this->member->id, MemberStatusEnum::VERIFIED->value, '沒變')
            ->assertStatus(400);

        $this->assertFalse(AgentStatusLog::query()->where('target_type', AgentStatusLog::TARGET_TYPE_MEMBER)->where('target_id', $this->member->id)->exists());
        $this->assertFalse(MemberStatusLog::query()->where('member_id', $this->member->id)->exists());
    }

    public function test_member_outside_agent_tree_is_rejected(): void
    {
        // createAgentAndDomain 共用 $this->domain，第二棵樹需換新域名避免撞唯一鍵
        $this->domain = 'ms' . bin2hex(random_bytes(5)) . '.test.local';
        $outsider = $this->createMember($this->createAgentAndDomain(), [
            'username' => 'mso' . bin2hex(random_bytes(5)),
            'status' => MemberStatusEnum::VERIFIED->value,
        ]);

        $res = $this->changeStatus($outsider->id, MemberStatusEnum::UNVERIFIED->value, '越權');

        $this->assertNotSame(200, $res->json('code'));
        $this->assertSame(MemberStatusEnum::VERIFIED->value, (int) $outsider->fresh()->status, '擋下不改');
    }

    public function test_permission_node_blocks_agent_without_grant(): void
    {
        // 測試庫無 seeder：自建節點讓權限檢查從 fail-open 變成真的擋
        $menu = AgentMenu::query()->firstOrCreate(
            ['code' => 'member_disabled_permissiom'],
            ['name' => '會員停用/啟用', 'parent' => 0, 'url' => '', 'symbol' => '', 'action' => '', 'sort' => -999, 'status' => 1, 'admin' => 0],
        );

        $denied = $this->changeStatus($this->member->id, MemberStatusEnum::UNVERIFIED->value, '無權限');
        $this->assertNotSame(200, $denied->json('code'), '無權限節點應被擋下');
        $this->assertSame(MemberStatusEnum::VERIFIED->value, (int) $this->member->fresh()->status);

        AgentPermission::create(['agent_id' => $this->agent->id, 'agent_menu_id' => $menu->id, 'action' => 'view', 'admin' => 0]);

        $this->changeStatus($this->member->id, MemberStatusEnum::UNVERIFIED->value, '有權限')
            ->assertOk()
            ->assertJsonPath('code', 200);
    }

    private function changeStatus(int $memberId, int $status, string $remark): TestResponse
    {
        return $this->postJson('agent/api/member/status', [
            'member_id' => $memberId,
            'status' => $status,
            'remark' => $remark,
        ], $this->headers);
    }
}
