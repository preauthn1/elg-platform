import { requestClient } from '#/api/request';

/**
 * 代理簡易結算報表 API */

export interface SimpleSettlementDashboard {
  bet: number | string;
  bonus: number | string;
  deposit: number | string;
  jackpot: number | string;
  percentage: number | string;
  period_reg: number | string;
  team_commission: number | string;
  total_reg: number | string;
  win_lose: number | string;
  withdraw: number | string;
}

export interface SimpleSettlementDashboardBody {
  code: number;
  data: SimpleSettlementDashboard;
  message: string;
}

export interface SimpleSettlementDashboardParams {
  end_time: string;
  start_time: string;
  username?: string;
}

/** 統計總計 */
export async function getSimpleSettlementDashboardApi(
  params: SimpleSettlementDashboardParams,
) {
  return requestClient.post<SimpleSettlementDashboardBody>(
    '/api/simple_settlement_report/dashboard',
    params,
    { responseReturn: 'body' },
  );
}

export interface SimpleSettlementAgentInfo {
  hierarchy?: string;
  percentage: number | string;
  username: string;
}

export interface SimpleSettlementReportRow {
  agent_id: number | string;
  bet: number | string;
  bonus: number | string;
  commission: number | string;
  gross_pnl: number | string;
  id: number | string;
  net_pnl: number | string;
  pay_time?: string;
  percentage: number | string;
  status: number | string;
  team_commission: number | string;
  total_commission: number | string;
  updated_at: string;
  username: string;
}

export interface SimpleSettlementReportBody {
  agent_host?: SimpleSettlementAgentInfo;
  agent_parent?: SimpleSettlementAgentInfo;
  code: number;
  data: SimpleSettlementReportRow[];
  message: string;
}

export interface SimpleSettlementReportParams {
  period_start: string;
  username?: string;
}

/** 結算報表 */
export async function getSimpleSettlementReportApi(
  params: SimpleSettlementReportParams,
) {
  return requestClient.post<SimpleSettlementReportBody>(
    '/api/simple_settlement_report/getSettlementReport',
    params,
    { responseReturn: 'body' },
  );
}

export interface SimpleSettlementDownlineRow {
  agent_id: number | string;
  percentage: number | string;
  username: string;
}

export interface SimpleSettlementDownlineBody {
  code: number;
  data: {
    agent_host?: SimpleSettlementAgentInfo;
    agent_list: SimpleSettlementDownlineRow[];
    agent_parent?: SimpleSettlementAgentInfo;
    percentage_limit: number | string;
  };
  message: string;
}

/** 下線佔成列表 */
export async function getSimpleSettlementDownlineListApi(username?: string) {
  return requestClient.post<SimpleSettlementDownlineBody>(
    '/api/simple_settlement_report/getAgentDownlineList',
    username ? { username } : {},
    { responseReturn: 'body' },
  );
}

export interface SimpleSettlementActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 設定代理佔成 */
export async function setSimpleSettlementAgentPercentageApi(
  agentId: number | string,
  percentage: number | string,
) {
  return requestClient.post<SimpleSettlementActionResult>(
    '/api/simple_settlement_report/setAgentPercentage',
    { agent_id: agentId, percentage: Number.parseInt(String(percentage), 10) },
    { responseReturn: 'body' },
  );
}

/** 設定派發狀態 */
export async function setSimpleSettlementStatusApi(
  id: number | string,
  value: number | string,
) {
  return requestClient.post<SimpleSettlementActionResult>(
    '/api/simple_settlement_report/setStatus',
    { id, value },
    { responseReturn: 'body' },
  );
}
