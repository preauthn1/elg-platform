# scripts/

`script-recorder` 側錄出的可重播 Playwright 腳本放這裡（`<name>.mjs`）。

- 側錄產物 `*.mjs` 為**本機工具、不隨 repo commit**（已於 `scripts/.gitignore` 排除）。每支腳本各自內嵌登入、無法跨腳本共用 session，留在本機即可。
- 腳本一律用 `process.env.WEBBROWSER_*` 引用網址/帳密，**不寫死祕密**。
- 重播：`node plugins/qa-toolkit/scripts/<name>.mjs`（需 Node + `playwright` 套件 + 系統 Google Chrome，env 已設；不需 `npx playwright install`）。
