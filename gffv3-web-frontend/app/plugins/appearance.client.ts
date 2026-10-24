/**
 * 水合前把外觀套上去，避免先閃一下基底再切成第二套。
 *
 * 真正「零閃爍」的那一段是 nuxt.config.ts 塞在 <head> 的行內 script
 * （在 CSS 之前就跑），這支只是把同一個值同步進 useState 讓元件讀得到。
 */
export default defineNuxtPlugin(() => {
  const mode = useState<0 | 1>('appearance', () => 0)
  const stored = (() => {
    try {
      return localStorage.getItem('template_variant')
    }
    catch {
      return null
    }
  })()
  mode.value = stored === '1' ? 1 : 0
  document.documentElement.dataset.appearance = String(mode.value)
})
