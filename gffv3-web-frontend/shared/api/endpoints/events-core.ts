import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'
import { paginatedSchema } from '#shared/types/api'

/** 後端金額多半是字串（"12000.0000"），偶有數字。 */
const numeric = z.union([z.string(), z.number()])
const text = z.string().nullable().optional()
const optionalNumber = z.coerce.number().nullable().optional()
const optionalNumeric = numeric.nullable().optional()
const emptyRequest = z.object({})
/** data: [] 的成功回應（僅成功／失敗）。 */
const emptyData = z.array(z.unknown()).nullish()
const pageRequest = z.object({
  page: z.coerce.number().int().min(1),
  per_page: z.coerce.number().int().min(1),
})

// ---------------------------------------------------------------- event/list

export const eventListItemSchema = z.looseObject({
  event_v1_id: optionalNumber,
  event_v2_id: optionalNumber,
  tp_event: optionalNumber,
  name: text,
  image: text,
  params: z.looseObject({
    type: optionalNumber,
    category: text,
  }).nullable().optional(),
  game_type_ids: z.array(z.coerce.number()).optional(),
  end_time: text,
  /** 活動 V2 的類型 handler；紅包固定 AngpowService，V1／第三方活動為 null */
  handler_class: text,
})
export type EventListItem = z.output<typeof eventListItemSchema>

// ---------------------------------------------------------- legacy (V1) rows

export const legacyEventDetailSchema = z.looseObject({
  id: optionalNumber,
  action_id: optionalNumber,
  vendor_id: optionalNumber,
  game_type_id: optionalNumber,
  point: optionalNumeric,
  now_point: optionalNumeric,
  /** 指定遊戲（說明頁的遊戲列表） */
  games: z.array(z.looseObject({ name: text })).nullish(),
  vendor_maintenances: flagSchema.optional(),
  vendor_name: text,
  game_type_name: text,
  /** 達成條件名稱（例如「投注滿額」） */
  action_name: text,
  /** 達成期限 */
  start_time: text,
  end_time: text,
  /** 邀請類活動的推廣碼 */
  code: text,
})
export type LegacyEventDetailRow = z.output<typeof legacyEventDetailSchema>

export const prizeContentSchema = z.looseObject({
  id: optionalNumber,
  name: text,
  type: optionalNumber,
  point: optionalNumeric,
  point_percentage: optionalNumeric,
  percentage: optionalNumeric,
  number: optionalNumber,
  sent: optionalNumber,
  random_point_max: optionalNumeric,
  point_type: optionalNumber,
})
export type PrizeContent = z.output<typeof prizeContentSchema>

/** 輸分回扣（action 9）的上期／上上期業績，一期一筆自己的兌換鈕。 */
export const payOutDetailSchema = z.looseObject({
  id: optionalNumber,
  now_point: optionalNumeric,
  end_time: text,
  show_exchange: optionalNumber,
  show_exchange_text: text,
})
export type PayOutDetail = z.output<typeof payOutDetailSchema>

const legacyEventBaseFields = {
  id: z.coerce.number(),
  category: text,
  event_detail_id: optionalNumber,
  event_detail_point: optionalNumeric,
  title: text,
  content: text,
  type_id: optionalNumber,
  agent_id: optionalNumber,
  prize_id: optionalNumber,
  prize_number: optionalNumber,
  prize_is_repeatedly: flagSchema.optional(),
  prize_expire_date: text,
  expire_date: text,
  prize_draw_date: text,
  event_start_time: text,
  event_end_time: text,
  type: optionalNumber,
  action_id: optionalNumber,
  count: optionalNumber,
  reached_today: optionalNumber,
  show_exchange: optionalNumber,
  show_exchange_text: text,
  remaining_number: optionalNumber,
  start_time: text,
  end_time: text,
  detail: z.array(legacyEventDetailSchema).optional(),
  prize_content: z.array(prizeContentSchema).optional(),
  pay_out_detail: z.array(payOutDetailSchema).nullish(),
  /** action 59 連續儲值：已累積天數／目標天數 */
  consecutive_day: optionalNumber,
  max_consecutive_day: optionalNumber,
}

export const legacyEventRowSchema = z.looseObject({
  ...legacyEventBaseFields,
  auto_payout: optionalNumber,
  image_url: text,
  image_pc_url: text,
  status: optionalNumber,
  exchange: optionalNumber,
  exchanged: optionalNumber,
  is_repeatedly: optionalNumber,
  has_receive: optionalNumber,
  can_receive: optionalNumber,
  reward_point: optionalNumeric,
  prize_name: text,
  flow_limit_game_type: z.unknown().optional(),
  code: z.unknown().optional(),
  action_name: text,
  now_point: optionalNumeric,
})
export type LegacyEventRow = z.output<typeof legacyEventRowSchema>

export const legacyEventDetailResponseSchema = z.looseObject({
  ...legacyEventBaseFields,
  prize: z.array(prizeContentSchema).optional(),
})
export type LegacyEventDetail = z.output<typeof legacyEventDetailResponseSchema>

/** 舊版 LengthAwarePaginator 手包：{ items, pagination } 外加 is_reddeem。 */
const legacyEventListSchema = paginatedSchema(legacyEventRowSchema).extend({
  is_reddeem: flagSchema.optional(),
})
export type LegacyEventList = z.output<typeof legacyEventListSchema>

// ------------------------------------------------------------- new-invite

export const newInvitePrizeContentSchema = z.looseObject({
  prize_content_id: optionalNumber,
  name: text,
  type: optionalNumber,
  achieve_people: optionalNumber,
  achieve_number: optionalNumber,
  point: optionalNumeric,
  show_exchange: optionalNumber,
  text: text,
})
export type NewInvitePrizeContent = z.output<typeof newInvitePrizeContentSchema>

export const newInviteEventSchema = z.looseObject({
  id: optionalNumber,
  event_detail_id: optionalNumber,
  point: optionalNumeric,
  ip_block: optionalNumber,
  phone_block: optionalNumber,
  account_block: optionalNumber,
  realname_block: optionalNumber,
  tgid_block: optionalNumber,
  title: text,
  content: text,
  agent_id: optionalNumber,
  prize_id: optionalNumber,
  prize_info: z.unknown().optional(),
  prize_expire_date: text,
  event_start_time: text,
  event_end_time: text,
  flow_limit_multiple: optionalNumeric,
  code: text,
  prize_contents: z.array(newInvitePrizeContentSchema).optional(),
})
export type NewInviteEvent = z.output<typeof newInviteEventSchema>

// ---------------------------------------------------------------- reminders

export const eventRemindSchema = z.looseObject({
  has_prize: z.boolean().optional(),
  count: z.coerce.number().optional(),
})
export type EventRemind = z.output<typeof eventRemindSchema>

export const prizeRemindSchema = z.looseObject({
  vip: z.boolean().optional(),
  event: z.boolean().optional(),
})
export type PrizeRemind = z.output<typeof prizeRemindSchema>

export const eventClaimRecordSchema = z.looseObject({
  record_source: z.string(),
  record_id: z.coerce.number(),
  event_name: text,
  prize_name: text,
  point: z.coerce.string(),
  claimed_at: z.string(),
})
export type EventClaimRecord = z.output<typeof eventClaimRecordSchema>

// ------------------------------------------------------------------ event-v2

export const eventV2DetailSchema = z.looseObject({
  content: text,
  join_withdraw: z.boolean().optional(),
  validate: z.boolean().optional(),
  message: z.string().optional(),
  bonus_code: z.array(z.looseObject({
    bonus_code: z.string(),
    created_at: z.string(),
  })).optional(),
  end_time: text,
})
export type EventV2Detail = z.output<typeof eventV2DetailSchema>

export const eventV2JoinSchema = z.looseObject({
  bonus_code: text,
  redirect_path: text,
  /** 這次參加拿到的獎勵金額（紅包畫面顯示的金額） */
  reward: optionalNumeric,
  /** true 已入主錢包；false 進活動錢包要打流水 */
  join_withdraw: z.boolean().optional(),
})
export type EventV2Join = z.output<typeof eventV2JoinSchema>

export const eventV2JoinDetailSchema = z.looseObject({
  event_detail_id: z.coerce.number(),
  balance_limit: z.coerce.number(),
  flow_limit: z.coerce.number(),
  balance: z.coerce.number(),
  flow: z.coerce.number(),
})
export type EventV2JoinDetail = z.output<typeof eventV2JoinDetailSchema>

/** 紅包畫面數字。null＝該限制沒設（不限）；能不能抽不在這裡，由 event-v2/join 失敗的 message 回。 */
export const angpowInfoSchema = z.looseObject({
  /** 在活動期間內且在每日可抽時段內；false 不展示紅包 */
  is_open: z.boolean(),
  /** 每抽一次扣幾分；null＝活動沒設（配錯，join 會擋） */
  score_cost: numeric.nullable(),
  score_balance: numeric,
  member_times_limit: z.number().int().nullable(),
  member_times_remaining: z.number().int().nullable(),
  site_times_limit: z.number().int().nullable(),
  site_times_remaining: z.number().int().nullable(),
  site_amount_limit: numeric.nullable(),
  site_amount_remaining: numeric.nullable(),
  interval: z.number().int().nullable(),
  /** 距下次可抽還要等幾秒，0＝現在可以 */
  next_available_in: z.number().int(),
  /** 每天可抽時段 [起, 迄]，已是站的時區；null＝全天 */
  time_window: z.tuple([z.string(), z.string()]).nullable(),
  in_time_window: z.boolean(),
})
export type AngpowInfo = z.output<typeof angpowInfoSchema>

// --------------------------------------------------------------------- prize

export const drawnPrizeSchema = prizeContentSchema.extend({
  prize_member: optionalNumber,
  get_point: optionalNumeric,
  point_type: optionalNumber,
})
export type DrawnPrize = z.output<typeof drawnPrizeSchema>

const prizeBaseFields = {
  id: optionalNumber,
  event_id: optionalNumber,
  type_id: optionalNumber,
  name: text,
  background: text,
  number: optionalNumber,
  expire_date: text,
  is_repeatedly: flagSchema.optional(),
  prizes: z.array(prizeContentSchema).optional(),
}

export const prizeDrawSchema = z.looseObject({
  ...prizeBaseFields,
  prize: drawnPrizeSchema,
})
export type PrizeDraw = z.output<typeof prizeDrawSchema>

export const prizeDrawAllSchema = z.looseObject({
  data: z.looseObject(prizeBaseFields).optional(),
  prize: z.array(drawnPrizeSchema),
})
export type PrizeDrawAll = z.output<typeof prizeDrawAllSchema>

export const prizeDrawByDetailSchema = z.looseObject({
  ...prizeBaseFields,
  prize: drawnPrizeSchema.optional(),
})

export const prizeRecordSchema = z.looseObject({
  id: z.coerce.number(),
  event_name: text,
  name: text,
  point: optionalNumeric,
  updated_at: text,
})
export type PrizeRecord = z.output<typeof prizeRecordSchema>

// ------------------------------------------------------------------- checkin

export const checkinPrizeSchema = z.looseObject({
  prize_type: z.string(),
  prize_amount: numeric,
})
export type CheckinPrize = z.output<typeof checkinPrizeSchema>

export const checkinRecordSchema = z.looseObject({
  day: z.coerce.number(),
  is_checkin: z.boolean(),
  checkin_day: z.boolean(),
  prize: z.array(checkinPrizeSchema).optional(),
})
export type CheckinRecord = z.output<typeof checkinRecordSchema>

// ------------------------------------------------------------------- mission

export const missionSchema = z.looseObject({
  id: z.coerce.number(),
  name: text,
  description: text,
  reset_type: text,
  action_type: z.string(),
  progress: z.coerce.string().optional(),
  is_claimed: flagSchema.optional(),
  reward_type: text,
  reward_value: optionalNumeric,
  requirement: z.coerce.string().optional(),
  game: z.array(z.looseObject({
    game_type: z.array(z.coerce.string()).optional(),
    vendor_id: z.array(z.coerce.string()).optional(),
  })).nullable().optional(),
})
export type Mission = z.output<typeof missionSchema>

// -------------------------------------------------------------- event-invite

export const eventInviteSettingSchema = z.looseObject({
  title: text,
  content: text,
  max_reward: optionalNumeric,
  invite_count: optionalNumber,
  total_point: optionalNumeric,
  total_receive: optionalNumeric,
  can_receive: optionalNumeric,
  deposit_point: optionalNumeric,
  bet_point: optionalNumeric,
  bet_reward: optionalNumeric,
  realname_reward: optionalNumeric,
  deposit_reward: optionalNumeric,
})
export type EventInviteSetting = z.output<typeof eventInviteSettingSchema>

export const eventInviteRewardCardSchema = z.looseObject({
  status: optionalNumber,
  created_at: text,
  username: text,
  realname_status: flagSchema.optional(),
  deposit_status: flagSchema.optional(),
  bet: optionalNumeric,
  point: optionalNumeric,
  receive: optionalNumeric,
})
export type EventInviteRewardCard = z.output<typeof eventInviteRewardCardSchema>

export const eventInviteListSchema = z.looseObject({
  setting: eventInviteSettingSchema.nullable().optional(),
  reward_card: z.array(eventInviteRewardCardSchema).nullable().optional(),
})
export type EventInviteList = z.output<typeof eventInviteListSchema>

// ---------------------------------------------------------- newbie-challenge

export const newbieChallengeDetailSchema = z.looseObject({
  name: text,
  content: text,
  activity_reward: optionalNumeric,
  challenge_flow_target: optionalNumeric,
  min_unlock_amount: optionalNumeric,
  max_unlock_amount: optionalNumeric,
  bonus_window_hours: optionalNumber,
  allowed_vendors: z.array(z.looseObject({
    id: z.coerce.number(),
    name: text,
  })).optional(),
})
export type NewbieChallengeDetail = z.output<typeof newbieChallengeDetailSchema>

export const newbieChallengeProgressSchema = z.looseObject({
  stage: z.coerce.number(),
  activity_reward: optionalNumeric,
  challenge_flow_target: optionalNumeric,
  challenge_flow_reach: optionalNumeric,
  challenge_flow_remaining: optionalNumeric,
  challenge_flow_percentage: optionalNumber,
  can_complete_challenge: z.boolean().optional(),
  unlocked_bonus_amount: optionalNumeric,
  bonus_window_expire_at: text,
  can_confirm_deposit: z.boolean().optional(),
  pending_bonus_amount: optionalNumeric,
  bonus_flow_target: optionalNumeric,
  bonus_flow_reach: optionalNumeric,
  bonus_flow_remaining: optionalNumeric,
  bonus_flow_percentage: optionalNumber,
  can_claim_bonus: z.boolean().optional(),
  principal_total: optionalNumeric,
  joined_at: text,
  event_wallet_balance: optionalNumeric,
  main_wallet_balance: optionalNumeric,
})
export type NewbieChallengeProgress = z.output<typeof newbieChallengeProgressSchema>

// ------------------------------------------------------------------ endpoints

export const eventsCoreEndpoints = {
  'event/list': defineEndpoint({
    path: 'event/list',
    auth: 'optional',
    description: '活動中心列表（V1／V2／第三方活動合併，後端已排序）',
    request: emptyRequest,
    response: z.array(eventListItemSchema).nullish(),
  }),
  'event/legacy-list': defineEndpoint({
    path: 'event/legacy-list',
    auth: 'member',
    description: '舊版活動列表（登入）',
    request: pageRequest,
    response: legacyEventListSchema,
  }),
  'event/legacy-list-by-domain': defineEndpoint({
    path: 'event/legacy-list-by-domain',
    auth: 'optional',
    description: '舊版活動列表（未登入，依 domain）',
    request: pageRequest,
    response: legacyEventListSchema,
  }),
  'event/legacy-detail': defineEndpoint({
    path: 'event/legacy-detail',
    auth: 'member',
    description: '舊版活動詳情（陣列固定一筆）',
    request: z.object({ id: z.coerce.number().int().min(1) }),
    response: z.array(legacyEventDetailResponseSchema),
  }),
  'event/legacy-validate': defineEndpoint({
    path: 'event/legacy-validate',
    auth: 'member',
    description: '可領獎狀態（action 60）',
    request: z.object({ event_id: z.coerce.number().int().min(1) }),
    response: emptyData,
  }),
  'event/new-invite': defineEndpoint({
    path: 'event/new-invite',
    auth: 'member',
    description: '邀請好友活動詳細（action 15）',
    request: z.object({ event_id: z.coerce.number().int().min(1) }),
    response: newInviteEventSchema,
  }),
  'event/new-invite/receive': defineEndpoint({
    path: 'event/new-invite/receive',
    auth: 'member',
    description: '邀請好友活動領獎',
    request: z.object({ prize_content_id: z.coerce.number().int().min(1) }),
    response: emptyData,
  }),
  'event/remind': defineEndpoint({
    path: 'event/remind',
    auth: 'member',
    description: '未領取獎勵提醒',
    request: emptyRequest,
    response: eventRemindSchema,
  }),
  'event/prize-remind': defineEndpoint({
    path: 'event/prize-remind',
    auth: 'member',
    description: '活動／VIP 入口紅點',
    request: emptyRequest,
    response: prizeRemindSchema,
  }),
  'event/claim-records': defineEndpoint({
    path: 'event/claim-records',
    auth: 'member',
    description: '領獎紀錄（含活動 V2）',
    request: z.object({ year_month: z.string().regex(/^\d{4}-\d{2}$/).optional() }),
    response: z.array(eventClaimRecordSchema),
  }),
  'event-v2/detail': defineEndpoint({
    path: 'event-v2/detail',
    auth: 'member',
    description: '活動 V2 詳情',
    request: z.object({ event_detail_id: z.coerce.number().int() }),
    response: eventV2DetailSchema,
  }),
  'event-v2/join': defineEndpoint({
    path: 'event-v2/join',
    auth: 'member',
    description: '活動 V2 參加',
    request: z.object({ event_detail_id: z.coerce.number().int() }),
    response: eventV2JoinSchema,
  }),
  'event-v2/angpow/info': defineEndpoint({
    path: 'event-v2/angpow/info',
    auth: 'member',
    description: '紅包畫面數字（扣分、可用積分、個人／全站剩餘、間隔倒數、可抽時段）；活動不是紅包回 400',
    request: z.object({ event_detail_id: z.coerce.number().int() }),
    response: angpowInfoSchema,
  }),
  'event-v2/join-detail': defineEndpoint({
    path: 'event-v2/join-detail',
    auth: 'member',
    description: '活動 V2 參加詳細（未參加回 501）',
    request: emptyRequest,
    response: eventV2JoinDetailSchema,
  }),
  'event-v2/withdraw': defineEndpoint({
    path: 'event-v2/withdraw',
    auth: 'member',
    description: '活動 V2 領取',
    request: emptyRequest,
    response: z.looseObject({ withdraw: z.coerce.number().optional() }),
  }),
  'event-v2/quit': defineEndpoint({
    path: 'event-v2/quit',
    auth: 'member',
    description: '活動 V2 放棄',
    request: emptyRequest,
    response: emptyData,
  }),
  'prize/list': defineEndpoint({
    path: 'prize/list',
    auth: 'member',
    description: '兌獎紀錄',
    request: pageRequest,
    response: paginatedSchema(prizeRecordSchema),
  }),
  'prize/draw': defineEndpoint({
    path: 'prize/draw',
    auth: 'member',
    description: '兌獎（單筆）',
    request: z.object({ event_id: z.coerce.number().int().min(1) }),
    response: prizeDrawSchema,
  }),
  'prize/draw-all': defineEndpoint({
    path: 'prize/draw-all',
    auth: 'member',
    description: '兌獎（一次領完）',
    request: z.object({ event_id: z.coerce.number().int().min(1) }),
    response: prizeDrawAllSchema,
  }),
  'prize/draw-by-detail': defineEndpoint({
    path: 'prize/draw-by-detail',
    auth: 'member',
    description: '兌獎（輸分回扣，依 detail）',
    request: z.object({
      event_id: z.coerce.number().int().min(1),
      detail_id: z.coerce.number().int().min(1),
    }),
    response: prizeDrawByDetailSchema,
  }),
  'prize/set': defineEndpoint({
    path: 'prize/set',
    auth: 'member',
    description: '兌獎回傳（後端相容空殼）',
    request: z.object({ prize_member_id: z.coerce.number().optional() }),
    response: emptyData,
  }),
  'prize/choose': defineEndpoint({
    path: 'prize/choose',
    auth: 'member',
    description: '自選獎項',
    request: z.object({
      event_id: z.coerce.number().int().min(1),
      prize_content_id: z.coerce.number().int().min(1),
    }),
    response: emptyData,
  }),
  'prize/cancel': defineEndpoint({
    path: 'prize/cancel',
    auth: 'member',
    description: '放棄未領取獎勵',
    request: emptyRequest,
    response: emptyData,
  }),
  'checkin/records': defineEndpoint({
    path: 'checkin/records',
    auth: 'member',
    description: '簽到列表',
    request: emptyRequest,
    response: z.array(checkinRecordSchema),
  }),
  'checkin': defineEndpoint({
    path: 'checkin',
    auth: 'member',
    description: '簽到領獎',
    request: emptyRequest,
    response: z.array(checkinPrizeSchema),
  }),
  'mission/list': defineEndpoint({
    path: 'mission/list',
    auth: 'member',
    description: '每日任務列表',
    request: emptyRequest,
    response: z.array(missionSchema),
  }),
  'mission/claim': defineEndpoint({
    path: 'mission/claim',
    auth: 'member',
    description: '每日任務領獎',
    request: z.object({ mission_id: z.coerce.number().int() }),
    response: emptyData,
  }),
  'event-invite/list': defineEndpoint({
    path: 'event-invite/list',
    auth: 'member',
    description: '邀請好友獎勵活動資訊',
    request: emptyRequest,
    response: eventInviteListSchema,
  }),
  'event-invite/receive': defineEndpoint({
    path: 'event-invite/receive',
    auth: 'member',
    description: '邀請好友獎勵領取',
    request: emptyRequest,
    response: eventInviteListSchema,
  }),
  'newbie-challenge/detail': defineEndpoint({
    path: 'newbie-challenge/detail',
    auth: 'member',
    description: '新人專享挑戰活動介紹',
    request: emptyRequest,
    response: newbieChallengeDetailSchema,
  }),
  'newbie-challenge/progress': defineEndpoint({
    path: 'newbie-challenge/progress',
    auth: 'member',
    description: '新人專享挑戰進度（未參加回 400）',
    request: emptyRequest,
    response: newbieChallengeProgressSchema,
  }),
  'newbie-challenge/join': defineEndpoint({
    path: 'newbie-challenge/join',
    auth: 'member',
    description: '領取活動金',
    request: emptyRequest,
    response: emptyData,
  }),
  'newbie-challenge/unlock-bonus': defineEndpoint({
    path: 'newbie-challenge/unlock-bonus',
    auth: 'member',
    description: '結算可解鎖彩金',
    request: emptyRequest,
    response: newbieChallengeProgressSchema,
  }),
  'newbie-challenge/confirm-deposit': defineEndpoint({
    path: 'newbie-challenge/confirm-deposit',
    auth: 'member',
    description: '核銷彩金',
    request: emptyRequest,
    response: newbieChallengeProgressSchema,
  }),
  'newbie-challenge/claim-bonus': defineEndpoint({
    path: 'newbie-challenge/claim-bonus',
    auth: 'member',
    description: '領取彩金',
    request: emptyRequest,
    response: newbieChallengeProgressSchema,
  }),
  'newbie-challenge/quit': defineEndpoint({
    path: 'newbie-challenge/quit',
    auth: 'member',
    description: '放棄挑戰',
    request: emptyRequest,
    response: newbieChallengeProgressSchema,
  }),
} satisfies EndpointMap
