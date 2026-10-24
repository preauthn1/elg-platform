import type { GgrVendorSettingDetail } from '#/api';

import { tl } from '#/locales';

import {
  isNonNegativeInt,
  isNonNegativeMoney,
  isWithinMax,
  MAX_AMOUNT,
  MAX_INT_AMOUNT,
  MAX_RATE,
} from '../validation';

/**
 * 4123 彈窗的表單資料模型與驗證（dialog 與 detail-table 共用）。
 *
 * 每個 vendor_info × game_type 組合一列，內含多階層（等級 1..n）：
 * 以「計算金額>=」為階梯鍵、逐階嚴格遞增。`0` 是特殊值＝不限制
 * （2026-08-31 工單定案）：「無最低限制／無上限」checkbox＝自動填 0，
 * 加權 0＝該階不計算（新增遊戲商補列的預設）。驗證錯誤為欄位下方即時紅字。
 */

export interface VendorTierForm {
  maxGgr: string;
  minWinlose: string;
  noCap: boolean;
  noMinLimit: boolean;
  /** 計算金額>=（ElInputNumber 步進；0=不限制） */
  thresholdAmount: number;
  weightRate: string;
}

export interface VendorDetailForm {
  excludedGameIds: number[];
  gameTypeId: number;
  /** 編輯既有設置時，由「更新遊戲商」或開啟時發現的新開通遊戲商（顯示「新增」徽章） */
  isNew?: boolean;
  gameTypeName: string;
  status: boolean;
  tiers: VendorTierForm[];
  vendorId: number;
  vendorInfoId: number;
  vendorInfoName: string;
}

export interface TierErrors {
  maxGgr?: string;
  minWinlose?: string;
  thresholdAmount?: string;
  weightRate?: string;
}

/**
 * 預設階層（工單定案）：計算金額 1／最低輸贏 1／最大 GGR 0（=無上限,故勾「無上限」）／加權 100。
 * 「新增遊戲商」自動補列時加權傳 0（=不計算），避免順手儲存把新遊戲商誤全開。
 */
export function makeDefaultTier(weightRate = '100'): VendorTierForm {
  return {
    maxGgr: '0',
    minWinlose: '1',
    noCap: true,
    noMinLimit: false,
    thresholdAmount: 1,
    weightRate,
  };
}

/**
 * 後端巢狀明細 → 表單列（編輯/複製回填）；0＝不限制（舊資料的 NULL 容錯為 0）。
 * 兩顆「無…限制」checkbox 是 0 的顯示載體，回填時依值反推勾選狀態，
 * 否則存進去的 0 會以「門檻就是 0」的樣子回來，跟勾選當下的語意對不上。
 */
export function detailToForm(detail: GgrVendorSettingDetail): VendorDetailForm {
  return {
    excludedGameIds: detail.excluded_game_ids ?? [],
    gameTypeId: detail.game_type_id,
    gameTypeName: detail.game_type_name ?? '',
    status: Number(detail.status) === 1,
    tiers: detail.tiers.map((tier) => ({
      maxGgr: String(tier.max_ggr ?? 0),
      minWinlose: String(tier.min_winlose ?? 0),
      noCap: Number(tier.max_ggr ?? 0) === 0,
      noMinLimit: Number(tier.min_winlose ?? 0) === 0,
      thresholdAmount: Number(tier.threshold_amount),
      weightRate: String(tier.weight_rate),
    })),
    vendorId: detail.vendor_id,
    vendorInfoId: detail.vendor_info_id,
    vendorInfoName: detail.vendor_info_name ?? '',
  };
}

/** 表單列 → 送後端的 details payload（checkbox 勾選＝送 0＝無限制） */
export function formToPayload(rows: VendorDetailForm[]) {
  return rows.map((row) => ({
    excluded_game_ids: row.excludedGameIds,
    game_type_id: row.gameTypeId,
    status: row.status ? 1 : 0,
    tiers: row.tiers.map((tier) => ({
      max_ggr: tier.noCap ? 0 : Number(tier.maxGgr),
      min_winlose: tier.noMinLimit ? 0 : Number(tier.minWinlose),
      threshold_amount: tier.thresholdAmount,
      weight_rate: tier.weightRate,
    })),
    vendor_id: row.vendorId,
    vendor_info_id: row.vendorInfoId,
  }));
}

/**
 * 單一階層的即時驗證；prevThreshold 為上一階計算金額（首階傳 null）。
 * 上限與後端 Request 對齊：超出時要說「超出範圍」而不是含糊的「必填」。
 */
export function tierErrors(
  tier: VendorTierForm,
  prevThreshold: null | number,
): TierErrors {
  const errors: TierErrors = {};

  // 計算金額走 ElInputNumber（min 0、precision 2 已由元件把關），
  // 這裡驗上限與「同遊戲商內逐階遞增」（蘊含工單要求的不可重複）
  const threshold = tier.thresholdAmount;
  if (Number.isFinite(threshold) && threshold >= 0) {
    if (threshold <= MAX_AMOUNT) {
      // 相等時明示「重複」（工單需求1），小於才是排序問題
      if (prevThreshold !== null && threshold === prevThreshold) {
        errors.thresholdAmount = tl('ggr_duplicate_threshold');
      } else if (prevThreshold !== null && threshold < prevThreshold) {
        errors.thresholdAmount = tl('ggr_must_exceed_prev');
      }
    } else {
      errors.thresholdAmount = tl('ggr_value_out_of_range');
    }
  } else {
    errors.thresholdAmount = tl('ggr_field_required');
  }

  // 0 為合法輸入（=無限制，送出轉 null），未勾 checkbox 時須為非負整數
  if (!tier.noMinLimit) {
    const message = intFieldError(tier.minWinlose);
    if (message) {
      errors.minWinlose = message;
    }
  }
  if (!tier.noCap) {
    const message = intFieldError(tier.maxGgr);
    if (message) {
      errors.maxGgr = message;
    }
  }

  // 加權 0＝該階不計算，為合法值
  const weightRate = tier.weightRate.trim();
  if (!isNonNegativeMoney(weightRate)) {
    errors.weightRate = tl('ggr_field_required');
  } else if (!isWithinMax(weightRate, MAX_RATE)) {
    errors.weightRate = tl('ggr_value_out_of_range');
  }

  return errors;
}

/** 最低輸贏／最大 GGR 共用的檢查（非負整數且未超出後端上限） */
function intFieldError(value: string): string | undefined {
  const trimmed = value.trim();
  if (!isNonNegativeInt(trimmed)) {
    return tl('ggr_field_required');
  }

  return isWithinMax(trimmed, MAX_INT_AMOUNT)
    ? undefined
    : tl('ggr_value_out_of_range');
}

/**
 * 整列（含所有階層）的第一個錯誤訊息，save 前總擋一次；無錯回 null。
 * 回訊息而非布林，讓彈窗能報出真正的原因而不是一律「必須超過前一行」。
 */
export function rowFirstError(row: VendorDetailForm): null | string {
  let prev: null | number = null;
  for (const tier of row.tiers) {
    const message = Object.values(tierErrors(tier, prev))[0];
    if (message) {
      return message;
    }
    prev = tier.thresholdAmount;
  }

  return null;
}
