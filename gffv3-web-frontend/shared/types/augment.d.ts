import type { Site, SiteError } from './site'

// Nitro middleware 放進 event.context 的站台資料
declare module 'h3' {
  interface H3EventContext {
    site?: Site
    siteError?: SiteError
  }
}

// nuxt-auth-utils session 形狀：user 瀏覽器可讀，secure 只在伺服器解得開
declare module '#auth-utils' {
  interface User {
    id: number | string
    username: string
    nickname?: string | null
  }

  interface SecureSessionData {
    token: [REDACTED]
  }
}

export {}
