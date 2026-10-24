import type { ShareLinks } from '#shared/utils/agent'

/** /agent/qrcode 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentQrcode.vue。 */
export function useAgentQrcodePage() {
  const { summary, fetchSummary } = useAgentCenter()
  const { ensureTgBot, botMissing, resolveShareLinks } = useAgentTgBot()

  const links = ref<ShareLinks | null>(null)
  /** UiQrCode 產出的 data URL，保存到相簿直接指它 */
  const qrSrc = ref('')
  const copied = ref(false)

  onMounted(async () => {
    if (!summary.value) {
      const res = await fetchSummary()
      if (!isApiOk(res)) return
    }
    await ensureTgBot()
    links.value = await resolveShareLinks(summary.value?.link ?? '', summary.value?.code ?? '', {
      preferOrigin: import.meta.dev,
    })
  })

  async function copyLink() {
    const link = links.value?.copyLink
    if (!link || !import.meta.client) return
    try {
      await navigator.clipboard.writeText(link)
      copied.value = true
    }
    catch {
      copied.value = false
    }
  }

  return {
    summary,
    botMissing,
    links,
    qrSrc,
    copied,
    copyLink,
  }
}
