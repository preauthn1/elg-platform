import { defineStore } from 'pinia'
import type { CountryItem, CurrencyItem } from '#shared/api/endpoints/site'

/** 國家／幣別參考資料，跨頁共用；只在空的時候才向後端取。 */
export const useSiteReferenceStore = defineStore('siteReference', () => {
  const countries = ref<CountryItem[]>([])
  const currencies = ref<CurrencyItem[]>([])
  const selectedCurrencyId = ref<number | null>(null)

  function setCountries(list: CountryItem[]) {
    countries.value = list
  }

  function setCurrencies(list: CurrencyItem[]) {
    currencies.value = list
  }

  function setCurrency(id: number | null) {
    selectedCurrencyId.value = id
  }

  function reset() {
    countries.value = []
    currencies.value = []
    selectedCurrencyId.value = null
  }

  return { countries, currencies, selectedCurrencyId, setCountries, setCurrencies, setCurrency, reset }
})
