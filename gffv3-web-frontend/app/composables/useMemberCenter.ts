import { MEMBER_PATH, hasValue } from '#shared/utils/member'
import { isOn } from '#shared/utils/site'
import { decFormat } from '#shared/utils/amount'

/**
 * 個人資料頁：會員卡、錢包三格、功能格的資料與顯示條件，頭像上傳、刷新餘額、進頁的補資料檢查。
 * 所有皮共用，畫面在 components/<皮>/Page/Member.vue。
 */
export function useMemberCenter() {
  const member = useMember()
  const { flag, settings } = useSite()
  const route = useRoute()
  const wallet = useWalletStore()
  const { refreshBalance } = useWallet()
  const vipStore = useVipStore()
  const { ensureInfo } = useVip()

  const profile = member.profile
  const uploadFailed = ref(false)
  const uploading = ref(false)
  const checking = ref(true)
  const refreshing = ref(false)

  const currency = computed(() => settings.value?.text_wallet_point || '')
  const balanceText = computed(() => decFormat(wallet.balance, 2))

  const showSetFullname = computed(() => profile.value !== null && !hasValue(profile.value.first_name))
  const showRealNameVerification = computed(() =>
    flag('real_name_verification_apply_visible') && profile.value !== null && !isOn(profile.value.realname_validate),
  )

  /**
   * 錢包卡的三格：設計稿是 存款／提款／轉帳。
   * 顯示條件照舊前台：提款吃 withdrawal_button_visible（C13 My/Menus.vue），
   * 轉帳吃 is_multiple_wallet（C14 WalletModal.vue，與 WalletPanel 相同）。
   */
  const walletActions = computed(() => [
    { key: 'deposit', to: '/topup', labelKey: 'deposit.entry.nav.deposit', visible: true },
    { key: 'withdraw', to: '/withdrawal', labelKey: 'withdrawal.common.label.withdrawal', visible: flag('withdrawal_button_visible') },
    { key: 'transfer', to: '/wallet/transfer', labelKey: 'wallet.transfer.title.transfer', visible: flag('is_multiple_wallet') },
  ].filter(action => action.visible))

  /** 功能格：順序照設計稿由左至右、由上而下；兌換碼照舊前台吃 coupon_code_visible。 */
  const entries = computed(() => [
    { key: 'daily-checkin', to: '/events/checkin', labelKey: 'events.checkin.title.dailyCheckin', visible: true },
    { key: 'profile-setting', to: MEMBER_PATH.setting, labelKey: 'member.profile.title.information', visible: true },
    { key: 'winner-list', to: '/ranking/winners', labelKey: 'competitions.ranking.title.winnerList', visible: true },
    { key: 'code-redeem', to: '/wallet/coupon', labelKey: 'wallet.exchange.title.coupon', visible: flag('coupon_code_visible') },
    { key: 'transaction-record', to: '/record/transaction', labelKey: 'records.transaction.title.record', visible: true },
    { key: 'win-loss', to: '/record/win-loss', labelKey: 'records.winLoss.title.report', visible: true },
    { key: 'betting-record', to: '/record/bet', labelKey: 'records.tab.nav.bet', visible: true },
    { key: 'tutorial', to: '/information', labelKey: 'site.support.nav.beginnerTutorial', visible: true },
  ].filter(entry => entry.visible))

  /** VIP 入口照舊前台 My/Menus.vue 吃 vip_visible。 */
  const vipVisible = computed(() => flag('vip_visible'))

  /** 頭像：縮到寬 1200 再轉 base64 送 update-profile。 */
  function resizeImageToDataUrl(file: File, maxWidth: number): Promise<string> {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file)
      const img = new Image()
      img.onload = () => {
        const scale = Math.min(1, maxWidth / img.width)
        const canvas = document.createElement('canvas')
        canvas.width = Math.round(img.width * scale)
        canvas.height = Math.round(img.height * scale)
        const ctx = canvas.getContext('2d')
        if (!ctx) {
          URL.revokeObjectURL(url)
          reject(new Error('canvas unavailable'))
          return
        }
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
        URL.revokeObjectURL(url)
        resolve(canvas.toDataURL('image/jpeg', 0.9))
      }
      img.onerror = () => {
        URL.revokeObjectURL(url)
        reject(new Error('image decode failed'))
      }
      img.src = url
    })
  }

  async function onAvatarChange(event: Event) {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return
    uploading.value = true
    uploadFailed.value = false
    try {
      const image = await resizeImageToDataUrl(file, 1200)
      await member.setAvatar(image)
    }
    catch {
      uploadFailed.value = true
    }
    finally {
      uploading.value = false
      input.value = ''
    }
  }

  async function onRefresh() {
    if (refreshing.value) return
    refreshing.value = true
    try {
      await refreshBalance()
    }
    finally {
      refreshing.value = false
    }
  }

  onMounted(async () => {
    // 沒過時 checkProfile 自己彈訊息、關掉才導頁（照舊版 My.vue），這頁照常載入
    await member.checkProfile(route.path)
    checking.value = false
    await member.load()
    if (wallet.balance === null) void refreshBalance()
    // 帳號右邊的 VIP 膠囊與徽章都吃 vip/info，這頁沒抓過就自己抓一次
    void ensureInfo()
  })

  return {
    vipStore,
    profile,
    uploadFailed,
    uploading,
    checking,
    refreshing,
    currency,
    balanceText,
    showSetFullname,
    showRealNameVerification,
    walletActions,
    entries,
    vipVisible,
    onAvatarChange,
    onRefresh,
  }
}
