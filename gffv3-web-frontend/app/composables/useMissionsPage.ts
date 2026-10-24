import type { Mission } from '#shared/api/endpoints/events-core'
import type { MissionMemberInfo } from '~/utils/events-core'

/** /missions 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Missions.vue。 */
export function useMissionsPage() {
  const { t } = useI18n()
  const { site } = useSite()
  const missionsApi = useMissions()
  const { missions } = missionsApi
  const member = useMember()

  const loading = ref(true)
  const claimingId = ref<number | null>(null)

  onMounted(async () => {
    await member.ensureLoaded()
    await missionsApi.fetchMissions()
    loading.value = false
  })

  /**
   * 綁定類任務的「系統確認中」要看會員資料。
   * 銀行卡張數目前沒有現成來源，先不帶——帶不到只是少顯示確認中，不會誤判完成。
   */
  const memberInfo = computed<MissionMemberInfo>(() => {
    const p = member.profile.value
    return {
      birthday: p?.birthday ?? null,
      mobile_validate: p?.mobile_validate ?? null,
      email_validate: p?.email_validate ?? null,
      realname: { is_validated: Number(p?.realname_validate ?? 0) === 1 },
    }
  })

  function stateOf(mission: Mission) {
    return missionsApi.stateOf(mission, memberInfo.value)
  }

  /** 進度條：requirement 為 0（不限次數）時不畫。 */
  function percentOf(mission: Mission): number | null {
    const requirement = Number(mission.requirement ?? 0)
    if (!(requirement > 0)) return null
    const progress = Number(mission.progress ?? 0)
    return Math.min(100, Math.max(0, (progress / requirement) * 100))
  }

  async function claim(mission: Mission) {
    if (claimingId.value !== null) return
    claimingId.value = mission.id
    try {
      await missionsApi.claimMission(mission.id)
    }
    finally {
      claimingId.value = null
    }
  }

  /** 條款裡的 {value} 是站名，要當 vue-i18n 參數傳。 */
  const platformName = computed(() => site.value?.raw.title || t('site.legal.unit.platformName'))
  const terms = computed(() => t('events.mission.text.terms', { value: platformName.value }))

  return {
    missionsApi,
    missions,
    loading,
    claimingId,
    stateOf,
    percentOf,
    claim,
    terms,
  }
}
