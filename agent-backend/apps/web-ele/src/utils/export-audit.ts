import { ElMessage } from 'element-plus';

import { writeExportOperateLogApi } from '#/api';
import { tl } from '#/locales';

/**
 * 匯出稽核紀錄：每一次 Excel 匯出都要寫一筆代理操作紀錄（type=export_data）。
 *
 * 寫入由 exportExcel()／useExcelExport() 統一觸發，頁面只需給 action 與查詢條件，
 * 漏給也還是會寫一筆（action 退回由頁面路徑推導、條件為空），不會整頁沒紀錄。
 */

/** 呼叫端提供的稽核資訊 */
export interface ExportAuditInfo {
  /**
   * 匯出動作代碼。同一個頁面有多個匯出鈕（主表＋明細彈窗）時要各自給，
   * 否則兩者會推導出同一個 action。
   */
  action?: string;
  /** 查詢條件；直接給查列表用的參數即可，分頁與敏感欄位後端會再濾一次 */
  conditions?: Record<string, unknown>;
  /** 選單名稱，預設用匯出的標題 */
  menuName?: string;
  /** 資料筆數；rows 夾帶總計等非資料列時要明確給，否則會比畫面多算 */
  recordCount?: number;
}

/** 後端 action 只收 [A-Za-z0-9_]，長度 50 */
function pathToAction(menuUrl: string): string {
  const action = menuUrl.replaceAll(/[^a-z0-9]/gi, '_').slice(0, 50);
  return action || 'export_excel';
}

/**
 * 匯出當下的頁面路徑（member/index），對應 agent_menus.url。
 *
 * 用 import() 而不是頂層 import router：本檔會被各頁面的匯出流程引用，
 * 而 router 這條線最終又會載入頁面，靜態相依會繞成一圈。
 */
async function currentMenuUrl(): Promise<string> {
  try {
    const { router } = await import('#/router');
    return router.currentRoute.value.path.replace(/^\/+/, '').slice(0, 100);
  } catch {
    return '';
  }
}

/**
 * 寫入一筆匯出稽核紀錄；回 false 代表呼叫端不得產出檔案。
 *
 * @param recordCount 資料列數（不含總計等非資料列）
 * @param title 匯出標題，稽核紀錄對不到選單時由它顯示選單名稱
 */
export async function logExcelExport(
  recordCount: number,
  title?: string,
  audit?: ExportAuditInfo,
): Promise<boolean> {
  const menuUrl = await currentMenuUrl();

  try {
    const res = await writeExportOperateLogApi({
      action: audit?.action ?? pathToAction(menuUrl),
      conditions: audit?.conditions ?? {},
      menu_name: audit?.menuName ?? title ?? '',
      menu_url: menuUrl,
      record_count: Math.max(0, Math.trunc(audit?.recordCount ?? recordCount)),
    });

    if (Number(res?.code) === 1000 || Number(res?.code) === 200) {
      return true;
    }
  } catch {
    // 逾時／連線失敗一律當寫入失敗處理
  }

  ElMessage.error(tl('export_audit_failed'));
  return false;
}
