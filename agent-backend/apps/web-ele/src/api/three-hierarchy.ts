import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 三層代理佔成模組 API（含代理）。
 * 返水報表（api/three_hierarchy/rebate）在 report.ts 的 getRebateReportApi。 */

/** zTree simpleData 節點 */
export interface ThreeHierarchyTreeNode {
  id: number;
  is_agent?: number;
  name: string;
  pId: number;
}

export interface ThreeHierarchyTreeBody {
  code: number | string;
  data?: ThreeHierarchyTreeNode[];
  message: string;
}

/** 查詢代理層級樹 */
export async function getThreeHierarchyMemberTreeApi(username: string) {
  return requestClient.post<ThreeHierarchyTreeBody>(
    '/api/three_hierarchy/get_member_hierarchy',
    { username },
    { responseReturn: 'body' },
  );
}

export interface ThreeHierarchyUserRow {
  /** 工單4190：總獎金（bonus_amount） */
  bonus_amount?: number | string;
  created_at?: string;
  /** 工單4190：身分（member/agent），供身分欄顯示 */
  identity?: string;
  down2_rebate_amount?: number | string;
  down_rebate_amount?: number | string;
  member_username?: string;
  parent_username?: string;
  rebate_amount?: number | string;
  /** 可提領；非數字時顯示原值 */
  withdraw_able?: number | string;
  /** 已提領；非數字時顯示原值 */
  withdraw_amount?: number | string;
}

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 工單4190：兩組統計卡合計 */
export interface ThreeHierarchyUserSummary {
  agent?: {
    bonus_amount?: number | string;
    down2_rebate_amount?: number | string;
    down_rebate_amount?: number | string;
    total?: number;
  };
  member?: {
    bonus_amount?: number | string;
    total?: number;
    withdraw_able?: number | string;
    withdraw_amount?: number | string;
  };
}

/**
 * 用戶查詢（4190：identity 身分篩選、sort/direction 排序、回應含 summary）。
 * 已由 API_Agent user_search 移植到 apis（POST agent/api/three-hierarchy/user-search，
 * 成功碼 200、巢狀 { code, data: { items, pagination, summary } }），此處攤平成
 * 頁面沿用的 { code, data: rows[], pagination, summary } 形狀。
 */
export async function getThreeHierarchyUserSearchApi(params: {
  agent?: string;
  direction?: string;
  identity?: string;
  member?: string;
  page?: number;
  per_page?: number;
  sort?: string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: ThreeHierarchyUserRow[];
      pagination?: Pagination;
      summary?: ThreeHierarchyUserSummary;
    };
    message: string;
  }>(
    '/agent/api/three-hierarchy/user-search',
    { agent: '', member: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
    summary: res.data?.summary,
  };
}

export interface ThreeHierarchyPerformanceRow {
  bet?: number | string;
  game_type?: string;
  game_type_id?: number | string;
  lose?: number | string;
  point_reward?: number | string;
  rolling_chip_reward?: number | string;
  summary_time?: string;
  /** '-' 代表彙總列（可點「詳細」下鑽） */
  username?: string;
  vendor?: string;
  vendor_id?: number | string;
}

/** 業績查詢 */
export async function getThreeHierarchyPerformanceApi(params: {
  agent_line?: number | string;
  agent_username?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
  /** 遊戲商 ID，逗號分隔；空字串＝不篩選 */
  vendor_id?: string;
}) {
  const { end_time, start_time, ...rest } = params;
  return requestClient.post<PagedBody<ThreeHierarchyPerformanceRow>>(
    '/api/three_hierarchy/performance',
    {
      agent_line: 0,
      agent_username: '',
      page: 1,
      per_page: 20,
      username: '',
      ...rest,
      end_time: `${end_time} 23:59:59`,
      start_time: `${start_time} 00:00:00`,
    },
    { responseReturn: 'body' },
  );
}

/** 業績明細 */
export async function getThreeHierarchyPerformanceDetailApi(params: {
  agent_username?: string;
  game_type?: number | string;
  page?: number;
  per_page?: number;
  summary_time: string;
  vendor_id?: number | string;
}) {
  return requestClient.post<PagedBody<ThreeHierarchyPerformanceRow>>(
    '/api/three_hierarchy/performance_detail',
    { agent_username: '', page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 提現紀錄列（4214，apis 版）。一列＝一次返利實際入帳 */
export interface ThreeHierarchyWithdrawRow {
  /** 申請時間（GMT+8） */
  created_at?: string;
  /** 提現單號（UI 全體系以此 id 作為單號） */
  id?: number | string;
  remaining_point?: number | string;
  /** 來源：schedule=自動派發、manual=會員申請（依 admin 判斷） */
  source?: string;
  /** 1=已入帳、0=失敗（兩寫入點皆寫死 1，失敗實際不會出現） */
  status?: number;
  /** 獎勵形式：1=真金、2=禮碼 */
  type?: number;
  username?: string;
  withdraw_point?: number | string;
}

/** 4214：統計卡（提現總額／筆數／去重人數），與列表同條件全期間 */
export interface ThreeHierarchyWithdrawSummary {
  member_count?: number;
  total_count?: number;
  total_point?: string;
}

/**
 * 提現紀錄（4214：由 API_Agent 移植到 apis，成功碼 200、巢狀
 * { code, data: { items, pagination, summary } }，攤平成頁面沿用形狀）。
 * 新增 agent 篩選（該代理線下所有會員）；期間上限 32 天（後端強制）。
 */
export async function getThreeHierarchyWithdrawRecordApi(params: {
  agent?: string;
  end_time: string;
  member?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: ThreeHierarchyWithdrawRow[];
      pagination?: Pagination;
      summary?: ThreeHierarchyWithdrawSummary;
    };
    message: string;
  }>(
    '/agent/api/three-hierarchy/withdraw-record',
    { agent: '', member: '', order_no: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
    summary: res.data?.summary,
  };
}

/** 派發紀錄主表列（4199，apis 版）。一列＝受益人（會員/代理）× 結算日 */
export interface ThreeHierarchyPayoutRow {
  /** 計算基數＝投注額合計；null＝混到輸分佔成資料，顯示「混合，見明細」 */
  calc_base?: null | string;
  /** 派發時間（GMT+8） */
  created_at?: string;
  down2_rebate?: number | string;
  down_rebate?: number | string;
  /** member=會員、agent=代理 */
  identity?: string;
  rebate?: number | string;
  reward?: number | string;
  /** 結算期間（YYYY-MM-DD） */
  summary_time?: string;
  /** 受益人 id（下鑽明細用） */
  target_id?: number;
  username?: string;
  /** 提現單號＝withdraw_logs.id（會員列；未配到或代理列為 null） */
  withdraw_log_id?: null | number;
}

/** 4199：兩組統計卡（會員合計／代理合計），與列表同一組篩選條件 */
export interface ThreeHierarchyPayoutSummary {
  agent?: {
    agent_count?: number;
    down2_rebate?: string;
    down_rebate?: string;
    rebate?: string;
    reward?: string;
  };
  member?: {
    down2_rebate?: string;
    down_rebate?: string;
    member_count?: number;
    rebate?: string;
    reward?: string;
  };
}

/**
 * 派發紀錄（4199：由 API_Agent 移植到 apis，成功碼 200、巢狀
 * { code, data: { items, pagination, summary } }，攤平成頁面沿用形狀）。
 * 期間上限 32 天（後端強制）。
 */
export async function getThreeHierarchyPayoutRecordApi(params: {
  agent?: string;
  end_time: string;
  identity?: string;
  member?: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: ThreeHierarchyPayoutRow[];
      pagination?: Pagination;
      summary?: ThreeHierarchyPayoutSummary;
    };
    message: string;
  }>(
    '/agent/api/three-hierarchy/payout-record',
    { agent: '', member: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
    summary: res.data?.summary,
  };
}

/** 派發紀錄下鑽明細列（4199）：一列＝日報原始列（來源會員×遊戲商×遊戲類別×反水類別） */
export interface ThreeHierarchyPayoutDetailRow {
  /** 基數（該列投注額或輸分） */
  base?: string;
  from_username?: null | string;
  game_type_name?: null | string;
  /** 1=投注量、2=輸分 */
  rebate_type?: number;
  reward?: string;
  /** self=自身、down=下層、down2=下下層；全 0 列無法判定為 null */
  tier?: null | string;
  vendor_name?: null | string;
}

/** 派發紀錄下鑽明細（4199）：附全量合計與反水類別小計 */
export async function getThreeHierarchyPayoutRecordDetailApi(params: {
  id: number;
  identity: string;
  page?: number;
  per_page?: number;
  summary_time: string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: ThreeHierarchyPayoutDetailRow[];
      pagination?: Pagination;
      summary?: {
        base?: string;
        by_rebate_type?: Array<{
          base?: string;
          rebate_type?: number;
          reward?: string;
        }>;
        reward?: string;
      };
    };
    message: string;
  }>(
    '/agent/api/three-hierarchy/payout-record/detail',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
    summary: res.data?.summary,
  };
}

/** 佔成設定列 */
export interface ThreeHierarchyProportionRow {
  game_type_id: number | string;
  game_type_name?: string;
  id: number | string;
  proportion_1?: number | string;
  proportion_2?: number | string;
  proportion_3?: number | string;
  vendor_id: number | string;
  vendor_name?: string;
}

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 佔成設定列表 */
export async function getThreeHierarchyProportionApi(type: number) {
  return requestClient.post<BodyOf<ThreeHierarchyProportionRow[]>>(
    '/api/three_hierarchy/getProportion',
    { group_id: 41, type },
    { responseReturn: 'body' },
  );
}

/** 更新佔成設定 */
export async function updateThreeHierarchyProportionApi(payload: {
  game_types: Array<number | string>;
  ids: Array<number | string>;
  proportion_1: Array<number | string>;
  proportion_2: Array<number | string>;
  proportion_3: Array<number | string>;
  type: number;
  vendors: Array<number | string>;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/three_hierarchy/update_proportion',
    payload,
    { responseReturn: 'body' },
  );
}
