import type { ResponseOf } from '#shared/api/registry'
import { isOn } from '#shared/utils/site'
import {
  ACHIEVEMENT_PERIODS,
  buildShareLinks,
  teamManagementResult,
  toNumber,
  type AchievementPeriodType,
  type TeamManagementResult,
} from '#shared/utils/agent'

type Achievement = ResponseOf<'member-team/achievement'>
type AgentReport = ResponseOf<'member-team/agent-achievement'>

export interface TeamShareOptions {
  tgBotUsername?: string | null
}

/**
 * 會員團隊（member-team/*）：C16 代理中心的 Summary／Performance／TeamManagement／Report 分頁。
 * 不分代理模式。結算界線由後端算（14:00），前端只負責「昨日／前天」標籤。
 */
export function useMemberTeam() {
  const api = useApi()
  const store = useAgentStore()
  const memberStore = useMemberStore()

  const isTgUser = computed(() => isOn(memberStore.profile?.is_tg_user))
  const info = computed(() => store.teamInfo)
  const shareLinks = ref<{ copyLink: string, qrLink: string } | null>(null)

  const achievement = ref<Achievement | null>(null)
  const achievementPeriod = ref<AchievementPeriodType>(1)
  const periods = ACHIEVEMENT_PERIODS

  const teamMember = ref<TeamManagementResult | null>(null)
  const teamMemberSearched = ref(false)

  const report = ref<AgentReport | null>(null)

  async function fetchInfo(opts: TeamShareOptions = {}) {
    const res = await api.post('member-team/info', {})
    if (isApiOk(res)) {
      const d = res.data
      store.teamInfo = {
        directPlayerCount: toNumber(d.directPlayerCount),
        addedToday: toNumber(d.addedToday),
        addedTodayGroupMember: toNumber(d.addedTodayGroupMember),
        totalGroupMember: toNumber(d.totalGroupMember),
        todayCommission: toNumber(d.todayCommission),
        isPayout: isOn(d.is_payout),
        code: String(d.code ?? ''),
        link: d.link ?? '',
      }
      shareLinks.value = buildShareLinks({
        link: d.link ?? '',
        code: String(d.code ?? ''),
        origin: import.meta.client ? window.location.origin : null,
        tgBotUsername: isTgUser.value ? opts.tgBotUsername ?? null : null,
      })
    }
    return res
  }

  /** toggle 切換昨日／前天即重新請求。 */
  async function fetchAchievement(type: AchievementPeriodType = achievementPeriod.value) {
    achievementPeriod.value = type
    const res = await api.post('member-team/achievement', { type })
    if (isApiOk(res)) achievement.value = res.data
    return res
  }

  /** 查不到（非直屬或不存在）後端回 code 200 + []，視為查無。 */
  async function searchTeamMember(username: string) {
    const res = await api.post('member-team/team-management', { username })
    teamMemberSearched.value = true
    teamMember.value = isApiOk(res) ? teamManagementResult(res.data) : null
    return res
  }

  async function fetchReport() {
    const res = await api.post('member-team/agent-achievement', {})
    if (isApiOk(res)) report.value = res.data
    return res
  }

  return {
    isTgUser,
    info,
    shareLinks,
    achievement,
    achievementPeriod,
    periods,
    teamMember,
    teamMemberSearched,
    report,
    fetchInfo,
    fetchAchievement,
    searchTeamMember,
    fetchReport,
  }
}
