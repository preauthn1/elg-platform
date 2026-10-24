import { requestClient } from '#/api/request';

/**
 * 新人專享挑戰活動 API（apis 後端，路徑 agent/api/newbie-challenge/*，成功碼 200）。
 * 失敗時 400 把訊息放在 message，其餘碼放在 data。 */

interface Body<T> {
  code: number | string;
  data?: T;
  message?: string;
}

interface Paged<T> {
  items?: T[];
  pagination?: { current?: number; pages?: number; total?: number };
}

// ---------- 活動配置 ----------

export interface NewbieChallengeConfigRow {
  activity_amount?: string;
  /** null = 全站 */
  agent_id?: null | number;
  agent_username?: null | string;
  bonus_flow_multiple?: string;
  bonus_window_hours?: number;
  challenge_flow_multiple?: string;
  created_at?: string;
  end_time?: null | string;
  id: number;
  max_unlock_amount?: string;
  min_unlock_amount?: string;
  restrict_new_member?: number;
  sort?: number;
  start_time?: null | string;
  status?: number;
}

export async function getNewbieChallengeConfigListApi(params: {
  agent_username?: string;
  page: number;
  per_page: number;
  status?: number | string;
}) {
  return requestClient.post<Body<Paged<NewbieChallengeConfigRow>>>(
    '/agent/api/newbie-challenge/config-list',
    params,
    { responseReturn: 'body' },
  );
}

export interface NewbieChallengeConfigLang {
  content?: null | string;
  image?: null | string;
  language_id: number;
  name?: null | string;
}

export interface NewbieChallengeConfigDetail {
  account_block?: null | number;
  activity_amount?: string;
  agent_id?: null | number;
  /** null = 不限制遊戲商，與 excluded_vendor_ids 互斥 */
  allowed_vendor_ids?: null | number[];
  /** null = 不限制遊戲類型 */
  allowed_game_type_ids?: null | number[];
  /** null = 不排除，與 allowed_vendor_ids 互斥 */
  excluded_vendor_ids?: null | number[];
  bonus_flow_multiple?: string;
  /** null = 不限制遊戲商，與 excluded_bonus_flow_vendor_ids 互斥 */
  bonus_flow_vendor_ids?: null | number[];
  /** null = 不限制遊戲類型 */
  bonus_flow_game_type_ids?: null | number[];
  /** null = 不排除，與 bonus_flow_vendor_ids 互斥 */
  excluded_bonus_flow_vendor_ids?: null | number[];
  bonus_window_hours?: number;
  challenge_flow_multiple?: string;
  config_lang?: NewbieChallengeConfigLang[];
  end_time?: null | string;
  exclude_agent?: { agent_username: string; id: number }[];
  id: number;
  ip_block?: null | number;
  max_unlock_amount?: string;
  min_unlock_amount?: string;
  phone_block?: null | number;
  restrict_new_member?: number;
  sort?: number;
  start_time?: null | string;
  status?: number;
}

export async function getNewbieChallengeConfigDetailApi(
  configId: number | string,
) {
  return requestClient.post<Body<NewbieChallengeConfigDetail>>(
    '/agent/api/newbie-challenge/config-detail',
    { config_id: configId },
    { responseReturn: 'body' },
  );
}

export interface NewbieChallengeConfigPayload {
  account_block: number;
  /** null = 全站 */
  agent_id: null | number;
  activity_amount: number | string;
  /** null = 不限制遊戲商，與 excluded_vendor_ids 互斥 */
  allowed_vendor_ids: null | number[];
  /** null = 不限制遊戲類型 */
  allowed_game_type_ids: null | number[];
  /** null = 不排除，與 allowed_vendor_ids 互斥 */
  excluded_vendor_ids: null | number[];
  bonus_flow_multiple: number | string;
  /** null = 不限制遊戲商，與 excluded_bonus_flow_vendor_ids 互斥 */
  bonus_flow_vendor_ids: null | number[];
  /** null = 不限制遊戲類型 */
  bonus_flow_game_type_ids: null | number[];
  /** null = 不排除，與 bonus_flow_vendor_ids 互斥 */
  excluded_bonus_flow_vendor_ids: null | number[];
  bonus_window_hours: number | string;
  challenge_flow_multiple: number | string;
  config_lang: NewbieChallengeConfigLang[];
  /** null = 不限制 */
  end_time: null | string;
  exclude_agent: number[];
  ip_block: number;
  max_unlock_amount: number | string;
  min_unlock_amount: number | string;
  phone_block: number;
  restrict_new_member: number;
  sort: number | string;
  start_time: null | string;
  status: number;
}

export async function createNewbieChallengeConfigApi(
  payload: NewbieChallengeConfigPayload,
) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/config-create',
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateNewbieChallengeConfigApi(
  payload: NewbieChallengeConfigPayload & { config_id: number | string },
) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/config-update',
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateNewbieChallengeConfigStatusApi(payload: {
  config_id: number | string;
  status: number | string;
}) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/config-update-status',
    payload,
    { responseReturn: 'body' },
  );
}

// ---------- 會員進度 ----------

export interface NewbieChallengeProgressRow {
  activity_reward?: string;
  agent_username?: string;
  bonus_window_expire_at?: null | string;
  claimed_bonus_amount?: null | string;
  config_id?: number;
  event_wallet_balance?: string;
  id: number;
  is_bonus_window_expired?: boolean;
  member_id?: number;
  member_username?: string;
  principal_total?: string;
  stage?: number;
  unlocked_bonus_amount?: null | string;
}

export async function getNewbieChallengeProgressListApi(params: {
  member_username?: string;
  page: number;
  per_page: number;
  stage?: number | string;
}) {
  return requestClient.post<Body<Paged<NewbieChallengeProgressRow>>>(
    '/agent/api/newbie-challenge/progress-list',
    params,
    { responseReturn: 'body' },
  );
}

export interface NewbieChallengeFlow {
  flow_limit?: string;
  reach?: string;
  status?: number | string;
}

export interface NewbieChallengeProgressDetail {
  activity_reward?: string;
  bonus_claim?: null | {
    claimed_bonus_amount?: string;
    deposit_amount?: string;
    deposit_order_id?: number | string;
  };
  bonus_flow?: NewbieChallengeFlow | null;
  bonus_window_expire_at?: null | string;
  challenge_flow?: NewbieChallengeFlow | null;
  id: number;
  is_bonus_window_expired?: boolean;
  member_username?: string;
  principal_total?: string;
  stage?: number;
  unlocked_bonus_amount?: null | string;
}

export async function getNewbieChallengeProgressDetailApi(
  progressId: number | string,
) {
  return requestClient.post<Body<NewbieChallengeProgressDetail>>(
    '/agent/api/newbie-challenge/progress-detail',
    { progress_id: progressId },
    { responseReturn: 'body' },
  );
}

export interface NewbieChallengeOperationLogRow {
  action?: string;
  agent_username?: null | string;
  created_at?: string;
  from_stage?: null | number;
  id: number;
  member_username?: null | string;
  operator_type?: number;
  remark?: null | string;
  to_stage?: null | number;
}

export async function getNewbieChallengeOperationLogListApi(params: {
  page: number;
  per_page: number;
  progress_id: number | string;
}) {
  return requestClient.post<Body<Paged<NewbieChallengeOperationLogRow>>>(
    '/agent/api/newbie-challenge/operation-log-list',
    params,
    { responseReturn: 'body' },
  );
}

/** 活動錢包加減分；扣分由呼叫端送負數 */
export async function updateNewbieChallengeWalletBalanceApi(payload: {
  balance: number | string;
  progress_id: number | string;
}) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/update-wallet-balance',
    payload,
    { responseReturn: 'body' },
  );
}

/** 代理後台強制取消進行中的挑戰 */
export async function quitNewbieChallengeApi(progressId: number | string) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/quit',
    { progress_id: progressId },
    { responseReturn: 'body' },
  );
}

// ---------- 監控 ----------

export interface NewbieChallengeMonitorRow {
  member_count?: number;
  status_group?: string;
  total_activity_reward?: string;
  total_bonus_paid?: string;
}

export async function getNewbieChallengeMonitorApi() {
  return requestClient.post<Body<NewbieChallengeMonitorRow[]>>(
    '/agent/api/newbie-challenge/monitor',
    {},
    { responseReturn: 'body' },
  );
}

// ---------- 重複阻擋名單 ----------

export interface NewbieChallengeBlockRow {
  agent_username?: string;
  allowed_time?: null | string;
  block_type?: number;
  block_updated_at?: null | string;
  config_id?: number;
  id: number;
  ip?: null | string;
  member_id?: number;
  member_username?: string;
  message?: null | string;
  status?: number;
}

export async function getNewbieChallengeBlockListApi(params: {
  agent_username?: string;
  end_time?: string;
  member_username?: string;
  page: number;
  per_page: number;
  start_time?: string;
  status?: number | string;
}) {
  return requestClient.post<Body<Paged<NewbieChallengeBlockRow>>>(
    '/agent/api/newbie-challenge/block-list',
    params,
    { responseReturn: 'body' },
  );
}

export interface NewbieChallengeBlockDetailRow {
  block_type?: number;
  created_at?: string;
  id: number;
  ip?: null | string;
  message?: null | string;
}

export async function getNewbieChallengeBlockDetailApi(
  blockJoinId: number | string,
) {
  return requestClient.post<Body<NewbieChallengeBlockDetailRow[]>>(
    '/agent/api/newbie-challenge/block-detail',
    { block_join_id: blockJoinId },
    { responseReturn: 'body' },
  );
}

export async function allowNewbieChallengeJoinApi(
  blockJoinId: number | string,
) {
  return requestClient.post<Body<unknown>>(
    '/agent/api/newbie-challenge/allow-join',
    { block_join_id: blockJoinId },
    { responseReturn: 'body' },
  );
}
