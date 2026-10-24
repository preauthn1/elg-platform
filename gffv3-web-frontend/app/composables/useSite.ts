import type { CustomizedSetting, Site, SiteError } from '#shared/types/site'
import { isOn } from '#shared/utils/site'

/**
 * 站台資料唯一入口。元件與 composable 只能讀這裡的 brand / settings，
 * 不准看 hostname，也不准把設定存進瀏覽器儲存區。
 */
export function useSite() {
  const site = useState<Site | null>('site', () => null)
  const error = useState<SiteError | null>('site-error', () => null)

  const settings = computed<CustomizedSetting | null>(() => site.value?.settings ?? null)
  const brand = computed(() => site.value?.brand ?? '')
  const template = computed(() => site.value?.template ?? null)
  /** 金額前的幣別標籤：text_currency_name 某些站是空字串，退回頂部列用的 text_wallet_point */
  const currencyLabel = computed(() => settings.value?.text_currency_name || settings.value?.text_wallet_point || '')

  /** 讀 customized_setting 的 0/1 開關。 */
  function flag(key: keyof CustomizedSetting): boolean {
    return isOn(settings.value?.[key])
  }

  return { site, error, settings, brand, template, currencyLabel, flag }
}
