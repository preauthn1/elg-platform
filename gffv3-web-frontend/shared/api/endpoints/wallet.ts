import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { paginatedSchema } from '#shared/types/api'

/** 後端金額欄位一律 floor 到 4 位小數的字串，少數欄位（point、amount）會以數字回來。 */
const amount = z.union([z.string(), z.number()])

/** 前台時區的 Y-m-d H:i:s。 */
const dateTime = z.string().regex(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/, 'Y-m-d H:i:s')

/** TransferBackResource：transfer / transfer-all / transfer-back-recent 共用。 */
export const transferBackSchema = z.looseObject({
  balance: amount,
  game_wallet_balance: amount,
  rolling_chip: amount,
  vendor_balance: amount,
})

export type TransferBackData = z.infer<typeof transferBackSchema>

/** WalletLogsRequest：main-logs / game-logs / histories 共用。 */
const walletLogsRequestSchema = z.object({
  wallet_type: z.enum(['main', 'game']).optional(),
  start_time: dateTime.optional(),
  end_time: dateTime.optional(),
  page: z.coerce.number().int().positive().optional(),
  per_page: z.coerce.number().int().positive().optional(),
})

/** MainWalletLogResource / GameWalletLogResource：欄位相同，type/status 已是翻譯字串。 */
export const walletLogItemSchema = z.looseObject({
  id: z.coerce.number(),
  created_at: z.string(),
  before_balance: amount,
  point: amount,
  balance: amount,
  type: z.string(),
  status: z.string(),
})

export type WalletLogItem = z.infer<typeof walletLogItemSchema>

const walletLogsResponseSchema = paginatedSchema(walletLogItemSchema)

export const walletEndpoints = {
  'member/transfer': defineEndpoint({
    path: 'member/transfer',
    auth: 'member',
    description: '轉回上一家遊戲商並刷新餘額；帶 vendor_id > 0 時再把主錢包全額轉入該遊戲商',
    request: z.object({
      vendor_id: z.coerce.number().int().optional(),
    }),
    response: transferBackSchema,
  }),

  'member/transfer-all': defineEndpoint({
    path: 'member/transfer-all',
    auth: 'member',
    description: '重新整理餘額：漸進式把遊戲商餘額轉回主錢包',
    request: z.object({}),
    response: transferBackSchema,
  }),

  'member/transfer-back-recent': defineEndpoint({
    path: 'member/transfer-back-recent',
    auth: 'member',
    description: '一次轉回近三個月有轉入紀錄的所有遊戲商',
    request: z.object({}),
    response: transferBackSchema,
  }),

  'member/check-transfer-status': defineEndpoint({
    path: 'member/check-transfer-status',
    auth: 'member',
    description: '依遊戲商轉帳單號查轉帳結果',
    request: z.object({
      vendor_id: z.coerce.number().int().positive(),
      transaction_no: z.string().min(1),
    }),
    response: z.looseObject({
      result: z.string(),
    }),
  }),

  'member/vendor-balance': defineEndpoint({
    path: 'member/vendor-balance',
    auth: 'member',
    description: '單一轉帳錢包遊戲商的餘額（已換算回平台幣別）',
    request: z.object({
      vendor_id: z.coerce.number().int(),
    }),
    response: z.looseObject({
      vendor_balance: amount,
    }),
  }),

  'member/vendors': defineEndpoint({
    path: 'member/vendors',
    auth: 'member',
    description: '會員已有帳號且啟用中的遊戲商清單',
    request: z.object({}),
    response: z.array(z.looseObject({
      vendor_id: z.coerce.number(),
      vendor_name: z.string(),
    })),
  }),

  'wallet/main-logs': defineEndpoint({
    path: 'wallet/main-logs',
    auth: 'member',
    description: '主錢包交易紀錄（分頁）',
    request: walletLogsRequestSchema,
    response: walletLogsResponseSchema,
  }),

  'wallet/game-logs': defineEndpoint({
    path: 'wallet/game-logs',
    auth: 'member',
    description: '遊戲錢包交易紀錄（分頁）',
    request: walletLogsRequestSchema,
    response: walletLogsResponseSchema,
  }),

  'wallet/histories': defineEndpoint({
    path: 'wallet/histories',
    auth: 'member',
    description: '錢包交易紀錄，wallet_type 切主錢包／遊戲錢包（分頁）',
    request: walletLogsRequestSchema,
    response: walletLogsResponseSchema,
  }),

  'wallet/transfer': defineEndpoint({
    path: 'wallet/transfer',
    auth: 'member',
    description: '主錢包與遊戲錢包互轉：type 1 主→遊戲、2 遊戲→主',
    request: z.object({
      type: z.coerce.number().pipe(z.union([z.literal(1), z.literal(2)])).optional(),
      amount: z.coerce.number().positive(),
    }),
    response: z.unknown(),
  }),

  'coupon/redeem': defineEndpoint({
    path: 'coupon/redeem',
    auth: 'member',
    description: '兌換優惠碼',
    request: z.object({
      code: z.string().min(1).max(10).regex(/^[A-Za-z0-9]+$/),
    }),
    response: z.looseObject({
      amount: amount,
      type: z.coerce.number(),
      image_url: z.string().nullable().optional(),
    }),
  }),
} satisfies EndpointMap
