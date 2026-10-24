import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref, type Ref } from 'vue'

/**
 * 守門：從主畫面（standalone）開啟時，「已安裝」要等水合完才寫入。
 * 水合前就寫入的話 SSR（一律未安裝）與瀏覽器渲染的「下載 APP」列不一致，
 * console 會出現 hydration mismatch；改成 app:mounted 也不行，layout 那時還沒水合完。
 */
describe('pwa-install plugin', () => {
  const states = new Map<string, Ref<unknown>>()
  const hooks = new Map<string, () => void>()

  beforeEach(() => {
    states.clear()
    hooks.clear()
    vi.stubGlobal('defineNuxtPlugin', (fn: unknown) => fn)
    vi.stubGlobal('useState', (key: string, init: () => unknown) => {
      if (!states.has(key)) states.set(key, ref(init()))
      return states.get(key)
    })
    vi.stubGlobal('window', {
      matchMedia: (q: string) => ({ matches: q.includes('standalone') }),
      navigator: {},
      addEventListener: () => {},
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.resetModules()
  })

  it('水合完成前不改已安裝狀態，app:suspense:resolve 之後才改', async () => {
    const plugin = (await import('../../app/plugins/pwa-install.client')).default as unknown as
      (app: { hook: (name: string, fn: () => void) => void }) => void
    plugin({ hook: (name, fn) => hooks.set(name, fn) })

    expect(states.get('pwa-installed')!.value).toBe(false)
    expect(hooks.has('app:mounted')).toBe(false)

    hooks.get('app:suspense:resolve')!()
    expect(states.get('pwa-installed')!.value).toBe(true)
  })
})
