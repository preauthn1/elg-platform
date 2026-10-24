import { requestClient } from '#/api/request';

/**
 * 首頁相關 API。 */

export interface AgentSetting {
  setting_key: number;
  value: any;
}

export interface AnnouncementRow {
  content: string;
  created_at: string;
  id: number;
  is_top: number;
  type: number;
}

export interface RebateProportion {
  game_type_name?: string;
  item_type?: number;
  proportion_1: number;
  proportion_2: number;
  proportion_3: number;
  vendor_name: string;
}

export interface AgentView {
  balance: number | string;
  email: string;
  last_login: string;
  mobile: string;
  nickname: string;
  status: number;
  username: string;
  [key: string]: any;
}

/** 代理自訂設定 */
export async function getCustomizedSettingApi() {
  return requestClient.post<AgentSetting[]>(
    '/api/agent/customized-setting',
    {},
  );
}

/** 代理本人資料 */
export async function getAgentViewApi(id: number | string) {
  return requestClient.post<AgentView>('/api/agent/view', { id });
}

/** 公告列表 */
export async function getAnnouncementsApi(params: {
  page?: number;
  per_page?: number;
  type?: number;
}) {
  return requestClient.post<AnnouncementRow[]>(
    '/api/announcement/showByAgentId',
    {
      page: params.page ?? 1,
      per_page: params.per_page ?? 20,
      type: params.type ?? 0,
    },
  );
}

/** 標記代理公告為已讀 */
export async function markAnnouncementReadApi(id?: number | string) {
  return requestClient.post('/api/announcement/updateAgentAnnouncementStatus', {
    id: id ?? '',
  });
}

/** 一般代理判斷 */
export async function getGeneralAgentApi() {
  return requestClient.post<{ host: Array<{ id: number }> }>(
    '/api/announcement/getGeneralAgent',
    {},
  );
}

/** 股東返水占成列表 */
export async function getRebateProportionApi() {
  return requestClient.post<RebateProportion[]>(
    '/api/three_hierarchy/getProportion',
    { type: 1 },
  );
}
