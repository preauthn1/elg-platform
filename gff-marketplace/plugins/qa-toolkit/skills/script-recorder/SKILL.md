---
name: script-recorder
description: 把剛才用 web-browser 在 dev 站點做的操作，側錄成一支可重播的獨立 Playwright 腳本存進 plugin。當使用者說「把剛才的操作錄成腳本」「側錄這段」「存成可重播腳本」「錄下這段瀏覽」「record script」時使用。腳本用 env 引用網址/帳密、不寫死祕密；重播走 node 獨立執行。
---

# script-recorder — 側錄可重播的 Playwright 腳本

使用者用 [web-browser](../web-browser/SKILL.md) 操作 dev 站點後，若說要「錄下來」，把這一段操作整理成一支**獨立、可執行的 Playwright Node 腳本**（`.mjs`），存到 `plugins/qa-toolkit/scripts/`。**側錄是使用者主動決定的動作**——沒被要求就不錄。

## 硬規則（必守）

1. **不寫死祕密**：網址、帳號、密碼一律用 `process.env.WEBBROWSER_<FE|BE>_<...>` 引用（見 [web-browser reference/site-env.md](../web-browser/reference/site-env.md)）。側錄產物雖為本機保存、不入 repo，仍**絕不寫死帳密**（避免本機外流）。
2. **穩定 selector**：優先 `getByRole` / `getByLabel` / `getByText`，少用脆弱的 nth / 深層 CSS。
3. **忠實側錄**：只記錄實際做過的操作步驟，不臆造。破壞性步驟（刪除/批次改）在腳本裡加註解說明。

## 產出位置與命名

`plugins/qa-toolkit/scripts/<kebab-name>.mjs`（名字描述用途，如 `tpower-be-disable-player.mjs`）。

## 腳本骨架（依實際操作填「側錄區」）

```javascript
// <name>.mjs — 側錄自 web-browser。重播：node plugins/qa-toolkit/scripts/<name>.mjs
// 需求：本機有 Node + playwright 套件 + 系統 Google Chrome（與 web-browser MCP 同一顆，不需 npx playwright install）；對應 WEBBROWSER_* env 已設。
import { chromium } from 'playwright';

const URL  = process.env.WEBBROWSER_BE_URL;
const USER = process.env.WEBBROWSER_BE_USER;
const PASS = process.env.WEBBROWSER_BE_PASS;
for (const [k, v] of Object.entries({ WEBBROWSER_BE_URL: URL, WEBBROWSER_BE_USER: USER, WEBBROWSER_BE_PASS: PASS })) {
  if (!v) { console.error(`缺 env ${k}，請設定後再跑`); process.exit(1); }
}

// 用系統 Chrome（channel:'chrome'），對齊 @playwright/mcp 預設；免依賴 Playwright 自帶 chromium/headless-shell 下載
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const page = await browser.newPage();
try {
  await page.goto(URL);
  await page.getByLabel('帳號').fill(USER);
  await page.getByLabel('密碼').fill(PASS);
  await page.getByRole('button', { name: '登入' }).click();

  // --- 側錄的操作從這裡開始（依實際步驟填寫）---
  // await page.getByRole('link', { name: '玩家管理' }).click();
  // await page.getByRole('button', { name: '停用' }).click();
  // --- 側錄結束 ---

  console.log('replay done');
} finally {
  await browser.close();
}
```

## 重播

```bash
node plugins/qa-toolkit/scripts/<name>.mjs
```

先確認：對應 `WEBBROWSER_*` env 已設、Node 能解析到 `playwright` 套件、本機有系統 Google Chrome（與 web-browser MCP 同一顆，**不需** `npx playwright install`）。腳本自足、不依賴 Claude。

## 本機保存（不 commit）

側錄出的 `.mjs` 為**本機工具、不隨 repo commit**（`scripts/.gitignore` 已排除 `*.mjs`）。因每支腳本各自內嵌登入、無法跨腳本共用 session，留在本機重播即可。即使不進 repo，仍**一律用 `process.env.WEBBROWSER_*`、不寫死帳密**（避免本機外流）。
