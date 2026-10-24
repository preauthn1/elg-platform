import type { Site, SiteError } from '#shared/types/site'

/**
 * 伺服器端把 Nitro middleware 解析好的 site 放進 useState，水合後瀏覽器直接用，
 * 不再重打 template API。
 */
export default defineNuxtPlugin(() => {
  const site = useState<Site | null>('site', () => null)
  const siteError = useState<SiteError | null>('site-error', () => null)

  if (import.meta.server) {
    const event = useRequestEvent()
    site.value = event?.example.invalid ?? null
    siteError.value = event?.context.siteError ?? null
  }
})
