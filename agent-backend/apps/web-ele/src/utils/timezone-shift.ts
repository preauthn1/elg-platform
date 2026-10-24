import { useTimezoneStore } from '@vben/stores';

import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';

dayjs.extend(utc);

/**
 * 時區平移工具（target/tickets#4095 批 0）。
 *
 * 舊後端（API_Agent）與 apis 的報表端點介面基準固定是 GMT+8：
 * 輸入字串被當 +8 牆鐘解讀（後端 -8hour 轉 UTC 查詢）、輸出字串是 +8 牆鐘直出。
 * 工單限定整數時區且不涉夏令時，所以「使用者時區 T ↔ 端點基準 B」是純粹的
 * 固定小時差平移 —— 本檔是全站唯一的換算入口，頁面不得自行加減小時。
 */

/** 預設時區：GMT+8。絕不能用 dayjs.tz.guess()，否則海外使用者升版當下數字會無聲變動。 */
export const DEFAULT_TIMEZONE = 'Etc/GMT-8';

/** 端點介面基準（GMT+8）。目前全部已核對端點皆為 +8；例外頁走固定+8+提示不平移。 */
export const API_TZ_BASIS = 8;

/**
 * 時區選項 GMT−12 ～ GMT+12 共 25 筆。label 純 GMT±N 不掛城市名（規格決策）。
 * IANA 的 Etc/GMT±N 是 POSIX 反號：Etc/GMT-8 才是 GMT+8 —— 反號只在這一行出現，
 * 其他地方一律用 offset 數字，避免反號散落。
 */
export const TIMEZONE_OPTIONS: {
  label: string;
  offset: number;
  value: string;
}[] = Array.from({ length: 25 }, (_, i) => {
  const offset = i - 12;
  return {
    label: offset >= 0 ? `GMT+${offset}` : `GMT${offset}`,
    offset,
    value: `Etc/GMT${offset >= 0 ? '-' : '+'}${Math.abs(offset)}`,
  };
});

/** Etc/GMT±N 字串 → 偏移小時數。非本清單格式（如殘留的 IANA 城市名）回預設 +8。 */
export function offsetOfZone(zone: string | undefined): number {
  const m = /^Etc\/GMT([+-])(\d+)$/.exec(zone ?? '');
  if (!m) return API_TZ_BASIS;
  const n = Number(m[2]);
  return m[1] === '-' ? n : -n;
}

/** 目前使用者所選時區的偏移小時數（讀 core-timezone store，pinia persist 持久化）。 */
export function currentTzOffset(): number {
  return offsetOfZone(useTimezoneStore().timezone);
}

/** 目前時區的顯示標籤（如 GMT+8），供畫面常駐顯示與匯出表頭用。 */
export function currentTzLabel(): string {
  const offset = currentTzOffset();
  return offset >= 0 ? `GMT+${offset}` : `GMT${offset}`;
}

/**
 * 所選時區的「現在」：utcOffset 已設為該時區，.format() 直接得該時區牆鐘。
 * 「今天／本週／本月」的定義一律由此而來（所選時區的日界，不是瀏覽器機器時區）。
 */
export function nowInUserTz(
  offsetHours: number = currentTzOffset(),
): dayjs.Dayjs {
  return dayjs().utcOffset(offsetHours * 60);
}

const OUT_FORMAT = 'YYYY-MM-DD HH:mm:ss';

/**
 * 查詢送出用：使用者時區 T 的牆鐘字串 → 端點基準 B 的牆鐘字串。
 *
 * 純 naive 小時算術：dayjs 以機器時區 parse、加減小時後 format 回字串，
 * 機器時區在 parse/format 之間對消，跨瀏覽器行為一致。
 * 輸出精度跟隨輸入（YYYY-MM-DD HH:mm 進就 YYYY-MM-DD HH:mm 出）。
 *
 * 回歸保證：T=+8 時對 basis 8 是恆等函式（原字串原樣返回）。
 *
 * 純日期字串（長度 < 16，如 2026-08-12）不平移原樣返回 —— 裸日期參數頁
 * 已裁定整頁固定 GMT+8＋提示，不在平移範圍（見評估文件 Section 08）。
 */
export function toApiTime(
  wall: null | string | undefined,
  basis: number = API_TZ_BASIS,
): string {
  if (wall === null || wall === undefined) return '';
  return shift(wall, basis - currentTzOffset());
}

/**
 * 顯示用：端點基準 B 的牆鐘字串 → 使用者時區 T 的牆鐘字串。
 * 表格時間欄位的 formatter 用這支；null/undefined 回空字串，非時間字串原樣返回。
 */
export function fromApiTime(
  api: null | string | undefined,
  basis: number = API_TZ_BASIS,
): string {
  if (api === null || api === undefined) return '';
  return shift(api, currentTzOffset() - basis);
}

function shift(value: string, hours: number): string {
  if (!value || hours === 0 || value.length < 16) return value;
  const d = dayjs(value);
  if (!d.isValid()) return value;
  return d.add(hours, 'hour').format(OUT_FORMAT).slice(0, value.length);
}
