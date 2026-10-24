import { z } from 'zod'

/** apis 統一回應信封：code 200 成功，其餘為業務碼；tid 為後端 request id，對帳用。 */
export interface ApiEnvelope<T = unknown> {
  code: number
  message: string
  data: T
  tid?: string
}

export function apiEnvelopeSchema<T extends z.ZodTypeAny>(data: T) {
  return z.object({
    code: z.number(),
    message: z.string(),
    data,
    tid: z.string().optional(),
  })
}

/** 頁碼欄位：apis OnlyPaginateResource 沒有上一頁／下一頁時回空字串 ''。 */
const pageNumber = z.union([z.coerce.number(), z.literal('')]).optional()

/**
 * apis successWithPagination 的 data 形狀：{ items, pagination }。
 * pagination 鍵名照 apis OnlyPaginateResource：per_page / total / pages / previous / current / next。
 */
export const paginationSchema = z.looseObject({
  per_page: z.coerce.number().optional(),
  total: z.coerce.number().optional(),
  pages: z.coerce.number().optional(),
  previous: pageNumber,
  current: z.coerce.number().optional(),
  next: pageNumber,
})

export function paginatedSchema<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    items: z.array(item),
    pagination: paginationSchema,
  })
}

export type Pagination = z.infer<typeof paginationSchema>
