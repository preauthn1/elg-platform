<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Services\Agent\AdminDefaultGroupSyncService;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan db:seed --class=SyncAgentMenuSeeder
 *
 * 結尾清 AgentMenuPermissionService 的 code ⇒ id 快取（TTL 一小時）：該服務查無 code 是 fail-open 放行，
 * 新節點 seed 進 DB 後若快取還是舊的，掛該碼的接口在快取過期前對任何代理都不設防。
 *
 * 新選單照舊補給 admin（agents.id = 1）的個人權限，最後再把 admin 的權限同步到「admin 預設角色」
 * （target/tickets#4422），見 AdminDefaultGroupSyncService。
 */
class SyncAgentMenuSeeder extends Seeder
{
    /**
     * 新節點首次建立時，除 admin 外還要自動補給的對象（target/tickets#4506）：admin 子帳號（host_id = 1）、
     * 總代（role_id = 11）、客服（被設為客服的總代子帳號，role_id = 12）。
     * 其他帳號與之後新建的角色預設不勾；只在節點「首次建立」那一輪補，之後人工取消勾選不會被升級補回。
     */
    private const array GRANT_TO_PLATFORM_AND_GENERAL_AGENT_CODES = [
        'agent_modify_password',
        'agent_modify_payment_password',
        // 4545：PM 要求上線時一併配給各站總代，故列入；工單原文的「既有角色不自動勾」仍適用於其他角色
        'bank_account_create_permission',
    ];

    public function run(): int
    {
        $output = $this->command?->getOutput();

        $output?->writeln('SyncAgentMenuSeeder 開始執行...');

        $source = require database_path('data/agent_menus.php');

        // 取得DB的菜單
        $menuMap = AgentMenu::query()->pluck('id', 'code')->toArray();

        $count = 0;
        $newMenus = [];
        foreach ($source as $row) {
            if (!isset($menuMap[$row['code']])) {
                $parentCode = $row['parent'];
                $row['parent'] = 0;
                $row['admin'] = 0;
                $menu = AgentMenu::create($row);

                // parent_code 要放在 AgentMenu::create 後
                $row['parent_code'] = $parentCode;
                $newMenus[] = [
                    'source' => $row,
                    'model' => $menu,
                ];

                // 幫 admin 新增權限
                AgentPermission::updateOrCreate(
                    [
                        'agent_id' => 1,
                        'agent_menu_id' => $menu->id,
                    ],
                    [
                        'action' => '',
                        'admin' => 0,
                    ],
                );

                $output?->writeln('菜單 ' . $row['code'] . ' 已新增');
                $count++;
            }
        }

        $this->grantToPlatformAndGeneralAgents($newMenus, $output);

        // 更新 parent
        $menuMap = AgentMenu::query()->pluck('id', 'code')->toArray();
        foreach ($newMenus as $menu) {
            $source = $menu['source'];
            $model = $menu['model'];
            if (isset($menuMap[$source['parent_code']])) {
                $model->parent = $menuMap[$source['parent_code']];
                $model->save();
            }
        }

        Cache::forget(CacheKeys::agentMenuCodeMap());

        $this->syncAdminDefaultGroup($output);

        $output?->writeln('SyncAgentMenuSeeder 已執行完成。');
        $output?->writeln("新增 {$count} 個 AgentMenu");

        return $count;
    }

    /**
     * @param  array<int, array{source: array<string, mixed>, model: AgentMenu}>  $newMenus
     */
    private function grantToPlatformAndGeneralAgents(array $newMenus, ?OutputStyle $output): void
    {
        $menuIds = [];
        foreach ($newMenus as $menu) {
            if (in_array($menu['source']['code'], self::GRANT_TO_PLATFORM_AND_GENERAL_AGENT_CODES, true)) {
                $menuIds[] = (int) $menu['model']->id;
            }
        }
        if ($menuIds === []) {
            return;
        }

        $now = now();
        $granted = 0;
        Agent::query()
            ->where(function ($query): void {
                $query->where('id', 1)
                    ->orWhere('host_id', 1)
                    ->orWhereIn('role_id', [RoleEnum::GENERAL_AGENT->value, RoleEnum::CUSTOMER_SERVICE->value]);
            })
            ->select('id')
            ->chunkById(500, function ($agents) use ($menuIds, $now, &$granted): void {
                foreach ($menuIds as $menuId) {
                    $existing = AgentPermission::query()
                        ->where('agent_menu_id', $menuId)
                        ->whereIn('agent_id', $agents->pluck('id'))
                        ->pluck('agent_id')
                        ->all();
                    $rows = [];
                    foreach ($agents as $agent) {
                        if (in_array($agent->id, $existing, true)) {
                            continue;
                        }
                        $rows[] = [
                            'agent_id' => $agent->id,
                            'agent_menu_id' => $menuId,
                            'action' => '',
                            'admin' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($rows !== []) {
                        AgentPermission::query()->insert($rows);
                        $granted += count($rows);
                    }
                }
            });

        $output?->writeln("白名單權限已補給 admin／admin 子帳號／總代／客服，共 {$granted} 筆");
    }

    /** admin 預設角色的權限跟 admin 本人保持一致（target/tickets#4422），規則見 AdminDefaultGroupSyncService */
    private function syncAdminDefaultGroup(?OutputStyle $output): void
    {
        $result = app(AdminDefaultGroupSyncService::class)->sync();

        if ($result['group_id'] === null) {
            return;
        }
        if ($result['created']) {
            $output?->writeln("已建立 admin 預設角色（id {$result['group_id']}）");
        }
        $output?->writeln("admin 預設角色補上 {$result['added']} 個權限");
    }
}
