import type { Ref } from 'vue';

import { ref } from 'vue';

import { ElMessage } from 'element-plus';

/** 後端統一列表回應信封（舊 API_Agent 成功碼 1000、apis 成功碼 200） */
interface ListResponse<T> {
  code: number | string;
  data?: null | T[];
  message?: string;
  pagination?: null | { total?: number };
}

interface UseListQueryOptions<T> {
  /**
   * 載入前驗證；回傳 false 則中止本次載入（不動 loading/list）。
   * 驗證未過的提示訊息由 guard 自行顯示。
   */
  guard?: () => boolean;
  /** 建立時是否立即載入，預設 false（由呼叫端自行觸發） */
  immediate?: boolean;
  /**
   * 每次載入後（成功/失敗皆會）以原始 res 回呼，
   * 用於承接 list/total 以外的額外狀態（如 summary、total_summary）。
   */
  onLoaded?: (res: ListResponse<T>) => void;
  /** 每頁筆數，預設 20 */
  perPage?: number;
  /** 非 1000 時是否以 ElMessage.error 顯示後端訊息，預設 true */
  showError?: boolean;
}

/**
 * 列表頁共用查詢狀態：loading / list / total / page，
 * 以及 loadData / search（重設頁碼）/ handlePageChange。
 */
export function useListQuery<T>(
  fetcher: (params: {
    page: number;
    per_page: number;
  }) => Promise<ListResponse<T>>,
  options: UseListQueryOptions<T> = {},
) {
  const {
    guard,
    immediate = false,
    onLoaded,
    perPage = 20,
    showError = true,
  } = options;

  const loading = ref(false);
  const list = ref<T[]>([]) as Ref<T[]>;
  const total = ref(0);
  const page = ref(1);
  /** 請求序號：搜尋/翻頁很快連續觸發時，只採用最後一次呼叫的回應 */
  let requestSeq = 0;

  async function loadData() {
    if (guard && !guard()) return;
    const seq = ++requestSeq;
    loading.value = true;
    try {
      const res = await fetcher({ page: page.value, per_page: perPage });
      if (seq !== requestSeq) return;
      if (Number(res.code) === 1000 || Number(res.code) === 200) {
        list.value = res.data ?? [];
        total.value = res.pagination?.total ?? 0;
      } else {
        list.value = [];
        total.value = 0;
        if (showError && res.message) ElMessage.error(res.message);
      }
      onLoaded?.(res);
    } catch {
      if (seq !== requestSeq) return;
      list.value = [];
      total.value = 0;
    } finally {
      if (seq === requestSeq) loading.value = false;
    }
  }

  /** 查詢：重設到第 1 頁再載入 */
  function search() {
    page.value = 1;
    return loadData();
  }

  /** 分頁切換 */
  function handlePageChange(p: number) {
    page.value = p;
    return loadData();
  }

  if (immediate) loadData();

  return {
    handlePageChange,
    list,
    loadData,
    loading,
    page,
    perPage,
    search,
    total,
  };
}
