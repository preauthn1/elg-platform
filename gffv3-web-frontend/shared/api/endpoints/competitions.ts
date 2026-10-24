import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

/** apis 規則 required|integer|min:1（event_id / no / vendor_id / game_id） */
const positiveInt = z.coerce.number().int().min(1)
/** 排行榜分頁：nullable|integer|min:1；per_page 另有 max:100，未帶時後端以獎項總數當每頁筆數 */
const page = z.coerce.number().int().min(1).optional()
const perPage = z.coerce.number().int().min(1).max(100).optional()
/** 金額欄位後端多為字串（TRUNCATE 2 位），部分端點回數字，顯示層再統一格式 */
const amount = z.union([z.string(), z.number()])
/** 'YYYY-MM-DD HH:mm:ss'，後端已轉成前台時間（+8h） */
const dateTime = z.string()
const nullableText = z.string().nullable().optional()

/** 排行榜區間；1 即時、2 日、3 週、4 月 */
export const rankingTypeSchema = z.union([z.literal(1), z.literal(2), z.literal(3), z.literal(4)])
export type RankingType = z.output<typeof rankingTypeSchema>

const emptyRequest = z.object({})
const eventIdRequest = z.object({ event_id: positiveInt })
const leaderBoardRequest = z.object({
  event_id: positiveInt,
  no: positiveInt,
  page,
  per_page: perPage,
})
const rankingTypeRequest = z.object({ type: rankingTypeSchema })

export const seasonSchema = z.looseObject({
  event_id: z.coerce.number(),
  id: z.coerce.number(),
  no: z.coerce.number(),
  start_time: dateTime,
  end_time: dateTime,
})
export type Season = z.output<typeof seasonSchema>

export const refreshRankingSeasonSchema = seasonSchema.extend({
  my_bet_amount: amount.optional(),
})
export type RefreshRankingSeason = z.output<typeof refreshRankingSeasonSchema>

/** 排行榜列；自己不在名次內時後端補在最後一筆，ranking 為 '--' */
export const leaderBoardRowSchema = z.looseObject({
  ranking: z.union([z.coerce.number(), z.literal('--')]),
  username: z.string(),
  bet_amount: amount,
  last_bet_time: nullableText,
})
export type LeaderBoardRow = z.output<typeof leaderBoardRowSchema>

/** 我的排名列；left join，沒有紀錄的期數欄位為 null */
export const slotRankingRowSchema = z.looseObject({
  event_id: z.coerce.number(),
  no: z.coerce.number(),
  draw_time: nullableText,
  start_time: dateTime,
  end_time: dateTime,
  bet_amount: amount.nullable().optional(),
  ranking: z.number().nullable().optional(),
  type: z.number().nullable().optional(),
  status: z.number().nullable().optional(),
  last_bet_time: nullableText,
  point: amount.nullable().optional(),
})
export type SlotRankingRow = z.output<typeof slotRankingRowSchema>

export const prizeSchema = z.looseObject({
  event_id: z.coerce.number(),
  name: z.string(),
  level: z.coerce.number(),
  number: z.coerce.number(),
  point: amount,
})
export type Prize = z.output<typeof prizeSchema>

export const awardsSchema = z.looseObject({
  content: z.string().nullable(),
  prizes: z.array(prizeSchema),
})
export type Awards = z.output<typeof awardsSchema>

export const eventVendorSchema = z.looseObject({
  id: z.coerce.number(),
  name: z.string(),
  image: nullableText,
  app_icon: nullableText,
  only_one_game: flagSchema.optional(),
  need_login: flagSchema.optional(),
  status: z.coerce.number(),
})
export type EventVendor = z.output<typeof eventVendorSchema>

export const eventGameSchema = z.looseObject({
  id: z.coerce.number(),
  name: z.string(),
  image: nullableText,
  type_id: z.coerce.number(),
  is_hot: flagSchema.optional(),
  is_new: flagSchema.optional(),
  disable: flagSchema.optional(),
  is_maintenance: flagSchema.optional(),
  maintenance_end_time: nullableText,
})
export type EventGame = z.output<typeof eventGameSchema>

export const gameUrlSchema = z.looseObject({
  pc: z.string().nullable().optional(),
  mobile: z.string().nullable().optional(),
  open_window: flagSchema,
  app_infos: z.unknown().optional(),
})
export type GameUrl = z.output<typeof gameUrlSchema>

export const winningRowSchema = z.looseObject({
  username: z.string(),
  payout_time: dateTime,
  win_lose: amount,
  game_name: nullableText,
  vendor_name: nullableText,
})
export type WinningRow = z.output<typeof winningRowSchema>

export const platformBettingRowSchema = z.looseObject({
  sum_bet: amount,
  username: z.string(),
  rank: z.coerce.number(),
})
export type PlatformBettingRow = z.output<typeof platformBettingRowSchema>

export const profitRowSchema = z.looseObject({
  username: z.string(),
  image: nullableText,
  total_win_lose: amount,
  rank: z.coerce.number(),
})
export type ProfitRow = z.output<typeof profitRowSchema>

export const gameWinnerRowSchema = profitRowSchema.extend({
  vendor_name: nullableText,
})
export type GameWinnerRow = z.output<typeof gameWinnerRowSchema>

export const competitionsEndpoints = {
  'event/slot-competition/list': defineEndpoint({
    path: 'event/slot-competition/list',
    auth: 'member',
    description: '老虎機爭霸賽期數列表（no 由新到舊）',
    request: emptyRequest,
    response: z.array(seasonSchema),
  }),
  'event/slot-competition/leader-board': defineEndpoint({
    path: 'event/slot-competition/leader-board',
    auth: 'member',
    description: '老虎機爭霸賽排行榜；自己不在名次內時補在最後一筆',
    request: leaderBoardRequest,
    response: z.array(leaderBoardRowSchema),
  }),
  'event/slot-competition/ranking': defineEndpoint({
    path: 'event/slot-competition/ranking',
    auth: 'member',
    description: '老虎機爭霸賽我的排名（已開獎期數）',
    request: eventIdRequest,
    response: z.array(slotRankingRowSchema),
  }),
  'event/slot-competition/receive': defineEndpoint({
    path: 'event/slot-competition/receive',
    auth: 'member',
    description: '老虎機爭霸賽領取獎項；成功與失敗都以 message 呈現',
    request: z.object({ event_id: positiveInt, no: positiveInt }),
    response: z.unknown(),
  }),
  'event/slot-competition/awards': defineEndpoint({
    path: 'event/slot-competition/awards',
    auth: 'member',
    description: '老虎機爭霸賽規則內容與獎項表',
    request: eventIdRequest,
    response: awardsSchema,
  }),
  'event/refresh-ranking/list': defineEndpoint({
    path: 'event/refresh-ranking/list',
    auth: 'member',
    description: '刷分排行榜期數列表（含自己該期投注量）',
    request: emptyRequest,
    response: z.array(refreshRankingSeasonSchema),
  }),
  'event/refresh-ranking/leader-board': defineEndpoint({
    path: 'event/refresh-ranking/leader-board',
    auth: 'member',
    description: '刷分排行榜排行榜；自己不在名次內時補在最後一筆',
    request: leaderBoardRequest,
    response: z.array(leaderBoardRowSchema),
  }),
  'event/refresh-ranking/awards': defineEndpoint({
    path: 'event/refresh-ranking/awards',
    auth: 'member',
    description: '刷分排行榜規則內容與獎項表',
    request: eventIdRequest,
    response: awardsSchema,
  }),
  'event/refresh-ranking/wallet-balance': defineEndpoint({
    path: 'event/refresh-ranking/wallet-balance',
    auth: 'member',
    description: '刷分排行榜活動錢包餘額；不吃任何參數',
    request: emptyRequest,
    response: z.looseObject({ balance: amount }),
  }),
  'event/refresh-ranking/vendor': defineEndpoint({
    path: 'event/refresh-ranking/vendor',
    auth: 'member',
    description: '刷分排行榜可玩遊戲商；status 1 才可點',
    request: emptyRequest,
    response: z.array(eventVendorSchema),
  }),
  'event/refresh-ranking/game': defineEndpoint({
    path: 'event/refresh-ranking/game',
    auth: 'member',
    description: '刷分排行榜指定遊戲商的遊戲列表（無分頁）',
    request: z.object({ vendor_id: positiveInt }),
    response: z.array(eventGameSchema),
  }),
  'event/refresh-ranking/game-url': defineEndpoint({
    path: 'event/refresh-ranking/game-url',
    auth: 'member',
    description: '刷分排行榜啟動遊戲；exit_url 必須是完整網址',
    request: z.object({ game_id: positiveInt, exit_url: z.url() }),
    response: gameUrlSchema,
  }),
  'ranking/winning': defineEndpoint({
    path: 'ranking/winning',
    auth: 'member',
    description: '中獎排行榜（最多 30 筆，伺服器快取 300 秒）',
    request: rankingTypeRequest,
    response: z.array(winningRowSchema),
  }),
  'ranking/platform-betting': defineEndpoint({
    path: 'ranking/platform-betting',
    auth: 'member',
    description: '平台投注排行榜（最多 30 筆，type 1/2 都是前一日）',
    request: rankingTypeRequest,
    response: z.array(platformBettingRowSchema),
  }),
  'ranking/profits': defineEndpoint({
    path: 'ranking/profits',
    auth: 'member',
    description: '今日盈利榜（固定 30 筆，不足以假資料補齊）',
    request: emptyRequest,
    response: z.array(profitRowSchema),
  }),
  'ranking/game-winners': defineEndpoint({
    path: 'ranking/game-winners',
    auth: 'member',
    description: '今日遊戲贏家榜（固定 30 筆，不足以假資料補齊）',
    request: emptyRequest,
    response: z.array(gameWinnerRowSchema),
  }),
} satisfies EndpointMap
