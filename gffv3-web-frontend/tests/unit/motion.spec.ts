import { readFileSync, readdirSync } from 'node:fs'
import { extname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  DEFAULT_SKELETON_TIMING,
  ENTER_ANIMATIONS,
  planSkeleton,
  WHEEL_SPIN_MS,
  WHEEL_SPIN_TURNS,
  wheelStopAngle,
  type SkeletonInput,
} from '../../app/utils/motion'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const MAIN_CSS = readFileSync(join(ROOT, 'app/assets/css/main.css'), 'utf8')

const TIMING = DEFAULT_SKELETON_TIMING

function plan(partial: Partial<SkeletonInput>) {
  return planSkeleton({
    loading: false,
    visible: false,
    pending: false,
    shownAt: 0,
    now: 0,
    timing: TIMING,
    ...partial,
  })
}

describe('載入骨架的時序', () => {
  it('開始載入時排在 delay 之後顯示', () => {
    expect(plan({ loading: true })).toEqual({ action: 'show', after: TIMING.delay })
  })

  /**
   * 守門測試：`loading` 維持 true 但 watcher 又觸發一次時（父層重設 props、
   * 同一次載入分兩段送），**不可以**把計時器重排——每次重排都把顯示時間往後推
   * delay 毫秒，請求夠慢時骨架永遠等不到出現，畫面就是一片空白。
   */
  it('已經排定或已經顯示時，再收到 loading=true 不重排', () => {
    expect(plan({ loading: true, pending: true })).toEqual({ action: 'idle' })
    expect(plan({ loading: true, visible: true })).toEqual({ action: 'idle' })
  })

  it('delay 之內就載完，完全不顯示骨架', () => {
    expect(plan({ loading: false, pending: true })).toEqual({ action: 'cancel' })
  })

  it('沒顯示過也沒排程時，載完不用做事', () => {
    expect(plan({ loading: false })).toEqual({ action: 'idle' })
  })

  it('已經顯示的骨架至少停滿 minimumDuration', () => {
    expect(plan({ loading: false, visible: true, shownAt: 0, now: 100 }))
      .toEqual({ action: 'hide', after: TIMING.minimumDuration - 100 })
  })

  it('停夠久了就立刻收，不會回負數', () => {
    expect(plan({ loading: false, visible: true, shownAt: 0, now: 9999 }))
      .toEqual({ action: 'hide', after: 0 })
  })

  it('delay 設成 0 也不會回負數', () => {
    expect(plan({ loading: true, timing: { delay: -50, minimumDuration: 280 } }))
      .toEqual({ action: 'show', after: 0 })
  })

  /** 一次完整的載入：排程 → 顯示 → 載完 → 收掉，中途不會漏狀態。 */
  it('走完一輪不會卡住', () => {
    const first = plan({ loading: true })
    expect(first).toEqual({ action: 'show', after: TIMING.delay })

    // 計時器到期，骨架顯示
    const whileLoading = plan({ loading: true, visible: true })
    expect(whileLoading).toEqual({ action: 'idle' })

    const done = plan({ loading: false, visible: true, shownAt: 0, now: TIMING.minimumDuration })
    expect(done).toEqual({ action: 'hide', after: 0 })
  })
})

describe('進場動畫', () => {
  /**
   * 守門測試：名字與 CSS 對不上時**不會報錯**，只是完全沒有動畫——
   * 這種缺陷 code review 看不出來，也不會有人在 console 看到警告。
   */
  it('每個名稱都有對應的類別與 keyframes', () => {
    for (const name of ENTER_ANIMATIONS) {
      expect(MAIN_CSS, `缺 .wf-in-${name}`).toContain(`.wf-in-${name} {`)
      expect(MAIN_CSS, `缺 @keyframes wf-in-${name}`).toContain(`@keyframes wf-in-${name}`)
    }
  })

  it('每個名稱都有關掉動畫的 reduced-motion 規則', () => {
    const reduced = MAIN_CSS.slice(MAIN_CSS.indexOf('@media (prefers-reduced-motion: reduce)'))
    for (const name of ENTER_ANIMATIONS) {
      expect(reduced, `${name} 沒有處理 prefers-reduced-motion`).toContain(`.wf-in-${name}`)
    }
  })

  /** 動畫的時間與位移要能換皮，所以只能從 --wf-motion-* 來，不可以寫死在類別裡。 */
  it('時間與位移走 token', () => {
    const block = MAIN_CSS.slice(
      MAIN_CSS.indexOf('.wf-in-fade,'),
      MAIN_CSS.indexOf('@media (prefers-reduced-motion: reduce)'),
    )
    expect(block).toContain('var(--wf-motion-enter')
    expect(block).toContain('var(--wf-motion-ease-enter')
    expect(block).toContain('var(--wf-motion-shift')
  })

  /** 皮要給得出這幾顆，不然全站只會吃到 fallback。 */
  it('C17 有定義 --wf-motion-*', () => {
    const theme = readFileSync(join(ROOT, 'skins/C17/theme.css'), 'utf8')
    for (const token of ['enter', 'ease-enter', 'shift', 'scale', 'skeleton', 'spin', 'dim']) {
      expect(theme, `theme.css 缺 --wf-motion-${token}`).toContain(`--wf-motion-${token}:`)
    }
  })

  /**
   * 守門測試：`prefers-reduced-motion` 時**不可以**把轉圈停下來。
   * 進場動畫、骨架呼吸是裝飾，關掉沒差；轉圈是「還在載入」的唯一訊號，
   * 停住就只剩一個不會動的圖示，使用者看不出系統在做事。放慢可以，關掉不行。
   */
  it('reduced-motion 只放慢轉圈，不關掉', () => {
    const bodies = [...MAIN_CSS.matchAll(/\.wf-spin\s*\{([^}]*)\}/g)].map(m => m[1] ?? '')
    expect(bodies.length, '.wf-spin 少了一般或 reduced-motion 的規則').toBeGreaterThan(1)
    for (const body of bodies) expect(body).not.toMatch(/animation:\s*none/)
    expect(bodies.join(''), '轉速沒走 --wf-motion-spin').toContain('var(--wf-motion-spin')
  })

  /**
   * 守門測試：彈窗的進場動畫要留在 `UiModal` 裡，而且只能用 CSS animation。
   * 換回 Vue 的 `<Transition appear>` 會在一半的場合安靜地失效——Nuxt 的頁面包在
   * `<Suspense>` 裡，切路由時整棵樹是先在畫面外組好再搬進來，appear 的 hook 會被跳過
   * （`/login` 這種「整頁就是一個彈窗」的頁面實測過）。
   */
  it('彈窗自己帶進場動畫', () => {
    const modal = readFileSync(join(ROOT, 'app/components/Ui/Modal.vue'), 'utf8')
    expect(modal).toContain('wf-in-fade')
    expect(modal).toContain('wf-in-slide-up')
    expect(modal).not.toContain('<Transition')
  })

  /** 元件一律不寫 <style> 區塊，動畫規則要放全域那一份。 */
  it('元件裡沒有 style 區塊', () => {
    const offenders: string[] = []
    const walk = (dir: string) => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name)
        if (entry.isDirectory()) walk(full)
        else if (extname(entry.name) === '.vue' && /<style[\s>]/.test(readFileSync(full, 'utf8'))) {
          offenders.push(full.replace(ROOT, '').replace(/\\/g, '/'))
        }
      }
    }
    walk(join(ROOT, 'app'))
    expect(offenders).toEqual([])
  })
})

describe('骨架元件的分工', () => {
  const walk = (dir: string, out: string[] = []): string[] => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name)
      if (entry.isDirectory()) walk(full, out)
      else if (extname(entry.name) === '.vue') out.push(full)
    }
    return out
  }

  /**
   * 守門測試：時序不可以搬回元件裡。
   * 呼叫端一律寫 `<UiSkeleton v-if="…">`，元件會在載入結束那一刻直接被卸載，
   * 放在元件內的 minimumDuration 計時器永遠執行不到——那正是它要擋的抖動。
   */
  it('UiSkeleton 不自己管時機', () => {
    const source = readFileSync(join(ROOT, 'app/components/Ui/Skeleton.vue'), 'utf8')
    expect(source).not.toMatch(/setTimeout|Date\.now|planSkeleton/)
  })

  /**
   * 同一個理由的另一面：用到骨架的檔案一定要經過 useSkeleton，不可以直接綁 loading。
   *
   * `Ui/Table.vue` 例外。它跟 `Ui/Skeleton.vue` 一樣只管長相——骨架列畫幾條、
   * 塞在哪一格是它的事，**什麼時候畫是呼叫端的事**（由下一條測試擋）。
   */
  const PRESENTATION_ONLY = ['app/components/Ui/Skeleton.vue', 'app/components/Ui/Table.vue']

  it('每個用到骨架的檔案都經過 useSkeleton', () => {
    const offenders: string[] = []
    for (const file of walk(join(ROOT, 'app'))) {
      const rel = file.replace(ROOT, '').replace(/\\/g, '/')
      if (PRESENTATION_ONLY.some(p => rel.endsWith(p))) continue
      const source = readFileSync(file, 'utf8')
      if (source.includes('<UiSkeleton') && !source.includes('useSkeleton(')) offenders.push(rel)
    }
    expect(offenders).toEqual([])
  })

  /** 表格骨架走的是 UiTable 的 loading prop，那顆一樣只能吃 useSkeleton 的結果。 */
  it('開了表格骨架的檔案都經過 useSkeleton', () => {
    const offenders: string[] = []
    for (const file of walk(join(ROOT, 'app'))) {
      const rel = file.replace(ROOT, '').replace(/\\/g, '/')
      if (PRESENTATION_ONLY.some(p => rel.endsWith(p))) continue
      const source = readFileSync(file, 'utf8')
      if (source.includes('<UiTable') && source.includes(':loading=') && !source.includes('useSkeleton(')) {
        offenders.push(rel)
      }
    }
    expect(offenders).toEqual([])
  })

  /** 骨架要能給其他皮共用，所以只能認語意層的兩顆色，不可以認 --c17-*。 */
  it('骨架的顏色走語意層 token', () => {
    const css = MAIN_CSS.slice(MAIN_CSS.indexOf('.wf-skeleton {'), MAIN_CSS.indexOf('@keyframes wf-skeleton-fade'))
    expect(css).toContain('var(--wf-skeleton-base')
    expect(css).toContain('var(--wf-skeleton-top')
    expect(css).not.toContain('--c17-')

    // 只看真的用到 token 的兩種寫法（var() 與 Tailwind 的任意值），說明文字裡提到不算
    const component = readFileSync(join(ROOT, 'app/components/Ui/Skeleton.vue'), 'utf8')
    expect(component).not.toContain('var(--c17-')
    expect(component).not.toMatch(/-\(--c17-/)

    const theme = readFileSync(join(ROOT, 'skins/C17/theme.css'), 'utf8')
    expect(theme).toContain('--wf-skeleton-base:')
    expect(theme).toContain('--wf-skeleton-top:')
  })

  /**
   * 守門測試：骨架的動畫只能動 opacity。
   * 這是無限迴圈的動畫，一個畫面同時九塊（遊戲列表）；只要有人改成動
   * background-position / width / filter，每一幀都要重畫，低階機就看得出掉幀。
   * opacity 由合成器處理，不進 layout 也不重繪。
   */
  it('骨架的動畫只動 opacity', () => {
    const frames = /@keyframes wf-skeleton-fade\s*\{([\s\S]*?)\n\}/.exec(MAIN_CSS)?.[1] ?? ''
    const properties = [...frames.matchAll(/([a-z-]+)\s*:/g)].map(m => m[1])
    expect(properties.length).toBeGreaterThan(0)
    expect([...new Set(properties)]).toEqual(['opacity'])
  })
})

describe('輪盤停格', () => {
  /**
   * 守門測試：停格角度算錯**不會報錯**，只會停在兩格的接縫上——
   * 畫面看起來像沒中獎，但結果文字寫著中了，第一版就是這樣錯的。
   * 扇形是從「負半格」起畫的，第一格的中線本來就對著 12 點鐘的指針，
   * 所以要停到第 n 格就是倒退 n 格，不可以再減半格。
   */
  it('停下來時中獎那一格的中線正對指針', () => {
    for (const count of [3, 6, 8]) {
      for (let index = 0; index < count; index++) {
        const slice = 360 / count
        const centre = (index * slice + wheelStopAngle(index, count)) % 360
        expect(centre, `${count} 格的第 ${index} 格`).toBeCloseTo(0)
      }
    }
  })

  it('第一格只轉整圈，找不到格子時也是', () => {
    expect(wheelStopAngle(0, 6)).toBe(360 * WHEEL_SPIN_TURNS)
    expect(wheelStopAngle(-1, 6)).toBe(360 * WHEEL_SPIN_TURNS)
    expect(wheelStopAngle(2, 0)).toBe(360 * WHEEL_SPIN_TURNS)
  })

  /** CSS 只定義怎麼轉，轉多久由 TS 給——兩邊各寫一份就會出現「還在轉就能再按」。 */
  it('轉盤的時間只有一個出處', () => {
    expect(MAIN_CSS).toContain('.wf-wheel {')
    const block = MAIN_CSS.slice(MAIN_CSS.indexOf('.wf-wheel {'))
    expect(block.slice(0, block.indexOf('}'))).not.toMatch(/transition-duration|\ds\b/)
    expect(WHEEL_SPIN_MS).toBeGreaterThan(0)
  })
})
