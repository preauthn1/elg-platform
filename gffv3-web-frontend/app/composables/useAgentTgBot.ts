import { isOn } from '#shared/utils/site'
import { buildShareLinks, tgBotFromBrand, type AgentTgBot, type ShareLinks } from '#shared/utils/agent'

/**
 * TG 使用者的分享連結規則：先取 TG bot（tg-bot/get-brand-by-domain，auth 領域），
 * 有 bot 就把推薦連結換成 example.invalid startapp，找不到 bot 沿用後端 link 並顯示 Can't find bot。
 * 非 TG 使用者不打 bot 端點。
 */
export function useAgentTgBot() {
  const store = useAgentStore()
  const memberStore = useMemberStore()

  const isTgUser = computed(() => isOn(memberStore.profile?.is_tg_user))
  const tgBot = computed(() => store.tgBot)
  const tgShareText = computed(() => store.tgBot?.shareText ?? '')
  const botMissing = computed(() => isTgUser.value && store.tgBotLoaded && !store.tgBot)

  /** 只查一次，結果放 store；非 TG 使用者直接回 null。 */
  async function ensureTgBot(): Promise<AgentTgBot | null> {
    if (!isTgUser.value) return null
    if (store.tgBotLoaded) return store.tgBot
    const { brand } = await useTelegramLogin().fetchBrand()
    store.setTgBot(tgBotFromBrand(brand))
    return store.tgBot
  }

  function browserOrigin(): string | null {
    return import.meta.client ? window.location.origin : null
  }

  /**
   * 組分享／QR 連結。explicitBot 有值時直接用（呼叫端已知 bot），否則 TG 使用者自動查 bot。
   * preferOrigin：開發環境改用 origin/Signup/code（舊前台白牌／開發環境行為）。
   */
  async function resolveShareLinks(link: string, code: string, opts: { explicitBot?: string | null, preferOrigin?: boolean } = {}): Promise<ShareLinks> {
    const botUsername = isTgUser.value
      ? (opts.explicitBot ?? (await ensureTgBot())?.botUsername ?? null)
      : null
    return buildShareLinks({
      link,
      code,
      origin: browserOrigin(),
      preferOrigin: opts.preferOrigin ?? false,
      tgBotUsername: botUsername,
    })
  }

  return { isTgUser, tgBot, tgShareText, botMissing, ensureTgBot, resolveShareLinks }
}
