import { requestClient } from '#/api/request';

/**
 * 國家管理 country API。 */

export interface CountryManageRow {
  code?: string;
  country_code?: number | string;
  id: number | string;
  name?: string;
  phone_rule?: string;
  status?: number;
}

export interface CountryManageDetail extends CountryManageRow {
  names?: Array<{ language_id: number | string; name: string }>;
}

export async function getCountryListApi() {
  return requestClient.post<CountryManageRow[]>('/api/country/list', {});
}

export async function getCountryViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: CountryManageDetail;
    message: string;
  }>('/api/country/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯國家 */
export async function saveCountryApi(payload: {
  code: string;
  country_code: number | string;
  id: number | string;
  name: string;
  names?: Record<string, string>;
  phone_rule: string;
  status: number;
}) {
  const isAdd = !payload.id || Number(payload.id) === 0;
  return requestClient.post<{ code: number | string; message: string }>(
    isAdd ? '/api/country/add' : '/api/country/edit',
    payload,
    { responseReturn: 'body' },
  );
}

export async function toggleCountryStatusApi(
  id: number | string,
  status: number,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/country/edit',
    { id, status },
    { responseReturn: 'body' },
  );
}
