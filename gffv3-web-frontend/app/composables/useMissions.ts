import { computed } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { Mission } from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import { missionActionPath, missionState, type MissionMemberInfo } from '~/utils/events-core'

/**
 * 每日任務（mission/list、mission/claim）。
 */
export function useMissions() {
  const api = useApi()
  const store = useEventsStore()

  /** mission/list */
  async function fetchMissions(): Promise<ApiEnvelope<Mission[]>> {
    const res = await api.post('mission/list', {})
    if (isApiOk(res)) store.missions = Array.isArray(res.data) ? res.data : []
    return res
  }

  const missions = computed(() => store.missions)

  /** mission/claim：只在 claimable 時呼叫；成功後重抓列表（錢包刷新由 wallet 領域接）。 */
  async function claimMission(missionId: number): Promise<ApiEnvelope<unknown>> {
    const res = await api.post('mission/claim', { mission_id: missionId })
    if (isApiOk(res)) await fetchMissions()
    return res
  }

  function stateOf(mission: Mission, member: MissionMemberInfo = {}) {
    return missionState(mission, member)
  }

  function actionPath(mission: Mission) {
    return missionActionPath(mission)
  }

  return { store, fetchMissions, missions, claimMission, stateOf, actionPath }
}
