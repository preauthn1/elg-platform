import type { CashFlowForTpRow } from '#/api';

import { tl } from '#/locales';

/**
 * 報表「來源」下拉與拆解（比照舊版 sr99.php／sr98.php：用 agent/cashFlowsForTp
 * 取得含幣別前綴的金流清單，而非 agent/cashFlows；後者查詢會把不同幣別的設定
 * 併成一列、幣別資訊因此消失）。
 *
 * source_temp 值：
 *  - '0'  全部
 *  - '-1' 線上支付 / '-2' 線下支付 / '-3' 代付 / '-4' 自助洗分
 *    （舊版僅 SR98 有這組固定選項；SR99 從來沒有，選了會讓後端查詢炸掉）
 *  - '3.{cash_flow_id}.{agent_cash_flow_id}' 存款金流
 *  - '4.{cash_flow_id}.{agent_cash_flow_id}' 提款金流
 */

export interface SourceOptionGroup {
  /** 金流分組：3=存款 4=提款；非分組(固定選項)為 0 */
  group: number;
  label: string;
  options: Array<{ label: string; value: string }>;
}

/** 固定選項（僅 SR98 使用，比照舊版 sr98.php） */
function fixedOptions() {
  return [
    { label: tl('cash_flow_1'), value: '-1' },
    { label: tl('cash_flow_2'), value: '-2' },
    { label: tl('cash_flow_3'), value: '-3' },
    { label: tl('cash_flow_4'), value: '-4' },
  ];
}

/**
 * 由 TP 金流清單（agent/cashFlowsForTp）組出來源下拉的分組：
 *  type 4 跳過；type 1/2 → 存款(group 3)；其餘 → 提款(group 4)。
 *  includeFixed：是否附加線上支付/線下支付/代付/自助洗分固定選項（僅 SR98 傳 true）。
 *
 *  分組順序比照舊版：舊版用 PHP 關聯陣列依「第一次遇到該 type 的資料」建立 key，
 *  foreach 又照插入順序跑，所以存款/提款誰先顯示取決於原始資料裡誰先出現，
 *  不是固定「存款一定在前」。這裡用 Map 重現同樣的插入順序語意。
 */
export function buildSourceGroups(
  cashFlows: CashFlowForTpRow[],
  includeFixed = false,
): SourceOptionGroup[] {
  const groupMap = new Map<number, SourceOptionGroup>();
  // 後端會回傳多筆組出同一個 value 的資料（同 cash_flow_id+agent_cash_flow_id），
  // 必須去重：ElSelect 內部以 value 登記選項，重複 value 會讓篩選時多出來的
  // 節點收不到隱藏通知（開分組篩不掉的元凶），且選誰送出的參數都一樣，去重無損
  const seen = new Set<string>();
  for (const cf of cashFlows) {
    if (cf.type === 4) continue;
    const group = cf.type === 1 || cf.type === 2 ? 3 : 4;
    const value = `${group}.${cf.cash_flow_id}.${cf.agent_cash_flow_id}`;
    if (seen.has(value)) continue;
    seen.add(value);
    let g = groupMap.get(group);
    if (!g) {
      g = {
        group,
        label: group === 3 ? tl('deposit') : tl('withdrawal'),
        options: [],
      };
      groupMap.set(group, g);
    }
    g.options.push({
      label: `　${cf.name}`,
      value,
    });
  }
  const groups: SourceOptionGroup[] = [
    {
      group: 0,
      label: '',
      options: [
        { label: tl('all'), value: '0' },
        ...(includeFixed ? fixedOptions() : []),
      ],
    },
    ...groupMap.values(),
  ];
  return groups;
}

/** 拆 source_temp → { source, cash_flow_id, agent_cash_flow_id } */
export function splitSourceTemp(sourceTemp: string): {
  agent_cash_flow_id: number | string;
  cash_flow_id: number | string;
  source: number;
} {
  if (sourceTemp.includes('.')) {
    const [src, cfId, acfId] = sourceTemp.split('.');
    return {
      agent_cash_flow_id: Number(acfId ?? 0),
      cash_flow_id: Number(cfId),
      source: Number(src),
    };
  }
  return { agent_cash_flow_id: 0, cash_flow_id: 0, source: Number(sourceTemp) };
}

/** 是否為存款金流來源（需載入訂單類型） */
export function isDepositSource(sourceTemp: string) {
  return sourceTemp.startsWith('3.');
}
/** 是否為提款金流來源（需載入提款子類型） */
export function isWithdrawalSource(sourceTemp: string) {
  return sourceTemp.startsWith('4.');
}
