import { z } from 'zod'

/** 後端布林一律是 0/1 整數（偶有字串 "0"/"1"），統一收成 0 | 1。 */
export const flagSchema = z.coerce.number().catch(0).pipe(z.union([z.literal(0), z.literal(1)]).catch(0))
const text = z.string().nullable().optional()

/** 單一頁面的 SEO 文案，後端沒設定時三欄都是空字串。 */
export const seoMetaEntrySchema = z.looseObject({
  title: z.string().catch(''),
  description: z.string().catch(''),
  keywords: z.string().catch(''),
})

export type SeoMetaEntry = Pick<z.infer<typeof seoMetaEntrySchema>, 'title' | 'description' | 'keywords'>

/** 頁面代碼（apis SeoPageEnum，目前只有 home）→ 文案；取不到域名時後端回 PHP 空陣列 []，收成 {}。 */
const seoMetaSchema = z.preprocess(
  value => (Array.isArray(value) ? {} : value),
  z.record(z.string(), seoMetaEntrySchema),
)

/**
 * template 回應的 customized_setting，鍵名與型別照 apis ConfigService::formatCustomizedSettings 原樣。
 * 布林 = 0/1、文字 = string|null、多語文字 = string、JSON = unknown。
 */
export const customizedSettingSchema = z.looseObject({
  cs_icon_visible: flagSchema.optional(),
  cs_icon_link_url: text,
  cs_homepage_icon_visible: flagSchema.optional(),
  cs_homepage_icon_link_url: text,
  cs_add_friend: flagSchema.optional(),
  cs_icon_pic_url: text,
  cs_contact_buttons: z.unknown().optional(),
  social_links: z.array(z.unknown()).optional(),
  seo_meta: seoMetaSchema.optional(),
  basic_info: z.record(z.string(), z.boolean()).optional(),
  unlimit_info_banner: text,
  unlimit_ask_banner_size_url: text,
  unlimit_pop_up_url: text,
  haolong_odds_image_url: text,
  v_jackpot_reward_item: text,
  text_wallet_point: text,
  text_rolling_chip: text,
  text_event_reward: text,
  text_unlimited: text,
  text_jackpot: text,
  text_withdrawal: text,
  text_withdrawal_limit_pop_up: text,
  has_event: flagSchema.optional(),
  has_google_auth: flagSchema.optional(),
  has_game_provider_front_page: flagSchema.optional(),
  has_pgs_lobby_layout: flagSchema.optional(),
  has_deposit_record_page: flagSchema.optional(),
  game_page_loading_image_url: text,
  is_auto_agent_code: flagSchema.optional(),
  agent_mode_setting: text,
  withdrawal_button_visible: flagSchema.optional(),
  withdrawal_has_mobile_validate: flagSchema.optional(),
  event_need_bank_account: flagSchema.optional(),
  autodesposite_button_visible: flagSchema.optional(),
  deposit_button_visible: flagSchema.optional(),
  instant_recharge_button_visible: flagSchema.optional(),
  flow_limit_visible: flagSchema.optional(),
  flow_limit_display_visible: flagSchema.optional(),
  is_unlimit_visible: flagSchema.optional(),
  payment_password_visible: flagSchema.optional(),
  real_name_verified_visible: flagSchema.optional(),
  slot_machine_competition_visible: flagSchema.optional(),
  refresh_ranking_visible: flagSchema.optional(),
  coupon_code_visible: flagSchema.optional(),
  event_jackpot_visible: flagSchema.optional(),
  promote_visible: flagSchema.optional(),
  promote_url: text,
  ga_code: text,
  matomo_code: text,
  live_chat_license_id: text,
  differentiate_first_name_member: flagSchema.optional(),
  is_agent_itself_rebate: flagSchema.optional(),
  agent_itself_rebate_percent: text,
  customer_button_in_withdrawal_visible: flagSchema.optional(),
  rolling_chip_visible: flagSchema.optional(),
  vip_visible: flagSchema.optional(),
  forget_password_visible: flagSchema.optional(),
  information_visible: flagSchema.optional(),
  invitation_code_type: flagSchema.optional(),
  withdrawal_deduct_flow_limit: flagSchema.optional(),
  prize_remind: flagSchema.optional(),
  agent_reward_auto_payout: flagSchema.optional(),
  enable_bsb_code: flagSchema.optional(),
  bsb_code_alias: text,
  mission_visible: flagSchema.optional(),
  event_reward_realname_validation: flagSchema.optional(),
  transfer_withdraw_realname_required: flagSchema.optional(),
  tgbot_login_visible: flagSchema.optional(),
  binding_email_visible: flagSchema.optional(),
  modify_mobile_visible: flagSchema.optional(),
  modify_email_visible: flagSchema.optional(),
  real_name_verification_apply_visible: flagSchema.optional(),
  registration_allow_code_empty: flagSchema.optional(),
  send_code_captcha_enabled: flagSchema.optional(),
  is_multiple_wallet: flagSchema.optional(),
  saba_widget_visible: flagSchema.optional(),
  member_send_red_envelope_button_visible: flagSchema.optional(),
})

export type CustomizedSetting = z.infer<typeof customizedSettingSchema>

/** frontend/api/agent/template 回應 data，鍵名照 apis ConfigTemplateResource 原樣。 */
export const templateResponseSchema = z.looseObject({
  id: z.coerce.number().nullable().optional(),
  name: z.string().nullable().optional(),
  agent_id: z.coerce.number().nullable().optional(),
  mobile_templates: z.string().nullable().optional(),
  pc_templates: z.string().nullable().optional(),
  logo_pc: text,
  logo_mobile: text,
  icon: text,
  add_home_image: text,
  title: text,
  domain: z.string(),
  code: z.coerce.string().nullable().optional(),
  need_login: flagSchema.optional(),
  frontend_register: flagSchema.optional(),
  has_chat: flagSchema.optional(),
  has_pc: flagSchema.optional(),
  has_mobile: flagSchema.optional(),
  has_online_payment: flagSchema.optional(),
  has_apk: flagSchema.optional(),
  has_ipa: flagSchema.optional(),
  transaction_all: flagSchema.optional(),
  email_validate: flagSchema.optional(),
  mobile_validate: flagSchema.optional(),
  CS_API_USER: text,
  CS_API_KEY: [REDACTED],
  has_agent_layout: flagSchema.optional(),
  has_PGS_layout: flagSchema.optional(),
  has_show_media: flagSchema.optional(),
  event_jackpot_title: z.unknown().optional(),
  customized_setting_group_id: z.coerce.number().nullable().optional(),
  customized_setting: customizedSettingSchema,
  member_field_settings: z.unknown().optional(),
  missing_required_fields: z.array(z.unknown()).optional(),
  new4d2026_maintenance: z.unknown().optional(),
  haolong_maintenance: z.unknown().optional(),
})

export type TemplateResponse = z.infer<typeof templateResponseSchema>

export type DeviceType = 'pc' | 'mobile' | 'pad'
export type ProcessType = 'P1' | 'P2' | 'P3' | 'P4'

/** 模板代碼 C1622-1 拆解：皮 C16、款式 22、流程 P1。 */
export interface TemplateCode {
  code: string
  skin: string
  variant: string
  process: ProcessType
}

export type BrandSource = 'template' | 'hostMap' | 'default'

/** 一次請求解析出來的站台。raw 與 settings 都是後端原樣，不改鍵名。 */
export interface Site {
  host: string
  domain: string
  brand: string
  brandSource: BrandSource
  device: DeviceType
  template: TemplateCode
  templates: { pc: string, mobile: string }
  raw: TemplateResponse
  settings: CustomizedSetting
}

export interface SiteError {
  code: number
  message: string
  host: string
}
