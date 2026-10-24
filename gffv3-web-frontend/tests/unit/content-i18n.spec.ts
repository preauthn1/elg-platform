import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, reactive, ref } from 'vue'

/**
 * 活動詳情彈窗的固定文案要跟著彈窗選的語言（store.contentLocale）翻譯，
 * 但站台語系不能動——所以 ct() 必須每次都把 locale 明確帶給 t()，而不是去改全域語系。
 */
const t = vi.fn((key: string) => key)
const siteLocale = ref('zh-tw')
const store = reactive<{ contentLocale: string | null }>({ contentLocale: null })

beforeEach(() => {
  t.mockClear()
  siteLocale.value = 'zh-tw'
  store.contentLocale = null
  vi.stubGlobal('computed', computed)
  vi.stubGlobal('useI18n', () => ({ t, locale: siteLocale }))
  vi.stubGlobal('useEventsStore', () => store)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const { useContentI18n } = await import('../../app/composables/useContentI18n')

describe('useContentI18n', () => {
  it('彈窗沒選語言時照站台語系', () => {
    const { ct } = useContentI18n()
    ct('events.detail.label.eventPeriod')
    expect(t).toHaveBeenLastCalledWith('events.detail.label.eventPeriod', {}, { locale: 'zh-tw' })
  })

  it('彈窗選了語言就照那個語言翻，站台語系不變', () => {
    const { ct } = useContentI18n()
    store.contentLocale = 'th-th'
    ct('events.list.label.participationStatus', { n: 1 })
    expect(t).toHaveBeenLastCalledWith('events.list.label.participationStatus', { n: 1 }, { locale: 'th-th' })
    expect(siteLocale.value).toBe('zh-tw')
  })

  it('離開彈窗（contentLocale 清回 null）就回到站台語系', () => {
    const { ct } = useContentI18n()
    store.contentLocale = 'en'
    ct('a')
    store.contentLocale = null
    ct('a')
    expect(t).toHaveBeenLastCalledWith('a', {}, { locale: 'zh-tw' })
  })
})
