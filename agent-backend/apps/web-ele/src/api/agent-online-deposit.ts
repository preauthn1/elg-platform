import { requestClient } from '#/api/request';

/**
 * 代理線上存款設定（agent_online_deposit_setting）API
 * 命名以 AgentCashFlow / Acf 區隔已存在的後台金流設定 deposit.ts（CashFlow*）。 */

/** 主列表一列 */
export interface AgentCashFlowRow {
  agent_cash_flow_name?: string;
  agent_cash_flow_order_type_id?: number | string;
  backend_display?: number | string;
  cash_flow_id?: number | string;
  cost?: number | string;
  currency?: string;
  display_name?: string;
  id: number | string;
  info?: string;
  is_recommend?: number | string;
  name?: string;
  order_type_id?: number | string;
  order_type_name?: string;
  order_type_names?: Record<string, string>;
  rate?: number | string;
  sorts?: number | string;
  status?: number | string;
  tax?: number | string;
  tax_percentage?: number | string;
  type?: number | string;
  [key: string]: any;
}

/** 金流商下拉 */
export interface AgentCashFlowOption {
  id: number | string;
  name?: string;
}

export interface AgentOnlineDepositBody {
  code: number;
  data?: {
    agent_cash_flow_list: AgentCashFlowRow[];
    agent_group: { id: number | string; name?: string };
    cash_flow_list: AgentCashFlowOption[];
  };
  message: string;
}

/** 金流設定主資料 */
export async function getAgentOnlineDepositListApi(groupId: number | string) {
  return requestClient.post<AgentOnlineDepositBody>(
    '/api/agent/onlineDeposit/cashFlowList',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 切換金流商狀態 */
export async function agentCashFlowToggleStatusApi(data: {
  acf_id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number; data?: any; message: string }>(
    '/api/agent/onlineDeposit/agentCashFlowToggleStatus',
    data,
    { responseReturn: 'body' },
  );
}

/** 新增/編輯金流商 */
export async function updateAgentCashFlowApi(data: {
  agent_cash_flow_name: string;
  backend_display: number | string;
  cash_flow_id: number | string;
  cost: number | string;
  display_name: Record<string, string>;
  group_id: number | string;
  id: number | string;
  info?: string;
  is_recommend: number | string;
  order_type_id: number | string;
  rate: number | string;
  sorts: number | string;
  tax: number | string;
  tax_percentage: number | string;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/agent/onlineDeposit/updateAgentCashFlow',
    data,
    { responseReturn: 'body' },
  );
}

/** 金流商支付方式清單 */
export interface AcfOrderTypeRow {
  available_amount?: string;
  id: number | string;
  max_amount?: number | string;
  min_amount?: number | string;
  name?: string;
  settings?: string;
  sorts?: number | string;
  status?: number | string;
  [key: string]: any;
}
export interface AcfOrderTypeOption {
  id: number | string;
  name?: string;
}
export interface AcfOrderTypeListBody {
  code: number;
  data?: {
    acf_order_type_list: AcfOrderTypeRow[];
    order_type_list: AcfOrderTypeOption[];
  };
  message: string;
}
export async function getAcfOrderTypeListApi(data: {
  acf_id: number | string;
  group_id: number | string;
}) {
  return requestClient.post<AcfOrderTypeListBody>(
    '/api/agent/onlineDeposit/acfOrderTypeList',
    data,
    { responseReturn: 'body' },
  );
}

/** 切換支付方式狀態 */
export async function agentCashFlowOrderTypeToggleStatusApi(data: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number; data?: any; message: string }>(
    '/api/agent/onlineDeposit/agentCashFlowOrderTypeToggleStatus',
    data,
    { responseReturn: 'body' },
  );
}

/** 新增/編輯支付方式 */
export async function updateAgentCashFlowOrderTypeApi(data: {
  agent_cash_flow_id: number | string;
  available_amount?: string;
  id: number | string;
  max_amount: number | string;
  min_amount: number | string;
  order_type_id: number | string;
  popup_text: string[];
  sorts: number | string;
}) {
  return requestClient.post<{ code: number; data?: any; message: string }>(
    '/api/agent/onlineDeposit/updateAgentCashFlowOrderType',
    data,
    { responseReturn: 'body' },
  );
}

/** 刪除支付方式 */
export async function deleteAgentCashFlowOrderTypeApi(id: number | string) {
  return requestClient.post<{ code: number; data?: any; message: string }>(
    '/api/agent/onlineDeposit/agentCashFlowOrderTypeDelete',
    { id },
    { responseReturn: 'body' },
  );
}

/** 依幣別批次調整存款匯率 */
export async function updateDepositRateByCurrencyApi(data: {
  currency: number | string;
  rate: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/agent/onlineDeposit/updateRateByCurrency',
    data,
    { responseReturn: 'body' },
  );
}
