<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\OperateLog;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Data\AgentOperateLog\StatusChangeDebugInfo;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Models\Agent;
use App\Models\AgentOperateLog;
use App\Services\Agent\AgentOperateLogService;
use LogicException;
use Tests\TestCase;

/**
 * 代理操作紀錄的統一寫入方法 AgentOperateLogService::log()（target/tickets#4439）
 *
 * php artisan test tests/Feature/Agent/OperateLog/UnifiedWriteTest.php
 */
class UnifiedWriteTest extends TestCase
{
    private AgentOperateLogService $service;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AgentOperateLogService::class);
        $this->agent = Agent::factory()->create();
    }

    public function test_writes_all_columns(): void
    {
        $log = $this->service->log(
            agentId: $this->agent->id,
            type: AgentOperateTypeEnum::WITHDRAWAL_MARK_FAILED,
            debugInfo: new StatusChangeDebugInfo(oldStatus: 21, newStatus: 29, remark: '查無此單'),
            ip: '0.0.0.0',
            referenceId: '123',
            referenceType: AgentOperateReferenceTypeEnum::WITHDRAWAL_ORDER,
        );

        $log = AgentOperateLog::findOrFail($log->id);
        $this->assertSame($this->agent->id, $log->agent_id);
        $this->assertSame('withdrawal_mark_failed', $log->type);
        $this->assertSame('123', $log->reference_id);
        $this->assertSame('withdrawal_order', $log->reference_type);
        $this->assertSame('0.0.0.0', $log->ip);
        $this->assertSame(
            '{"old":{"status":21},"attributes":{"status":29},"remark":"查無此單"}',
            $log->debug_info,
        );
    }

    public function test_rejects_debug_info_kind_mismatch_without_writing(): void
    {
        $before = AgentOperateLog::where('agent_id', $this->agent->id)->count();

        try {
            // 狀態變更類型卻傳純文字備註：寫進去讀取端會認不得，必須擋下
            $this->service->log(
                agentId: $this->agent->id,
                type: AgentOperateTypeEnum::WITHDRAWAL_FILL_THIRD_PARTY_NO,
                debugInfo: new PlainDebugInfo(['status' => 22]),
                ip: '0.0.0.0',
            );
            $this->fail('種類不一致必須拋例外');
        } catch (LogicException $e) {
            $this->assertStringContainsString('withdrawal_fill_third_party_no', $e->getMessage());
        }

        $this->assertSame($before, AgentOperateLog::where('agent_id', $this->agent->id)->count());
    }

    public function test_every_type_declares_debug_info_kind(): void
    {
        // 新增類型漏寫 debugInfoKind() 時，match 會拋 UnhandledMatchError 讓本測試失敗
        foreach (AgentOperateTypeEnum::cases() as $type) {
            $this->assertNotNull($type->debugInfoKind(), $type->value);
        }
    }

    public function test_plain_debug_info_keeps_existing_format(): void
    {
        $content = ['member_id' => 1, 'vendor_username' => '測試帳號'];

        // 陣列：與既有寫入點直接 json_encode()（預設選項）的結果逐字相同
        $this->assertSame(json_encode($content), (new PlainDebugInfo($content))->toDebugInfo());
        // 字串：原樣存，包含既有寫入點用到的空字串
        $this->assertSame('', (new PlainDebugInfo(''))->toDebugInfo());
    }

    public function test_plain_array_debug_info_strips_credentials(): void
    {
        $log = $this->service->log(
            agentId: $this->agent->id,
            type: AgentOperateTypeEnum::UPDATE_AGENT,
            debugInfo: new PlainDebugInfo([
                'request_info' => ['TOKEN' => 'abc', 'new_payment_password' => '123456', 'remark' => '備註', 'id' => 9],
                'originalDataJson' => ['password' => 'hash', 'CS_API_KEY' => 'k', 'status' => 1],
            ]),
            ip: '0.0.0.0',
        );

        $this->assertSame(
            json_encode(['request_info' => ['remark' => '備註', 'id' => 9], 'originalDataJson' => ['status' => 1]]),
            AgentOperateLog::findOrFail($log->id)->debug_info,
        );
    }

    public function test_strip_credentials_keeps_lookalike_keys(): void
    {
        $kept = ['token_expired' => '300', 'has_2fa' => 1, 'id' => 1, 'username' => 'a', 'bank_account' => '1234567890'];

        $this->assertSame($kept, $this->service->stripCredentials($kept + [
            'agent_token' => 'x', 'bot_token' => 'x', 'otp' => '123456', 'secret' => '123456',
            'password_confirmation' => 'x', 'agent_payment_password' => 'x', 'im_certify' => 'x', 'ID' => 1,
        ]));
    }
}
