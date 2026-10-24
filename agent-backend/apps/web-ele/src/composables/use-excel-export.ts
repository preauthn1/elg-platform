import type { ExportAuditInfo } from '#/utils/export-audit';

import { ref } from 'vue';

import { exportExcel } from '#/utils/export-excel';

/** Excel 儲存格型別（對齊 export-excel） */
type ExportCell = null | number | string | undefined;

/** 後端統一回應信封（舊 api/ 成功碼 1000；apis、agent/api/ 成功碼 200） */
interface ExportResponse<T> {
  code: number | string;
  data?: null | T[];
}

/** 匯出排版選項（標題列 / 報表區間列）與稽核資訊 */
interface ExportOptions {
  audit?: ExportAuditInfo;
  dateRange?: [string, string];
  title?: string;
}

/**
 * 列表匯出共用：提供 exporting 狀態與 runExport。
 */
export function useExcelExport() {
  const exporting = ref(false);

  async function runExport<T>(
    fetcher: () => Promise<ExportResponse<T>>,
    filename: string,
    headers: ExportCell[],
    mapRow: (row: T) => ExportCell[],
    options?: ExportOptions,
  ) {
    exporting.value = true;
    try {
      const res = await fetcher();
      const ok = Number(res.code) === 1000 || Number(res.code) === 200;
      const rows = ok ? (res.data ?? []) : [];
      await exportExcel({
        audit: options?.audit,
        columns: headers.map((h) => String(h ?? '')),
        dateRange: options?.dateRange,
        filename,
        rows: rows.map((row) => mapRow(row)),
        title: options?.title,
      });
    } finally {
      exporting.value = false;
    }
  }

  return { exporting, runExport };
}
