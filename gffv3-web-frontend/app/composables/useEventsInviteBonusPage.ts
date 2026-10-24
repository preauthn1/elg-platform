import { INVITE_TP_EVENT } from '~/composables/useEventInvite'

/** /events/invite-bonus 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsInviteBonus.vue。 */
export function useEventsInviteBonusPage() {
  const { t } = useI18n()
  const { site } = useSite()
  const { ensureTpEventAllowed } = useEvents()
  const invite = useEventInvite()
  const { setting, rewardCards, claiming, canReceive } = invite

  const loading = ref(true)

  onMounted(async () => {
    // 站台沒開這個活動就導回首頁（與其他第三方活動頁一致）
    if (!(await ensureTpEventAllowed(INVITE_TP_EVENT))) return
    await invite.fetchInviteList()
    loading.value = false
  })

  /** 條款裡的 {name} 要當 vue-i18n 參數傳，t() 回傳後再取代就來不及了。 */
  const platformName = computed(() => site.value?.raw.title || t('site.legal.unit.platformName'))
  const terms = computed(() => t('events.invite.text.terms', { name: platformName.value }))

  /** 我的邀請碼：與 /agent/promote 同一組推廣碼，這裡只給入口。 */
  const inviteEntry = '/agent/promote'

  async function claim() {
    await invite.receive()
  }

  /** 好友卡的三個任務狀態，全部照後端旗標，不自己判斷。 */
  function cardTasks(card: (typeof rewardCards.value)[number]) {
    return [
      { labelKey: 'events.invite.hint.taskKyc', done: Number(card.realname_status ?? 0) === 1 },
      { labelKey: 'events.invite.hint.taskDepositPrefix', done: Number(card.deposit_status ?? 0) === 1 },
    ]
  }

  return {
    setting,
    rewardCards,
    claiming,
    canReceive,
    loading,
    terms,
    inviteEntry,
    claim,
    cardTasks,
  }
}
