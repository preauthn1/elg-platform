import { describe, expect, it } from 'vitest'
import { ALERT_NAVIGATION_GRACE_MS, isStaleOnNavigation, type AlertState } from '../../app/composables/useAlert'

/**
 * 換頁時收掉上一頁留下的彈窗，但「先跳訊息再導頁」的不能被吃掉
 * （realname 守衛、bind-email 的 router.back()、platform-event 的恭喜訊息）。
 */
describe('isStaleOnNavigation：換頁時要不要自動關彈窗', () => {
  const navStart = 100_000
  const alert = (partial: Partial<AlertState>): AlertState => ({ message: 'x', tone: 'error', ...partial })

  it('換頁前就開著一陣子的要關', () => {
    expect(isStaleOnNavigation(alert({ shownAt: navStart - ALERT_NAVIGATION_GRACE_MS - 1 }), navStart)).toBe(true)
  })

  it('換頁前剛跳、或換頁途中才跳的要留著', () => {
    expect(isStaleOnNavigation(alert({ shownAt: navStart - ALERT_NAVIGATION_GRACE_MS }), navStart)).toBe(false)
    expect(isStaleOnNavigation(alert({ shownAt: navStart + 50 }), navStart)).toBe(false)
  })

  it('要整頁重載的（423）不自動關；沒有彈窗就沒事', () => {
    expect(isStaleOnNavigation(alert({ action: 'reload', shownAt: 0 }), navStart)).toBe(false)
    expect(isStaleOnNavigation(null, navStart)).toBe(false)
  })
})
