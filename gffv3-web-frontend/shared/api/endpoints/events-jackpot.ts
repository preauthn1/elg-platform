import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'
import { paginatedSchema, paginationSchema } from '#shared/types/api'

const noFields = z.object({})
const text = z.string().nullable().optional()

/** 後端金額一律是字串（"1888.0000"），偶有數字，統一收成字串。 */
const amount = z.coerce.string()

// ---------- Jackpot（積寶活動） ----------

export const jackpotPrizeSchema = z.looseObject({
  id: z.coerce.number(),
  event_id: z.coerce.number().optional(),
  point: amount,
  image: text,
  status: flagSchema,
  expired_time: text,
})

export const jackpotShowSchema = z.looseObject({
  start_time: z.string().optional(),
  end_time: z.string().optional(),
  title: text,
  content: text,
  banner: text,
  jackpot: amount,
  prize: z.array(jackpotPrizeSchema),
})

export const jackpotWinnerSchema = z.looseObject({
  image: text,
  username: z.string(),
  point: amount,
  created_at: z.string().optional(),
})

export const jackpotNotificationSchema = z.looseObject({
  id: z.coerce.number(),
  point: amount,
  image: z.string(),
})

// ---------- 抽獎券三變體（V-Jackpot / W-Jackpot / W-Jackpot 6D） ----------

const issueIdRequest = z.object({ issue_id: z.coerce.number().int() })
const ticketIdRequest = z.object({ ticket_id: z.coerce.number().int() })
const ticketsRequest = z.object({
  issue_id: z.coerce.number().int(),
  page: z.coerce.number().int().min(1),
  per_page: z.coerce.number().int().min(1),
})

export const lotteryIssueSchema = z.looseObject({
  issue_id: z.coerce.number(),
  issue_name: z.string(),
  draw_time: text,
})

export const lotterySummarySchema = z.looseObject({
  totalCount_tickets: z.coerce.number(),
  totalCount_winnings: amount,
  totalCount_unclaimed: amount,
})

export const lotteryIssueDetailSchema = z.looseObject({
  issue_id: z.coerce.number(),
  issue_name: z.string(),
  begin_time: z.string(),
  end_time: z.string(),
  draw_time: text,
  result_number: z.string(),
  summary: lotterySummarySchema,
})

/** W-Jackpot 開獎五組：第一組沿用 result_number，其餘 _2.._5（_4/_5 是陣列）。 */
export const wjackpotIssueDetailSchema = lotteryIssueDetailSchema.extend({
  result_number_2: z.string(),
  result_number_3: z.string(),
  result_number_4: z.array(z.string()),
  result_number_5: z.array(z.string()),
})

export const lotteryTicketSchema = z.looseObject({
  ticket_id: z.coerce.number(),
  ticket_number: z.string(),
  reward_type: z.coerce.number().nullable(),
  reward_level: z.coerce.number(),
  reward_amount: amount,
  reward_name: z.string(),
  type: flagSchema,
  status: z.coerce.number(),
  statusText: z.string(),
})

/** apis OnlyPaginateResource：per_page / total / pages / previous / current / next（邊界為 ''）。 */
export const lotteryPaginationSchema = paginationSchema.extend({
  pages: z.coerce.number().optional(),
  previous: z.union([z.number(), z.literal('')]).optional(),
  current: z.coerce.number().optional(),
  next: z.union([z.number(), z.literal('')]).optional(),
})

export const lotteryTicketsSchema = paginatedSchema(lotteryTicketSchema).extend({
  result_number: z.string(),
  pagination: lotteryPaginationSchema,
})

export const wjackpotTicketsSchema = lotteryTicketsSchema.extend({
  result_number_2: z.string(),
  result_number_3: z.string(),
  result_number_4: z.array(z.string()),
  result_number_5: z.array(z.string()),
})

// ---------- 成長彩金計劃（progressive jackpot） ----------

export const growthSettingSchema = z.looseObject({
  status: flagSchema,
  title: text,
  content: text,
  image: text,
})

export const growthSummarySchema = z.looseObject({
  level: z.coerce.number().nullable(),
  reward: z.string().nullable(),
  status: z.coerce.number().nullable(),
  progress: amount,
  can_receive: amount,
  pending_unlock: amount,
  unlimited_time: z.boolean(),
  expire_time: text,
  completed_count: z.coerce.number(),
  total_count: z.coerce.number(),
})

export const growthLevelSchema = z.looseObject({
  level: z.coerce.number(),
  status: z.coerce.number().nullable(),
  reward: amount,
  point: amount,
  receive: amount,
  remaining: z.string().nullable(),
  progress: amount,
  last_point: amount,
  expire_time: text,
  deposit_point: amount,
  remaining_deposit: amount,
  reward_type: z.coerce.number(),
  reward_percentage: amount,
  max_reward: amount,
  expire_day: z.coerce.number(),
})

export const growthBetSettingSchema = z.looseObject({
  game_type_id: z.coerce.number(),
  rate: amount,
})

export const growthListSchema = z.looseObject({
  setting: growthSettingSchema,
  summary: growthSummarySchema,
  levels: z.array(growthLevelSchema),
  bet_settings: z.array(growthBetSettingSchema),
})

export const growthReceiveSchema = z.looseObject({
  summary: growthSummarySchema,
  levels: z.array(growthLevelSchema),
})

export const eventsJackpotEndpoints = {
  'event/jackpot/show': defineEndpoint({
    path: 'event/jackpot/show',
    auth: 'member',
    description: '積寶活動資訊與自己的獎項（沒有進行中活動時 data 為 null）',
    request: noFields,
    response: jackpotShowSchema.nullable(),
  }),
  'event/jackpot/win-list': defineEndpoint({
    path: 'event/jackpot/win-list',
    auth: 'member',
    description: '積寶活動當個營業日（07:00 起）中獎名單',
    request: noFields,
    response: z.array(jackpotWinnerSchema),
  }),
  'event/jackpot/receive': defineEndpoint({
    path: 'event/jackpot/receive',
    auth: 'member',
    description: '領取積寶獎項（prize_members.id）',
    request: z.object({ id: z.coerce.number().int().min(1) }),
    response: z.null(),
  }),
  'event/jackpot/check-notify': defineEndpoint({
    path: 'event/jackpot/check-notify',
    auth: 'member',
    description: '尚未看過的積寶中獎彈窗',
    request: noFields,
    response: z.array(jackpotNotificationSchema),
  }),
  'event/jackpot/confirm-notify': defineEndpoint({
    path: 'event/jackpot/confirm-notify',
    auth: 'member',
    description: '確認已看過積寶中獎彈窗（event_jackpot_records.id）',
    request: z.object({ id: z.coerce.number().int().min(1) }),
    response: z.null(),
  }),

  'event/lucky-draw/issues': defineEndpoint({
    path: 'event/lucky-draw/issues',
    auth: 'member',
    description: 'V-Jackpot 期數列表',
    request: noFields,
    response: z.array(lotteryIssueSchema),
  }),
  'event/lucky-draw/issues/detail': defineEndpoint({
    path: 'event/lucky-draw/issues/detail',
    auth: 'member',
    description: 'V-Jackpot 期數明細（未開獎 result_number 為 7 個 -）',
    request: issueIdRequest,
    response: lotteryIssueDetailSchema,
  }),
  'event/lucky-draw/issues/tickets': defineEndpoint({
    path: 'event/lucky-draw/issues/tickets',
    auth: 'member',
    description: 'V-Jackpot 每期我的抽獎券（分頁）',
    request: ticketsRequest,
    response: lotteryTicketsSchema,
  }),
  'event/lucky-draw/issues/receive': defineEndpoint({
    path: 'event/lucky-draw/issues/receive',
    auth: 'member',
    description: 'V-Jackpot 領取單張抽獎券獎項',
    request: ticketIdRequest,
    response: z.null(),
  }),
  'event/lucky-draw/issues/receive-all': defineEndpoint({
    path: 'event/lucky-draw/issues/receive-all',
    auth: 'member',
    description: 'V-Jackpot 該期獎項全部領取（沒有可領取時回 1000）',
    request: issueIdRequest,
    response: z.null(),
  }),

  'event/wjackpot/issues': defineEndpoint({
    path: 'event/wjackpot/issues',
    auth: 'member',
    description: 'W-Jackpot 期數列表（最近 3 期）',
    request: noFields,
    response: z.array(lotteryIssueSchema),
  }),
  'event/wjackpot/issues/detail': defineEndpoint({
    path: 'event/wjackpot/issues/detail',
    auth: 'member',
    description: 'W-Jackpot 期數明細（五組開獎號碼，未開獎為 ----）',
    request: issueIdRequest,
    response: wjackpotIssueDetailSchema,
  }),
  'event/wjackpot/issues/tickets': defineEndpoint({
    path: 'event/wjackpot/issues/tickets',
    auth: 'member',
    description: 'W-Jackpot 每期我的抽獎券（分頁）',
    request: ticketsRequest,
    response: wjackpotTicketsSchema,
  }),
  'event/wjackpot/issues/receive': defineEndpoint({
    path: 'event/wjackpot/issues/receive',
    auth: 'member',
    description: 'W-Jackpot 領取單張抽獎券獎項（有實名檢查）',
    request: ticketIdRequest,
    response: z.null(),
  }),
  'event/wjackpot/issues/receive-all': defineEndpoint({
    path: 'event/wjackpot/issues/receive-all',
    auth: 'member',
    description: 'W-Jackpot 該期獎項全部領取（沒有可領取仍回成功）',
    request: issueIdRequest,
    response: z.null(),
  }),

  'event/wjackpot6d/issues': defineEndpoint({
    path: 'event/wjackpot6d/issues',
    auth: 'member',
    description: 'W-Jackpot 6D 期數列表（最近 3 期）',
    request: noFields,
    response: z.array(lotteryIssueSchema),
  }),
  'event/wjackpot6d/issues/detail': defineEndpoint({
    path: 'event/wjackpot6d/issues/detail',
    auth: 'member',
    description: 'W-Jackpot 6D 期數明細（未開獎 result_number 為 6 個 -）',
    request: issueIdRequest,
    response: lotteryIssueDetailSchema,
  }),
  'event/wjackpot6d/issues/tickets': defineEndpoint({
    path: 'event/wjackpot6d/issues/tickets',
    auth: 'member',
    description: 'W-Jackpot 6D 每期我的抽獎券（分頁；逾期未領回 status 4）',
    request: ticketsRequest,
    response: lotteryTicketsSchema,
  }),
  'event/wjackpot6d/issues/receive': defineEndpoint({
    path: 'event/wjackpot6d/issues/receive',
    auth: 'member',
    description: 'W-Jackpot 6D 領取單張抽獎券獎項（有實名檢查；逾期 1948）',
    request: ticketIdRequest,
    response: z.null(),
  }),
  'event/wjackpot6d/issues/receive-all': defineEndpoint({
    path: 'event/wjackpot6d/issues/receive-all',
    auth: 'member',
    description: 'W-Jackpot 6D 該期獎項全部領取（沒有可領取時回 1000）',
    request: issueIdRequest,
    response: z.null(),
  }),

  'event/progressive-jackpot/list': defineEndpoint({
    path: 'event/progressive-jackpot/list',
    auth: 'member',
    description: '成長彩金計劃：活動設定、當前檔位總覽、全部檔位、各遊戲解鎖比例',
    request: noFields,
    response: growthListSchema,
  }),
  'event/progressive-jackpot/receive': defineEndpoint({
    path: 'event/progressive-jackpot/receive',
    auth: 'member',
    description: '成長彩金計劃：領取指定檔位已解鎖彩金（0 = 首儲檔），回更新後的 summary 與 levels',
    request: z.object({ level: z.coerce.number().int() }),
    response: growthReceiveSchema,
  }),
} satisfies EndpointMap
