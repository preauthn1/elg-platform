import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 占成設定 API（公司占成 customer_proportion / 代理占成 agent_proportion） */

/** 代理占成列 */
export interface AgentProportionRow {
  agent_username: string;
  id: number | string;
  parent_username: string;
  proportion_1: string;
  proportion_2: string;
  proportion_3: string;
  sharecode: string;
}

export interface AgentProportionListBody {
  code: number;
  data: AgentProportionRow[];
  message: string;
  pagination?: Pagination;
}

export interface ProportionListParams {
  page: number;
  per_page: number;
  username?: string;
}

/** 代理占成列表 */
export async function getAgentProportionListApi(params: ProportionListParams) {
  return requestClient.post<AgentProportionListBody>(
    '/api/agent_proportion/list',
    params,
    { responseReturn: 'body' },
  );
}

/** 公司占成列 */
export interface CustomerProportionRow {
  agent_username: string;
  bonus: number | string;
  customer_proportion: number | string;
  id: number | string;
  remark: string;
  sharecode: string;
  type: number | string;
  vendor_fee: string;
}

export interface CustomerProportionListBody {
  code: number;
  data: CustomerProportionRow[];
  message: string;
  pagination?: Pagination;
}

/** 公司占成列表 */
export async function getCustomerProportionListApi(
  params: ProportionListParams,
) {
  return requestClient.post<CustomerProportionListBody>(
    '/api/customer_proportion/list',
    params,
    { responseReturn: 'body' },
  );
}

export interface ProportionActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 遊戲商盤費下拉 */
export async function getVendorFeeDropdownApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/vendor/dropdownList',
    { type: 1 },
  );
}

/**
 * 公司占成更新（單值）。
 * type 1=公司占成 / 3=Bonus → val 直接送輸入百分比；
 * type 2=單一盤費 → val 包成 { '0': 百分比/100 }；
 * type 4=備註 → val 為文字。 */
export async function updateCustomerProportionApi(
  id: number | string,
  type: number | string,
  rawVal: number | string,
) {
  let val: number | Record<string, number> | string = rawVal;
  if (Number(type) === 2) {
    val = { 0: Number(rawVal) / 100 };
  }
  return requestClient.post<ProportionActionResult>(
    '/api/customer_proportion/update',
    { id, type, val },
    { responseReturn: 'body' },
  );
}

/** 多遊戲商盤費更新 */
export async function saveCustomerVendorFeeApi(
  id: number | string,
  vendorPercents: Record<string, number | string>,
) {
  const val: Record<string, number> = {};
  for (const [k, v] of Object.entries(vendorPercents)) {
    val[k] = Number(v) / 100;
  }
  return requestClient.post<ProportionActionResult>(
    '/api/customer_proportion/update',
    { id, type: 2, val },
    { responseReturn: 'body' },
  );
}

/** 代理占成更新 */
export async function saveAgentProportionApi(
  id: number | string,
  klass: number | string,
  percents: Record<string, number | string>,
) {
  const proportion: Record<string, number> = {};
  for (const [k, v] of Object.entries(percents)) {
    proportion[k] = Number(v) / 100;
  }
  return requestClient.post<ProportionActionResult>(
    '/api/agent_proportion/update',
    { class: klass, id, proportion },
    { responseReturn: 'body' },
  );
}
