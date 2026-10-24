import { type Component, defineAsyncComponent } from 'vue'

/**
 * 依皮載入元件，檔名就是規則，新增皮不用改這裡：
 *   外殼  components/<皮>/Shell.vue
 *   頁面  components/<皮>/Page/<名稱>.vue
 *
 * 用 import.meta.glob 延遲載入：每個皮的元件各自成一個 chunk，使用者只下載自己那個皮的程式碼。
 */
const SHELLS = import.meta.glob<{ default: Component }>('../components/*/Shell.vue')
const PAGES = import.meta.glob<{ default: Component }>('../components/*/Page/*.vue')

/** 還沒有外殼的皮（還沒搬進來）與站台解析失敗時，沿用 C17，行為與只有 C17 時相同。 */
export const DEFAULT_SKIN = 'C17'

const cache = new Map<string, Component>()

function load(key: string, loader: () => Promise<{ default: Component }>): Component {
  let component = cache.get(key)
  if (!component) {
    component = defineAsyncComponent(() => loader().then(m => m.default))
    cache.set(key, component)
  }
  return component
}

/** 這個皮已經搬進來（有外殼）就用它，否則用 C17。 */
export function resolveSkin(skin: string | null | undefined): string {
  return skin && SHELLS[`../components/${skin}/Shell.vue`] ? skin : DEFAULT_SKIN
}

export function skinShell(skin: string | null | undefined): Component {
  const key = `../components/${resolveSkin(skin)}/Shell.vue`
  return load(key, SHELLS[key]!)
}

/** 這個皮沒有這一頁回 null（呼叫端照舊前台導回首頁）。 */
export function skinPage(skin: string | null | undefined, name: string): Component | null {
  const key = `../components/${resolveSkin(skin)}/Page/${name}.vue`
  const loader = PAGES[key]
  return loader ? load(key, loader) : null
}
