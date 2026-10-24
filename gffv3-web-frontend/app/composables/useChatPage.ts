import { safeInternalPath } from '#shared/utils/withdrawal'

/** /chat 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Chat.vue。 */
export function useChatPage() {
  const config = useRuntimeConfig()
  const { clear } = useUserSession()
  const { loadMarqueeTexts } = useMarquee()

  const chatBaseUrl = computed(() => String(config.public.chatBaseUrl || ''))

  /** 只允許 http(s)，解析失敗就當沒設定。 */
  const chatOrigin = computed(() => {
    if (!chatBaseUrl.value) return ''
    try {
      const url = new URL(chatBaseUrl.value)
      return /^https?:$/.test(url.protocol) ? url.origin : ''
    }
    catch {
      return ''
    }
  })

  const ready = computed(() => chatOrigin.value !== '')
  const showHelp = ref(false)

  async function onMessage(event: MessageEvent) {
    // 來源不是設定的客服站就整包丟掉
    if (!chatOrigin.value || event.origin !== chatOrigin.value) return
    const payload = event.data as { type?: number, data?: unknown, clearParent?: boolean } | null
    if (!payload || typeof payload !== 'object') return

    switch (Number(payload.type)) {
      case 1: {
        if (payload.clearParent) {
          await clear()
          await navigateTo('/login', { replace: true })
          return
        }
        // 只接受站內路徑，避免客服端把使用者導去外站
        const path = safeInternalPath(payload.data)
        if (path) await navigateTo(path)
        return
      }
      case 5: {
        // 舊版是 actionMarqueeText(2)，location 沿用
        const source = event.source as WindowProxy | null
        if (!source) return
        try {
          const data = await loadMarqueeTexts(2)
          source.postMessage({ data }, event.origin)
        }
        catch (error) {
          source.postMessage({ error: error instanceof Error ? error.message : String(error) }, event.origin)
        }
        return
      }
    }
  }

  onMounted(() => window.addEventListener('message', onMessage))
  onBeforeUnmount(() => window.removeEventListener('message', onMessage))

  return {
    chatBaseUrl,
    ready,
    showHelp,
  }
}
