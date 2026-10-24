import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

/** 金額欄位在各 Resource 之間有字串與數字兩種形狀，一律收成 union 由前端自行轉。 */
const amount = z.union([z.string(), z.number()])

/** 匯率：rateDecimals / convertPoints 兩邊都吃 string | number。 */
const rate = z.union([z.string(), z.number()])

/**
 * 動態欄位的單一選項（select 用）。送出時取 key，顯示時取 value。
 * key 允許空值，validateFields 會把空值視為未填。
 */
export const orderTypeFieldOptionSchema = z.looseObject({
  key: z.union([z.string(), z.number()]).nullable().optional(),
  value: z.union([z.string(), z.number()]).nullable().optional(),
})

export type OrderTypeFieldOption = z.infer<typeof orderTypeFieldOptionSchema>

/**
 * order_type_info / options 裡的一個欄位定義。
 * require 後端有 boolean、0/1、"1" 三種寫法，isFieldRequired 統一判斷。
 */
export const orderTypeFieldSchema = z.looseObject({
  name: z.string(),
  type: z.string().optional(),
  require: z.union([z.boolean(), z.number(), z.string()]).nullable().optional(),
  display: z.union([z.boolean(), z.number(), z.string()]).nullable().optional(),
  placeholder: z.string().nullable().optional(),
  option: z.array(orderTypeFieldOptionSchema).nullable().optional(),
})

export type OrderTypeField = z.infer<typeof orderTypeFieldSchema>

/**
 * OrderTypeResource（deposit/top-up-types）。
 * order_type_info 可能是陣列、{ data: [] } 或 JSON 字串，交給 normalizeFieldDefs 處理，這裡不限制形狀。
 * type / acf_id / cf_name / is_recommend 在分組版（deposit/types）沒有，故為 optional，
 * normalizeGroupItem 才能把分組項對齊成同一個型別。
 */
export const orderTypeSchema = z.looseObject({
  order_type_id: z.coerce.number(),
  currency_id: z.coerce.number(),
  name: z.string(),
  cf_id: z.coerce.number(),
  min_amount: z.coerce.number(),
  max_amount: z.coerce.number(),
  rate,
  image_path: z.string(),
  choose_bank: flagSchema,
  order_type_info: z.unknown(),
  PopupText: z.string(),
  type: z.union([z.string(), z.number()]).nullable().optional(),
  acf_id: z.coerce.number().optional(),
  cf_name: z.string().nullable().optional(),
  is_recommend: flagSchema.optional(),
  crypto_wallet_id: z.coerce.number().optional(),
  crypto_wallet_address: z.string().nullable().optional(),
})

export type OrderType = z.infer<typeof orderTypeSchema>

/**
 * ChildOrderTypeResource（deposit/top-up-channels，choose_bank == 1 才有）。
 * 新 API 的欄位定義放 options；order_type_info 是舊的 JSON 字串，保留為退路。
 */
export const childOrderTypeSchema = z.looseObject({
  id: z.coerce.number(),
  name: z.string(),
  order_type_info: z.unknown(),
  options: z.unknown(),
  image: z.string().nullable().optional(),
  min_amount: z.coerce.number(),
  max_amount: z.coerce.number(),
})

export type ChildOrderType = z.infer<typeof childOrderTypeSchema>

/**
 * OrderTypeGroupItemResource（deposit/types）。
 * 鍵名與 top-up-types 不同（cash_flow_id / popup_text），normalizeGroupItem 會補上 cf_id / PopupText。
 */
export const orderTypeGroupItemSchema = z.looseObject({
  order_type_id: z.coerce.number(),
  currency_id: z.coerce.number(),
  name: z.string(),
  cash_flow_id: z.coerce.number(),
  min_amount: z.coerce.number(),
  max_amount: z.coerce.number(),
  rate,
  image_path: z.string(),
  choose_bank: flagSchema,
  order_type_info: z.unknown(),
  banks: z.array(childOrderTypeSchema).nullable().optional(),
  popup_text: z.string().nullable().optional(),
  crypto_wallet_id: z.coerce.number().optional(),
  crypto_wallet_address: z.string().nullable().optional(),
})

export type OrderTypeGroupItem = z.infer<typeof orderTypeGroupItemSchema>

export const orderTypeGroupSchema = z.looseObject({
  type: z.string().nullable().optional(),
  items: z.array(orderTypeGroupItemSchema),
})

export type OrderTypeGroup = z.infer<typeof orderTypeGroupSchema>

/**
 * DepositResponseData（deposit/create-order）。Spatie Data，鍵名是 camelCase，
 * 與其他端點的 snake_case 不同，照後端原樣不改名。
 * type 對應 shared/utils/deposit.ts 的 DEPOSIT_RESULT_TYPE。
 */
export const depositResultSchema = z.looseObject({
  type: z.coerce.number(),
  url: z.string().nullable().optional(),
  html: z.string().nullable().optional(),
  orderNo: z.string().nullable().optional(),
  providerOrderNo: z.string().nullable().optional(),
  code: z.union([z.string(), z.number()]).nullable().optional(),
  message: z.string().nullable().optional(),
})

export type DepositResult = z.infer<typeof depositResultSchema>

/** BankAccountDetailResource（auto-deposit/bank-accounts）。 */
export const bankAccountSchema = z.looseObject({
  order_type_id: z.coerce.number(),
  bank_id: z.coerce.number(),
  bank_name: z.string(),
  bank_code: z.string(),
  currency_id: z.coerce.number(),
  account_name: z.string(),
  account_no: z.string(),
  qrcode: z.string().nullable().optional(),
  bank_icon: z.string().nullable().optional(),
  agent_cash_flow_id: z.coerce.number().optional(),
  rate,
  min_amount: z.coerce.number(),
  max_amount: z.coerce.number(),
})

export type BankAccount = z.infer<typeof bankAccountSchema>

/** type 1 = 可用帳號列表；type 2 = 有一筆未上傳憑證的 CREATED 訂單，bank 只含該訂單的帳號。 */
export const bankAccountsDataSchema = z.looseObject({
  type: z.coerce.number(),
  bank: z.array(bankAccountSchema),
})

export type BankAccountsData = z.infer<typeof bankAccountsDataSchema>

/** OrderResource（auto-deposit/order/create）。status 0 = CREATED。 */
export const bankOrderSchema = z.looseObject({
  order_id: z.coerce.number(),
  order_no: z.string(),
  bank_name: z.string(),
  bank_code: z.string(),
  account_name: z.string(),
  account_no: z.string(),
  amount: amount,
  status: z.coerce.number(),
  qrcode: z.string().nullable().optional(),
})

export type BankOrder = z.infer<typeof bankOrderSchema>

export const depositEndpoints = {
  'deposit/top-up-types': defineEndpoint({
    path: 'deposit/top-up-types',
    auth: 'member',
    description: '存款方式列表（線上支付 + 自助上分固定項）；code 323 代表要先設定支付密碼',
    request: z.object({}),
    response: z.array(orderTypeSchema),
  }),

  'deposit/types': defineEndpoint({
    path: 'deposit/types',
    auth: 'member',
    description: '分組版存款方式列表；子渠道已內嵌在 banks，不需再打 top-up-channels',
    request: z.object({}),
    response: z.array(orderTypeGroupSchema),
  }),

  'deposit/top-up-channels': defineEndpoint({
    path: 'deposit/top-up-channels',
    auth: 'member',
    description: '父支付方式 choose_bank == 1 時的子渠道列表',
    request: z.object({
      order_type_id: z.coerce.number().int(),
    }),
    response: z.array(childOrderTypeSchema),
  }),

  'deposit/create-order': defineEndpoint({
    path: 'deposit/create-order',
    auth: 'member',
    description: '建立存款訂單；order_type_info 定義的動態欄位（pin、bank_code…）原樣送出當 extra_content',
    // looseObject：動態欄位名稱由後端下發，不能在這裡列舉，用 object 會被 zod 濾掉
    request: z.looseObject({
      order_type_id: z.coerce.number().int(),
      amount: z.coerce.number().positive().optional(),
    }),
    response: depositResultSchema.nullable(),
  }),

  'auto-deposit/bank-accounts': defineEndpoint({
    path: 'auto-deposit/bank-accounts',
    auth: 'member',
    description: '銀行轉帳可用帳號；type 2 代表有未上傳憑證的訂單要先接回',
    request: z.object({}),
    response: bankAccountsDataSchema,
  }),

  'auto-deposit/order/create': defineEndpoint({
    path: 'auto-deposit/order/create',
    auth: 'member',
    description: '建立銀行轉帳訂單；不帶欄位則查回目前 CREATED 的訂單',
    request: z.object({
      bank_id: z.coerce.number().int().optional(),
      amount: z.coerce.number().min(0).optional(),
    }),
    response: bankOrderSchema,
  }),

  'auto-deposit/order/delete': defineEndpoint({
    path: 'auto-deposit/order/delete',
    auth: 'member',
    description: '取消 CREATED 狀態的銀行轉帳訂單',
    request: z.object({
      order_id: z.coerce.number().int(),
    }),
    response: z.unknown(),
  }),

  'auto-deposit/order/upload-image': defineEndpoint({
    path: 'auto-deposit/order/upload-image',
    auth: 'member',
    description: '上傳銀行轉帳憑證（base64 data URL），成功後訂單轉為待審核',
    request: z.object({
      order_id: z.coerce.number().int(),
      image: z.string().regex(/^data:image\/(jpeg|png|gif|bmp|webp);base64,/, 'must be a base64 image data URL'),
    }),
    response: z.looseObject({
      order_no: z.string().optional(),
      image: z.string().optional(),
    }),
  }),
} satisfies EndpointMap
