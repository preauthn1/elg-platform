<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Agent;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentPersonalSetting;
use App\Models\Template;
use App\Models\Vendor;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Agent/TemplateTest.php
 */
class TemplateTest extends TestCase
{
    private Agent $agent;

    private string $apiPath = 'frontend/api/agent/template';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        Vendor::factory()->create(['id' => 4]);
        Vendor::factory()->create(['id' => 181]);
        $this->agent = $this->createAgentAndDomain();
        $this->createAgentTemplate();
    }

    private function createAgentTemplate(): void
    {
        $template = Template::create([
            'name' => 'Localhost-Test',
            'thumbnail' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/157345181764.jpeg',
            'status' => 1,
        ]);

        $this->agent->agentDomains()->update([
            'template_id' => $template->id,
        ]);
    }

    private function setAgentMaintain(): void
    {
        AgentPersonalSetting::create([
            'agent_id' => $this->agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG,
            'value' => 1,
            'created_by' => 0,
        ]);

        AgentPersonalSetting::create([
            'agent_id' => $this->agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME,
            'value' => Carbon::now()->startOfDay(),
            'created_by' => 0,
        ]);

        AgentPersonalSetting::create([
            'agent_id' => $this->agent->id,
            'setting_id' => AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME,
            'value' => Carbon::now()->endOfDay(),
            'created_by' => 0,
        ]);
    }

    /**
     * 訪客身份
     */
    public function test_guest_success()
    {
        // Params
        $params = [
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonPath('data.customized_setting.matomo_code', '')
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'id',
                    'name',
                    'mobile_templates',
                    'pc_templates',
                    'title',
                    'domain',
                    'customized_setting',
                ],
            ]);
    }

    /**
     * 會員身份
     */
    public function test_member_success()
    {
        $this->createMemberAndToken($this->agent);

        // Params
        $params = [
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            // ->assertJsonPath('data.customized_setting.matomo_code', fn($value) => !empty($value))
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'id',
                    'name',
                    'mobile_templates',
                    'pc_templates',
                    'title',
                    'domain',
                    'customized_setting',
                ],
            ]);
    }

    /**
     * 錯誤域名
     */
    public function test_fail_with_wrong_domain()
    {
        // Params
        $params = [
            'domain' => 'error.domain',
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1213')]);
    }
}
