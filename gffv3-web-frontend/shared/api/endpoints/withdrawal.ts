import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

/** 後端金額/匯率多為字串（"50.00"），偶有數字。 */
const amountText = z.union([z.string(), z.number()])

/** 成功時 data 為空陣列的端點。 */
const emptyDataSchema = z.union([z.array(z.unknown()), z.looseObject({})]).nullable()

export const bankInfoFieldSchema = z.looseObject({
  type: z.string().nullable().optional(),
  display: z.string().nullable().optional(),
  placeholder: z.string().nullable().optional(),
  regex: z.string().nullable().optional(),
  options: z.array(z.looseObject({
    text: z.string(),
    value: z.union([z.string(), z.number()]),
  })).nullable().optional(),
})

export const withdrawalBankSchema = z.looseObject({
  bank_id: z.coerce.number(),
  bank_name: z.string(),
  bank_icon: z.string().nullable().optional(),
  bank_info: z.union([z.record(z.string(), bankInfoFieldSchema), z.array(z.unknown())]).nullable().optional(),
  bank_code: z.string(),
  bank_type: z.coerce.number(),
  rate: amountText,
  currency_id: z.coerce.number(),
  currency_name: z.string(),
  withdrawal_type_id: z.coerce.number(),
  withdrawal_type_name: z.string().nullable().optional(),
  min_amount: amountText,
  max_amount: amountText,
  tax_type: z.coerce.number(),
  tax: amountText,
})

export const memberBankAccountSchema = z.looseObject({
  bank_id: z.coerce.number(),
  bank_name: z.string(),
  bank_code: z.string(),
  bank_type: z.coerce.number(),
  bank_rate: amountText.nullable().optional(),
  min_amount: amountText.nullable().optional(),
  max_amount: amountText.nullable().optional(),
  tax_type: z.coerce.number().nullable().optional(),
  bank_tax: amountText.nullable().optional(),
  account_name: z.string().nullable().optional(),
  account_no: z.string(),
})

export const checkBankAccountSchema = z.looseObject({
  has_bank_card_setting: flagSchema,
  has_bank_card: flagSchema,
  bank_card_amount: z.coerce.number(),
  country_code: z.string().nullable().optional(),
  mobile: z.string().nullable().optional(),
  account_name: z.string(),
  account: z.array(memberBankAccountSchema).nullable(),
  min_amount: amountText,
  max_amount: amountText,
  is_first: flagSchema,
  account_name_modify: flagSchema,
  has_login_event_reward: flagSchema,
  withdrawal_times: z.coerce.number().nullable(),
  withdrawal_times_limit: z.coerce.number().nullable(),
  over_times: flagSchema,
  vip_tax: amountText.nullable(),
})

export const flowLimitSchema = z.looseObject({
  flow_limit: amountText.nullable(),
  reach: amountText.nullable(),
  pass: flagSchema,
  message: z.string().nullable().optional(),
})

const bindAccountItemSchema = z.object({
  bank_id: z.coerce.number().int(),
  account_name: z.string().max(255).nullable().optional(),
  account_no: z.string().trim().min(1).max(255).regex(/^\S+$/),
})

export const withdrawalEndpoints = {
  'withdrawal/flow-limit': defineEndpoint({
    path: 'withdrawal/flow-limit',
    auth: 'member',
    withDomain: false,
    description: '稽核流水：flow_limit / reach / pass',
    request: z.object({}),
    response: flowLimitSchema,
  }),
  'audit/release-application': defineEndpoint({
    path: 'audit/release-application',
    auth: 'member',
    withDomain: false,
    description: '申請解除稽核流水',
    request: z.object({}),
    response: emptyDataSchema,
  }),
  'withdrawal/check-bank-account': defineEndpoint({
    path: 'withdrawal/check-bank-account',
    auth: 'member',
    withDomain: false,
    description: '提款資訊：已綁卡、限額、次數；type = 代付方式 id，-1 不限',
    request: z.object({
      type: z.coerce.number().int().optional(),
    }),
    response: checkBankAccountSchema,
  }),
  'withdrawal/bind-bank-account': defineEndpoint({
    path: 'withdrawal/bind-bank-account',
    auth: 'member',
    withDomain: false,
    description: '綁定銀行帳號（可多組）',
    request: z.object({
      account: z.array(bindAccountItemSchema).min(1),
    }),
    response: emptyDataSchema,
  }),
  'withdrawal/banks': defineEndpoint({
    path: 'withdrawal/banks',
    auth: 'member',
    withDomain: false,
    description: '可提款銀行清單；type = 代付方式 id',
    request: z.object({
      type: z.coerce.number().int().optional(),
    }),
    response: z.array(withdrawalBankSchema),
  }),
  'withdrawal/confirm-payment-password': defineEndpoint({
    path: 'withdrawal/confirm-payment-password',
    auth: 'member',
    withDomain: false,
    description: '提款前驗證支付密碼',
    request: z.object({
      payment_password: [REDACTED],
    }),
    response: emptyDataSchema,
  }),
  'withdrawal/create-order': defineEndpoint({
    path: 'withdrawal/create-order',
    auth: 'member',
    withDomain: false,
    description: '建立提款單；bank_info 動態欄位照 key 原樣附帶',
    request: z.looseObject({
      amount: z.coerce.number().min(1),
      account_name: z.string().max(255).nullable().optional(),
      account_no: z.string().trim().min(1).max(255),
      bank_id: z.coerce.number().int(),
      bank_name: z.string().min(1).max(255),
      bank_code: z.string().min(1).max(255),
      mobile: z.string().optional(),
      ifsc: z.string().optional(),
    }),
    response: emptyDataSchema,
  }),
} satisfies EndpointMap
