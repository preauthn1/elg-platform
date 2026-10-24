<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\SeoMeta;

use App\Enums\Agent\RoleEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentDomain;
use App\Models\AgentDomainSeoMeta;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Services\Agent\AgentSeoMetaService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEO 元數據 工單4102：選單權限、綁域名儲存、跨線防護、同線唯一預設、前台預設回退。
 *
 * php artisan test tests/Feature/Agent/SeoMeta/SeoMetaTest.php
 */
class SeoMetaTest extends TestCase
{
    private Agent $agent;

    private AgentDomain $ownDomain;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->ownDomain = $this->createDomain($this->agent);
        $this->loginAs($this->agent);
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function createMenu(): AgentMenu
    {
        return AgentMenu::create([
            'code' => 'seo_meta_management',
            'name' => 'SEO 元數據',
            'parent' => 0,
            'url' => 'seo_meta/index',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    private function grantMenu(Agent $agent): void
    {
        $menu = AgentMenu::query()->where('code', 'seo_meta_management')->first() ?? $this->createMenu();

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    /** createAgentAndDomain() 綁的測試網域有 unique 限制，額外域名另以隨機名稱建立 */
    private function createDomain(Agent $agent): AgentDomain
    {
        return AgentDomain::create([
            'agent_id' => $agent->id,
            'domain' => Str::lower(Str::random(12)) . '.seo.test.local',
            'status' => 1,
            'type' => 0,
            'admin' => 1,
        ]);
    }

    /** 直接用 factory 建第二個總代，避免 createAgentAndDomain() 重用同一個測試網域 */
    private function createOtherGeneralAgent(): Agent
    {
        $agent = Agent::factory()->create([
            'status' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ]);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    private function createDownlineAgent(Agent $parent): Agent
    {
        $agent = Agent::factory()->create([
            'status' => 1,
            'role_id' => RoleEnum::AGENT->value,
            'parent' => $parent->id,
        ]);
        $agent->hierarchy_path = "/{$parent->id}/{$agent->id}";
        $agent->hierarchy = "[-1,{$parent->id},{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    private function saveMeta(AgentDomain $domain, bool $isDefault, string $title = 'Title'): \Illuminate\Testing\TestResponse
    {
        return $this->requestApi('agent/api/seo-meta/save', [
            'agent_domain_id' => $domain->id,
            'is_default' => $isDefault,
            'items' => [
                [
                    'page' => 'home',
                    'title' => $title,
                    'description' => 'Desc',
                    'keywords' => 'a,b,c',
                ],
            ],
        ]);
    }

    /** 沒有 seo_meta_management 選單權限的總代不得呼叫 */
    public function test_rejects_agent_without_menu_permission(): void
    {
        $this->createMenu();

        $response = $this->requestApi('agent/api/seo-meta/list', [
            'agent_domain_id' => $this->ownDomain->id,
        ]);

        $response->assertJsonPath('message', __('error_message.9003'));
    }

    /** 總代對自己名下的域名儲存後，清單回填一致 */
    public function test_save_and_list_own_domain(): void
    {
        $this->grantMenu($this->agent);

        $this->saveMeta($this->ownDomain, true, 'My Title')->assertJsonPath('code', 200);

        $response = $this->requestApi('agent/api/seo-meta/list', [
            'agent_domain_id' => $this->ownDomain->id,
        ]);

        $response->assertJsonPath('code', 200)
            ->assertJsonPath('data.can_select_agent', false)
            ->assertJsonPath('data.agent_id', $this->agent->id)
            ->assertJsonPath('data.agent_domain_id', $this->ownDomain->id)
            ->assertJsonPath('data.is_default', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.page', 'home')
            ->assertJsonPath('data.items.0.title', 'My Title')
            ->assertJsonPath('data.items.0.description', 'Desc')
            ->assertJsonPath('data.items.0.keywords', 'a,b,c');
    }

    /** 帶別條線總代名下的域名進來儲存會被拒絕，且不落地 */
    public function test_rejects_domain_of_other_general_agent(): void
    {
        $this->grantMenu($this->agent);

        $otherDomain = $this->createDomain($this->createOtherGeneralAgent());

        $response = $this->saveMeta($otherDomain, false);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertDatabaseMissing('agent_domain_seo_metas', [
            'agent_domain_id' => $otherDomain->id,
        ]);
    }

    /** 同一總代線只能有一個預設：後設的取代先設的 */
    public function test_only_one_default_per_general_agent(): void
    {
        $this->grantMenu($this->agent);

        $secondDomain = $this->createDomain($this->agent);

        $this->saveMeta($this->ownDomain, true)->assertJsonPath('code', 200);
        $this->saveMeta($secondDomain, true)->assertJsonPath('code', 200);

        $this->assertDatabaseHas('agent_domain_seo_metas', [
            'agent_domain_id' => $this->ownDomain->id,
            'is_default' => 0,
        ]);
        $this->assertDatabaseHas('agent_domain_seo_metas', [
            'agent_domain_id' => $secondDomain->id,
            'is_default' => 1,
        ]);
        $this->assertSame(1, AgentDomainSeoMeta::query()
            ->where('general_agent_id', $this->agent->id)
            ->where('is_default', 1)
            ->count());
    }

    /** 前台取值：域名自己 → 同線預設 → 空字串，key 固定為各頁面代碼 */
    public function test_frontend_meta_fallback_order(): void
    {
        $service = app(AgentSeoMetaService::class);

        // 都沒有：三欄空字串、key 固定為 home
        $this->assertSame(
            ['home' => ['title' => '', 'description' => '', 'keywords' => '']],
            $service->getFrontendMeta($this->ownDomain),
        );

        $service->save(
            agentDomainId: $this->ownDomain->id,
            generalAgentId: $this->agent->id,
            isDefault: true,
            items: [['page' => 'home', 'title' => 'Default', 'description' => 'D', 'keywords' => 'k']],
            adminId: $this->agent->id,
        );

        // 下線代理名下的域名沒有自己的設定：回退用該線預設
        $downlineDomain = $this->createDomain($this->createDownlineAgent($this->agent));

        $this->assertSame(
            ['home' => ['title' => 'Default', 'description' => 'D', 'keywords' => 'k']],
            $service->getFrontendMeta($downlineDomain->fresh()),
        );

        // 有自己的設定：取自己
        $selfDomain = $this->createDomain($this->agent);
        $service->save(
            agentDomainId: $selfDomain->id,
            generalAgentId: $this->agent->id,
            isDefault: false,
            items: [['page' => 'home', 'title' => 'Own', 'description' => null, 'keywords' => null]],
            adminId: $this->agent->id,
        );

        $this->assertSame(
            ['home' => ['title' => 'Own', 'description' => '', 'keywords' => '']],
            $service->getFrontendMeta($selfDomain->fresh()),
        );
    }
}
