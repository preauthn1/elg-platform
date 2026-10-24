import { useRouter } from 'vue-router';

import { useTabs } from '@vben/hooks';

/**
 * 整頁式表單的離開導向：先關閉當前分頁（連同 keep-alive 快取）再跳轉。
 * 只用 router.push 離開的話，分頁與元件快取都留著，下次進來是喚醒舊元件、
 * onMounted 不重跑，未儲存的變動會殘留在畫面上。
 */
export function useLeavePage() {
  const router = useRouter();
  const { closeCurrentTab } = useTabs();
  /** 省略 path 時只關分頁，由分頁系統自動回上一個造訪的分頁（等價 router.back） */
  return async function leaveTo(path?: string) {
    await closeCurrentTab();
    if (path) await router.push(path);
  };
}
