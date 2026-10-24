import { getRequestHeader, getRequestHost } from 'h3'
import { buildSite, detectDevice, parseHostBrandMap } from '#shared/utils/site'
import { getTemplateForHost } from '../utils/templateCache'

const SKIP_PREFIXES = ['/_nuxt', '/__nuxt', '/@', '/_ipx', '/api/_health', '/api/_auth', '/favicon', '/robots.txt', '/.well-known', '/pwa/', '/firebase-messaging-sw.js']

function resolveHost(event: Parameters<typeof getRequestHost>[0], override: string): string {
  if (override) return override.toLowerCase()
  const host = getRequestHost(event, { xForwardedHost: true }) || ''
  return host.split(':')[0]?.toLowerCase() ?? ''
}

/**
 * 每個請求先依 Host 找站台：template 快取 → example.invalid。
 * 找不到不丟錯，放 event.context.siteError 讓頁面決定怎麼呈現。
 */
export default defineEventHandler(async (event) => {
  const path = event.path || '/'
  if (SKIP_PREFIXES.some(p => path.startsWith(p))) return

  const cfg = useRuntimeConfig(event)
  const host = resolveHost(event, cfg.devHostOverride)
  if (!host) {
    event.context.siteError = { code: 0, message: 'missing host', host }
    return
  }

  let entry
  try {
    entry = await getTemplateForHost(event, host)
  }
  catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    event.context.siteError = { code: 0, message: `template lookup failed: ${message}`, host }
    return
  }
  if (!entry.raw) {
    event.context.siteError = { code: entry.error?.code ?? 0, message: entry.error?.message ?? 'template unavailable', host }
    return
  }

  example.invalid = buildSite(entry.raw, host, detectDevice(getRequestHeader(event, 'user-agent')), {
    brandField: cfg.brandField,
    hostBrandMap: parseHostBrandMap(cfg.hostBrandMap),
    defaultBrand: cfg.public.defaultBrand,
  })
})
