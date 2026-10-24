import { requestClient } from '#/api/request';

/** 內部工具：Excel 轉多語系（internal-helper/excel_helper）。 */

/** 轉換結果（api/data-convert/excel/to/php-lang） */
export interface ExcelLangConvertData {
  /** 檔名 → 各語言輸出的語言檔程式碼（索引對應 list_langs） */
  file_outCode_result?: Record<string, string[]>;
  list_files?: string[];
  list_langs?: string[];
  /** 未定義的多語系鍵值（選 Web.AgentBackend 分類時比對） */
  undefined_columns?: Array<{ file_name: string; lang_key: string }>;
}

/**
 * 轉換 Excel 貼上內容為語言檔格式。
 * 對齊舊版 ajax.php helper_convert_excel_to_langFileFormat：
 * applicationType 51（Web.AgentBackend）需帶 compareTargetLangKeys 檢查未定義鍵值；
 * isCheck 走 query string（舊版 UI 的檢查開關已註解，固定 0）。
 */
export async function convertExcelToLangApi(
  data: {
    applicationType: number | string;
    compareTargetLangKeys?: string;
    excel_content: string;
  },
  isCheck: number | string = 0,
) {
  return requestClient.post<{
    code?: number | string;
    data?: ExcelLangConvertData;
    message?: string;
  }>(
    `/api/data-convert/excel/to/php-lang?isCheck=${encodeURIComponent(String(isCheck))}`,
    data,
    { responseReturn: 'body' },
  );
}
