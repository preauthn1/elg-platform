# env 命名表

命名規則：`WEBBROWSER_<FE|BE>_<URL|USER|PASS>`（dev 環境）

| 目標 | key |
|---|---|
| 前台(FE) | `WEBBROWSER_FE_URL` / `WEBBROWSER_FE_USER` / `WEBBROWSER_FE_PASS` |
| 後台(BE) | `WEBBROWSER_BE_URL` / `WEBBROWSER_BE_USER` / `WEBBROWSER_BE_PASS` |

- 值寫在使用者的 shell 啟動檔（`~/.zshrc` 或 `~/.bashrc`），repo 不存。
- prod 站台不在首版範圍。
- 側錄出的 Playwright 腳本一律用 `process.env.WEBBROWSER_*` 引用這些值，不寫死。
