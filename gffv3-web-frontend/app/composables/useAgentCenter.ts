import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import { isOn } from '#shared/utils/site'
import {
  buildShareLinks,
  emptyPageState,
  filterItselfRewardRows,
  memberTypeKind,
  normalizeHierarchySummary,
  normalizePagination,
  ruleItemsForType,
  toNumber,
  uniqueGameTypes,
  type PageState,
  type RuleItem,
} from '#shared/utils/agent'

type DirectPlayerRow = ResponseOf<'unlimit-hierarchy/direct-players'>['items'][number] & { typeKind: 'agent' | 'member' }
type RewardRow = ResponseOf<'unlimit-hierarchy/reward'>['items'][number]
type ThreeAchievementRow = ResponseOf<'three-hierarchy/achievements'>['items'][number]
type ThreeRebateRow = ResponseOf<'three-hierarchy/rebates'>['items'][number]
type RuleLevelRow = ResponseOf<'unlimit-hierarchy/list'>[number]
type VendorRateRow = ResponseOf<'vendor/rate-list'>[number]

export interface FetchSummaryOptions {
  /** TG 使用者的 bot 帳號（由 TG 登入領域提供）；沒有就沿用後端 link */
  tgBotUsername?: string | null
}

/**
 * 代理中心：總覽／今日流水／規則／我的推廣。
 * 模式分流（unlimit vs three）全部在這裡，頁面只拿正規化後的資料。
 */
export function useAgentCenter() {
  const api = useApi()
  const store = useAgentStore()
  const memberStore = useMemberStore()
  const { mode, isThree } = useAgentMode()

  const isTgUser = computed(() => isOn(memberStore.profile?.is_tg_user))
  const summary = computed(() => store.summary)
  const shareLinks = computed(() => store.shareLinks)
  const flowAmount = computed(() => store.flowAmount)

  // 無限級規則頁
  const ruleLevels = ref<RuleLevelRow[]>([])
  const vendorRates = ref<VendorRateRow[]>([])

  // 我的推廣
  const directPlayers = ref<DirectPlayerRow[]>([])
  const directPlayersPage = ref<PageState>(emptyPageState())
  const rewards = ref<RewardRow[]>([])
  const rewardsPage = ref<PageState>(emptyPageState())
  const turnoverRows = computed(() => filterItselfRewardRows(rewards.value))
  const threeAchievements = ref<ThreeAchievementRow[]>([])
  const threeAchievementsPage = ref<PageState>(emptyPageState())
  const threeRebates = ref<ThreeRebateRow[]>([])
  const threeRebatesPage = ref<PageState>(emptyPageState())

  // 三級分銷返利規則（不分模式）
  const ruleItems = ref<RuleItem[]>([])
  const ruleGameTypes = computed(() => uniqueGameTypes(ruleItems.value))
  const selectedRuleGameType = ref<string | null>(null)
  const ruleItemsOfSelected = computed(() => ruleItemsForType(ruleItems.value, selectedRuleGameType.value))

  function browserOrigin(): string | null {
    return import.meta.client ? window.location.origin : null
  }

  /** agent_mode_setting 決定打 unlimit-hierarchy 或 three-hierarchy，成功後同時算好分享連結。 */
  async function fetchSummary(opts: FetchSummaryOptions = {}): Promise<ApiEnvelope<unknown>> {
    // 兩支端點回應形狀不同，先收斂成 unknown 再正規化，否則 isApiOk 推不出單一型別
    const res: ApiEnvelope<unknown> = isThree.value
      ? await api.post('three-hierarchy', {})
      : await api.post('unlimit-hierarchy', {})
    if (isApiOk(res)) {
      const next = normalizeHierarchySummary(res.data as Record<string, unknown>, mode.value)
      store.summary = next
      store.shareLinks = buildShareLinks({
        link: next.link,
        code: next.code,
        origin: browserOrigin(),
        preferOrigin: import.meta.dev,
        tgBotUsername: isTgUser.value ? opts.tgBotUsername ?? null : null,
      })
    }
    return res
  }

  /** 新版回 { performance }，舊版回裸數值；只取 performance。 */
  async function fetchFlowAmount() {
    const res = await api.post('unlimit-hierarchy/flow-amount', {})
    if (isApiOk(res)) store.flowAmount = toNumber(res.data?.performance)
    return res
  }

  async function fetchRuleLevels() {
    const res = await api.post('unlimit-hierarchy/list', {})
    if (isApiOk(res)) ruleLevels.value = Array.isArray(res.data) ? res.data : []
    return res
  }

  async function fetchVendorRates() {
    const res = await api.post('vendor/rate-list', {})
    if (isApiOk(res)) vendorRates.value = Array.isArray(res.data) ? res.data : []
    return res
  }

  async function fetchDirectPlayers(page = 1, perPage = 10) {
    const res = await api.post('unlimit-hierarchy/direct-players', { page, per_page: perPage })
    if (isApiOk(res)) {
      directPlayers.value = (res.data?.items ?? []).map(item => ({ ...item, typeKind: memberTypeKind(item.memberType) }))
      directPlayersPage.value = normalizePagination(res.data?.pagination, { page, perPage })
    }
    return res
  }

  async function fetchRewards(page = 1, perPage = 10) {
    const res = await api.post('unlimit-hierarchy/reward', { page, per_page: perPage })
    if (isApiOk(res)) {
      rewards.value = res.data?.items ?? []
      rewardsPage.value = normalizePagination(res.data?.pagination, { page, perPage })
    }
    return res
  }

  async function fetchThreeAchievements(page = 1, perPage = 10) {
    const res = await api.post('three-hierarchy/achievements', { page, per_page: perPage })
    if (isApiOk(res)) {
      threeAchievements.value = res.data?.items ?? []
      threeAchievementsPage.value = normalizePagination(res.data?.pagination, { page, perPage })
    }
    return res
  }

  async function fetchThreeRebates(page = 1, perPage = 10) {
    const res = await api.post('three-hierarchy/rebates', { page, per_page: perPage })
    if (isApiOk(res)) {
      threeRebates.value = res.data?.items ?? []
      threeRebatesPage.value = normalizePagination(res.data?.pagination, { page, perPage })
    }
    return res
  }

  /** 成功後預設選第一個遊戲類型分頁籤。 */
  async function fetchRule() {
    const res = await api.post('three-hierarchy/rule', {})
    if (isApiOk(res)) {
      ruleItems.value = (res.data?.items ?? []).map(item => ({ ...item, vendor_image: item.vendor_image ?? '' }))
      selectedRuleGameType.value = ruleGameTypes.value[0] ?? null
    }
    return res
  }

  return {
    mode,
    isTgUser,
    summary,
    shareLinks,
    flowAmount,
    ruleLevels,
    vendorRates,
    directPlayers,
    directPlayersPage,
    rewards,
    rewardsPage,
    turnoverRows,
    threeAchievements,
    threeAchievementsPage,
    threeRebates,
    threeRebatesPage,
    ruleItems,
    ruleGameTypes,
    selectedRuleGameType,
    ruleItemsOfSelected,
    fetchSummary,
    fetchFlowAmount,
    fetchRuleLevels,
    fetchVendorRates,
    fetchDirectPlayers,
    fetchRewards,
    fetchThreeAchievements,
    fetchThreeRebates,
    fetchRule,
  }
}
