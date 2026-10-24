import type { z } from 'zod'
import type { EndpointDef } from './define'
import { agentEndpoints } from './endpoints/agent'
import { authEndpoints } from './endpoints/auth'
import { memberEndpoints } from './endpoints/member'
import { siteEndpoints } from './endpoints/site'
import { messagesEndpoints } from './endpoints/messages'
import { walletEndpoints } from './endpoints/wallet'
import { depositEndpoints } from './endpoints/deposit'
import { withdrawalEndpoints } from './endpoints/withdrawal'
import { gamesEndpoints } from './endpoints/games'
import { eventsCoreEndpoints } from './endpoints/events-core'
import { eventsJackpotEndpoints } from './endpoints/events-jackpot'
import { competitionsEndpoints } from './endpoints/competitions'
import { recordsEndpoints } from './endpoints/records'
import { agentHierarchyEndpoints } from './endpoints/agent-hierarchy'
import { vipEndpoints } from './endpoints/vip'

/**
 * 所有 BFF 允許轉發的端點。key = apis 路徑（去掉 frontend/api/）。
 * 各領域檔案只維護自己的物件，這裡只做合併；新增領域要在這裡加一行。
 */
export const endpoints = {
  ...agentEndpoints,
  ...authEndpoints,
  ...memberEndpoints,
  ...siteEndpoints,
  ...messagesEndpoints,
  ...walletEndpoints,
  ...depositEndpoints,
  ...withdrawalEndpoints,
  ...gamesEndpoints,
  ...eventsCoreEndpoints,
  ...eventsJackpotEndpoints,
  ...competitionsEndpoints,
  ...recordsEndpoints,
  ...agentHierarchyEndpoints,
  ...vipEndpoints,
} as const

export type Endpoints = typeof endpoints
export type EndpointKey = keyof Endpoints & string
export type RequestOf<K extends EndpointKey> = z.input<Endpoints[K]['request']>
export type ResponseOf<K extends EndpointKey> = z.output<Endpoints[K]['response']>

export function getEndpoint(key: string): EndpointDef | undefined {
  return (endpoints as Record<string, EndpointDef>)[key]
}

export function isEndpointKey(key: string): key is EndpointKey {
  return Object.prototype.hasOwnProperty.call(endpoints, key)
}
