import { requestClient } from '#/api/request';

/**
 * 股東（cj7）新增/編輯 API（agent/cj7_edit、cj7_add_sub_account）。
 * 讀取重用 getAgentViewByIdApi、searchAgentOptionsApi、confirmAgentPasswordApi、
 * setAgentPermissionApi、getCustomizedSettingApi。 */

/** 新增股東時套用的預設 CJ7 權限選單碼 */
export const DEFAULT_CJ7_PERMISSIONS = [
  '190',
  '193',
  '197',
  '198',
  '199',
  '200',
  '201',
  '202',
  '203',
];

/** 取代理股份 */
export async function getAgentShareHolderApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { value?: number | string };
    message: string;
  }>('/api/agent/getAgentShareHolder', { id }, { responseReturn: 'body' });
}

/** 新增/編輯股東（api/agent/cj7_add | api/agent/cj7_edit）。
 *  新增成功回 data=新代理 id（呼叫端再套預設權限）。 */
export async function saveCj7AgentApi(params: {
  id?: number | string;
  nickname?: string;
  parent?: number | string;
  password?: string;
  password_confirmation?: string;
  share?: number | string;
  username?: string;
}) {
  const isEdit = params.id !== undefined && params.id !== '';
  const body: Record<string, any> = {
    nickname: params.nickname ?? '',
    share: params.share ?? 0,
  };
  if (params.password) {
    body.password = [REDACTED];
    body.password_confirmation = params.password_confirmation ?? '';
  }
  if (isEdit) {
    body.id = params.id;
  } else {
    body.username = params.username ?? '';
    body.parent = params.parent ?? 0;
  }
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>(isEdit ? '/api/agent/cj7_edit' : '/api/agent/cj7_add', body, {
    responseReturn: 'body',
  });
}

/** 新增子帳號 */
export async function cj7AddSubAccountApi(params: Record<string, any>) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/agent/cj7_add_sub_account', params, { responseReturn: 'body' });
}
