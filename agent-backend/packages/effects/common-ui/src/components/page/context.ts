import type { InjectionKey } from 'vue';

/** 標記「目前已在某個 Page 之內」，讓巢狀 Page（例如彈窗裡載入的設定頁）不再重複保留底部空間 */
export const PAGE_NESTED_KEY: InjectionKey<boolean> =
  Symbol('vben-page-nested');
