import type { ApiEnvelope } from '#shared/types/api'
import type { EventInviteList } from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import { inviteCanReceive } from '~/utils/events-core'

export const INVITE_TP_EVENT = 2
export const INVITE_MIN_CLAIMING_MS = 600

function delay(ms: number) {
  return new Promise<void>(resolve => setTimeout(resolve, ms))
}

/**
 * 邀請好友獎勵活動（event-invite/list、event-invite/receive）。
 */
export function useEventInvite() {
  const api = useApi()
  const store = useEventsStore()

  /** event-invite/list：state 固定 { setting: data.setting || {}, reward_card: data.reward_card }。 */
  async function fetchInviteList(): Promise<ApiEnvelope<EventInviteList>> {
    const res = await api.post('event-invite/list', {})
    if (isApiOk(res)) store.setInvite(res.data?.setting, res.data?.reward_card)
    return res
  }

  const setting = computed(() => store.inviteSetting)
  const rewardCards = computed(() => store.inviteRewardCards)
  const claiming = ref(false)
  const canReceive = computed(() => inviteCanReceive(store.inviteSetting) && !claiming.value)

  /** event-invite/receive：回應與 list 同形狀，直接更新；claiming 至少維持 minClaimingMs。 */
  async function receive(minClaimingMs = INVITE_MIN_CLAIMING_MS): Promise<ApiEnvelope<EventInviteList> | null> {
    if (!canReceive.value) return null
    claiming.value = true
    try {
      const [res] = await Promise.all([api.post('event-invite/receive', {}), delay(minClaimingMs)])
      if (isApiOk(res)) store.setInvite(res.data?.setting, res.data?.reward_card)
      return res
    }
    finally {
      claiming.value = false
    }
  }

  return { store, fetchInviteList, setting, rewardCards, claiming, canReceive, receive }
}
