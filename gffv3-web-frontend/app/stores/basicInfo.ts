import { defineStore } from 'pinia'

/**
 * basic-info 內容快取（key => content），依語系放；同一次載入在各項之間來回不重打。
 * pending 只放在 closure（Promise 不能進 SSR payload），多個畫面同時等待共用同一個請求；
 * 起跑時就記下語系，第二個等待者才不會把在途請求當成別的語系清掉。
 */
export const useBasicInfoStore = defineStore('basicInfo', () => {
  const locale = ref<string | null>(null)
  const contents = ref<Record<string, string> | null>(null)
  let pending: Promise<Record<string, string>> | null = null

  const loaded = computed(() => contents.value !== null)

  function isFresh(currentLocale: string): boolean {
    return contents.value !== null && locale.value === currentLocale
  }

  function setContents(currentLocale: string, next: Record<string, string>) {
    locale.value = currentLocale
    contents.value = next
    pending = null
  }

  function invalidate() {
    locale.value = null
    contents.value = null
    pending = null
  }

  function getPending() {
    return pending
  }

  function setPending(currentLocale: string, next: Promise<Record<string, string>> | null) {
    locale.value = currentLocale
    pending = next
  }

  function contentOf(key: string): string {
    return contents.value?.[key] ?? ''
  }

  return { locale, contents, loaded, isFresh, setContents, invalidate, getPending, setPending, contentOf }
})
