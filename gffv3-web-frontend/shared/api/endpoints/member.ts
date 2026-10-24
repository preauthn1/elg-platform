import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

const text = z.string().nullable().optional()
const dateYmd = z.string().regex(/^\d{4}-\d{2}-\d{2}$/)
const password = [REDACTED]
const sixDigitCode = z.string().length(6)
/** 後端 boolean 規則會把 false 當空值丟掉，開關一律送 0/1 或 "0"/"1"。 */
const zeroOne = z.literal([0, 1, '0', '1'])
/** ApiResponse::success() 沒帶資料時是空陣列，舊文件寫 null，兩種都收。 */
const emptyDataSchema = z.union([z.null(), z.tuple([])])

export const memberSettingSchema = z.looseObject({
  activity_limit_time: z.unknown().optional(),
  push_notify: flagSchema.optional(),
  sms_notify: flagSchema.optional(),
  email_notify: flagSchema.optional(),
  message_box_notify: flagSchema.optional(),
  message_popup_notify: flagSchema.optional(),
})

/** frontend/api/member/detail 的 data，鍵名照 MemberDetailResource。 */
export const memberDetailSchema = z.looseObject({
  id: z.coerce.number(),
  parent: z.coerce.number().nullable().optional(),
  username: z.string(),
  balance: z.union([z.string(), z.number()]).nullable().optional(),
  rolling_chip: z.union([z.string(), z.number()]).nullable().optional(),
  image: text,
  email: text,
  nickname: text,
  first_name: text,
  last_name: text,
  birthday: text,
  mobile: text,
  timezone_id: z.coerce.number().nullable().optional(),
  country_id: z.coerce.number().nullable().optional(),
  zip_code: z.union([z.string(), z.number()]).nullable().optional(),
  district: text,
  city: text,
  address: text,
  status: z.coerce.number().optional(),
  is_validate: z.coerce.number().optional(),
  mobile_validate: flagSchema.optional(),
  email_validate: flagSchema.optional(),
  realname_validate: flagSchema.optional(),
  has_2fa: flagSchema.optional(),
  event_mode: z.coerce.number().optional(),
  /** 0=無 1=活動模式中 2=流水清除申請待審 */
  flow_limit_application: z.coerce.number().optional(),
  last_login: text,
  /** 只是有無設定的旗標，不是密碼本身 */
  payment_password: [REDACTED],
  agent_username: z.string().optional(),
  is_tg_user: z.boolean().optional(),
  ip_change_mobile_verify: flagSchema.optional(),
  ip_change_email_verify: flagSchema.optional(),
  setting: memberSettingSchema.optional(),
})

export type MemberDetail = z.output<typeof memberDetailSchema>

export const updateProfileRequestSchema = z.object({
  email: z.email().optional(),
  nickname: z.string().max(30).regex(/^[A-Za-z0-9_-]*$/).optional(),
  /** base64 圖檔或 http(s) 網址 */
  image: z.string().min(1).optional(),
  timezone_id: z.coerce.number().int().min(0).optional(),
  country_id: z.coerce.number().int().min(0).optional(),
  zip_code: z.coerce.number().int().min(0).optional(),
  district: z.string().max(30).optional(),
  city: z.string().max(30).optional(),
  address: z.string().max(100).optional(),
  has_2fa: zeroOne.optional(),
  ip_change_mobile_verify: zeroOne.optional(),
  ip_change_email_verify: zeroOne.optional(),
})

export type UpdateProfileInput = z.input<typeof updateProfileRequestSchema>

export const changePasswordRequestSchema = z.object({
  password,
  new_password: [REDACTED],
  new_password_confirmation: password,
})

export type ChangePasswordInput = z.input<typeof changePasswordRequestSchema>

export const changePaymentPasswordRequestSchema = z.object({
  /** 首次設定可不帶；已有支付密碼時錯誤或缺少回 1018 */
  current_payment_password: [REDACTED],
  new_payment_password: [REDACTED],
  new_payment_password_confirmation: password,
})

export type ChangePaymentPasswordInput = z.input<typeof changePaymentPasswordRequestSchema>

/**
 * 會員端發碼型別：1=綁定手機/信箱 5=設定/變更支付密碼 6=忘記支付密碼
 * 7=舊洗分驗證(棄用) 9=重新綁定(碼寄到新目標) 10=登入手機/信箱 2FA 11=單純驗證已綁定管道
 */
export const sendCodeRequestSchema = z.object({
  type: z.literal([1, 5, 6, 7, 9, 10, 11]),
  /** 1=手機簡訊 2=電子信箱 */
  verify_by: z.literal([1, 2]),
  /** 含國碼的純數字 */
  mobile: z.string().regex(/^\d{1,20}$/).optional(),
  email: z.email().max(200).optional(),
  username: z.string().regex(/^[A-Za-z0-9]{4,30}$/).optional(),
  payment_password: [REDACTED],
  payment_password_confirmation: password.optional(),
  /** captcha/verify 取得的一次性憑證，代理開 send_code_captcha_enabled 時必填 */
  captcha_token: [REDACTED],
  sms_gateway_info_id: z.coerce.number().int().optional(),
  guest_validate_token: [REDACTED],
})

export type SendCodeInput = z.input<typeof sendCodeRequestSchema>

export const captchaSchema = z.looseObject({
  captcha_id: z.string(),
  bg_image: z.string(),
  piece_image: z.string(),
  bg_width: z.coerce.number(),
  bg_height: z.coerce.number(),
  piece_width: z.coerce.number(),
  piece_y: z.coerce.number(),
  expires_in: z.coerce.number().optional(),
})

export type CaptchaData = z.output<typeof captchaSchema>

export const realnameFieldRuleSchema = z.looseObject({
  show: z.boolean().optional(),
  required: z.boolean().optional(),
})

export const realnameValidationCheckSchema = z.looseObject({
  is_validated: z.boolean(),
  is_exists: z.boolean(),
  fields: z.record(z.string(), realnameFieldRuleSchema).optional(),
})

export type RealnameValidationCheck = z.output<typeof realnameValidationCheckSchema>

/** 銀行卡三欄後端預設必填，但代理有實名欄位設定時改依 realname-validation/check 的 fields；BFF 不擋、交後端判。 */
export const realnameValidationRequestSchema = z.object({
  realname: z.string().min(1).max(100),
  bank_id: z.coerce.number().int().optional(),
  bank_name: z.string().max(100).optional(),
  /** 代理開 BSB 時送 `${bsb}__${account}` */
  bank_account: z.string().max(100).optional(),
  birthday: dateYmd.optional(),
  id_number: z.string().max(100).optional(),
  id_front_image: z.string().optional(),
  id_back_image: z.string().optional(),
})

export type RealnameValidationInput = z.input<typeof realnameValidationRequestSchema>

export const trackingEventSchema = z.looseObject({
  type: z.string(),
  data: z.unknown().optional(),
})

export type TrackingEvent = z.output<typeof trackingEventSchema>

export const memberEndpoints = {
  'member/detail': defineEndpoint({
    path: 'member/detail',
    auth: 'member',
    description: '會員詳情（登入後載入 profile 的來源）',
    request: z.object({}),
    response: memberDetailSchema,
  }),
  'member/update-profile': defineEndpoint({
    path: 'member/update-profile',
    auth: 'member',
    description: '修改會員資料：頭像／暱稱／開關共用，只送要改的欄位',
    request: updateProfileRequestSchema,
    response: emptyDataSchema,
  }),
  'member/set-birthday': defineEndpoint({
    path: 'member/set-birthday',
    auth: 'member',
    description: '設定生日（成年檢查，只能設定一次；已設定回 1100）',
    request: z.object({ birthday: dateYmd }),
    response: emptyDataSchema,
  }),
  'member/set-realname': defineEndpoint({
    path: 'member/set-realname',
    auth: 'member',
    description: '設定真實姓名（只能一次；已設定回 1097、重複回 2065）',
    request: z.object({
      real_name: z.string().min(2).max(60).regex(/^(?!.*\d)[\p{L}\s\-_]+$/u),
    }),
    response: emptyDataSchema,
  }),
  'member/change-password': defineEndpoint({
    path: 'member/change-password',
    auth: 'member',
    description: '修改登入密碼（舊密碼錯 1018；與支付密碼相同 9019）',
    request: changePasswordRequestSchema,
    response: emptyDataSchema,
  }),
  'member/change-payment-password': defineEndpoint({
    path: 'member/change-payment-password',
    auth: 'member',
    description: '設定／變更支付密碼（舊支付密碼錯 1018；與登入密碼相同 1094）',
    request: changePaymentPasswordRequestSchema,
    response: emptyDataSchema,
  }),
  'member/send-validation-code': defineEndpoint({
    path: 'member/send-validation-code',
    auth: 'member',
    description: '會員端發送驗證碼（型別 1/5/6/7/9/10/11）',
    request: sendCodeRequestSchema,
    response: emptyDataSchema,
  }),
  'member/captcha': defineEndpoint({
    path: 'member/captcha',
    auth: 'member',
    description: '滑塊驗證出題（send_code_captcha_enabled 開啟時才需要）',
    request: z.object({}),
    response: captchaSchema,
  }),
  'member/captcha/verify': defineEndpoint({
    path: 'member/captcha/verify',
    auth: 'member',
    description: '滑塊驗證判定，通過回一次性 captcha_token；失敗 2080 需重新出題',
    request: z.object({
      captcha_id: z.string().max(64),
      /** 拼圖塊左緣，以 bg_width 邏輯像素為單位 */
      x: z.coerce.number().int().min(0).max(1000),
    }),
    response: z.looseObject({
      captcha_token: [REDACTED],
      expires_in: z.coerce.number().optional(),
    }),
  }),
  'member/reset-mobile': defineEndpoint({
    path: 'member/reset-mobile',
    auth: 'member',
    description: '重新綁定手機（token 為型別 9 寄到新手機的驗證碼）',
    request: z.object({
      token: [REDACTED],
      new_mobile: z.string().regex(/^\d{6,12}$/),
    }),
    response: emptyDataSchema,
  }),
  'member/reset-email': defineEndpoint({
    path: 'member/reset-email',
    auth: 'member',
    description: '重新綁定信箱（token 為型別 9 寄到新信箱的驗證碼）；回傳的代理資料不使用',
    request: z.object({
      token: [REDACTED],
      new_email: z.email().min(3).max(60),
    }),
    response: z.unknown(),
  }),
  'realname-validation': defineEndpoint({
    path: 'realname-validation',
    auth: 'member',
    description: '送出實名認證申請（待審；message 即提示文字）',
    request: realnameValidationRequestSchema,
    response: emptyDataSchema,
  }),
  'realname-validation/check': defineEndpoint({
    path: 'realname-validation/check',
    auth: 'member',
    description: '實名認證狀態與表單欄位設定',
    request: z.object({}),
    response: realnameValidationCheckSchema,
  }),
  'member/tracking-events': defineEndpoint({
    path: 'member/tracking-events',
    auth: 'member',
    description: '待發送的行銷追蹤事件，每筆只回一次',
    request: z.object({}),
    response: z.looseObject({ items: z.array(trackingEventSchema) }),
  }),
  'google-auth/image': defineEndpoint({
    path: 'google-auth/image',
    auth: 'member',
    description: 'Google 2FA 的 QR（inline SVG）與 secret',
    request: z.object({}),
    response: z.looseObject({
      image: z.string(),
      secret: [REDACTED],
    }),
  }),
  'google-auth/auth': defineEndpoint({
    path: 'google-auth/auth',
    auth: 'optional',
    description: 'Google 2FA 驗碼（會員中心啟用 2FA 的確認步驟；登入 2FA 也共用）',
    request: z.object({
      username: z.string().min(1),
      secret: [REDACTED],
    }),
    response: emptyDataSchema,
  }),
} satisfies EndpointMap
