import Decimal from 'decimal.js'

/**
 * 金額運算與顯示的唯一入口。**金額、匯率、流水、點數都走這裡，不准用 +-*／ 或 toFixed**。
 *
 * 後端金額一律是字串（多為 4 位小數），所以輸入輸出也都用字串；只有畫面寬度、
 * 百分比這種非金額用途才轉 number。
 *
 * 顯示一律**無條件捨去**（舊前台的 roundDown）——四捨五入會顯示出會員沒有的錢。
 * 真的要四捨五入（例如換算後的預估值）才用 decFixed。
 */

/** 後端金額欄位可能是字串、數字，或沒回。 */
export type AmountLike = string | number | null | undefined

/** unknown（例如會員資料或 API 未定型的欄位）收斂成 AmountLike，其他型別當 null。 */
export function toAmountLike(value: unknown): AmountLike {
  return typeof value === 'string' || typeof value === 'number' ? value : null
}

/**
 * 專用的 Decimal，不動全域設定。toExpNeg/toExpPos 放到極大，
 * 永遠輸出一般記法而不是 1e+21。
 */
const D = Decimal.clone({
  precision: 40,
  toExpNeg: -1e9,
  toExpPos: 1e9,
  rounding: Decimal.ROUND_HALF_UP,
})

/** 只認一般十進位寫法；科學記號、空字串、亂碼都不算金額。 */
const DECIMAL_RE = /^[+-]?(\d+)(?:\.(\d+))?$/

function parse(value: AmountLike): Decimal | null {
  if (value === null || value === undefined) return null
  // 數字先轉成固定小數的字串，避免把浮點誤差帶進來
  // 字串去掉千分位逗號，讓已經格式化過的值再丟回來也算得動
  const text = typeof value === 'number'
    ? (Number.isFinite(value) ? value.toFixed(12) : '')
    : String(value).replace(/,/g, '').trim()
  if (!DECIMAL_RE.test(text)) return null
  return new D(text)
}

/** 不是合法金額就當 0，與後端缺值時的行為一致。 */
function orZero(value: AmountLike): Decimal {
  return parse(value) ?? new D(0)
}

/** 去掉尾端多餘的 0（'1.500' → '1.5'、'2.00' → '2'）。 */
function text(d: Decimal): string {
  return d.toString()
}

/** 捨去後值為 0 卻留著負號時（-0.001 捨到 2 位）去掉負號，畫面不該出現 -0.00。 */
function stripNegativeZero(fixed: string): string {
  return fixed.startsWith('-') && !/[1-9]/.test(fixed) ? fixed.slice(1) : fixed
}

function groupThousands(fixed: string): string {
  const [int = '0', frac] = fixed.split('.')
  const sign = int.startsWith('-') ? '-' : ''
  const digits = sign ? int.slice(1) : int
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return frac === undefined ? `${sign}${grouped}` : `${sign}${grouped}.${frac}`
}

export function isDecimalText(value: AmountLike): boolean {
  return parse(value) !== null
}

/** 正規化成金額字串：去尾零、非金額回 '0'（'1.5000' → '1.5'、null → '0'）。 */
export function decText(value: AmountLike): string {
  return text(orZero(value))
}

export function decAdd(a: AmountLike, b: AmountLike): string {
  return text(orZero(a).plus(orZero(b)))
}

export function decSub(a: AmountLike, b: AmountLike): string {
  return text(orZero(a).minus(orZero(b)))
}

export function decMul(a: AmountLike, b: AmountLike): string {
  return text(orZero(a).times(orZero(b)))
}

/** 除法，結果無條件捨去到 places 位；除數為 0 回 '0'。 */
export function decDiv(a: AmountLike, b: AmountLike, places = 2): string {
  const divisor = orZero(b)
  if (divisor.isZero()) return '0'
  return text(orZero(a).div(divisor).toDecimalPlaces(places, Decimal.ROUND_DOWN))
}

export function decCmp(a: AmountLike, b: AmountLike): -1 | 0 | 1 {
  return orZero(a).comparedTo(orZero(b)) as -1 | 0 | 1
}

/**
 * 無條件捨去到 places 位並補足位數。**金額顯示與送出金額都用這個**，
 * 等同舊前台的 ToMoneyDisplayFormat_FixedN（'1.999' → '1.99'、'7' → '7.00'）。
 */
export function decFloorFixed(value: AmountLike, places = 2): string {
  return stripNegativeZero(orZero(value).toFixed(places, Decimal.ROUND_DOWN))
}

/**
 * 四捨五入到 places 位並保留位數。只有明確要四捨五入的場合才用
 * （例如匯率換算後的預估值）；顯示餘額請用 decFloorFixed。
 */
export function decFixed(value: AmountLike, places = 2): string {
  return stripNegativeZero(orZero(value).toFixed(places, Decimal.ROUND_HALF_UP))
}

export function decIsZero(value: AmountLike): boolean {
  return orZero(value).isZero()
}

export function decIsPositive(value: AmountLike): boolean {
  return orZero(value).greaterThan(0)
}

export function decMax(a: AmountLike, b: AmountLike): string {
  return decCmp(a, b) >= 0 ? text(orZero(a)) : text(orZero(b))
}

export function decMin(a: AmountLike, b: AmountLike): string {
  return decCmp(a, b) <= 0 ? text(orZero(a)) : text(orZero(b))
}

/**
 * 千分位顯示；無條件捨去，預設 2 位小數。
 * 要去尾零不要固定位數時傳 'trim'。
 */
export function decFormat(value: AmountLike, places: number | 'trim' = 2): string {
  return groupThousands(places === 'trim' ? text(orZero(value)) : decFloorFixed(value, places))
}

/** 只有真的需要 number 的地方才用；拿到的值不可以再回去做金額運算。 */
export function decToNumber(value: AmountLike): number {
  return orZero(value).toNumber()
}
