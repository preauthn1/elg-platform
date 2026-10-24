const CURRENCY_IMAGE_BASE = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency'

/**
 * 幣別圖示（圓形國旗）網址，對應舊前台的 `$getCurrencyImageById`。
 * currency/list 只回 id 與名稱，圖示一律依 id 從 CDN 取；id 為 0／空值代表沒有幣別，回 null。
 */
export function currencyImageUrl(id: number | string | null | undefined): string | null {
  if (!id) return null
  return `${CURRENCY_IMAGE_BASE}/${id}.png`
}
