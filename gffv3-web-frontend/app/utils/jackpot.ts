import { decAdd, decFloorFixed } from '#shared/utils/amount'

/** 本領域後端把 200 與 1000 都當成功（receive-all「沒有可領取」就是靠 1000）。 */
export const JACKPOT_OK_CODES: readonly number[] = [200, 1000]

export function isJackpotOk(code: number): boolean {
  return JACKPOT_OK_CODES.includes(code)
}

/** 後端 'Y-m-d H:i:s' 一律當本地時間解析；改成 '/' 分隔各瀏覽器才一致。 */
export function parseLocalDateTime(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(String(value).replace(/-/g, '/'))
  return Number.isNaN(date.getTime()) ? null : date
}

/** 積寶金額每天 15:00 歸零重算。 */
export const JACKPOT_RESET_HOUR = 15

export function jackpotLastResetAt(now: Date, hour = JACKPOT_RESET_HOUR): Date {
  const at = new Date(now)
  at.setHours(hour, 0, 0, 0)
  if (now.getTime() < at.getTime()) at.setDate(at.getDate() - 1)
  return at
}

export function jackpotNextResetAt(now: Date, hour = JACKPOT_RESET_HOUR): Date {
  const at = new Date(now)
  at.setHours(hour, 0, 0, 0)
  if (now.getTime() >= at.getTime()) at.setDate(at.getDate() + 1)
  return at
}

/** 畫面上的積寶金額 = 後端 jackpot + 距上一個 15:00 的秒數（金額字串，不轉 number）。 */
export function jackpotAmountAt(base: string | number, now: Date): string {
  const elapsed = Math.floor((now.getTime() - jackpotLastResetAt(now).getTime()) / 1000)
  return decAdd(base, elapsed)
}

export function msUntilNextJackpotReset(now: Date): number {
  return jackpotNextResetAt(now).getTime() - now.getTime()
}

export interface JackpotPrizeLike {
  id: number
  status: number
  expired_time?: string | null
}

/** 底部 /jackpot 分頁的紅點：還有 status 0 的獎項。 */
export function hasUnclaimedJackpotPrize(prizes: readonly JackpotPrizeLike[] | null | undefined): boolean {
  return (prizes ?? []).some(prize => Number(prize.status) === 0)
}

export interface PrizeCountdown {
  expired: boolean
  /** 'H:mm'，小時含天數 × 24；過期為空字串 */
  text: string
}

export function jackpotPrizeCountdown(expiredTime: string | null | undefined, now: Date): PrizeCountdown | null {
  const end = parseLocalDateTime(expiredTime)
  if (!end) return null
  const diff = end.getTime() - now.getTime()
  if (diff < 0) return { expired: true, text: '' }
  const totalMinutes = Math.floor(diff / 60000)
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  return { expired: false, text: `${hours}:${String(minutes).padStart(2, '0')}` }
}

/** 中獎彈窗圖片依紀錄 id 破快取。 */
export function jackpotNotificationImageUrl(notification: { id: number, image: string }): string {
  return `${notification.image}?rid=${notification.id}`
}

/**
 * 活動與積寶的金額顯示：截斷到兩位小數，沿用舊前台 ToMoneyDisplayFormat_Fixed2 的行為。
 * 實作在 shared/utils/amount.ts，這裡只是本領域的名字。
 */
export function formatEventAmount(value: string | number | null | undefined): string {
  return decFloorFixed(value, 2)
}
