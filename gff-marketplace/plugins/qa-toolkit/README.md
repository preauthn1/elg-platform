# qa-toolkit

QA 專用工具包（opt-in；自帶 Playwright MCP，不影響其他 plugin）。以 Playwright 瀏覽並**操作**（可讀可寫）dev 站點前台/後台。首版 dev-only。含兩個 skill：

- `web-browser` — 登入 dev 前台/後台、瀏覽與操作。
- `script-recorder` — 把剛才的操作側錄成**可重播的 Playwright 腳本**，存進 `scripts/`。

> ⚠️ 無唯讀限制：可送表單、按刪除、執行任意 Playwright 操作。**限定 dev 站台**，影響範圍＝dev 資料。

## 設定（每位使用者一次）

在 shell 啟動檔（`~/.zshrc` 或 `~/.bashrc`）加入 env（dev 前台/後台）：

```bash
export WEBBROWSER_FE_URL="https://<dev-前台>"
export WEBBROWSER_FE_USER="<帳號>"
export WEBBROWSER_FE_PASS="<密碼>"
export WEBBROWSER_BE_URL="https://<dev-後台>"
export WEBBROWSER_BE_USER="<帳號>"
export WEBBROWSER_BE_PASS="<密碼>"
```

命名規則 `WEBBROWSER_<FE|BE>_<URL|USER|PASS>`，見 `skills/web-browser/reference/site-env.md`。

## 側錄與重播

- 側錄：瀏覽時說「把剛才的操作錄成腳本」，`script-recorder` 產出獨立 `.mjs` 到 `scripts/`。
- 重播：`node plugins/qa-toolkit/scripts/<name>.mjs`（需 Node + `playwright` 套件 + 系統 Google Chrome，env 已設；不需 `npx playwright install`）。
- 腳本一律用 `process.env.WEBBROWSER_*` 引用網址/帳密，**絕不寫死祕密**；側錄產物為本機工具、**不隨 repo commit**（`scripts/.gitignore` 已排除 `*.mjs`）。
