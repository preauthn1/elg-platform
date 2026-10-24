import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 銀行戶口管理模組 API（api/bank/*）。
 */

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 銀行戶口列 */
export interface BankManagementRow {
  account_id?: number | string;
  account_name?: string;
  account_nickname?: string;
  account_no?: string;
  bank_code?: string;
  bank_name?: string;
  bsb_code?: string;
  currency?: string;
  /** 1 已達標（顯紅字 reached_target） */
  is_reached?: number | string;
  max_amount?: number | string;
  min_amount?: number | string;
  rate?: number | string;
  /** 1=啟用、否則停用 */
  status?: number | string;
  total_amount?: number | string;
  total_count?: number | string;
  updated_at?: string;
}

/** 銀行戶口列表 */
export async function getBankManagementListApi(params?: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<BankManagementRow>>(
    '/api/bank/list',
    { page: params?.page ?? 1, per_page: params?.per_page ?? 1000 },
    { responseReturn: 'body' },
  );
}

/**
 * 切換戶口啟用狀態
 * status 一定要送字串：後端 BankController::modifyStatus() 用 `$request->status != ''`
 * 判斷有沒有帶值，送數字 0 時 PHP 弱型別比較會把它當成空字串，停用會被悄悄忽略。
 */
export async function toggleBankAccountStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/bank/status',
    { id: params.id, status: String(params.status), type: 2 },
    { responseReturn: 'body' },
  );
}

/** 依貨幣批量更新匯率 */
export async function updateBankRateByCurrencyApi(params: {
  currency: number | string;
  rate: number | string;
  /** 2=不調整、1=批量啟用、0=批量停用 */
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/bank/updateRateByCurrency',
    params,
    { responseReturn: 'body' },
  );
}

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 銀行清單列 */
export interface BankShowRow {
  bank_code?: string;
  bank_id?: number | string;
  bank_name?: string;
  currency?: string;
  image_path?: string;
  status?: number | string;
}

/** 銀行清單 */
export async function getBankShowListApi(params?: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<BankShowRow>>(
    '/api/bank/showBankList',
    { page: params?.page ?? 1, per_page: params?.per_page ?? 100 },
    { responseReturn: 'body' },
  );
}

/** 切換銀行啟用狀態（status 需送字串，理由同 toggleBankAccountStatusApi） */
export async function toggleBankStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/bank/status',
    { id: params.id, status: String(params.status), type: 1 },
    { responseReturn: 'body' },
  );
}

/** 銀行詳細 */
export interface BankViewDetail {
  bank_code?: string;
  bank_name?: string;
  currency_id?: number | string;
  id?: number | string;
  image_path?: string;
  status?: number | string;
  type?: number | string;
}

export async function getBankViewApi(id: number | string) {
  return requestClient.post<BodyOf<BankViewDetail>>(
    '/api/bank/view',
    { id },
    { responseReturn: 'body' },
  );
}

/** 新增/編輯銀行 */
export async function saveBankApi(params: {
  bank_code: string;
  bank_name: string;
  currency: number | string;
  id?: number | string;
  image?: string;
  status: number | string;
  type: number | string;
}) {
  const isEdit = params.id !== undefined && params.id !== '';
  return requestClient.post<BodyOf<unknown>>(
    isEdit ? '/api/bank/edit' : '/api/bank/add',
    params,
    { responseReturn: 'body' },
  );
}

/** 銀行下拉 */
export interface BankDropdownItem {
  bank_name?: string;
  currency?: string;
  id: number | string;
}

export async function getBankDropdownListApi() {
  return requestClient.post<BodyOf<BankDropdownItem[]>>(
    '/api/bank/dropdownList',
    {},
    { responseReturn: 'body' },
  );
}

/**
 * 新增戶口。
 *
 * 4545：戶口建立後不再開放編輯，後端 bank/editAccount 已棄用（回 9003），
 * 這裡不再有編輯路由；要改戶口資料請新增一筆、停用舊的。
 */
export async function saveBankAccountApi(params: {
  account_name: string;
  account_nickname: string;
  account_no: string;
  amount_threshold: number | string;
  bsb_code?: string;
  count_threshold: number | string;
  image?: string;
  is_deposit_bank: number | string;
  is_withdrawal_bank: number | string;
  max_amount: number | string;
  min_amount: number | string;
  /** 選定銀行 id */
  order_type_id: number | string;
  qrcode?: null | string;
  rate: number | string;
  status: number | string;
}) {
  return requestClient.post<BodyOf<unknown>>('/api/bank/addAccount', params, {
    responseReturn: 'body',
  });
}

/** 已上傳圖片清單 */
export interface AdvertiseImageItem {
  id: number | string;
  image_url?: string;
}

export async function getAdvertiseImageApi(params?: { page?: number }) {
  return requestClient.post<PagedBody<AdvertiseImageItem>>(
    '/api/advertise/image',
    { page: params?.page ?? 1, per_page: 6 },
    { responseReturn: 'body' },
  );
}

/** 上傳 base64 圖片 */
export async function uploadBase64ImageApi(params: {
  agent_id: number | string;
  base64Image: string;
  type?: number | string;
}) {
  return requestClient.post<
    BodyOf<{
      /** type 1 寫入廣告圖庫 */
      advertise_image_id?: number;
      path?: string;
      /** type 2 寫入最新消息圖庫 */
      platform_announcement_image_id?: number;
    }>
  >(
    '/api/image/base64/upload',
    { type: 1, ...params },
    { responseReturn: 'body' },
  );
}

/** 戶口編輯紀錄列 */
export interface BankEditLogRow {
  after_amount?: number | string;
  before_amount?: number | string;
  created_at?: string;
  operator?: string;
}

/** 戶口編輯紀錄 */
export async function getBankEditLogApi(params: {
  id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<BankEditLogRow>>(
    '/api/bank/getBankEditLog',
    { id: params.id, page: params.page ?? 1, per_page: params.per_page ?? 10 },
    { responseReturn: 'body' },
  );
}
