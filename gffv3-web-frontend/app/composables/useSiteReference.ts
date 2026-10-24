import type { ApiEnvelope } from '#shared/types/api'
import { siteEndpoints, type CountryItem, type CurrencyItem, type VersionsResponse, type VideoAccount } from '#shared/api/endpoints/site'
import {
  canShowAppDownload,
  currencyName,
  findCountryByCode,
  findCountryByPhone,
  phoneRuleRegExp,
  pickDownloadLink,
  platformFromUserAgent,
  uniqueCurrencyIds,
  type MobilePlatform,
} from '#shared/utils/siteContent'
import { isApiOk, useApi } from './useApi'
import { useSite } from './useSite'
import { useSiteReferenceStore } from '~/stores/siteReference'

/** 國家清單（國碼＋手機格式正則）；只在 store 為空時呼叫。 */
export function useCountries() {
  const api = useApi()
  const store = useSiteReferenceStore()

  const countries = computed(() => store.countries)

  function fetchCountries(): Promise<ApiEnvelope<CountryItem[]>> {
    return api.post('country/list', {})
  }

  async function ensureCountries(): Promise<CountryItem[]> {
    if (store.countries.length > 0) return store.countries
    const res = await fetchCountries()
    if (isApiOk(res)) {
      // BFF 對 response 只檢查不轉型，後端的 country_code 是數字（60），要在這裡套 schema 轉成字串，
      // 否則 register-v2 的 country_code 會被 zod 擋成 invalid request
      const parsed = siteEndpoints['country/list'].response.safeParse(res.data)
      if (parsed.success) store.setCountries(parsed.data)
    }
    return store.countries
  }

  /** 預設選第一筆；有指定 country_code（例如 query）時優先。 */
  function defaultCountry(preferredCode?: string | null): CountryItem | undefined {
    return findCountryByCode(store.countries, preferredCode) ?? store.countries[0]
  }

  function countryForPhone(phone: string | null | undefined): CountryItem | undefined {
    return findCountryByPhone(store.countries, phone)
  }

  function phoneRegExp(country?: { phone_rule?: string | null } | null): RegExp {
    return phoneRuleRegExp(country?.phone_rule)
  }

  function isValidPhone(phone: string, country?: { phone_rule?: string | null } | null): boolean {
    return phoneRegExp(country).test(phone)
  }

  return { countries, fetchCountries, ensureCountries, defaultCountry, countryForPhone, phoneRegExp, isValidPhone }
}

/** 幣種清單：把銀行帳號／支付方式的 currency_id 轉成名稱。 */
export function useCurrencies() {
  const api = useApi()
  const store = useSiteReferenceStore()

  const currencies = computed(() => store.currencies)
  const selectedCurrencyId = computed(() => store.selectedCurrencyId)

  function fetchCurrencies(): Promise<ApiEnvelope<CurrencyItem[]>> {
    return api.post('currency/list', {})
  }

  /** 取回後以帳號清單第一個非 0 的 currency_id 當目前幣種。 */
  async function loadCurrencies(
    accounts: ReadonlyArray<{ currency_id?: number | string | null }> = [],
  ): Promise<CurrencyItem[]> {
    const res = await fetchCurrencies()
    if (isApiOk(res) && Array.isArray(res.data)) store.setCurrencies(res.data)
    const ids = uniqueCurrencyIds(accounts)
    store.setCurrency(ids[0] ?? null)
    return store.currencies
  }

  function setCurrency(id: number | null) {
    store.setCurrency(id)
  }

  function nameOf(id: number | string | null | undefined): string {
    return currencyName(store.currencies, id)
  }

  return { currencies, selectedCurrencyId, fetchCurrencies, loadCurrencies, setCurrency, nameOf }
}

/** APP 下載：顯示與否看 template 的 has_ipa / has_apk，點下載才打 versions。 */
export function useVersions() {
  const api = useApi()
  const { site } = useSite()

  function fetchVersions(): Promise<ApiEnvelope<VersionsResponse>> {
    return api.post('versions', {})
  }

  function platformFor(userAgent: string | null | undefined): MobilePlatform {
    return platformFromUserAgent(userAgent)
  }

  function canShowDownload(platform: MobilePlatform): boolean {
    return canShowAppDownload(site.value?.raw, platform)
  }

  async function downloadLink(platform: MobilePlatform): Promise<string | null> {
    const res = await fetchVersions()
    return isApiOk(res) ? pickDownloadLink(res.data, platform) : null
  }

  /** 取到連結才跳轉；data 為 null 不動作。 */
  async function download(platform: MobilePlatform): Promise<string | null> {
    const link = await downloadLink(platform)
    if (link) await navigateTo(link, { external: true })
    return link
  }

  return { fetchVersions, platformFor, canShowDownload, downloadLink, download }
}

/** 影音三方帳號（需登入）。 */
export function useVideos() {
  const api = useApi()

  function fetchVideos(): Promise<ApiEnvelope<VideoAccount[]>> {
    return api.post('videos', {})
  }

  async function loadVideos(): Promise<VideoAccount[]> {
    const res = await fetchVideos()
    return isApiOk(res) && Array.isArray(res.data) ? res.data : []
  }

  return { fetchVideos, loadVideos }
}
