/**
 * 共用顯示格式工具。
 * 之後有跨頁共用的格式化需求，往這裡加。
 *
 * 金額一律走這裡的函式，不要自己乘 10 的次方截位、也不要用 `+` 累加。 */
import Big from 'big.js';

import { useKUnitStore } from '#/store/k-unit';

type NumLike = null | number | string | undefined;

/** 取十進位運算值；字串走原值，number 先過 toPrecision(15) 收掉二進位雜訊。 */
function decimalOf(v: NumLike, n: number): Big {
  if (typeof v === 'string') {
    const s = v.trim();
    if (s !== '') return new Big(s);
  }
  return new Big(n.toPrecision(15));
}

/** 金額無條件捨去到 digits 位（往零截斷，同後端 bcadd）。 */
export function floorAmount(v: NumLike, digits = 2): number {
  const n = Number(v);
  if (!Number.isFinite(n)) return n;
  return Number(decimalOf(v, n).round(digits, Big.roundDown));
}

/**
 * 匯出組列期間停用千位數：Excel 需要可直接加總的數字，不能出現 K。
 * rows 是同步組出來的，故用同步計數而非 async 旗標。 */
let plainDepth = 0;
export function buildPlainAmounts<T>(build: () => T): T {
  plainDepth += 1;
  try {
    return build();
  } finally {
    plainDepth -= 1;
  }
}

/** 無條件捨去到 digits 位的十進位值；非數值回 null。 */
function flooredBig(v: NumLike, digits: number): Big | null {
  const n = Number(v);
  if (!Number.isFinite(n)) return null;
  return decimalOf(v, n).round(digits, Big.roundDown);
}

/**
 * 金額顯示：無條件捨去到 digits 位 + 千分位 + 固定小數位。
 * 開啟千位數時改成除以 1000、固定 2 位小數並加註 K。
 * fallback 不給時為 0 的固定小數（如 '0.00'）。 */
export function amountThousands(
  v: NumLike,
  digits = 2,
  fallback?: string,
): string {
  const b = flooredBig(v, digits);
  if (b === null) return fallback ?? (0).toFixed(digits);
  return plainDepth === 0 && useKUnitStore().enabled
    ? `${thousands(b.div(1000).round(2, Big.roundDown).toFixed(2))}K`
    : thousands(b.toFixed(digits));
}

/** 匯出用金額：不受千位數設定影響。 */
export function amountPlain(v: NumLike, digits = 2, fallback?: string): string {
  const b = flooredBig(v, digits);
  return b === null
    ? (fallback ?? (0).toFixed(digits))
    : thousands(b.toFixed(digits));
}

/** 金額合計（非數值略過）；不截位，截到幾位交給顯示層決定。 */
export function sumAmounts(values: Iterable<NumLike>): string {
  let acc = new Big(0);
  for (const v of values) {
    const n = Number(v);
    if (Number.isFinite(n)) acc = acc.plus(decimalOf(v, n));
  }
  return String(acc);
}

/** 金額相減，同樣不截位。 */
export function diffAmounts(a: NumLike, b: NumLike): string {
  const na = Number(a);
  const nb = Number(b);
  const ba = Number.isFinite(na) ? decimalOf(a, na) : new Big(0);
  const bb = Number.isFinite(nb) ? decimalOf(b, nb) : new Big(0);
  return String(ba.minus(bb));
}

/** 給定點字串插千分位。 */
function thousands(fixed: string): string {
  const [int = '', frac] = fixed.split('.');
  const grouped = int.replaceAll(/\B(?=(\d{3})+(?!\d))/g, ',');
  return frac === undefined ? grouped : `${grouped}.${frac}`;
}

/**
 * 數值顯示：四捨五入到 digits 位 + 千分位 + 固定小數位。 */
export function numberFormat(
  v: NumLike,
  digits = 2,
  fallback?: string,
): string {
  const n = Number(v);
  if (!Number.isFinite(n)) return fallback ?? (0).toFixed(digits);
  return n.toLocaleString('en-US', {
    maximumFractionDigits: digits,
    minimumFractionDigits: digits,
  });
}

/**
 * 鬆散千分位（明細表原始值顯示）：最多 digits 位、不補零。 */
export function looseThousands(v: NumLike, digits = 4, fallback = '-'): string {
  const n = Number(v);
  if (!Number.isFinite(n)) return fallback;
  return n.toLocaleString('en-US', { maximumFractionDigits: digits });
}

/**
 * 全形轉半形 + trim（帳號等搜尋輸入送 API 前的正規化）。 */
export function toHalfWidth(s: string): string {
  return s
    .replaceAll(/[！-～]/g, (c) =>
      String.fromCodePoint((c.codePointAt(0) ?? 0) - 65_248),
    )
    .trim();
}
