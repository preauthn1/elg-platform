<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use App\Enums\Agent\AgentModeEnum;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfigTemplateResource extends JsonResource
{
    protected Agent $agent;

    /**
     * @param  array{api_user: string, api_key: [REDACTED]  $csChatCredentials  有登入會員時才非 null，見 LoginService::issueMemberChatKey()
     */
    public function __construct($resource, Agent $agent, protected ?array $csChatCredentials = null)
    {
        parent::__construct($resource);

        $this->agent = $agent;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->template->id,
            'name' => $this->template->name,
            'agent_id' => $this->agent_id,
            'mobile_templates' => $this->mobile_templates,
            'pc_templates' => $this->pc_templates,
            'logo_pc' => $this->logo_pc,
            'logo_mobile' => $this->logo_mobile,
            'icon' => $this->icon,
            'add_home_image' => $this->add_home_image,
            'title' => $this->title,
            'domain' => $this->domain,
            'code' => $this->code,
            'need_login' => $this->need_login,
            'frontend_register' => $this->agent->frontend_register,
            'has_chat' => $this->agent->has_chat,
            'has_pc' => $this->agent->has_pc,
            'has_mobile' => $this->agent->has_mobile,
            'has_online_payment' => $this->agent->has_online_payment,
            'has_apk' => $this->agent->has_apk,
            'has_ipa' => $this->agent->has_ipa,
            'transaction_all' => $this->agent->transaction_all,
            'email_validate' => $this->agent->email_validate,
            'mobile_validate' => $this->agent->mobile_validate,
            // 匿名呼叫（未帶有效會員 token）一律回空字串，不回代理共用值（客服聊天會員身分驗證修正）
            'CS_API_USER' => $this->csChatCredentials['api_user'] ?? '',
            'CS_API_KEY' => $this->csChatCredentials['api_key'] ?? '',
            // op_ticket#3518：全民代理入口要「代理本身是全民代理模式」與「代理組別設置
            // 的是否顯示全民代理 (4052)」兩者同時成立才顯示，缺一即關閉。
            // 原寫法 `agent_mode ?? is_unlimit_visible` 永遠取不到右邊——agents.agent_mode
            // 是 NOT NULL DEFAULT 0，組別設置形同虛設；且 agent_mode 值域 0-5，直接輸出
            // 會讓三層代理 (2/3)、推廣代理 (5) 也被前台當成開啟。
            'has_agent_layout' => (int) (
                (int) $this->agent->agent_mode === AgentModeEnum::UNLIMIT->value
                && $this->customized_setting['is_unlimit_visible'] === 1
            ),
            'has_PGS_layout' => ($this->agent->id == config('custom.pgs_layout_lobby_agent')) ? 1 : 0,
            'has_show_media' => (int) $this->has_show_media,
            'event_jackpot_title' => $this->event_jackpot_title,
            'customized_setting_group_id' => $this->agent->customized_setting_group_id,
            'customized_setting' => $this->customized_setting,
            // 3969：會員欄位規則（register / login）+ 缺漏必填欄位（有會員時；TG 補資料用）
            'member_field_settings' => $this->member_field_settings,
            'missing_required_fields' => $this->missing_required_fields,
            'new4d2026_maintenance' => $this->new4d2026_maintenance,
            'haolong_maintenance' => $this->haolong_maintenance,
        ];
    }
}
