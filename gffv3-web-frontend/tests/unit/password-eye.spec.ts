import { readFileSync, readdirSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 密碼欄一定要有「顯示／隱藏」的眼睛。
 *
 * 設計稿 `form-components`（第 7 張畫板）把兩態都畫出來了——
 * `Eye hide`（uid 2b39d406）與 `Eye Unhide`（uid 1886e640），
 * 而且 login / register / login-reset-pw-mobile / -email 每一個密碼欄右邊都有那顆圖示。
 *
 * 這支擋的是「整頁忘記放」：登入放了、註冊或重設密碼漏掉，畫面上不會報錯，
 * 只是少一顆圖示，除非逐頁比對設計稿否則看不出來。
 *
 * 判斷方式刻意粗一點：只要檔案裡有密碼欄，就要求同一個檔案也出現 `eye-show`
 * （UiFieldIcon 的切換態）。抓的是「整頁忘記放」，不是逐欄位比對。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

/** 走訪 app/ 底下所有 .vue，不另外裝 glob 套件。 */
function walkVue(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) walkVue(full, out)
    else if (entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

/**
 * 還沒補眼睛的檔案。**這是待辦清單，不是豁免**——這幾頁設計稿沒畫，
 * 但一樣會被看到，做到那一頁時順手補上並從這裡移除。
 */
const TODO: Record<string, string> = {
  'app/components/C17/Page/AgentApplyShareholder.vue': '股東申請的密碼欄，設計稿沒有這頁',
  'app/components/C17/Page/GameApp.vue': '遊戲 APP 帳密展示，不是輸入框',
  'app/components/Auth/CompleteProfileForm.vue': '補資料表單，設計稿沒有這塊',
}

/** 這個檔案裡有沒有密碼輸入欄。 */
function hasPasswordField(src: string): boolean {
  return /type="password"/.test(src)
    || /'password'\s*:\s*'text'/.test(src)
    || /\?\s*'text'\s*:\s*'password'/.test(src)
    || /isPassword\(/.test(src)
}

describe('密碼欄的顯示／隱藏', () => {
  it('有密碼欄的畫面都放了眼睛', () => {
    const missing: string[] = []

    for (const full of walkVue(join(ROOT, 'app'))) {
      const src = readFileSync(full, 'utf8')
      if (!hasPasswordField(src)) continue
      if (src.includes('eye-show')) continue
      const key = relative(ROOT, full).split('\\').join('/')
      if (key in TODO) continue
      missing.push(key)
    }

    expect(missing, '這些畫面有密碼欄但沒有顯示／隱藏的眼睛。\n'
    + '設計稿 form-components 兩態都畫了（Eye hide / Eye Unhide），素材在 '
    + 'public/skins/c17/field/eye-{hide,show}.webp，'
    + '用 <UiFieldIcon> 掛在 UiTextField 的 #suffix：\n'
    + `${missing.join('\n')}`)
      .toEqual([])
  })

  it('TODO 清單裡的檔案都還在（補完了就要把它移除）', () => {
    const stale = Object.keys(TODO).filter((rel) => {
      try {
        const src = readFileSync(join(ROOT, rel), 'utf8')
        return !hasPasswordField(src) || src.includes('eye-show')
      }
      catch {
        return true
      }
    })
    expect(stale, `這幾個已經不需要留在 TODO 了（檔案不見了、沒有密碼欄，或已經補上眼睛），請刪掉：\n${stale.join('\n')}`)
      .toEqual([])
  })
})
