import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'

const nullableText = z.string().nullable()
const nullableId = z.coerce.number().nullable()

/** basic-info 五個項目的 key，後端固定順序回傳，順序即為頁尾呈現順序。 */
export const BASIC_INFO_KEYS = ['about_us', 'terms', 'faq', 'privacy', 'responsible_gaming'] as const
export const basicInfoKeySchema = z.enum(BASIC_INFO_KEYS)
export type BasicInfoKey = z.infer<typeof basicInfoKeySchema>

export const basicInfoItemSchema = z.looseObject({
  key: basicInfoKeySchema,
  content: z.string(),
})
export type BasicInfoItem = z.infer<typeof basicInfoItemSchema>

export const basicInfoResponseSchema = z.looseObject({
  items: z.array(basicInfoItemSchema),
})

/** information / advertise 共用的連結型別（apis InformationTypeEnum）。 */
export const LINK_TYPE = {
  NONE: 0,
  GAME_TYPE: 1,
  GAME: 2,
  VENDOR: 3,
  EXTERNAL_URL: 4,
  INTERNAL_URL: 5,
} as const

const linkFields = {
  type: z.coerce.number(),
  game_type_id: nullableId.optional(),
  game_id: nullableId.optional(),
  vendor_id: nullableId.optional(),
  url: nullableText.optional(),
  open_window: flagSchema.optional(),
}

export const informationItemSchema = z.looseObject({
  id: z.coerce.number(),
  ...linkFields,
  image: nullableText.optional(),
  image_pc: nullableText.optional(),
  description: nullableText.optional(),
})
export type InformationItem = z.infer<typeof informationItemSchema>

/** advertise/list 的 path_id（廣告位置）。 */
/**
 * advertise/list 的 path_id。
 *
 * 新增 `HOME: 5` 的依據是舊專案 `_source2/src/store/modules/List/index.js:663-672` 的分支
 * 註解（那裡逐個 path_id commit 到不同 state，用途直接寫在註解裡），不是猜的：
 *   4 = 全版廣告（首頁那個**彈窗**，目前沒有任何地方在用 → 廣告彈窗還沒做）
 *   5 = 首頁廣告（首頁**上方的輪播 banner**）← 原本整個漏掉，hero 才會不見
 *   6 = VMOVIE
 *
 * 注意：拿 `FULL` 去要首頁輪播，後端會回空陣列，hero 整塊消失。
 * 實際打 `advertise/list` 回來的首頁 banner 是 `path_id: 5`。
 * `FULL` 維持 1 沒有動——`tests/unit/site-content.spec.ts` 鎖著它對齊後端 enum。
 */
export const BANNER_PATH = {
  FULL: 1,
  GAME_TYPE_INDEX: 2,
  GAME_INDEX: 3,
  HOME_POPUP: 4,
  HOME: 5,
  VMOVIE: 6,
  HOT_TAGS: 7,
  AGENT_POPUP: 10,
} as const

export const advertiseItemSchema = z.looseObject({
  id: z.coerce.number(),
  path_id: z.coerce.number(),
  ...linkFields,
  image: nullableText.optional(),
  image_pc: nullableText.optional(),
})
export type AdvertiseItem = z.infer<typeof advertiseItemSchema>

/** marquee/texts 的 location（apis MarqueeTextLocationEnum）：1 大廳、2 聊天室，不帶＝全部。 */
export const MARQUEE_LOCATION = { LOBBY: 1, CHAT: 2 } as const
export const marqueeLocationSchema = z.union([z.literal(1), z.literal(2)])
export type MarqueeLocation = z.infer<typeof marqueeLocationSchema>

export const marqueeTextSchema = z.looseObject({
  id: z.coerce.number(),
  type: z.coerce.number().nullable().optional(),
  location: z.coerce.number(),
  content: z.string(),
})
export type MarqueeText = z.infer<typeof marqueeTextSchema>

export const winPointItemSchema = z.looseObject({
  id: z.coerce.number(),
  win: z.coerce.string(),
  username: z.string(),
  game_name: nullableText.optional(),
  game_id: z.coerce.number(),
  vendor_id: z.coerce.number(),
  image: nullableText.optional(),
})
export type WinPointItem = z.infer<typeof winPointItemSchema>

const versionPlatformSchema = z.looseObject({
  version: nullableText.optional(),
  downloadLink: nullableText.optional(),
})
export const versionsResponseSchema = z.looseObject({
  ios: versionPlatformSchema,
  android: versionPlatformSchema,
}).nullable()
export type VersionsResponse = z.infer<typeof versionsResponseSchema>

export const videoAccountSchema = z.looseObject({
  username: z.string(),
  password: [REDACTED],
  ios_download: z.string(),
  ios_open: z.string(),
  android_download: z.string(),
  android_open: z.string(),
  app_icon: z.string(),
})
export type VideoAccount = z.infer<typeof videoAccountSchema>

export const countryItemSchema = z.looseObject({
  id: z.coerce.number(),
  code: z.string(),
  name: z.string(),
  country_code: z.coerce.string(),
  phone_rule: nullableText.optional(),
})
export type CountryItem = z.infer<typeof countryItemSchema>

export const currencyItemSchema = z.looseObject({
  id: z.coerce.number(),
  code: z.string(),
  name: z.string(),
  rate: z.union([z.string(), z.number()]).nullable().optional(),
})
export type CurrencyItem = z.infer<typeof currencyItemSchema>

export const analyticsResponseSchema = z.looseObject({
  type: z.string(),
  code: z.string(),
})
export type AnalyticsResponse = z.infer<typeof analyticsResponseSchema>

export const siteEndpoints = {
  'agent/analytics': defineEndpoint({
    path: 'agent/analytics',
    auth: 'optional',
    description: '代理啟用中的分析代碼；無則 type/code 皆為空字串',
    request: z.object({}),
    response: analyticsResponseSchema,
  }),
  'basic-info': defineEndpoint({
    path: 'basic-info',
    auth: 'optional',
    description: '頁尾基本資訊五項長文（關於我們／條款／常見問題／隱私／博彩責任），關閉或無內容者 content 為空字串',
    request: z.object({}),
    response: basicInfoResponseSchema,
  }),
  'information/list': defineEndpoint({
    path: 'information/list',
    auth: 'optional',
    description: '大廳資訊按鈕列表；無 agent 時 data 為空陣列',
    request: z.object({}),
    response: z.array(informationItemSchema),
  }),
  'advertise/list': defineEndpoint({
    path: 'advertise/list',
    auth: 'optional',
    description: '指定廣告位置的橫幅列表，依 sort DESC、id DESC',
    request: z.object({
      path_id: z.coerce.number().int().min(0),
    }),
    response: z.array(advertiseItemSchema),
  }),
  'marquee/texts': defineEndpoint({
    path: 'marquee/texts',
    auth: 'optional',
    description: '文字跑馬燈；location 1 大廳、2 聊天室、不帶＝全部',
    request: z.object({
      location: marqueeLocationSchema.optional(),
    }),
    response: z.array(marqueeTextSchema),
  }),
  'marquee/win-points': defineEndpoint({
    path: 'marquee/win-points',
    auth: 'optional',
    description: '贏分跑馬燈，最多 30 筆、payout_time DESC',
    request: z.object({}),
    response: z.array(winPointItemSchema),
  }),
  'versions': defineEndpoint({
    path: 'versions',
    auth: 'optional',
    description: 'APP 版本與下載連結（沿代理鏈取最近一筆）',
    request: z.object({}),
    response: versionsResponseSchema,
  }),
  'videos': defineEndpoint({
    path: 'videos',
    auth: 'member',
    withDomain: false,
    description: '影音三方帳號與含 session 的開啟連結',
    request: z.object({}),
    response: z.array(videoAccountSchema),
  }),
  'country/list': defineEndpoint({
    path: 'country/list',
    auth: 'optional',
    description: '依域名代理簡訊閘道決定的可用國家（國碼與手機格式正則）',
    request: z.object({}),
    response: z.array(countryItemSchema),
  }),
  'currency/list': defineEndpoint({
    path: 'currency/list',
    auth: 'optional',
    withDomain: false,
    description: '啟用中的幣種列表（名稱依語系）',
    request: z.object({}),
    response: z.array(currencyItemSchema),
  }),
} satisfies EndpointMap
