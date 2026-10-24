import type { ExportAuditInfo } from '#/utils/export-audit';

import * as XLSX from 'xlsx-js-style';

import { tl } from '#/locales/legacy';
import { logExcelExport } from '#/utils/export-audit';
import { currentTzOffset, nowInUserTz } from '#/utils/timezone-shift';

/**
 * 真 .xlsx 匯出（xlsx-js-style，支援樣式）。
 * 還原舊版 PHP 後台 ajax_excel.php 的排版：
 *   合併標題列 → （可選）報表區間列 → 粗體置中表頭 → 置中自動換行資料，
 *   欄寬可指定，單號欄強制文字型別避免被 Excel 轉成科學記號。
 *
 * 每次匯出都會先寫一筆代理操作紀錄，寫不進去就不產檔（fail-closed）。
 */

type Cell = null | number | string | undefined;

/** 單一欄定義 */
export interface ExcelColumn {
  /** Excel 數字格式（如 '0.0000'）；只影響數值儲存格的顯示，不改值（工單4407） */
  numFmt?: string;
  /** 表頭文字 */
  title: string;
  /** 是否強制存成文字（單號/長數字避免變成 2.02E+17） */
  string?: boolean;
  /** Excel 欄寬（字元數，對應舊版 PHPExcel width） */
  width?: number;
}

/** 新版 options 呼叫 */
export interface ExportExcelOptions {
  /** 稽核紀錄資訊（action／查詢條件／筆數修正） */
  audit?: ExportAuditInfo;
  /** 欄定義（字串視為純表頭、自動寬度） */
  columns: (ExcelColumn | string)[];
  /** 報表區間 [start, end]；有值才畫「報表區間」列 */
  dateRange?: [string, string];
  /** 檔名（不含副檔名亦可）。省略時用 `{title}{YYYY-MM-DD}.xlsx` */
  filename?: string;
  /** 每列為儲存格陣列 */
  rows: Cell[][];
  /** 工作表名（預設 Sheet1） */
  sheetName?: string;
  /** 合併標題列文字 */
  title?: string;
  /**
   * 匯出內容的時區口徑（小時偏移，-12~12）。標在檔名與區間列／標題列，
   * 讓拿到檔案的人能判讀時間欄位是哪個時區（target/tickets#4095）。
   * 預設＝當前所選時區；整頁固定 GMT+8 的報表必須明確傳 8。
   */
  tzOffset?: number;
}

const HEADER_STYLE = {
  alignment: { horizontal: 'center', vertical: 'center' },
  font: { bold: true, sz: 11 },
} as const;

const TITLE_STYLE = {
  alignment: { horizontal: 'center', vertical: 'center' },
  font: { bold: true, sz: 11 },
} as const;

const DATA_STYLE = {
  alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
  font: { sz: 11 },
} as const;

/** 自動欄寬：依內容最長字元數估算，夾在 8~50 之間 */
function autoWidth(title: string, rows: Cell[][], colIndex: number): number {
  let max = displayLen(title);
  for (const row of rows) {
    max = Math.max(max, displayLen(row[colIndex]));
  }
  return Math.min(50, Math.max(8, max + 2));
}

/** 估算顯示寬度（CJK 視為 2） */
function displayLen(value: Cell): number {
  const s = String(value ?? '');
  let len = 0;
  for (const ch of s) {
    len += (ch.codePointAt(0) ?? 0) > 255 ? 2 : 1;
  }
  return len;
}

/** 清掉檔名非法字元（Windows 不接受 \ / : * ? " < > |），避免下載失敗 */
function sanitizeFilename(name: string): string {
  return name.replaceAll(/[/:*?"<>|]/g, '_').trim();
}

/** 位置呼叫的第 4 參數：字串視為 sheetName，物件可帶標題/區間列 */
export interface ExcelPositionalExtra {
  audit?: ExportAuditInfo;
  dateRange?: [string, string];
  sheetName?: string;
  title?: string;
  /** 匯出內容的時區口徑；固定 GMT+8 的報表傳 8，省略＝當前所選時區 */
  tzOffset?: number;
}

/**
 * 匯出 .xlsx。
 *
 * 兩種呼叫方式：
 * 1) 新版（建議）：`exportExcel({ title, dateRange, columns, rows, audit })`
 * 2) 舊版相容：`exportExcel(filename, headers, rows, { title, dateRange, audit })`
 *    （第 4 參數亦可只給字串當 sheetName）
 *
 * 稽核紀錄寫入失敗時不產檔，呼叫端要 await 才等得到這個結果。
 */
export function exportExcel(options: ExportExcelOptions): Promise<void>;
export function exportExcel(
  filename: string,
  headers: Cell[],
  rows: Cell[][],
  extra?: ExcelPositionalExtra | string,
): Promise<void>;
export async function exportExcel(
  arg1: ExportExcelOptions | string,
  headers?: Cell[],
  rows?: Cell[][],
  extra?: ExcelPositionalExtra | string,
): Promise<void> {
  const extraOpts: ExcelPositionalExtra =
    typeof extra === 'string' ? { sheetName: extra } : (extra ?? {});
  const opts: ExportExcelOptions =
    typeof arg1 === 'string'
      ? {
          audit: extraOpts.audit,
          columns: (headers ?? []).map((h) => String(h ?? '')),
          dateRange: extraOpts.dateRange,
          filename: arg1,
          rows: rows ?? [],
          sheetName: extraOpts.sheetName,
          title: extraOpts.title,
          tzOffset: extraOpts.tzOffset,
        }
      : arg1;

  const tzOffset = opts.tzOffset ?? currentTzOffset();

  // 稽核紀錄先寫，寫不進去就不產檔
  if (!(await logExcelExport(opts.rows.length, opts.title, opts.audit))) {
    return;
  }

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(
    wb,
    buildSheet(opts, tzLabelOf(tzOffset)),
    sheetName(opts.sheetName, 0),
  );

  writeWorkbook(wb, opts.filename, opts.title, tzOffset);
}

/** 一個工作表的內容 */
export interface ExcelSheet {
  /** 欄定義（字串視為純表頭、自動寬度） */
  columns: (ExcelColumn | string)[];
  /** 報表區間 [start, end]；有值才畫「報表區間」列 */
  dateRange?: [string, string];
  /** 工作表名（Excel 上限 31 字，且不接受 : \ / ? * [ ]） */
  name?: string;
  /** 每列為儲存格陣列 */
  rows: Cell[][];
  /** 合併標題列文字 */
  title?: string;
}

export interface ExportWorkbookOptions {
  /** 稽核紀錄資訊；`recordCount` 省略時取各分頁列數總和 */
  audit?: ExportAuditInfo;
  /** 檔名（不含副檔名亦可）。省略時用 `{title}{YYYY-MM-DD}` */
  filename?: string;
  /** 各分頁內容，依序寫入 */
  sheets: ExcelSheet[];
  /** 檔名與稽核用的標題；省略時取第一個分頁的 title */
  title?: string;
  /** 匯出內容的時區口徑；省略＝當前所選時區 */
  tzOffset?: number;
}

/**
 * 匯出多分頁 .xlsx。各分頁自己一組表頭與欄寬，稽核紀錄整份檔只寫一筆。
 *
 * 單一分頁請直接用 `exportExcel()`；兩者共用同一套排版。
 */
export async function exportExcelWorkbook(
  opts: ExportWorkbookOptions,
): Promise<void> {
  const tzOffset = opts.tzOffset ?? currentTzOffset();
  const title = opts.title ?? opts.sheets[0]?.title;
  const recordCount = opts.sheets.reduce(
    (count, sheet) => count + sheet.rows.length,
    0,
  );

  if (!(await logExcelExport(recordCount, title, opts.audit))) {
    return;
  }

  const wb = XLSX.utils.book_new();
  const tzLabel = tzLabelOf(tzOffset);

  opts.sheets.forEach((sheet, index) => {
    XLSX.utils.book_append_sheet(
      wb,
      buildSheet(sheet, tzLabel),
      sheetName(sheet.name, index),
    );
  });

  writeWorkbook(wb, opts.filename, title, tzOffset);
}

/** 時區標籤：標在檔名、標題列與區間列，讓拿到檔案的人知道時間欄的口徑 */
function tzLabelOf(tzOffset: number): string {
  return tzOffset >= 0 ? `GMT+${tzOffset}` : `GMT${tzOffset}`;
}

/** 工作表名：去掉 Excel 不接受的字元並截到 31 字；空的退回 SheetN */
function sheetName(name: string | undefined, index: number): string {
  const cleaned = (name ?? '').replaceAll(/[/:?*[\\\]]/g, '_').slice(0, 31);

  return cleaned || `Sheet${index + 1}`;
}

/** 組一個工作表：合併標題列 / 區間列 / 表頭 / 資料，並套欄寬、列高與樣式 */
function buildSheet(sheet: ExcelSheet, tzLabel: string): XLSX.WorkSheet {
  const columns: ExcelColumn[] = sheet.columns.map((c) =>
    typeof c === 'string' ? { title: c } : c,
  );
  const dataRows = sheet.rows;

  const colCount = columns.length;
  const lastCol = Math.max(0, colCount - 1);

  const aoa: Cell[][] = [];
  const merges: XLSX.Range[] = [];
  const rowHeights: { hpt: number }[] = [];

  let cursor = 0;
  let titleRowIndex = -1;
  let dateRowIndex = -1;

  if (sheet.title) {
    titleRowIndex = cursor;
    // 沒有區間列時，時區標籤掛在標題列
    aoa.push([sheet.dateRange ? sheet.title : `${sheet.title} (${tzLabel})`]);
    merges.push({ e: { c: lastCol, r: cursor }, s: { c: 0, r: cursor } });
    rowHeights[cursor] = { hpt: 22 };
    cursor++;
  }

  if (sheet.dateRange) {
    const [start, end] = sheet.dateRange;
    dateRowIndex = cursor;
    aoa.push([`${tl('report_between')}：${start} ~ ${end} (${tzLabel})`]);
    merges.push({ e: { c: lastCol, r: cursor }, s: { c: 0, r: cursor } });
    rowHeights[cursor] = { hpt: 20 };
    cursor++;
  }

  const headerRowIndex = cursor;
  aoa.push(columns.map((c) => c.title));
  cursor++;

  const dataStartRow = cursor;

  for (const row of dataRows) {
    aoa.push(
      columns.map((col, i) => {
        const v = row[i];
        // 強制文字欄：轉成字串，aoa_to_sheet 會存成文字型別
        return col.string && v !== null && v !== undefined ? String(v) : v;
      }),
    );
  }

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  ws['!cols'] = columns.map((c, i) => ({
    wch: c.width ?? autoWidth(c.title, dataRows, i),
  }));

  if (rowHeights.length > 0) {
    ws['!rows'] = rowHeights;
  }

  if (merges.length > 0) {
    ws['!merges'] = merges;
  }

  const totalRows = aoa.length;

  // 工單4407：有指定 numFmt 的欄，資料列樣式在 DATA_STYLE 之上加數字格式
  const dataStyles = columns.map((c) =>
    c.numFmt ? { ...DATA_STYLE, numFmt: c.numFmt } : DATA_STYLE,
  );

  for (let r = 0; r < totalRows; r++) {
    for (let c = 0; c < colCount; c++) {
      const addr = XLSX.utils.encode_cell({ c, r });
      const cell = ws[addr];
      if (!cell) continue;
      if (r === titleRowIndex || r === dateRowIndex) {
        cell.s = TITLE_STYLE;
      } else if (r === headerRowIndex) {
        cell.s = HEADER_STYLE;
      } else if (r >= dataStartRow) {
        cell.s = dataStyles[c];
      }
    }
  }

  return ws;
}

/**
 * 寫檔。檔名照舊版：有標題就用 `{標題}{YYYY-MM-DD}`，否則退回 filename；
 * 一律補時區後綴 ` (GMT±N)`。日期取該時區口徑的「今天」（原本用機器時間，
 * 海外使用者半夜匯出會差一天）。
 */
function writeWorkbook(
  wb: XLSX.WorkBook,
  filename: string | undefined,
  title: string | undefined,
  tzOffset: number,
): void {
  const ymd = nowInUserTz(tzOffset).format('YYYY-MM-DD');
  const baseName = (
    title ? `${title}${ymd}` : (filename ?? `export${ymd}`)
  ).replace(/\.xlsx$/i, '');
  const rawName = sanitizeFilename(`${baseName} (${tzLabelOf(tzOffset)})`);

  XLSX.writeFile(wb, `${rawName}.xlsx`);
}
