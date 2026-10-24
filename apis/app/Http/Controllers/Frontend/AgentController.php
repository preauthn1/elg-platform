<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Resources\Frontend\ConfigTemplateResource;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\Models\Analytic;
use App\Services\AgentService;
use App\Services\ConfigService;
use App\Services\LoginService;
use App\Services\MemberFieldSettingService;
use App\Vendors\Haolong\Driver as HaolongDriver;
use App\Vendors\New4d2026\Driver as New4d2026Driver;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Group('Agent (代理商)')]
class AgentController extends Controller
{
    public function __construct(
        protected ConfigService $configService,
        protected AgentService $agentService,
        protected MemberFieldSettingService $memberFieldSettingService,
        protected LoginService $loginService,
    ) {}

    /**
     * 透過域名取模版
     *
     * OLD: /api/template
     */
    public function template(DomainRequest $request)
    {
        $input = $request->validated();

        // 不一定有會員
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        $agentDomain = AgentDomain::with(['agent', 'template'])
            ->where('domain', $input['domain'])
            ->where('status', 1)
            ->first();

        if (!$agentDomain) {
            return ApiResponse::error(__('error_message.1213'));
        }

        if ($member) {
            $agent = $member->agent;
        } else {
            $agent = $agentDomain->agent;
        }

        $agentDomain->mobile_templates = ($agentDomain->mobile_templates == 0) ? $agentDomain->template->name : $this->configService->getTemplateName($agentDomain->mobile_templates);
        $agentDomain->pc_templates = ($agentDomain->pc_templates == 0) ? $agentDomain->template->name : $this->configService->getTemplateName($agentDomain->pc_templates);
        $agentDomain->event_jackpot_title = $this->configService->getEventJackpotTitle($agent, $languageId);
        $agentDomain->code = empty($agentDomain->info['default_invitation_code']) ? $agent->code : $agentDomain->info['default_invitation_code'];

        // 取得額外設置
        $agentSetting = $this->agentService->getAgentSettings($agent);
        $customizedSettings = $this->configService->formatCustomizedSettings($agentSetting, $agent, $agentDomain);

        // 判斷是否有 matomo code, 有的話更新 username
        if ($member && !empty($customizedSettings['matomo_code'])) {
            $customizedSettings['matomo_code'] = str_replace(
                '{USER_ID_HERE}',
                $member->username ?? '',
                $customizedSettings['matomo_code']
            );
        }

        $agentDomain->customized_setting = $customizedSettings;

        // 3969：會員欄位規則（依 3970 agent_member_field_settings）。規則一律以 domain agent 為準，
        // 不隨邀請碼漂移；限制值不下發（純後端）。有會員時多回 missing_required_fields 供 TG 補資料用。
        $agentDomain->member_field_settings = $this->memberFieldSettingService->presentation($agentDomain->agent);
        $agentDomain->missing_required_fields = $member
            ? $this->memberFieldSettingService->getMissingRequiredFields($member, $member->agent)
            : [];

        $agentDomain->has_show_media = $agentSetting[AgentCustomizedSettingKeyEnum::HAS_MEDIA_SHOW];
        $agentDomain->new4d2026_maintenance = Cache::safeRemember(
            'new4d2026_maintenance_window',
            CacheTtl::ONE_HOUR->value,
            fn () => (new New4d2026Driver(VendorIdEnum::NEW4D2026->value, null))->getMaintenanceWindow()
        );
        $agentDomain->haolong_maintenance = Cache::safeRemember(
            'haolong_maintenance_window',
            CacheTtl::ONE_HOUR->value,
            fn () => (new HaolongDriver(VendorIdEnum::HAOLONG->value, null))->getMaintenanceWindow()
        );

        // 已登入（有 Bearer token 解析出會員）才核發會員專屬聊天 key；
        // 匿名呼叫（QRcodeOpen 掃碼前、一般頁面載入的 fetchTemplate()）不回任何 CS 憑證，
        // 關閉原本無條件回傳代理共用 CS_API_USER／CS_API_KEY 的外洩管道。
        // QR code 登入後（src/store/modules/List/index.js 的 QRcodeOpen 分支）就是靠
        // 這支帶 token 的呼叫取得聊天憑證，拿掉會讓那段流程的 CS_API_USER／CS_API_KEY 變成 undefined。
        $csChatCredentials = $member ? $this->loginService->issueMemberChatKey($member, $agent) : null;

        $resource = new ConfigTemplateResource($agentDomain, $agent, $csChatCredentials);

        return ApiResponse::success($resource);
    }

    /**
     * 透過域名尋找下層代理邀請碼
     *
     * OLD: /api/getAgentCodeByDomain
     */
    public function getDownlineCode(DomainRequest $request)
    {
        $userData = $this->getUserContext();

        $agent = $userData->agent;
        $agentDomain = $agent->agentDomain;

        if (!$agentDomain) {
            $data = [
                'has_open' => 0,
                'agent_list' => [],
            ];

            return ApiResponse::success($data);
        }

        $info = $agentDomain->info;

        if ($info->has_auto_show_agent_code != 0) {
            $agents = Agent::query()
                ->where('hierarchy_path', 'like', $agent->hierarchy_path . '/%')
                ->where('host_id', 0)
                ->select([
                    'id',
                    'username',
                    'nickname',
                    'code',
                    'hierarchy_path',
                    DB::raw(
                        "(LENGTH(REPLACE(hierarchy_path, '" . $agent->hierarchy_path . "', ''))" .
                            "- LENGTH(REPLACE(REPLACE(hierarchy_path, '" . $agent->hierarchy_path . "', ''), '/', ''))) as level"
                    ),
                ])->get();

            $agents = $agents->filter(function ($item) use ($info) {
                // 過濾掉不符合等級的代理商
                $level = $info->agent_list_level ?? 1;

                return $item->level == $level;
            })->map(function ($item) {
                // 調整輸出格式
                return [
                    'nickname' => $item->username,
                    'code' => $item->code,
                ];
            })->values()->toArray();
        }

        $data = [
            'has_open' => isset($agents) ? 1 : 0,
            'agent_list' => $agents ?? [],
        ];

        return ApiResponse::success($data);
    }

    /**
     * 分析代碼列表
     */
    public function getAnalyticsCode(DomainRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();
        $agent = $userData->agent;

        $cachePrefix = CacheKeys::agentAnalyticsCode($agent->id);
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($agent) {
            $analytic = Analytic::query()
                ->where('agent_id', $agent->id)
                ->where('status', 1)
                ->select(['type', 'code'])
                ->first();

            return [
                'type' => $analytic?->type ?? '',
                'code' => $analytic?->code ?? '',
            ];
        });

        return ApiResponse::success($cacheData);
    }
}
