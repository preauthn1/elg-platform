import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'
import { paginatedSchema } from '#shared/types/api'

/** MySQL DECIMAL 欄位多半以字串回來，偶有數字；顯示時再做兩位小數格式化。 */
const amount = z.union([z.string(), z.number()])
const text = z.string().nullable().optional()
const pageMin1 = z.coerce.number().int().min(1)
const pageInt = z.coerce.number().int()
const searchDay = z.string().regex(/^\d{4}-\d{2}-\d{2}$/)
const dateTime = z.string().regex(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)

/** transaction/logs 的 type：0/省略 = 全部，1 進分 2 洗分 3 進出遊戲 4 活動獎勵 5 全民代理 6 紅包 7 補分 8 掃碼轉帳 */
export const transactionLogTypeSchema = z.coerce.number().pipe(z.union([
  z.literal(0),
  z.literal(1),
  z.literal(2),
  z.literal(3),
  z.literal(4),
  z.literal(5),
  z.literal(6),
  z.literal(7),
  z.literal(8),
]))

/** transaction/red-envelope 的 type：1 發送 2 領取 3 退還 */
export const redEnvelopeTypeSchema = z.coerce.number().pipe(z.union([z.literal(1), z.literal(2), z.literal(3)]))

export const recordIconStatusSchema = z.enum(['pending', 'fail', 'success'])

export const depositOrderSchema = z.looseObject({
  order_id: z.coerce.number(),
  cash_flow_id: z.coerce.number().nullable().optional(),
  deposit_url: text,
  extra_content: text,
  order_type: text,
  image_path: text,
  currency_id: z.coerce.number().nullable().optional(),
  member_id: z.coerce.number().optional(),
  amount: amount,
  point: amount.optional(),
  balance: amount.optional(),
  status: z.coerce.number(),
  status_name: text,
  created_at: z.string(),
  updated_at: z.string().optional(),
})

export const withdrawalOrderSchema = z.looseObject({
  id: z.coerce.number(),
  created_at: z.string(),
  amount: amount,
  status: z.string(),
  icon_status: recordIconStatusSchema,
  bank_icon: text,
  bank_name: text,
  remark: text,
})

export const transactionLogSchema = z.looseObject({
  is_rolling_chip: flagSchema,
  created_at: z.string(),
  type: z.coerce.number(),
  point: amount,
  currency: text,
  vendor_image: text,
  vendor_name: text,
})

export const redEnvelopeLogSchema = z.looseObject({
  id: z.coerce.number(),
  point: amount,
  created_at: z.string(),
  nickname: text,
})

export const customerServiceLogSchema = z.looseObject({
  id: z.coerce.number(),
  transaction_no: z.coerce.string().nullable().optional(),
  point: amount,
  type: z.coerce.number().pipe(z.union([z.literal(1), z.literal(2)])),
  created_at: z.string(),
})

export const gameTransferLogSchema = z.looseObject({
  id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  vendor_name: text,
  type: z.coerce.number(),
  point: amount,
  vendor_point: amount.nullable().optional(),
  balance: amount.nullable().optional(),
  vendor_balance: amount.nullable().optional(),
  rate: amount.nullable().optional(),
  status: z.coerce.number(),
  result: text,
  created_at: z.string(),
  updated_at: z.string(),
})

export const totalValidBetSchema = z.looseObject({
  total_valid_bet: amount,
  name: z.string(),
  image: text,
  summary_time: z.string(),
})

export const winloseSchema = z.looseObject({
  valid_bet: amount,
  win_lose: amount,
  record_count: amount,
  name: z.string(),
})

export const winloseSumSchema = z.looseObject({
  sum_win_lose: amount,
  sum_record_count: amount,
})

export const accumulatedBetsSchema = z.looseObject({
  today_accumulated_bet: amount,
  yesterday_accumulated_bet: amount,
})

/** 本領域的 FormRequest 都不驗 domain，AuthMember 已由會員帶出代理，BFF 不必補。 */
export const recordsEndpoints = {
  'deposit/orders': defineEndpoint({
    path: 'deposit/orders',
    auth: 'member',
    withDomain: false,
    description: '充值訂單紀錄；data 是扁平陣列、沒有分頁欄位',
    request: z.object({
      page: pageMin1,
      per_page: pageMin1,
    }),
    response: z.array(depositOrderSchema),
  }),

  'withdrawal/orders': defineEndpoint({
    path: 'withdrawal/orders',
    auth: 'member',
    withDomain: false,
    description: '洗分（提款）紀錄',
    request: z.object({
      page: pageInt.optional(),
      per_page: pageInt.optional(),
    }),
    response: paginatedSchema(withdrawalOrderSchema),
  }),

  'transaction/logs': defineEndpoint({
    path: 'transaction/logs',
    auth: 'member',
    withDomain: false,
    description: '交易紀錄；只開放最近 3 天，超出回 400',
    request: z.object({
      type: transactionLogTypeSchema.optional(),
      start_time: dateTime,
      end_time: dateTime,
      page: pageInt,
      per_page: pageInt,
    }),
    response: paginatedSchema(transactionLogSchema),
  }),

  'transaction/red-envelope': defineEndpoint({
    path: 'transaction/red-envelope',
    auth: 'member',
    withDomain: false,
    description: '紅包紀錄；type 1 發送 2 領取 3 退還',
    request: z.object({
      type: redEnvelopeTypeSchema.optional(),
      page: pageInt,
      per_page: pageInt,
    }),
    response: paginatedSchema(redEnvelopeLogSchema),
  }),

  'transaction/customer-service': defineEndpoint({
    path: 'transaction/customer-service',
    auth: 'member',
    withDomain: false,
    description: '客服轉入轉出紀錄；type 1 轉入 2 轉出',
    request: z.object({
      page: pageMin1,
      per_page: pageMin1,
    }),
    response: paginatedSchema(customerServiceLogSchema),
  }),

  'transaction/game-transfer-logs': defineEndpoint({
    path: 'transaction/game-transfer-logs',
    auth: 'member',
    withDomain: false,
    description: '平台與遊戲商轉點紀錄；type 1 轉入遊戲商 2 轉出遊戲商',
    request: z.object({
      vendor_id: pageInt.optional(),
      start_time: dateTime.optional(),
      end_time: dateTime.optional(),
      page: pageInt.optional(),
      per_page: pageInt.optional(),
    }),
    response: paginatedSchema(gameTransferLogSchema),
  }),

  'member/total-valid-bets': defineEndpoint({
    path: 'member/total-valid-bets',
    auth: 'member',
    withDomain: false,
    description: '單日各遊戲商有效投注合計；search_day 只開放最近 3 天。vendor_id 後端不理會',
    request: z.object({
      search_day: searchDay,
      page: pageInt,
      per_page: pageInt,
      vendor_id: z.string().optional(),
    }),
    response: paginatedSchema(totalValidBetSchema),
  }),

  'member/winloses': defineEndpoint({
    path: 'member/winloses',
    auth: 'member',
    withDomain: false,
    description: '單一遊戲商單日各遊戲輸贏報表；sum 是整日合計不是當頁',
    request: z.object({
      vendor_id: pageInt,
      search_day: searchDay,
      page: pageInt,
      per_page: pageInt,
    }),
    response: paginatedSchema(winloseSchema).extend({ sum: winloseSumSchema }),
  }),

  'bet-record/accumulated-bets': defineEndpoint({
    path: 'bet-record/accumulated-bets',
    auth: 'member',
    withDomain: false,
    description: '指定遊戲商今日／昨日累積投注',
    request: z.object({
      vendor_id: z.coerce.number().int().min(0),
    }),
    response: accumulatedBetsSchema,
  }),
} satisfies EndpointMap
