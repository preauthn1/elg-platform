import type { ComputedRef, MaybeRef, Ref } from 'vue';

import { computed, ref, unref } from 'vue';

type SortOrder = '' | 'ascending' | 'descending';

interface UseClientTableOptions<T> {
  /** 每頁筆數，預設 20；傳 Ref 可支援使用者切換每頁筆數 */
  perPage?: MaybeRef<number>;
  /**
   * 關鍵字比對取值；不傳則比對列上所有自有的字串/數字欄位。
   * 建議明確指定，避免比對到圖片網址等雜訊欄位。
   */
  searchFields?: (row: T) => unknown[];
}

function toSortNumber(v: unknown): null | number {
  if (v === '' || v === null || v === undefined) return null;
  const n = Number(v);
  return Number.isNaN(n) ? null : n;
}

/**
 * 前端分頁表格共用狀態：關鍵字搜尋 + 點標頭排序 + 分頁。
 * 管線：source → filter(keyword) → sort → slice(page)。
 * 排序作用在整份清單而非當前頁（前端分頁 + ElTable 本地排序只會排當頁，故用
 * sortable="custom" 配 handleSortChange）。
 * 匯出要與畫面順序一致時用 `sortedRows`（過濾＋關鍵字＋排序後、未分頁的整份）；
 * `filteredRows` 只過濾不排序。
 *
 * 用法：
 *   const table = useClientTable(allRows, { searchFields: (r) => [r.id, r.name] });
 *   <ElInput v-model="table.keyword.value" @input="table.handleSearch" />
 *   <ElTable :data="table.pagedRows.value" @sort-change="table.handleSortChange">
 *     <ElTableColumn prop="id" sortable="custom" />
 */
export function useClientTable<T extends object>(
  source: ComputedRef<T[]> | Ref<T[]>,
  options: UseClientTableOptions<T> = {},
) {
  const { perPage = 20, searchFields } = options;

  const keyword = ref('');
  const page = ref(1);
  const sortState = ref<{ order: SortOrder; prop: string }>({
    order: '',
    prop: '',
  });

  const filteredRows = computed(() => {
    const kw = keyword.value.trim().toLowerCase();
    if (!kw) return source.value;
    return source.value.filter((row) => {
      const values = searchFields
        ? searchFields(row)
        : Object.values(row).filter(
            (v) => typeof v === 'string' || typeof v === 'number',
          );
      return values.some((v) =>
        String(v ?? '')
          .toLowerCase()
          .includes(kw),
      );
    });
  });

  const sortedRows = computed(() => {
    const { order, prop } = sortState.value;
    if (!order || !prop) return filteredRows.value;
    const dir = order === 'ascending' ? 1 : -1;
    return filteredRows.value.toSorted((a, b) => {
      const av = (a as Record<string, unknown>)[prop];
      const bv = (b as Record<string, unknown>)[prop];
      const an = toSortNumber(av);
      const bn = toSortNumber(bv);
      if (an !== null && bn !== null) return (an - bn) * dir;
      return String(av ?? '').localeCompare(String(bv ?? '')) * dir;
    });
  });

  const total = computed(() => sortedRows.value.length);
  const pagedRows = computed(() => {
    const size = unref(perPage);
    return sortedRows.value.slice((page.value - 1) * size, page.value * size);
  });

  /** 關鍵字變動：跳回第 1 頁 */
  function handleSearch() {
    page.value = 1;
  }

  /** ElTable @sort-change（欄位需 sortable="custom"） */
  function handleSortChange({
    order,
    prop,
  }: {
    order: null | string;
    prop: null | string;
  }) {
    sortState.value = {
      order: (order ?? '') as SortOrder,
      prop: order ? (prop ?? '') : '',
    };
    page.value = 1;
  }

  /** 分頁切換 */
  function handlePageChange(p: number) {
    page.value = p;
  }

  return {
    filteredRows,
    handlePageChange,
    handleSearch,
    handleSortChange,
    keyword,
    page,
    pagedRows,
    perPage,
    sortedRows,
    total,
  };
}
