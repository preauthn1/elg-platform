<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ScopeHardening;

use App\CacheKeys\CacheKeys;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 工單 4495 權限／範圍測試的共用夾具。
 *
 * 測試庫 agent_menus 為空時 CheckAgentPermission 會 fail-open 放行，
 * 所以要驗「被擋」一定要先建選單碼（{@see grantMenus()} 會一併建立）。
 */
trait ActsAsScopedAgent
{
    /** 登入者（主帳號，hierarchy_path 為 /{id}） */
    private Agent $agent;

    private function bootScopedAgent(): void
    {
        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * 建立選單碼；$grant 為 true 時再把個人權限給登入者。
     *
     * @param  array<int, string>  $codes
     */
    private function createMenus(array $codes, bool $grant): void
    {
        foreach ($codes as $code) {
            $menu = AgentMenu::query()->where('code', $code)->first() ?? AgentMenu::create([
                'code' => $code,
                'name' => $code,
                'parent' => 0,
                'url' => '',
                'symbol' => '',
                'action' => '',
                'sort' => 0,
                'status' => 1,
                'admin' => 0,
            ]);

            if ($grant) {
                AgentPermission::query()->firstOrCreate(
                    ['agent_id' => $this->agent->id, 'agent_menu_id' => $menu->id],
                    ['action' => '', 'admin' => 0],
                );
            }
        }

        // 選單碼對應表在服務內快取 1 小時，id 隨每個測試重建，不清會拿到舊 id 比對
        Cache::forget(CacheKeys::agentMenuCodeMap());
    }

    /** MemberFactory 的 username 固定為 testuser，同一測試建多個會員會撞唯一鍵，這裡預設補隨機值 */
    protected function createMember(Agent $agent, array $data = []): Member
    {
        return parent::createMember($agent, array_merge(['username' => 'u' . bin2hex(random_bytes(6))], $data));
    }

    /** 登入者的下線代理（路徑 /{登入者}/{id}） */
    private function createDownline(): Agent
    {
        $downline = Agent::factory()->create(['parent' => $this->agent->id]);
        $downline->update(['hierarchy_path' => $this->agent->hierarchy_path . '/' . $downline->id]);

        return $downline->refresh();
    }

    /**
     * 與登入者「前綴相撞」的別線代理：路徑 /{登入者 id}9 以登入者路徑 /{id} 開頭，
     * 但不是下線。舊的 `like '<path>%'` 會把它誤判成下線，完整路徑比對則不會。
     */
    private function createPrefixCollidingAgent(): Agent
    {
        $other = Agent::factory()->create();
        $other->update(['hierarchy_path' => $this->agent->hierarchy_path . '9']);

        return $other->refresh();
    }

    /** 完全無關的另一條線 */
    private function createOutsideAgent(): Agent
    {
        $other = Agent::factory()->create();
        $other->update(['hierarchy_path' => '/' . $other->id]);

        return $other->refresh();
    }
}
