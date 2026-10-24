import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'
import { paginatedSchema } from '#shared/types/api'

/** 後端金額多為字串（'0.000'），少數聚合欄位可能直接是數字；前端算數時再用 toAmount 轉。 */
const amount = z.union([z.string(), z.number()])
const amountNullable = amount.nullable()
const emptyRequest = z.object({})
/** apis ApiResponse::success() 沒帶資料時 data 為 []。 */
const emptyData = z.array(z.unknown())

/** vip/info reward[].status：-1 未達成、0 可領取、1 已領取、2 已過期。 */
export const vipRewardStatusSchema = z.coerce.number().pipe(
  z.union([z.literal(-1), z.literal(0), z.literal(1), z.literal(2)]),
)

export const vipInfoRewardSchema = z.looseObject({
  id: z.coerce.number(),
  reward_id: z.coerce.number(),
  name: z.string(),
  status: vipRewardStatusSchema,
  expired_time: z.string().nullable(),
})

const vipRebateRecordSchema = z.looseObject({
  total_bet: amountNullable,
  rebate_amount: amountNullable,
})

export const vipRebateSummaryRecordSchema = z.looseObject({
  summary_start_time: z.string(),
  summary_end_time: z.string(),
  game_type_id: z.coerce.number(),
  bet: amount,
  proportion: amount,
  rebate_amount: amount,
})

export const vipInfoSchema = z.looseObject({
  vip_content_id: z.coerce.number(),
  deposit_amount: amount,
  keep_amount: amount,
  level: z.coerce.number(),
  this_rebate_records: vipRebateRecordSchema,
  last_rebate_records: vipRebateRecordSchema,
  rebate_summary_records: z.array(vipRebateSummaryRecordSchema),
  reward: z.array(vipInfoRewardSchema),
})

export const vipContentRewardSchema = z.looseObject({
  id: z.coerce.number(),
  name: z.string(),
  point: amount,
})

export const vipRebateProportionSchema = z.looseObject({
  game_type_id: z.coerce.number(),
  proportion: amount,
})

export const vipContentSchema = z.looseObject({
  id: z.coerce.number(),
  level: z.coerce.number(),
  name: z.string(),
  target_amount: amount,
  keep_amount: amount,
  withdrawal_times_limit: z.coerce.number(),
  image: z.string().nullable(),
  withdrawal_amount_limit: amount,
  vip_channel: flagSchema,
  reward: z.array(vipContentRewardSchema),
  support_order_type: z.array(z.looseObject({ name: z.string() })),
  rebate_proportion: z.array(vipRebateProportionSchema),
})

export const vipRewardHistoryItemSchema = z.looseObject({
  date: z.string(),
  vip_reward_id: z.coerce.number(),
  reward_name: z.string(),
  point: amount,
})

export const vipFlowLimitItemSchema = z.looseObject({
  date: z.string(),
  amount: amount,
  flow_limit: amount,
  finish_flow_limit: amount,
  status: z.coerce.number(),
})

export const vipFlowLimitRequestSchema = z.object({
  page: z.coerce.number().int().min(1),
  per_page: z.coerce.number().int().min(1),
})

export const vipReceiveRequestSchema = z.object({
  id: z.coerce.number().int().min(1),
})

export type VipInfo = z.output<typeof vipInfoSchema>
export type VipInfoReward = z.output<typeof vipInfoRewardSchema>
export type VipRebateSummaryRecord = z.output<typeof vipRebateSummaryRecordSchema>
export type VipContent = z.output<typeof vipContentSchema>
export type VipContentReward = z.output<typeof vipContentRewardSchema>
export type VipRebateProportion = z.output<typeof vipRebateProportionSchema>
export type VipRewardHistoryItem = z.output<typeof vipRewardHistoryItemSchema>
export type VipFlowLimitItem = z.output<typeof vipFlowLimitItemSchema>

/** 六條 vip/* 路由 middleware 皆為 AuthMember、無 HasDomain，所以 withDomain 一律 false。 */
export const vipEndpoints = {
  'vip/info': defineEndpoint({
    path: 'vip/info',
    auth: 'member',
    withDomain: false,
    description: '會員 VIP 資訊：等級、儲值/保級金額、本週/上週返水、週結明細、可領獎勵',
    request: emptyRequest,
    response: vipInfoSchema,
  }),
  'vip/contents': defineEndpoint({
    path: 'vip/contents',
    auth: 'member',
    withDomain: false,
    description: '代理層級內所有 VIP 等級設定（後端未排序）',
    request: emptyRequest,
    response: z.array(vipContentSchema),
  }),
  'vip/reward-history': defineEndpoint({
    path: 'vip/reward-history',
    auth: 'member',
    withDomain: false,
    description: 'VIP 領獎紀錄（只含已領取，依 id 倒序，不分頁）',
    request: emptyRequest,
    response: z.array(vipRewardHistoryItemSchema),
  }),
  'vip/flow-limit': defineEndpoint({
    path: 'vip/flow-limit',
    auth: 'member',
    withDomain: false,
    description: 'VIP 稽核流水限制列表（分頁）',
    request: vipFlowLimitRequestSchema,
    response: paginatedSchema(vipFlowLimitItemSchema),
  }),
  'vip/receive': defineEndpoint({
    path: 'vip/receive',
    auth: 'member',
    withDomain: false,
    description: '領取單筆 VIP 獎勵（id = vip/info reward[].reward_id）',
    request: vipReceiveRequestSchema,
    response: emptyData,
  }),
  'vip/receive-rebate': defineEndpoint({
    path: 'vip/receive-rebate',
    auth: 'member',
    withDomain: false,
    description: '一次領取所有待領且未過期的每日返水',
    request: emptyRequest,
    response: emptyData,
  }),
} satisfies EndpointMap
