import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

const text = z.string().nullable().optional()

/** ApiResponse::success() 沒帶 data 時是 []，少數 Controller 回 null，兩者都收。 */
export const emptyDataSchema = z.union([z.null(), z.array(z.never())])

/** guest/login 回應（LoginService::prepareResponseData）。hasDeviceId 客服登入分支只有 token/id，故多數欄位 optional。 */
export const loginDataSchema = z.looseObject({
  id: z.coerce.number().optional(),
  username: z.string().optional(),
  email: text,
  nickname: text,
  balance: z.coerce.number().optional(),
  image: text,
  first_name: text,
  last_name: text,
  mobile: text,
  token: [REDACTED],
  event_mode: flagSchema.optional(),
  is_force_unlock_event_mode: flagSchema.optional(),
  last_login: text,
  CS_API_USER: text,
  CS_API_KEY: [REDACTED],
  SHARE_CODE: z.coerce.string().nullable().optional(),
})

/** 登入 318 的 data：auth_2fa 為 'google_auth' 或 ['mobile','email'] 子集，後者另附 id/mobile/email。 */
export const twoFactorDataSchema = z.looseObject({
  auth_2fa: z.union([z.literal('google_auth'), z.array(z.enum(['mobile', 'email', 'google_auth']))]),
  id: z.coerce.number().optional(),
  mobile: text,
  email: text,
})

/** 登入 322 的 data。 */
export const retrievePasswordDataSchema = z.looseObject({
  member_id: z.coerce.number(),
  guest_validate_token: [REDACTED],
})

/** MemberDetailResource（guest/register、guest/register-v2 共用），不含 token。 */
export const memberDetailSchema = z.looseObject({
  id: z.coerce.number(),
  parent: z.coerce.number().nullable().optional(),
  username: z.string(),
  balance: z.coerce.number().optional(),
  rolling_chip: z.coerce.number().optional(),
  image: text,
  email: text,
  nickname: text,
  first_name: text,
  last_name: text,
  birthday: text,
  mobile: text,
  timezone_id: z.coerce.string().nullable().optional(),
  country_id: z.coerce.string().nullable().optional(),
  zip_code: text,
  district: text,
  city: text,
  address: text,
  status: z.coerce.number().optional(),
  is_validate: flagSchema.optional(),
  mobile_validate: flagSchema.optional(),
  email_validate: flagSchema.optional(),
  realname_validate: flagSchema.optional(),
  has_2fa: flagSchema.optional(),
  event_mode: flagSchema.optional(),
  flow_limit_application: z.coerce.number().optional(),
  last_login: text,
  payment_password: [REDACTED],
  agent_username: z.string().optional(),
  is_tg_user: z.boolean().optional(),
  ip_change_mobile_verify: flagSchema.optional(),
  ip_change_email_verify: flagSchema.optional(),
  setting: z.looseObject({
    activity_limit_time: z.unknown().optional(),
    push_notify: flagSchema.optional(),
    sms_notify: flagSchema.optional(),
    email_notify: flagSchema.optional(),
    message_box_notify: flagSchema.optional(),
    message_popup_notify: flagSchema.optional(),
  }).optional(),
})

export const captchaSchema = z.looseObject({
  captcha_id: z.string(),
  bg_image: z.string(),
  piece_image: z.string(),
  bg_width: z.coerce.number(),
  bg_height: z.coerce.number(),
  piece_width: z.coerce.number(),
  piece_y: z.coerce.number(),
  expires_in: z.coerce.number(),
})

export const captchaTokenSchema = z.looseObject({
  captcha_token: [REDACTED],
  expires_in: z.coerce.number(),
})

export const tokenDataSchema = z.looseObject({ token: [REDACTED] })

/** Telegram OAuth widget 回傳的使用者資料（後端以 bot_token 驗 hash）。 */
export const telegramOAuthUserSchema = z.looseObject({
  id: z.coerce.string(),
  first_name: z.string().nullable().optional(),
  last_name: z.string().nullable().optional(),
  username: z.string().nullable().optional(),
  photo_url: z.string().nullable().optional(),
  auth_date: z.coerce.string(),
  hash: z.string(),
})

const mobile = z.string().regex(/^\d{1,20}$/).optional()
const otp = z.string().length(6)

export const authEndpoints = {
  'guest/login': defineEndpoint({
    path: 'guest/login',
    auth: 'optional',
    description: '會員登入；318 二次驗證、319 鎖定、322 找回密碼由 composable 分流',
    request: z.object({
      username: z.string().min(1),
      password: [REDACTED],
      platform: z.string().optional(),
      device_id: z.string().optional(),
      app: z.string().optional(),
      do_2fa: z.enum(['google_auth', 'mobile', 'email']).optional(),
      secret: [REDACTED],
    }),
    response: loginDataSchema,
  }),

  'guest/register': defineEndpoint({
    path: 'guest/register',
    auth: 'optional',
    description: '靜態表單註冊（member_field_settings.register 為空時）',
    request: z.object({
      invitation_code: z.string().min(4).max(10).optional(),
      invitation_code_type: z.coerce.number().int().optional(),
      username: z.string().min(4).max(10),
      password: [REDACTED],
      password_confirmation: z.string().min(6).max(12),
      email: z.string().email().optional(),
      nickname: z.string().max(60).optional(),
      first_name: z.string().max(60).optional(),
      last_name: z.string().max(20).optional(),
      birthday: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
      mobile,
      mobile_token: [REDACTED],
      timezone_id: z.string().max(35).optional(),
      country_id: z.string().max(35).optional(),
      zip_code: z.string().max(35).optional(),
      district: z.string().max(35).optional(),
      city: z.string().max(35).optional(),
      address: z.string().max(60).optional(),
    }),
    response: memberDetailSchema,
  }),

  'guest/register-v2': defineEndpoint({
    path: 'guest/register-v2',
    auth: 'optional',
    description: '依 member_field_settings.register 動態欄位註冊',
    request: z.object({
      username: z.string().optional(),
      password: [REDACTED],
      confirm_password: [REDACTED],
      last_name: z.string().optional(),
      mobile: z.string().optional(),
      mobile_token: [REDACTED],
      email: z.string().optional(),
      email_token: [REDACTED],
      birthday: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
      referral_code: z.string().optional(),
      invitation_code: z.string().optional(),
      invitation_code_type: z.coerce.number().int().optional(),
      country_code: z.string().optional(),
      device_id: z.string().optional(),
      platform: z.string().optional(),
    }),
    response: memberDetailSchema,
  }),

  'guest/send-validation-code': defineEndpoint({
    path: 'guest/send-validation-code',
    auth: 'optional',
    description: '訪客發驗證碼：2 忘記密碼、3 找回密碼、4 洗分、8 一頁註冊、10 登入 IP 變更、12 register-v2',
    request: z.object({
      type: z.coerce.number().pipe(z.literal([2, 3, 4, 8, 10, 12])),
      verify_by: z.coerce.number().pipe(z.literal([1, 2])),
      mobile,
      email: z.string().email().max(200).optional(),
      username: z.string().regex(/^[A-Za-z0-9]{4,30}$/).optional(),
      guest_validate_token: [REDACTED],
      captcha_token: [REDACTED],
      sms_gateway_info_id: z.coerce.number().int().optional(),
      payment_password: [REDACTED],
    }),
    response: emptyDataSchema,
  }),

  'guest/check-validation-code': defineEndpoint({
    path: 'guest/check-validation-code',
    auth: 'optional',
    description: '驗證驗證碼；data 是單元素陣列 [username]',
    request: z.object({
      mobile,
      email: z.string().email().max(60).optional(),
      token: [REDACTED],
      type: z.coerce.number().pipe(z.literal([1, 3, 4, 5, 6, 7, 8, 11, 12])).optional(),
      guest_validate_token: [REDACTED],
    }),
    response: z.array(z.string()),
  }),

  'guest/get-member-accounts': defineEndpoint({
    path: 'guest/get-member-accounts',
    auth: 'optional',
    description: '忘記密碼（type=2）驗碼並取得同手機/信箱的帳號清單',
    request: z.object({
      mobile,
      email: z.string().email().max(60).optional(),
      token: [REDACTED],
      type: z.coerce.number().pipe(z.literal(2)).optional(),
      guest_validate_token: [REDACTED],
    }),
    response: z.looseObject({ username_list: z.array(z.string()) }),
  }),

  'guest/reset-password': defineEndpoint({
    path: 'guest/reset-password',
    auth: 'optional',
    description: '以驗證碼重設登入密碼；失敗回 319',
    request: z.object({
      username: z.string().min(1),
      token: [REDACTED],
      new_password: [REDACTED],
      new_password_confirmation: z.string().min(6).max(12),
    }),
    response: emptyDataSchema,
  }),

  'guest/captcha': defineEndpoint({
    path: 'guest/captcha',
    auth: 'optional',
    description: '滑塊驗證出題（訪客）',
    request: z.object({}),
    response: captchaSchema,
  }),

  'guest/captcha/verify': defineEndpoint({
    path: 'guest/captcha/verify',
    auth: 'optional',
    description: '滑塊驗證判定（訪客），通過回一次性 captcha_token',
    request: z.object({
      captcha_id: z.string().max(64),
      x: z.coerce.number().int().min(0).max(1000),
    }),
    response: captchaTokenSchema,
  }),

  'member/logout': defineEndpoint({
    path: 'member/logout',
    auth: 'member',
    description: '撤銷會員全部 access token',
    request: z.object({}),
    response: emptyDataSchema,
    withDomain: false,
  }),

  'member/check-profile': defineEndpoint({
    path: 'member/check-profile',
    auth: 'member',
    description: '登入後檢查資料是否完善：327 綁卡、325 真實姓名、323 支付密碼、324 手機、326 Email',
    request: z.object({}),
    response: emptyDataSchema,
    withDomain: false,
  }),

  'tg-bot/get-brand-by-domain': defineEndpoint({
    path: 'tg-bot/get-brand-by-domain',
    auth: 'optional',
    description: '依 domain 取 Telegram bot 資訊；回 code 1000',
    request: z.object({}),
    response: z.array(z.looseObject({
      bot_id: z.coerce.string(),
      bot_username: z.coerce.string(),
      tg_share_text: z.string().optional(),
      login_domain: z.string().optional(),
    })),
  }),

  'tg-bot/oauth-login': defineEndpoint({
    path: 'tg-bot/oauth-login',
    auth: 'optional',
    description: 'Telegram OAuth widget 登入；333 需輸入邀請碼',
    request: z.object({
      user: telegramOAuthUserSchema,
      bot_username: z.string().min(1),
      code: z.string().optional(),
    }),
    response: tokenDataSchema,
  }),

  'tg-bot/web-app-login': defineEndpoint({
    path: 'tg-bot/web-app-login',
    auth: 'optional',
    description: 'Telegram WebApp initData 登入；333 需輸入邀請碼',
    request: z.object({
      init_data: z.string().min(1),
      brand_code: z.string().min(1),
      code: z.string().optional(),
    }),
    response: tokenDataSchema,
  }),

  'tg-bot/delete-tg-user': defineEndpoint({
    path: 'tg-bot/delete-tg-user',
    auth: 'optional',
    description: '跨 brand 衝突時刪除舊 TG 關聯（apis 目前不會發出 delete_user_token）',
    request: z.object({ delete_user_token: [REDACTED] }),
    response: emptyDataSchema,
  }),

  'tgbot/complete-profile': defineEndpoint({
    path: 'tgbot/complete-profile',
    auth: 'member',
    description: 'TG 會員補齊 missing_required_fields，成功重新簽發 token',
    request: z.object({
      real_name: z.string().min(1).max(60).optional(),
      payment_password: [REDACTED],
      mobile,
      mobile_token: [REDACTED],
      email: z.string().email().max(60).optional(),
      email_token: [REDACTED],
      birthday: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
    }),
    response: tokenDataSchema,
  }),

  'agent/downline-code': defineEndpoint({
    path: 'agent/downline-code',
    auth: 'optional',
    description: '註冊頁邀請碼下拉：has_open 與代理清單',
    request: z.object({}),
    response: z.looseObject({
      has_open: flagSchema,
      agent_list: z.array(z.looseObject({
        nickname: z.string(),
        code: z.coerce.string(),
      })),
    }),
  }),
} satisfies EndpointMap

export type LoginParams = z.input<typeof authEndpoints['guest/login']['request']>
export type LoginData = z.output<typeof loginDataSchema>
export type TwoFactorData = z.output<typeof twoFactorDataSchema>
export type MemberDetail = z.output<typeof memberDetailSchema>
export type Captcha = z.output<typeof captchaSchema>
export type TelegramOAuthUser = z.input<typeof telegramOAuthUserSchema>
export type TelegramBrand = z.output<typeof authEndpoints['tg-bot/get-brand-by-domain']['response']>[number]
