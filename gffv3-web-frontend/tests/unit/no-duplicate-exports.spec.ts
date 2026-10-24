import { readdirSync, readFileSync } from 'node:fs'
import { join, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：自動匯入範圍內不可以有兩個檔案 export 同一個名字。
 *
 * Nuxt 會自動匯入 app/composables、app/utils、shared/utils、app/stores。
 * 名字撞到時它**只會挑其中一個，而且不報錯**——呼叫端默默拿到另一支的實作。
 * 例如 `formatCountdown` 一支回 `HH:mm:ss`、另一支回補零的秒數（`05`），
 * 同名共存時呼叫端拿到哪一支看載入順序，畫面錯了也沒有錯誤訊息。
 *
 * 撞名時請把其中一支改成講得出差別的名字（例：`formatClock`），
 * 不要用「加前綴」硬閃過去，也不要靠呼叫端自己寫 import 來迴避。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const DIRS = ['app/composables', 'app/utils', 'shared/utils', 'app/stores']

const EXPORT = /^export\s+(?:async\s+)?(?:function|const|class|interface|type|enum)\s+([A-Za-z_$][\w$]*)/gm

function tsFiles(dir: string, out: string[] = []): string[] {
  let entries: ReturnType<typeof readdirSync>
  try {
    entries = readdirSync(dir, { withFileTypes: true })
  }
  catch {
    return out
  }
  for (const entry of entries) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) tsFiles(full, out)
    else if (entry.name.endsWith('.ts')) out.push(full)
  }
  return out
}

function collectOwners(): Map<string, string[]> {
  const owners = new Map<string, string[]>()
  for (const dir of DIRS) {
    for (const file of tsFiles(join(ROOT, dir))) {
      const rel = file.slice(ROOT.length).split(sep).join('/').replace(/^\//, '')
      for (const match of readFileSync(file, 'utf8').matchAll(EXPORT)) {
        const name = match[1]!
        const list = owners.get(name) ?? []
        if (!list.includes(rel)) list.push(rel)
        owners.set(name, list)
      }
    }
  }
  return owners
}

describe('自動匯入範圍內不可以有同名 export', () => {
  it('每個名字只有一個出處', () => {
    const dupes = [...collectOwners().entries()]
      .filter(([, files]) => files.length > 1)
      .sort(([a], [b]) => a.localeCompare(b))

    const report = dupes
      .map(([name, files]) => `  ${name}\n      ${files.join('\n      ')}`)
      .join('\n')

    expect(
      dupes.map(([name]) => name),
      `以下名字被多個自動匯入的檔案 export，Nuxt 只會挑一個而且不報錯：\n${report}`,
    ).toEqual([])
  })
})
