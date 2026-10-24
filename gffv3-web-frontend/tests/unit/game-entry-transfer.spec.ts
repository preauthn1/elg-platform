import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * 進場轉帳要跟舊版 C13／C16 一致（target/tickets#4328 對過）。「要不要轉」的規則本身由
 * wallet.spec 的 shouldTransferOnVendorEntry 鎖；這裡鎖頁面有沒有接對：
 * - 遊戲頁：用 'game' 轉入；進場前不能再並發一支不帶參數的 member/transfer，
 *   否則前端防重送／後端會員鎖會把轉入那支擋掉。
 * - 遊戲頁、遊戲列表：等領獎提醒放行（@ready）才轉，不在 onMounted 直接轉。
 * - 場館前頁：只導頁，轉入交給目的頁。
 * 頁面元件掛載成本高，這裡直接鎖原始碼裡的呼叫。
 */
function source(path: string): string {
  return readFileSync(resolve(__dirname, '../..', path), 'utf8')
}

/** 每個皮的同名頁面畫面（components/<皮>/Page/<name>.vue），之後加的皮自動納入檢查。 */
function skinPages(name: string): string[] {
  const dir = resolve(__dirname, '../../app/components')
  return readdirSync(dir)
    .map(skin => `app/components/${skin}/Page/${name}.vue`)
    .filter(path => existsSync(resolve(__dirname, '../..', path)))
}

function scriptOf(sfc: string): string {
  return sfc.match(/<script[^>]*>([\s\S]*?)<\/script>/)?.[1] ?? ''
}

function stripComments(code: string): string {
  return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
}

function fnBody(code: string, signature: string): string {
  return code.match(new RegExp(`${signature}[\\s\\S]*?\\n}`))?.[0] ?? ''
}

describe('進場轉帳與舊專案一致', () => {
  it('遊戲頁：走 syncSingleWallet(…, \'game\')，不另打 refreshBalance', () => {
    const code = stripComments(source('app/composables/useGamePlaying.ts'))
    expect(code).toMatch(/syncSingleWallet\(show\.data\.vendor_id, 'game'\)/)
    expect(code).not.toMatch(/refreshBalance/)
  })

  it('遊戲頁、遊戲列表：轉入要等領獎提醒放行（@ready），不能在 onMounted 直接轉', () => {
    const views = [...skinPages('GamePlaying'), ...skinPages('GameList')]
    expect(views.length).toBeGreaterThanOrEqual(2)
    for (const path of views) {
      const sfc = source(path)
      expect(sfc, path).toMatch(/<EventsPrizeReminder\s+@ready="/)
      expect(stripComments(scriptOf(sfc)), path).not.toMatch(/onMounted\(/)
    }
    for (const path of ['app/composables/useGamePlaying.ts', 'app/composables/useVendorGameList.ts']) {
      expect(stripComments(source(path)), path).not.toMatch(/onMounted\(/)
    }
  })

  it('領獎提醒：看 prize_remind；沒有待領獎勵或放棄成功才發 ready，前往領取／關閉不發', () => {
    const code = stripComments(scriptOf(source('app/components/Events/PrizeReminder.vue')))
    expect(code).toMatch(/flag\('prize_remind'\)/)
    expect(code).toMatch(/if \(!shown\.value\) emitReady\(\)/)
    expect(fnBody(code, 'async function giveUp\\(\\)')).toMatch(/emitReady\(\)/)
    expect(fnBody(code, 'function goClaim\\(\\)')).not.toMatch(/[Rr]eady/)
    expect(fnBody(code, 'function close\\(\\)')).not.toMatch(/[Rr]eady/)
  })

  it('遊戲商頁的頂部膠囊顯示遊戲商餘額（vendor_balance），不是主錢包', () => {
    const pages: [string, RegExp][] = [
      ['app/components/C17/Page/GamePlaying.vue', /<C17BalancePill[^>]*source="vendor"/],
      ['app/components/C17/Page/GameList.vue', /<C17PageHeader[^>]*balance-source="vendor"/],
      ['app/components/C17/Page/GameApp.vue', /<C17PageHeader[^>]*balance-source="vendor"/],
      ['app/components/Games/Statement.vue', /<C17PageHeader[^>]*balance-source="vendor"/],
    ]
    for (const [path, pattern] of pages) expect(source(path), path).toMatch(pattern)
  })

  it('場館前頁：開始遊戲只導頁，不先轉入', () => {
    // 邏輯在 composable 裡多縮排一層，先退回頂層再找函式本體
    const logic = stripComments(source('app/composables/useVendorAmount.ts')).replace(/^ {2}/gm, '')
    const start = fnBody(logic, 'async function start\\(\\)')
    expect(start).not.toBe('')
    expect(start).not.toMatch(/enterVendor|syncSingleWallet|transfer\(/)
  })
})
