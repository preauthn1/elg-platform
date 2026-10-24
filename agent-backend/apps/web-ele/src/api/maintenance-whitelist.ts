import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 系統維護開關與白名單 API
 * 維護白名單 index/add_list（含維護動作）。 */

export interface MaintenanceWhitelistRow {
  id: number | string;
  type: number | string;
  value: string;
}

export interface MaintenanceWhitelistBody {
  code: number;
  data: MaintenanceWhitelistRow[];
  message: string;
  pagination?: Pagination;
}

export interface MaintenanceWhitelistParams {
  page: number;
  per_page: number;
  type: number | string;
  value?: string;
}

/** 白名單列表 */
export async function getMaintenanceWhitelistApi(
  params: MaintenanceWhitelistParams,
) {
  return requestClient.post<MaintenanceWhitelistBody>(
    '/api/system_maintenance/list',
    params,
    { responseReturn: 'body' },
  );
}

/** 是否維護中 */
export async function getHasMaintenanceApi() {
  return requestClient.post<boolean>('/api/has_maintenance', {});
}

export interface MaintenanceActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 切換後台維護開關 */
export async function toggleBackendMaintenanceApi(status: number | string) {
  return requestClient.post<MaintenanceActionResult>(
    '/api/system_maintenance/backendMaintenanceToggleStatus',
    { status },
    { responseReturn: 'body' },
  );
}

/** 新增白名單 */
export async function addMaintenanceWhitelistApi(
  type: number | string,
  value: string,
) {
  return requestClient.post<MaintenanceActionResult>(
    '/api/system_maintenance/add',
    { type, value },
    { responseReturn: 'body' },
  );
}

/** 刪除白名單 */
export async function deleteMaintenanceWhitelistApi(id: number | string) {
  return requestClient.post<MaintenanceActionResult>(
    '/api/system_maintenance/delete',
    { id },
    { responseReturn: 'body' },
  );
}
