<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\Models\AgentPersonalSetting;
use App\Models\Template;
use App\Services\Agent\AgentSeoMetaService;
use App\Services\Agent\AgentSocialLinkService;
use App\Services\Agent\BasicInfoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class ConfigService
{
    public function __construct(
        protected AgentService $agentService,
        protected MemberFieldSettingService $memberFieldSettingService,
        protected AgentSocialLinkService $socialLinkService,
        protected BasicInfoService $basicInfoService,
        protected AgentSeoMetaService $seoMetaService,
    ) {}

    public function getTemplateName($templateId)
    {
        $cacheKey = CacheKeys::templateMap();

        $templateMap = Cache::remember($cacheKey, CacheTtl::ONE_HOUR->value, function () {
            return Template::query()
                ->select(['id', 'name'])
                ->where('status', 1)
                ->pluck('name', 'id')
                ->toArray();
        });

        return $templateMap[$templateId] ?? '';
    }

    public function getAgentMedia(Agent $agent)
    {
        $cacheKey = CacheKeys::agentMedia($agent->id);
        $media = Cache::remember($cacheKey, CacheTtl::ONE_HOUR->value, function () use ($agent) {
            $agentIds = array_reverse($agent->hierarchy_array);

            foreach ($agentIds as $agentId) {
                // 從最上線開始找
                $setting = AgentPersonalSetting::query()
                    ->where('setting_id', AgentPersonalSettingKeyEnum::HAS_MEDIA_SHOW)
                    ->where('agent_id', $agentId)
                    ->first();

                if ($setting) {
                    return $setting->value;
                }
            }

            return 0;
        });

        return $media;
    }

    public function getEventJackpotTitle(Agent $agent, int $languageId)
    {
        $cacheKey = CacheKeys::eventJackpotTitle($agent->id, $languageId);
        $eventJackpotTitle = Cache::remember($cacheKey, CacheTtl::ONE_HOUR->value, function () use ($agent, $languageId) {

            $agentIds = $agent->hierarchy_array;

            $data = DB::table('events as e')
                ->join('prizes as p', 'p.event_id', 'e.id')
                ->leftJoin('event_names as en', function ($join) use ($languageId) {
                    $join->on('en.event_id', 'e.id')
                        ->where('en.language_id', $languageId);
                })
                ->whereIn('e.agent_id', $agentIds)
                ->where('e.status', 1)
                ->where('e.type_id', 3)
                ->select(
                    DB::raw('IFNULL(en.title, e.title) as title')
                )
                ->first();

            return is_object($data) ? $data->title : 'Event Jackpot';
        });

        return $eventJackpotTitle;
    }

    public function formatCustomizedSettings(array $agentSetting, ?Agent $agent = null, ?AgentDomain $agentDomain = null): array
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        $result = [
            'cs_icon_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::CS_IS_CUSTOM_SERVICE_ICON_VISIBLE),
            'cs_icon_link_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_LINK_URL),
            'cs_homepage_icon_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::CS_HOMEPAGE_ICON_VISIBLE),
            'cs_homepage_icon_link_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CS_HOMEPAGE_ICON_LINK_URL),
            'cs_add_friend' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ADD_FRIEND),
            'cs_icon_pic_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_PIC_URL),
            'cs_contact_buttons' => $this->getJson($agentSetting, AgentCustomizedSettingKeyEnum::CS_CONTACT_BUTTONS_JSON),
            // 工單4106：社群入口（TG／FB／APK 下載等）。設定綁總代，該線路下所有代理共用；
            // 取不到 agent 時回空陣列，前端不渲染任何 icon
            'social_links' => $agent ? $this->socialLinkService->getFrontendLinks($agent) : [],
            // 工單4102：依請求域名取，不隨會員歸屬代理漂移；取不到域名回空陣列
            'seo_meta' => $agentDomain ? $this->seoMetaService->getFrontendMeta($agentDomain) : [],
            // 工單4104：頁尾基本資訊各項是否呈現。內容為長文，不隨 template 下發——
            // 前台依此決定頁尾放哪些入口，點開才向 /basic-info 取內容
            'basic_info' => $this->getBasicInfoFlags($agentSetting, $agent),
            'unlimit_info_banner' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_UNLIMIT_INFO_BANNER_IMAGE_URL),
            'unlimit_ask_banner_size_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_UNLIMIT_ASK_BANNER_SIZE_IMAGE_URL),
            'unlimit_pop_up_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_UNLIMIT_POP_UP_IMAGE_URL),
            'haolong_odds_image_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_HAOLONG_ODDS_IMAGE_URL),
            'v_jackpot_reward_item' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_V_JACKPOT_REWARD_ITEM),
            'text_wallet_point' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_WALLET_POINT),
            'text_rolling_chip' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_ROLLING_CHIP),
            'text_event_reward' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_EVENT_REWARD),
            'text_unlimited' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_UNLIMITED),
            'text_jackpot' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_JACKPOT),
            'text_withdrawal' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::LABEL_TEXT_WITHDRAWAL),
            'text_withdrawal_limit_pop_up' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::VIP_WITHDRAWAL_POP_UP),
            'has_event' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::STYLE_HAS_EVENT, 1),
            'has_google_auth' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::FRONTEND_GOOGLE_AUTH, 0),
            'has_game_provider_front_page' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::GAME_PROVIDER_FRONT_PAGE, 0),
            'has_pgs_lobby_layout' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::STYLE_HAS_PGS_LOBBY_LAYOUT),
            'has_deposit_record_page' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::STYLE_HAS_DEPOSIT_RECORD_PAGE),
            'game_page_loading_image_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::STYLE_IMAGE_GAME_PAGE_LOADING_ICON),
            'is_auto_agent_code' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::AUTO_AGENT_CODE),
            'agent_mode_setting' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING),
            'withdrawal_button_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::WITHDRAWAL_BUTTON_VISIBLE),
            'withdrawal_has_mobile_validate' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE),
            'event_need_bank_account' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::EVENT_NEED_BANK_ACCOUNT),
            'autodesposite_button_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::AUTODESPOSITE_BUTTON_VISIBLE),
            'deposit_button_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::DEPOSIT_BUTTON_VISIBLE),
            'instant_recharge_button_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::INSTANT_RECHARGE_BUTTON_VISIBLE),
            'flow_limit_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE),
            'flow_limit_display_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_DISPLAY_VISIBLE),
            'is_unlimit_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::STYLE_IS_UNLIMIT_VISIBLE),
            'payment_password_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE),
            'real_name_verified_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFIED_VISIBLE),
            'slot_machine_competition_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::SLOT_MACHINE_COMPETITION_VISIBLE),
            'refresh_ranking_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::REFRESH_RANKING_VISIBLE),
            'coupon_code_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::COUPON_CODE_VISIBLE),
            'event_jackpot_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::EVENT_JACKPOT_VISIBLE),
            'promote_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_PROMOTE_VISIBLE),
            'promote_url' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_PROMOTE_URL),
            'ga_code' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_GA_CODE),
            'matomo_code' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_MATOMO_CODE),
            'live_chat_license_id' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_LIVE_CHAT_LICENSE_ID),
            'differentiate_first_name_member' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::DIFFERENTIATE_FIRST_NAME_MEMBER),
            'is_agent_itself_rebate' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::IS_AGENT_ITSELF_REBATE),
            'agent_itself_rebate_percent' => $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::AGENT_ITSELF_REBATE_PERCENT),
            'customer_button_in_withdrawal_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::Customer_Button_In_WITHDRAWAL_VISIBLE),
            'rolling_chip_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::IS_ROLLING_CHIP_VISIBLE),
            'vip_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::VIP_VISIBLE),
            'forget_password_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::FORGET_PASSWORD_VISIBLE),
            'information_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::INFORMATION_VISIBLE),
            'invitation_code_type' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT),
            'withdrawal_deduct_flow_limit' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::WITHDRAWAL_DEDUCT_FLOW_LIMIT),
            'prize_remind' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::PRIZE_REMIND),
            'agent_reward_auto_payout' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::AGENT_REWARD_AUTO_PAYOUT),
            'enable_bsb_code' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::ENABLE_BSB_CODE),
            'bsb_code_alias' => $this->getLanguageText($agentSetting, AgentCustomizedSettingKeyEnum::BSB_CODE_ALIAS),
            'mission_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::MISSION_VISIBLE),
            'event_reward_realname_validation' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION),
            'transfer_withdraw_realname_required' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED),
            'tgbot_login_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::TG_BOT_LOGIN_VISIBLE),
            'binding_email_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::BINDING_EMAIL_VISIBLE),
            'modify_mobile_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::MODIFY_MOBILE_VISIBLE),
            'modify_email_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::MODIFY_EMAIL_VISIBLE),
            'real_name_verification_apply_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFICATION_APPLY_VISIBLE),
            'registration_allow_code_empty' => $this->registrationAllowCodeEmpty($agent),
            'send_code_captcha_enabled' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::SEND_CODE_CAPTCHA_ENABLED),
            'is_multiple_wallet' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH),
            'saba_widget_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::SABA_WIDGET_VISIBLE),
            'member_send_red_envelope_button_visible' => $this->getBoolean($agentSetting, AgentCustomizedSettingKeyEnum::MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE),
        ];

        if (in_array($languageId, [2, 3])) {
            $result['haolong_odds_image_url'] = $this->getText($agentSetting, AgentCustomizedSettingKeyEnum::CUSTOMIZED_OTHER_HAOLONG_ODDS_IMAGE_CHS_URL);
        }

        return $result;
    }

    private function getBoolean($collection, $settingKey, $defaultValue = 0): int
    {
        $value = $collection[$settingKey] ?? $defaultValue;

        return $value == 1 ? 1 : 0;
    }

    /**
     * 頁尾基本資訊各項是否呈現 工單4104
     *
     * 判定沿用 BasicInfoService，避免「開關開了但內容空白」的規則散落兩處。
     * 取不到 agent 時無從判定內容，一律不呈現。
     *
     * @return array<string, bool>
     */
    private function getBasicInfoFlags(array $agentSetting, ?Agent $agent): array
    {
        if ($agent === null) {
            return [];
        }

        return $this->basicInfoService->resolveFlags($agentSetting, $agent);
    }

    /**
     * 推薦碼可否為空（下發前端）：依 3970 config 的註冊欄位 referral_code 的 is_required 取反。
     * 呼叫端未帶 agent 時無從判定，視為可空（與欄位未啟用同義）。
     */
    private function registrationAllowCodeEmpty(?Agent $agent): int
    {
        if ($agent === null) {
            return 1;
        }

        return $this->memberFieldSettingService->isReferralCodeRequired($agent) ? 0 : 1;
    }

    private function getText($collection, $settingKey)
    {
        return trim($collection[$settingKey] ?? '');
    }

    private function getLanguageText($collection, $settingKey)
    {
        return trim($collection[$settingKey] ?? '');
    }

    private function getJson($collection, $settingKey): array
    {
        $value = $collection[$settingKey] ?? '';

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
