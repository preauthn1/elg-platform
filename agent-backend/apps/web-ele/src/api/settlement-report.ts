import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 結算報表 API */

export interface SettlementPeriod {
  end_date: string;
  period: number | string;
  start_date: string;
}

/** 結算期數下拉 */
export async function getSettlementPeriodApi() {
  return requestClient.post<SettlementPeriod[]>(
    '/api/dropdown/settlement_report_period',
    {},
  );
}

export interface AgentReportRow {
  agent_name: string;
  bonus: number | string;
  count: number | string;
  down_profit: number | string;
  live: number | string;
  other: number | string;
  parent_username: string;
  profit_1: number | string;
  score: number | string;
  slot: number | string;
  sport: number | string;
}

export interface AgentReportSummary {
  bonus: number | string;
  count: number | string;
  down_profit: number | string;
  live: number | string;
  other: number | string;
  profit_1: number | string;
  score: number | string;
  slot: number | string;
  sport: number | string;
  total_profit: number | string;
  total_sales: number | string;
}

export interface AgentReportBody {
  code: number;
  data: AgentReportRow[];
  message: string;
  pagination?: Pagination;
  summary_data?: AgentReportSummary;
}

export interface AgentReportParams {
  end_period: number | string;
  page: number;
  per_page: number;
  start_period: number | string;
  type?: number | string;
  username?: string;
}

/** 代理傭金報表 */
export async function getAgentReportApi(params: AgentReportParams) {
  return requestClient.post<AgentReportBody>(
    '/api/settlement_report/get_agent_report',
    params,
    { responseReturn: 'body' },
  );
}

export interface CustomerReportHeader {
  bonus: number | string;
  customer_name: string;
  vendor_fee: number | string;
}

export interface CustomerReportSubRow {
  agent_id?: number | string;
  agent_username?: string;
  bonus: number | string;
  commission: number | string;
  customer_name: string;
  proportions: number | string;
  rebate: number | string;
  sales: number | string;
  vendor_fee: number | string;
}

export interface CustomerReportBody {
  code: number;
  data: {
    header: CustomerReportHeader;
    sub: CustomerReportSubRow[];
  };
  message: string;
  pagination?: Pagination;
}

export interface CustomerReportParams {
  end_period: number | string;
  page: number;
  per_page: number;
  start_period: number | string;
  username?: string;
}

/** 公司總報表 */
export async function getCustomerReportApi(params: CustomerReportParams) {
  return requestClient.post<CustomerReportBody>(
    '/api/settlement_report/get_customer_report',
    params,
    { responseReturn: 'body' },
  );
}

export interface ParamRow {
  key?: string;
  value: number | string;
}

/** 系統參數清單 */
export async function getParamListApi(key: string) {
  return requestClient.post<ParamRow[]>('/api/param/list', { key });
}

export interface SettlementActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 重算代理報表 */
export async function rerunSettlementReportApi(period: number | string) {
  return requestClient.post<SettlementActionResult>(
    '/api/settlement_report/rerun',
    { period },
    { responseReturn: 'body' },
  );
}

/** 派發代理報表 */
export async function paySettlementReportApi(period: number | string) {
  return requestClient.post<SettlementActionResult>(
    '/api/settlement_report/payout',
    { period },
    { responseReturn: 'body' },
  );
}
