import type {
  BrandSource,
  CustomizedSetting,
  DeviceType,
  ProcessType,
  SeoMetaEntry,
  Site,
  TemplateCode,
  TemplateResponse,
} from '#shared/types/site'

/**
 * 模板代碼拆解。規則沿用舊前台：去掉 -N 流程尾碼後，數字部分最後兩碼是款式，
 * 其餘是皮（C911 → C9 / 11；C1622 → C16 / 22；C16 → C16 / 無款式）。
 */
export function parseTemplateCode(input: string | null | undefined): TemplateCode {
  const code = (input ?? '').trim()
  const match = /^([A-Za-z]+)(\d*)(?:-(\d+))?$/.exec(code)
  if (!match) {
    return { code, skin: code, variant: '', process: 'P1' }
  }
  const prefix = match[1] ?? ''
  const digits = match[2] ?? ''
  const proc = Number(match[3] ?? '1')
  let skin = prefix + digits
  let variant = ''
  if (digits.length > 2) {
    skin = prefix + digits.slice(0, digits.length - 2)
    variant = digits.slice(-2)
  }
  const process: ProcessType = proc >= 1 && proc <= 4 ? (`P${proc}` as ProcessType) : 'P1'
  return { code, skin, variant, process }
}

/** 裝置判斷，與 apis DetectDevice::checkIsMobile 同一套規則。 */
export function detectDevice(userAgent: string | null | undefined): DeviceType {
  const ua = (userAgent ?? '').toLowerCase()
  if (!ua) return 'pc'
  const has = (s: string) => ua.includes(s.toLowerCase())
  const androidMobile = has('android') && has('mobile')
  const androidTablet = has('android') && !androidMobile
  if (has('ipad') || androidTablet || has('rim tablet')) return 'pad'
  if (has('ipod') || has('iphone') || androidMobile || has('webos') || has('blackberry')) return 'mobile'
  return 'pc'
}

export interface BrandConfig {
  brandField: string
  hostBrandMap: Record<string, string>
  defaultBrand: string
}

/** 品牌三層解析：template 欄位 → host 對照表 → 部署預設值。 */
export function resolveBrand(
  raw: Record<string, unknown>,
  host: string,
  cfg: BrandConfig,
): { brand: string, source: BrandSource } {
  const fromTemplate = cfg.brandField ? raw[cfg.brandField] : undefined
  if (typeof fromTemplate === 'string' && fromTemplate.trim()) {
    return { brand: fromTemplate.trim(), source: 'template' }
  }
  const fromMap = cfg.hostBrandMap[host.toLowerCase()]
  if (typeof fromMap === 'string' && fromMap.trim()) {
    return { brand: fromMap.trim(), source: 'hostMap' }
  }
  return { brand: cfg.defaultBrand || 'default', source: 'default' }
}

/**
 * NUXT_HOST_BRAND_MAP 的值。Nuxt 讀 NUXT_ 環境變數時會用 destr 把 JSON 先轉成物件，
 * 所以這裡同時收文字與物件；只收文字的話，部署時照文件設定也會變成空表。
 */
export function parseHostBrandMap(value: unknown): Record<string, string> {
  let parsed = value
  if (typeof value === 'string') {
    if (!value) return {}
    try {
      parsed = JSON.parse(value)
    }
    catch {
      return {}
    }
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(parsed as Record<string, unknown>)) {
    if (typeof v === 'string') out[k.toLowerCase()] = v
  }
  return out
}

/** 把 template 回應組成這次請求的 Site。raw / settings 保持後端原樣。 */
export function buildSite(
  raw: TemplateResponse,
  host: string,
  device: DeviceType,
  cfg: BrandConfig,
): Site {
  const pc = raw.pc_templates ?? raw.name ?? ''
  const mobile = raw.mobile_templates ?? raw.name ?? ''
  const active = device === 'pc' ? pc : mobile
  const { brand, source } = resolveBrand(raw as Record<string, unknown>, host, cfg)
  return {
    host,
    domain: raw.domain,
    brand,
    brandSource: source,
    device,
    template: parseTemplateCode(active),
    templates: { pc, mobile },
    raw,
    settings: raw.customized_setting,
  }
}

/** 站台開關讀法統一走這裡，避免散落 == 1 的比較。 */
export function isOn(value: unknown): boolean {
  return value === 1 || value === '1' || value === true
}

/**
 * 取某頁的 SEO 文案（template customized_setting.seo_meta）。
 * 後端已做「域名自己 → 總代預設 → 空字串」回退，這裡只負責收成三個去頭尾空白的字串；
 * schema 驗不過時 settings 是後端原樣，所以型別照 unknown 處理。
 */
export function seoMetaFor(settings: CustomizedSetting | null | undefined, page: string): SeoMetaEntry {
  const entry = (settings?.seo_meta as Record<string, unknown> | undefined)?.[page] as Record<string, unknown> | undefined
  const pick = (key: keyof SeoMetaEntry) => (typeof entry?.[key] === 'string' ? (entry[key] as string).trim() : '')
  return { title: pick('title'), description: pick('description'), keywords: pick('keywords') }
}
