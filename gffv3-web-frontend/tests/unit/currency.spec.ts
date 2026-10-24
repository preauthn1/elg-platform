import { describe, expect, it } from 'vitest'
import { currencyImageUrl } from '../../shared/utils/currency'

describe('currencyImageUrl', () => {
  it('依幣別 id 組出 CDN 圖示網址（與舊前台 $getCurrencyImageById 相同）', () => {
    expect(currencyImageUrl(2)).toBe('https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png')
    expect(currencyImageUrl('1')).toBe('https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png')
  })

  it('沒有幣別（0／空值）回 null', () => {
    expect(currencyImageUrl(0)).toBeNull()
    expect(currencyImageUrl(null)).toBeNull()
    expect(currencyImageUrl(undefined)).toBeNull()
    expect(currencyImageUrl('')).toBeNull()
  })
})
