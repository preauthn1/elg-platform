import { formatClock, NEWBIE_STAGE } from '~/utils/events-core'
import { NEWBIE_TP_EVENT } from '~/composables/useNewbieChallenge'

/** /newbie-challenge 的邏輯，所有皮共用，畫面在 components/<皮>/Page/NewbieChallenge.vue。 */
export function useNewbieChallengePage() {
  const { t } = useI18n()
  const { ensureTpEventAllowed } = useEvents()
  const newbie = useNewbieChallenge()
  const {
    detail,
    progress,
    bonusWindowHours,
    remainingSeconds,
    step,
    expired,
    ended,
    canCompleteChallenge,
    canConfirmDeposit,
    canClaimBonus,
    joining,
    busy,
  } = newbie

  const loading = ref(true)
  const confirmingQuit = ref(false)

  // 倒數與回到前景重抓；掛在 setup 期間註冊生命週期
  newbie.setupAutoRefresh()

  onMounted(async () => {
    if (!(await ensureTpEventAllowed(NEWBIE_TP_EVENT))) return
    await newbie.fetchAll()
    loading.value = false
  })

  const STEP_LABELS = [
    'events.newcomer.label.step01',
    'events.newcomer.label.step02',
    'events.newcomer.label.step03',
    'events.newcomer.label.step04',
    'events.newcomer.label.step05',
  ]

  /** 指定遊戲商清單，用於「在指定遊戲（…）中完成有效流水」。 */
  const allowedVendorNames = computed(() =>
    (detail.value.allowed_vendors ?? []).map(v => v.name).filter(Boolean).join('、'),
  )

  /** 倒數：HH:mm:ss。 */
  const countdown = computed(() => formatClock(remainingSeconds.value))

  const statusLabel = computed(() => {
    if (!progress.value) return t('events.newcomer.label.statusNotJoined')
    if (Number(progress.value.stage) === NEWBIE_STAGE.QUIT) return t('events.newcomer.label.statusQuit')
    if (expired.value) return t('events.newcomer.label.statusExpired')
    switch (Number(progress.value.stage)) {
      case NEWBIE_STAGE.BONUS_PENDING: return t('events.newcomer.label.statusBonusPending')
      case NEWBIE_STAGE.BONUS_COMPLETED: return t('events.newcomer.label.statusBonusDone')
      default: return t('events.newcomer.label.statusOngoing')
    }
  })

  async function onQuit() {
    confirmingQuit.value = false
    await newbie.quit()
  }

  return {
    newbie,
    detail,
    progress,
    bonusWindowHours,
    step,
    expired,
    ended,
    canCompleteChallenge,
    canConfirmDeposit,
    canClaimBonus,
    joining,
    busy,
    loading,
    confirmingQuit,
    STEP_LABELS,
    allowedVendorNames,
    countdown,
    statusLabel,
    onQuit,
  }
}
