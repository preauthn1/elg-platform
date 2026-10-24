import type { GgrPlanRow } from '#/api';

import { tl } from '#/locales';

import { isNonNegativeMoney } from '../validation';

/**
 * 4121 方案表單的資料模型與驗證（dialog 專用）。
 *
 * 開關以 SWITCH_SECTIONS 宣告式渲染（帳戶驗證限制 5 項＋領取前驗證 4 項）；
 * 成員下線設置＝兩顆綁定 id；特殊用戶＝可增刪列。驗證沿批次一慣例：
 * 欄位級即時紅字＋送出前 firstError() 總擋一次。
 */

export interface SpecialAgentForm {
  agentId: null | number;
  conditionId: null | number;
  vendorSettingId: null | number;
}

export interface PlanForm {
  adminFeeRate: string;
  agentGroupId: null | number;
  bonusRate: string;
  carryNegative: boolean;
  downlineConditionId: null | number;
  downlineVendorSettingId: null | number;
  ggrRate: string;
  handlingFee: string;
  kycUniqueBankAccount: boolean;
  kycUniqueContactNo: boolean;
  kycUniqueEmail: boolean;
  kycUniqueIp: boolean;
  kycUniqueName: boolean;
  kycViolateCount: number;
  name: string;
  settlementCycle: number;
  specialAgents: SpecialAgentForm[];
  startDate: string;
  status: boolean;
}

/** 開關群組宣告（labelKey → form 布林欄位），渲染與 payload 組裝共用 */
export const SWITCH_SECTIONS = {
  kyc: [
    { field: 'kycUniqueBankAccount', labelKey: 'ggr_unique_bank' },
    { field: 'kycUniqueContactNo', labelKey: 'ggr_unique_contact' },
    { field: 'kycUniqueEmail', labelKey: 'ggr_unique_email' },
    { field: 'kycUniqueIp', labelKey: 'ggr_unique_ip' },
    { field: 'kycUniqueName', labelKey: 'ggr_unique_name' },
  ],
} as const;

export function blankForm(): PlanForm {
  return {
    adminFeeRate: '0',
    agentGroupId: null,
    bonusRate: '0',
    carryNegative: false,
    downlineConditionId: null,
    downlineVendorSettingId: null,
    ggrRate: '0',
    handlingFee: '0',
    kycUniqueBankAccount: false,
    kycUniqueContactNo: false,
    kycUniqueEmail: false,
    kycUniqueIp: false,
    kycUniqueName: false,
    kycViolateCount: 0,
    name: '',
    settlementCycle: 1,
    specialAgents: [],
    startDate: '',
    status: true,
  };
}

/** 後端 view → 表單（編輯/複製回填） */
export function viewToForm(row: GgrPlanRow): PlanForm {
  return {
    adminFeeRate: String(row.admin_fee_rate ?? '0'),
    agentGroupId: row.agent_group_id ?? null,
    bonusRate: String(row.bonus_rate ?? '0'),
    carryNegative: Number(row.carry_negative) === 1,
    downlineConditionId: row.downline_condition_id ?? null,
    downlineVendorSettingId: row.downline_vendor_setting_id ?? null,
    ggrRate: String(row.ggr_rate ?? '0'),
    handlingFee: String(row.handling_fee ?? '0'),
    kycUniqueBankAccount: Number(row.kyc_unique_bank_account) === 1,
    kycUniqueContactNo: Number(row.kyc_unique_contact_no) === 1,
    kycUniqueEmail: Number(row.kyc_unique_email) === 1,
    kycUniqueIp: Number(row.kyc_unique_ip) === 1,
    kycUniqueName: Number(row.kyc_unique_name) === 1,
    kycViolateCount: Number(row.kyc_violate_count ?? 0),
    name: row.name ?? '',
    settlementCycle: Number(row.settlement_cycle ?? 1),
    specialAgents: (row.special_agents ?? []).map((item) => ({
      agentId: item.agent_id,
      conditionId: item.condition_id,
      vendorSettingId: item.vendor_setting_id,
    })),
    startDate: row.start_date ?? '',
    status: Number(row.status) === 1,
  };
}

/** 表單 → 送後端 payload */
export function formToPayload(form: PlanForm): Record<string, unknown> {
  return {
    admin_fee_rate: form.adminFeeRate,
    agent_group_id: form.agentGroupId,
    bonus_rate: form.bonusRate,
    carry_negative: form.carryNegative ? 1 : 0,
    downline_condition_id: form.downlineConditionId,
    downline_vendor_setting_id: form.downlineVendorSettingId,
    ggr_rate: form.ggrRate,
    handling_fee: form.handlingFee,
    kyc_unique_bank_account: form.kycUniqueBankAccount ? 1 : 0,
    kyc_unique_contact_no: form.kycUniqueContactNo ? 1 : 0,
    kyc_unique_email: form.kycUniqueEmail ? 1 : 0,
    kyc_unique_ip: form.kycUniqueIp ? 1 : 0,
    kyc_unique_name: form.kycUniqueName ? 1 : 0,
    kyc_violate_count: form.kycViolateCount,
    name: form.name.trim(),
    settlement_cycle: form.settlementCycle,
    special_agents: form.specialAgents.map((row) => ({
      agent_id: row.agentId,
      condition_id: row.conditionId,
      vendor_setting_id: row.vendorSettingId,
    })),
    start_date: form.startDate,
    status: form.status ? 1 : 0,
  };
}

/** 次數欄：非負整數，且不超過該區已開啟的開關數 */
function isCountWithin(value: number, enabled: number): boolean {
  return Number.isInteger(value) && value >= 0 && value <= enabled;
}

/** 百分比欄：非負、最多兩位小數、上限 100 */
function isValidRate(value: string): boolean {
  return isNonNegativeMoney(value.trim()) && Number(value) <= 100;
}

/** 送出前總擋：回傳第一個錯誤訊息（null=通過）。 */
export function firstError(form: PlanForm): null | string {
  if (!form.name.trim()) {
    return `${tl('please_enter')} ${tl('ggr_plan_title')}`;
  }
  if (!form.agentGroupId) {
    return `${tl('please_choose')} ${tl('ggr_agent_group')}`;
  }
  if (!form.startDate) {
    return `${tl('please_choose')} ${tl('ggr_start_date')}`;
  }

  for (const [labelKey, value] of [
    ['ggr_admin_fee', form.adminFeeRate],
    ['ggr', form.ggrRate],
    ['ggr_bonus_rate', form.bonusRate],
  ] as const) {
    if (!isValidRate(value)) {
      return `${tl(labelKey)}: ${tl('ggr_field_required')}`;
    }
  }
  if (!isNonNegativeMoney(form.handlingFee.trim())) {
    return `${tl('handling_fee')}: ${tl('ggr_field_required')}`;
  }
  // 次數不得超過該區已開啟的開關數（UI 已即時夾回，這裡是送出前的保險）
  const kycOn = SWITCH_SECTIONS.kyc.filter((item) => form[item.field]).length;
  if (!isCountWithin(form.kycViolateCount, kycOn)) {
    return `${tl('ggr_violate_count')}: ${tl('ggr_field_required')}`;
  }

  if (!form.downlineVendorSettingId || !form.downlineConditionId) {
    return `${tl('ggr_downline_section')}: ${tl('ggr_field_required')}`;
  }

  const seen = new Set<number>();
  for (const row of form.specialAgents) {
    if (!row.agentId || !row.vendorSettingId || !row.conditionId) {
      return `${tl('ggr_special_section')}: ${tl('ggr_field_required')}`;
    }
    if (seen.has(row.agentId)) {
      return `${tl('ggr_special_agent')}: ${tl('ggr_field_required')}`;
    }
    seen.add(row.agentId);
  }

  return null;
}
