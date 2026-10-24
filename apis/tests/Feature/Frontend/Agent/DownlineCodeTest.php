<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Agent;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownlineCodeTest extends TestCase
{
    private Agent $agent;

    private string $apiPath = 'frontend/api/agent/downline-code';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->createAgentDownline();
    }

    /**
     * 顯示下線代理邀請碼
     */
    private function showAgentCode(): void
    {
        $agent = $this->agent;
        $agentDomain = $agent->agentDomains->first();
        $agentDomain->update([
            'info' => [
                'has_auto_show_agent_code' => '1',
            ],
        ]);
    }

    /**
     * 隱藏下線代理邀請碼
     */
    private function hideAgentCode(): void
    {
        $agent = $this->agent;
        $agentDomain = $agent->agentDomains->first();
        $agentDomain->update([
            'info' => [
                'has_auto_show_agent_code' => '0',
            ],
        ]);
    }

    /**
     * 創建下線代理
     */
    private function createAgentDownline(): void
    {
        $parent = $this->agent;

        $agent = Agent::create([
            'parent' => 0,
            'code' => Str::random(8),
            'username' => 'Toyota',
            'email' => 'user@example.invalid',
            'password' => Hash::make('123456'),
            'status' => 1,
            'admin' => 1,
            'frontend_register' => 1, // For member creation
            'organization_id' => 1,
            'customized_setting_group_id' => 1,
            'cash_flow_group_id' => 1,
        ]);
        $agent->hierarchy_path = sprintf('%s/%s', $parent->hierarchy_path, $agent->id);
        $agent->hierarchy = sprintf('[-1,%s,%s,-2]', $agent->id, $parent->id);
        $agent->save();
    }

    /**
     * 訪客, 代理顯示下線邀請碼
     * php artisan test tests/Feature/Frontend/Agent/DownlineCodeTest.php --filter test_show_agent_code_success
     */
    public function test_show_agent_code_success()
    {
        $this->showAgentCode();

        // Params
        $params = [
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);
        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'has_open',
                    'agent_list',
                ],
            ])
            ->assertJsonPath('data.has_open', 1)
            ->assertJsonPath('data.agent_list', fn ($agentList) => is_array($agentList) &&
                collect($agentList)->every(fn ($item) => isset($item['nickname'], $item['code']) &&
                    is_string($item['nickname']) &&
                    is_string($item['code'])
                )
            );
    }

    /**
     * 訪客, 代理隱藏下線邀請碼
     */
    public function test_hide_agent_code_success()
    {
        $this->hideAgentCode();

        // Params
        $params = [
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'has_open',
                    'agent_list',
                ],
            ])
            ->assertJsonPath('data.has_open', 0);
    }
}
