import { storeToRefs } from 'pinia'
import type { VipInfoReward } from '#shared/api/endpoints/vip'
import { API_CODE } from '#shared/constants/codes'
import {
  depositDifference,
  hasNextLevel,
  relegationDifference,
  toAmount,
} from '#shared/utils/vip'

type Panel = 'none' | 'bonus' | 'levels' | 'records' | 'rules'

/** /vip 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Vip.vue。 */
export async function useVipPage() {
  const { t } = useI18n()
  const { settings, site } = useSite()
  const { loading, claimingId, fetchInfo, fetchRewardHistory, claim } = useVip()
  const store = useVipStore()
  const { info, contents, level, currentVip, nextVip, maxLevel, privileges, rewardHistory } = storeToRefs(store)

  const panel = ref<Panel>('none')
  const alert = useAlert()

  const dataReady = useAsyncData('vip-info', () => fetchInfo())

  const walletPointText = computed(() => settings.value?.text_wallet_point ?? '')

  /** 條款與規則的文案裡有 {value}，要當 vue-i18n 的參數傳；站名取 template 回的 title。 */
  const platformName = computed(() => site.value?.raw.title || t('site.legal.unit.platformName'))
  const ruleParagraphs = computed(() => [
    t('vip.rules.hint.program01', { value: platformName.value }),
    t('vip.rules.text.program02', { value: platformName.value }),
    t('vip.rules.text.program03', { value: platformName.value }),
    t('vip.rules.text.program04', { value: platformName.value }),
    t('vip.rules.hint.program05'),
  ])
  const showNextLevel = computed(() => hasNextLevel(level.value, maxLevel.value))
  const upgradeGap = computed(() => depositDifference(info.value, nextVip.value))
  const relegationGap = computed(() => relegationDifference(info.value, currentVip.value))

  /** 進度條：已累積 / 下一級門檻。沒有下一級就當作滿格。 */
  const progressPercent = computed(() => {
    if (!info.value) return 0
    const target = toAmount(nextVip.value?.target_amount)
    if (!nextVip.value || target <= 0) return 100
    const done = toAmount(info.value.deposit_amount)
    return Math.max(0, Math.min(100, (done / target) * 100))
  })

  /** 紅利說明／領取紀錄兩個切換鈕，樣式與紀錄頁的晶片列一致。 */
  const panelTabs = computed(() => [
    { key: 'bonus' as const, label: t('vip.rules.title.bonusExplanation') },
    { key: 'records' as const, label: t('agent.common.nav.collectionRecord') },
  ])

  function togglePanel(next: Panel) {
    panel.value = panel.value === next ? 'none' : next
  }

  async function openRecords() {
    togglePanel('records')
    if (panel.value !== 'records') return
    await fetchRewardHistory()
  }

  async function onClaim(reward: VipInfoReward) {
    const res = await claim(reward)
    if (res.code === API_CODE.OK) alert.show(t('common.ui.msg.success'), 'success')
  }

  // 資料在 SSR 就要抓好：上面已經開始抓，這裡才等（await 之後不能再呼叫 composable）
  await dataReady

  return {
    loading,
    claimingId,
    info,
    contents,
    level,
    currentVip,
    nextVip,
    privileges,
    rewardHistory,
    panel,
    walletPointText,
    ruleParagraphs,
    showNextLevel,
    upgradeGap,
    relegationGap,
    progressPercent,
    panelTabs,
    togglePanel,
    openRecords,
    onClaim,
  }
}
