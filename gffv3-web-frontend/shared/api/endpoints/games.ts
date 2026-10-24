import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

const text = z.string().nullable().optional()
const idOrIds = z.union([z.coerce.number().int(), z.array(z.coerce.number().int())])

/** lobby/vendors 的元素（VendorListResource），同一場館可因 item_type 1/2/3 重複出現。 */
export const lobbyVendorSchema = z.looseObject({
  item_type: z.coerce.number(),
  id: z.coerce.number(),
  game_id: z.coerce.number().nullable().optional(),
  type_id: z.coerce.number().nullable().optional(),
  path: text,
  name: text,
  image: text,
  front_page_image: text,
  app_icon: text,
  is_new: flagSchema.optional(),
  is_hot: flagSchema.optional(),
  is_event: flagSchema.optional(),
  only_one_game: flagSchema.optional(),
  need_login: flagSchema.optional(),
  giftcode: flagSchema.optional(),
  status: z.coerce.number(),
  currency_name: text,
  rate: z.coerce.number().optional(),
  maintenance_end_time: text,
})

/** game/list、list-by-types、recently-game-ids 的遊戲元素（ListResource）。 */
export const gameListItemSchema = z.looseObject({
  id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  vendor_name: text,
  type_id: z.coerce.number(),
  name: text,
  lottery_pool: text,
  image: text,
  is_new: flagSchema.optional(),
  is_hot: flagSchema.optional(),
  is_favourite: flagSchema.optional(),
  disable: flagSchema.optional(),
  is_maintenance: flagSchema.optional(),
  maintenance_end_time: text,
})

/** lobby/games 的遊戲元素（GamesByTagResource），多 rtp、無 is_favourite。 */
export const gamesByTagItemSchema = gameListItemSchema.extend({
  rtp: text,
})

/** game/show 的 ShowResource。 */
export const gameShowSchema = z.looseObject({
  id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  vendor_name: text,
  status: z.coerce.number(),
  type_id: z.coerce.number(),
  name: text,
  lottery_pool: text,
  image: text,
  is_new: flagSchema.optional(),
  is_hot: flagSchema.optional(),
  join_bet_event: flagSchema.optional(),
  join_win_event: flagSchema.optional(),
})

/** game/url 回應內 need_login 場館才有的 APP 登入資訊。 */
export const gameAppInfosSchema = z.looseObject({
  username: z.string(),
  password: [REDACTED],
  ios_downloads: z.array(z.string()).default([]),
  ios_open: text,
  android_downloads: z.array(z.string()).default([]),
  android_open: text,
  app_icon: text,
})

/** game/url 與 game/widget-url 的 GetGameUrlResource。 */
export const gameUrlSchema = z.looseObject({
  pc: text,
  mobile: text,
  open_window: flagSchema.optional(),
  app_infos: gameAppInfosSchema.optional(),
})

export const vendorGameTypeSchema = z.looseObject({
  vendor_id: z.coerce.number(),
  type_id: z.coerce.number(),
  root_type: z.coerce.number(),
})

/** game/list 用的是舊版 OnlyPaginateResource，previous / next 沒有時是空字串。 */
export const legacyPaginationSchema = z.looseObject({
  per_page: z.coerce.number(),
  total: z.coerce.number(),
  pages: z.coerce.number(),
  previous: z.union([z.number(), z.literal('')]).optional(),
  current: z.coerce.number(),
  next: z.union([z.number(), z.literal('')]).optional(),
})

export const promotionGameSchema = z.looseObject({
  id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  type_id: z.coerce.number(),
  rtp: text,
  name: text,
  lottery_pool: text,
  image: text,
  disable: flagSchema.optional(),
})

export const vendorEventGameSchema = z.looseObject({
  id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  vendor_name: text,
  type_id: z.coerce.number(),
  type_name: text,
  name: text,
  lottery_pool: text,
  image: text,
  is_new: flagSchema.optional(),
  is_hot: flagSchema.optional(),
  is_favourite: flagSchema.optional(),
})

export const vendorEventSchema = z.looseObject({
  id: z.coerce.number(),
  title: text,
  detail_id: z.coerce.number(),
  games: z.array(vendorEventGameSchema).optional(),
})

const gameUrlRequestSchema = z.object({
  vendor_id: z.coerce.number().int().optional(),
  game_id: z.coerce.number().int().optional(),
  exit_url: z.url(),
}).refine(v => v.vendor_id !== undefined || v.game_id !== undefined, {
  message: 'vendor_id or game_id is required',
  path: ['game_id'],
})

export type LobbyVendor = z.output<typeof lobbyVendorSchema>
export type GameListItem = z.output<typeof gameListItemSchema>
export type GamesByTagItem = z.output<typeof gamesByTagItemSchema>
export type GameShow = z.output<typeof gameShowSchema>
export type GameAppInfos = z.output<typeof gameAppInfosSchema>
export type GameUrl = z.output<typeof gameUrlSchema>
export type VendorGameType = z.output<typeof vendorGameTypeSchema>
export type LegacyPagination = z.output<typeof legacyPaginationSchema>
export type PromotionGame = z.output<typeof promotionGameSchema>
export type VendorEvent = z.output<typeof vendorEventSchema>

export const gamesEndpoints = {
  'lobby/vendors': defineEndpoint({
    path: 'lobby/vendors',
    auth: 'optional',
    description: '大廳場館列表；vendor_ids 取指定場館（回陣列取 [0]），type_id 限定指定類型項目',
    request: z.object({
      vendor_ids: z.array(z.coerce.number().int()).optional(),
      type_id: z.coerce.number().int().optional(),
    }),
    response: z.array(lobbyVendorSchema),
  }),

  'lobby/games': defineEndpoint({
    path: 'lobby/games',
    auth: 'optional',
    description: '依標籤（hot / new / recommend）取遊戲，不分頁',
    request: z.object({
      tag: z.string().min(1),
    }),
    response: z.array(gamesByTagItemSchema),
  }),

  'game/list': defineEndpoint({
    path: 'game/list',
    auth: 'optional',
    description: '遊戲分頁列表；Controller 讀 type_ids（非 type_id）；登入才有 is_favourite',
    request: z.object({
      vendor_id: idOrIds.optional(),
      type_ids: idOrIds.optional(),
      game_name: z.string().optional(),
      page: z.coerce.number().int().min(1),
      per_page: z.coerce.number().int().min(1),
    }),
    response: z.looseObject({
      games: z.array(gameListItemSchema),
      redirect_url: z.string().default(''),
      pagination: legacyPaginationSchema,
    }),
  }),

  'game/list-by-types': defineEndpoint({
    path: 'game/list-by-types',
    auth: 'optional',
    description: '每個類型各取前 N 款：type_counts = { [typeId]: count }',
    request: z.object({
      type_counts: z.record(z.string(), z.coerce.number().int().min(1)),
      vendor_ids: z.array(z.coerce.number().int()).optional(),
    }),
    response: z.looseObject({
      games: z.array(gameListItemSchema),
      redirect_url: z.string().default(''),
    }),
  }),

  'game/show': defineEndpoint({
    path: 'game/show',
    auth: 'member',
    withDomain: false,
    description: '單一遊戲資料（含場館狀態）；查無遊戲 code 400',
    request: z.object({
      game_id: z.coerce.number().int(),
    }),
    response: gameShowSchema,
  }),

  'game/url': defineEndpoint({
    path: 'game/url',
    auth: 'member',
    withDomain: false,
    description: '取遊戲網址；只給 vendor_id 時取該場館第一款遊戲；exit_url 必須含 scheme',
    request: gameUrlRequestSchema,
    response: gameUrlSchema,
  }),

  'game/widget-url': defineEndpoint({
    path: 'game/widget-url',
    auth: 'member',
    withDomain: false,
    description: '快捷投注元件網址（目前只有 SABA vendor 44）',
    request: gameUrlRequestSchema,
    response: gameUrlSchema,
  }),

  'game/recently-game-ids': defineEndpoint({
    path: 'game/recently-game-ids',
    auth: 'member',
    withDomain: false,
    description: '近 7 天啟動過的遊戲（會員取自 token，不讀參數）',
    request: z.object({
      member_id: z.coerce.number().int().optional(),
    }),
    response: z.looseObject({
      games: z.array(gameListItemSchema),
      redirect_url: z.string().default(''),
    }),
  }),

  'vendor/recently-vendor-ids': defineEndpoint({
    path: 'vendor/recently-vendor-ids',
    auth: 'member',
    withDomain: false,
    description: '近 7 天啟動過遊戲的場館；後端直接用 member_id 查，前端只能送 session 會員自己的 id',
    request: z.object({
      member_id: z.coerce.number().int(),
    }),
    response: z.array(z.looseObject({ vendor_id: z.coerce.number() })),
  }),

  'vendor/game-types': defineEndpoint({
    path: 'vendor/game-types',
    auth: 'optional',
    description: '代理可用的 場館 × 類型 對照（root_type 為根類型），後端快取 1 小時',
    request: z.object({}),
    response: z.array(vendorGameTypeSchema),
  }),

  'game/promotion-games': defineEndpoint({
    path: 'game/promotion-games',
    auth: 'member',
    withDomain: false,
    description: '近一個月投注過的遊戲 + 今日全平台投注過的遊戲',
    request: z.object({}),
    response: z.looseObject({
      recent_games: z.array(promotionGameSchema).default([]),
      recommend_games: z.array(promotionGameSchema).default([]),
    }),
  }),

  'vendor/reset-password': defineEndpoint({
    path: 'vendor/reset-password',
    auth: 'member',
    withDomain: false,
    description: '重設 need_login 場館的遊戲帳號密碼（英數 6~14 碼）',
    request: z.object({
      vendor_id: z.coerce.number().int(),
      new_password: [REDACTED],14}$/),
    }),
    response: z.array(z.unknown()),
  }),

  'event/vendor-games': defineEndpoint({
    path: 'event/vendor-games',
    auth: 'member',
    withDomain: false,
    description: '該場館參與中的活動與其遊戲（APP 場館頁用）',
    request: z.object({
      vendor_id: z.coerce.number().int().min(1),
    }),
    response: z.array(vendorEventSchema),
  }),
} satisfies EndpointMap
