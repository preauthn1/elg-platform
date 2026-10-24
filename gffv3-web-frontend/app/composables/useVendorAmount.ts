import type { AccumulatedBets } from '~/composables/useBetRecords'
import { decAdd, decFormat } from '#shared/utils/amount'
import { getVendorPath } from '#shared/utils/games'
import { isOn } from '#shared/utils/site'

/**
 * 場館前頁的邏輯，所有皮共用，畫面在 components/<皮>/Page/VendorAmount.vue。
 *
 * 站台開了 has_game_provider_front_page 時，
 * useGames.goToVendor 會先把場館存進 store 再導到這裡（?typeId=…）。
 * 之前這個路由不存在，開了那個旗標的站台點任何場館都是 404。
 *
 * 場館本身從 store 拿（列表頁點進來時放的），直接開網址或重新整理會是空的，
 * 這時導回首頁重新選——不猜 vendor id，也不多打一支列表。
 */
export function useVendorAmount() {
  const route = useRoute()
  const gamesStore = useGamesStore()
  const wallet = useWalletStore()
  const { refreshBalance } = useWallet()
  const { fetchAccumulatedBets } = useBetRecords()
  const { settings, flag } = useSite()
  const { t } = useI18n()

  const vendor = computed(() => gamesStore.selectedVendor)
  const typeId = computed(() => {
    const raw = Array.isArray(route.query.typeId) ? route.query.typeId[0] : route.query.typeId
    const id = Number(raw)
    return Number.isFinite(id) ? id : null
  })

  const bets = ref<AccumulatedBets | null>(null)
  const entering = ref(false)

  onMounted(async () => {
    if (!vendor.value) {
      await navigateTo('/', { replace: true })
      return
    }
    await refreshBalance()
    const res = await fetchAccumulatedBets(vendor.value.id)
    if (res.ok) bets.value = res.data
  })

  /**
   * 錢包列與帶入餘額，照舊版 C13／C16 VendorAmount 的 amountList／carriedBalance：
   * - 順序：主錢包 → 遊戲錢包（站台開 is_multiple_wallet）→ 禮碼（站台開 rolling_chip_visible 且這個場館 giftcode == 1）
   * - 帶入餘額：多錢包站是遊戲錢包、否則主錢包，有顯示禮碼時再加上禮碼
   */
  const isMultipleWallet = computed(() => flag('is_multiple_wallet'))
  const rollingChipVisible = computed(() => flag('rolling_chip_visible') && isOn(vendor.value?.giftcode))

  const amounts = computed(() => {
    const rows: { key: string, label: string, value: string }[] = [
      {
        key: 'main',
        label: settings.value?.text_wallet_point || '',
        value: decFormat(wallet.balance, 2),
      },
    ]
    if (isMultipleWallet.value) {
      rows.push({ key: 'game', label: t('wallet.overview.label.gameWallet'), value: decFormat(wallet.gameWalletBalance, 2) })
    }
    if (rollingChipVisible.value) {
      rows.push({ key: 'chip', label: settings.value?.text_rolling_chip || '', value: decFormat(wallet.rollingChip, 2) })
    }
    return rows
  })

  const carriedBalance = computed(() => {
    const base = isMultipleWallet.value ? wallet.gameWalletBalance : wallet.balance
    return decFormat(rollingChipVisible.value ? decAdd(base, wallet.rollingChip) : base, 2)
  })

  /**
   * 匯率提示：rate 為 1 就不顯示。rate < 1 時反過來寫（1 場館幣 = N 站台點），
   * 除不盡就不顯示——舊皮同樣的處理，避免出現一長串小數。
   */
  const exchangeText = computed(() => {
    const v = vendor.value
    const rate = Number(v?.rate ?? 1)
    if (!v || !Number.isFinite(rate) || rate === 1) return ''
    const point = settings.value?.text_wallet_point || ''
    if (rate > 1) return `1 ${point} = ${rate} ${v.currency_name ?? ''}`
    const inverse = 1 / rate
    if (!Number.isInteger(inverse)) return ''
    return `${inverse} ${point} = 1 ${v.currency_name ?? ''}`
  })

  const targetPath = computed(() => (vendor.value ? getVendorPath(vendor.value, typeId.value) : null))

  /**
   * 開始遊戲：只照 getVendorPath 導頁，**這裡不轉入**。
   * 跟舊版 C13／C16 VendorAmount.toVendor（$pushVendor）一樣，轉入交給目的頁
   * （列表／APP 場館／聲明頁看 transaction_all、遊戲頁直接轉），這裡先轉會重複打 member/transfer。
   */
  async function start() {
    const path = targetPath.value
    if (!vendor.value || !path || entering.value) return
    entering.value = true
    try {
      await navigateTo(path)
    }
    finally {
      entering.value = false
    }
  }

  return {
    vendor,
    bets,
    entering,
    amounts,
    carriedBalance,
    exchangeText,
    targetPath,
    start,
  }
}
