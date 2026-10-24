import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { paginatedSchema, paginationSchema } from '#shared/types/api'
import { flagSchema } from '#shared/types/site'

/** apis ApiResponse::success() 沒帶資料時 data = []。 */
const emptyData = z.array(z.unknown())
const text = z.string().nullable().optional()

const pageRequest = z.object({
  page: z.coerce.number().int().min(1).optional(),
  per_page: z.coerce.number().int().min(1).optional(),
})

const unreadResponse = z.looseObject({
  unread: z.coerce.number(),
})

export const announcementMessageSchema = z.looseObject({
  announcement_message_id: z.coerce.number(),
  title: z.string(),
  message: z.string(),
  created_at: z.string().optional(),
})

export const platformAnnouncementItemSchema = z.looseObject({
  id: z.coerce.number(),
  pal_id: z.coerce.number().nullable().optional(),
  sort: z.coerce.number().optional(),
  /** 1 最新 2 熱門 3 活動 4 遊戲 5 維護 */
  tag: z.coerce.number(),
  /** 0 無連結 1 彈窗 2 站外連結 3 站內連結 */
  type: z.coerce.number(),
  title: z.string(),
  content: text,
  open_window: flagSchema.optional(),
  image_url: text,
  display_time: z.string().optional(),
  created_at: z.string().optional(),
})

export const systemNotifyItemSchema = z.looseObject({
  data_name: z.enum([
    'orders',
    'withdrawal_orders',
    'transaction_logs',
    'rolling_chip_logs',
    'member_binding_logs',
    'realname_validation_requests',
  ]),
  data_id: z.coerce.number(),
  tag: z.enum(['orders', 'reward', 'system', 'transaction', '']),
  type: z.union([z.literal(''), z.coerce.number()]),
  content: z.string().nullable().optional(),
  content_1: text,
  content_2: text,
  currency_name: text,
  amount: z.coerce.number().optional(),
  point: z.coerce.number().optional(),
  status: z.union([z.enum(['success', 'fail', 'pending']), z.literal('')]).optional(),
  /** 0 現金 1 禮碼 2 綁定／實名 */
  data_type: z.coerce.number(),
  time: z.string(),
})

export const commissionRowSchema = z.looseObject({
  from_member_name: z.string().nullable().optional(),
  vendor_name: z.string().nullable().optional(),
  game_type_id: z.coerce.number().nullable().optional(),
  bet: z.coerce.number().optional(),
  lose: z.coerce.number().optional(),
  rebate: z.coerce.number().optional(),
  /** 1 依流水 2 依輸分 */
  rebate_type: z.coerce.number().optional(),
})

const commissionDetailSchema = z.looseObject({
  items: z.array(commissionRowSchema).optional(),
  pagination: paginationSchema.optional(),
  sum_data: z.looseObject({
    bet: z.coerce.number().optional(),
    lose: z.coerce.number().optional(),
    rebate: z.coerce.number().optional(),
  }).optional(),
  time: z.string().optional(),
})

/** transaction_logs/17 與 37、38 平鋪；rolling_chip_logs/4 多包一層 data。 */
export const systemNotifyDetailSchema = commissionDetailSchema.extend({
  sender: z.string().nullable().optional(),
  receiver: z.string().nullable().optional(),
  amount: z.coerce.number().optional(),
  tax: z.coerce.number().optional(),
  data: commissionDetailSchema.optional(),
})

export const memberMailItemSchema = z.looseObject({
  id: z.coerce.number(),
  title: z.string(),
  content: z.string(),
  publish_time: z.string(),
  is_read: flagSchema.optional(),
})

export type AnnouncementMessage = z.infer<typeof announcementMessageSchema>
export type PlatformAnnouncementItem = z.infer<typeof platformAnnouncementItemSchema>
export type SystemNotifyItem = z.infer<typeof systemNotifyItemSchema>
export type CommissionRow = z.infer<typeof commissionRowSchema>
export type MemberMailItem = z.infer<typeof memberMailItemSchema>

export const messagesEndpoints = {
  'announcement-message/latest': defineEndpoint({
    path: 'announcement-message/latest',
    auth: 'optional',
    description: '最新一筆未讀群發公告；沒有時 data = []',
    request: z.object({}),
    response: z.union([announcementMessageSchema, emptyData]),
  }),
  'announcement-message/read': defineEndpoint({
    path: 'announcement-message/read',
    auth: 'optional',
    description: '群發公告標記已讀（未登入為 no-op）',
    request: z.object({
      announcement_message_id: z.coerce.number().int(),
    }),
    response: emptyData,
  }),
  'platform-announcement/list': defineEndpoint({
    path: 'platform-announcement/list',
    auth: 'member',
    description: '平台公告（最新消息）分頁列表',
    request: pageRequest,
    response: paginatedSchema(platformAnnouncementItemSchema),
  }),
  'platform-announcement/read-all': defineEndpoint({
    path: 'platform-announcement/read-all',
    auth: 'member',
    description: '平台公告全部已讀',
    request: z.object({}),
    response: z.looseObject({
      lastReadTimeUtc: z.string().optional(),
    }),
  }),
  'platform-announcement/unread': defineEndpoint({
    path: 'platform-announcement/unread',
    auth: 'member',
    description: '平台公告未讀數',
    request: z.object({}),
    response: unreadResponse,
  }),
  'system-notify/list': defineEndpoint({
    path: 'system-notify/list',
    auth: 'member',
    description: '系統通知（三天內存提款／交易／禮碼／綁定／實名）分頁列表',
    request: pageRequest,
    response: paginatedSchema(systemNotifyItemSchema),
  }),
  'system-notify/read-all': defineEndpoint({
    path: 'system-notify/read-all',
    auth: 'member',
    description: '系統通知全部已讀',
    request: z.object({}),
    response: emptyData,
  }),
  'system-notify/detail': defineEndpoint({
    path: 'system-notify/detail',
    auth: 'member',
    description: '系統通知詳情：傭金明細（transaction_logs/17、rolling_chip_logs/4）與轉帳（transaction_logs/37、38）',
    request: z.object({
      data_name: z.enum(['transaction_logs', 'rolling_chip_logs']),
      data_id: z.coerce.number(),
      type: z.coerce.number(),
      page: z.coerce.number().int().min(1),
      per_page: z.coerce.number().int().min(1),
    }),
    response: z.union([systemNotifyDetailSchema, emptyData]),
  }),
  'system-notify/unread': defineEndpoint({
    path: 'system-notify/unread',
    auth: 'member',
    description: '系統通知未讀數',
    request: z.object({}),
    response: unreadResponse,
  }),
  'member-mail/list': defineEndpoint({
    path: 'member-mail/list',
    auth: 'member',
    description: '站內信分頁列表（內容取 Accept-Language 語系）',
    request: z.object({
      page: z.coerce.number().int().min(1).optional(),
      per_page: z.coerce.number().int().min(1).max(50).optional(),
    }),
    response: paginatedSchema(memberMailItemSchema),
  }),
  'member-mail/read': defineEndpoint({
    path: 'member-mail/read',
    auth: 'member',
    description: '站內信單封已讀（重複呼叫視為成功）',
    request: z.object({
      id: z.coerce.number().int().min(1),
    }),
    response: emptyData,
  }),
  'member-mail/read-all': defineEndpoint({
    path: 'member-mail/read-all',
    auth: 'member',
    description: '站內信全部已讀',
    request: z.object({}),
    response: emptyData,
  }),
  'member-mail/unread': defineEndpoint({
    path: 'member-mail/unread',
    auth: 'member',
    description: '站內信未讀數',
    request: z.object({}),
    response: unreadResponse,
  }),
} satisfies EndpointMap
