---
name: web-browser
description: 以 Playwright 瀏覽並操作 dev 站點前台/後台（可讀可寫）。當使用者說「看/操作 dev 前台、後台」「登入 dev 後台做 X」「巡檢 dev 站點」「在 dev 後台改/查 X」「web-browser」時使用。站台網址與帳密由本機 shell 啟動檔（~/.zshrc 或 ~/.bashrc）的 env 提供。要把操作錄成可重播腳本時改用 script-recorder。
---

# web-browser — dev 站點瀏覽與操作

以 Playwright 登入 dev 站台前台/後台，瀏覽並執行操作。**無唯讀限制**——可送表單、按刪除、任意 Playwright 操作。首版 dev-only、站台 tpower。

> ⚠️ 因限定 **dev**，寫入影響範圍＝dev 資料。若使用者指定 prod（首版不支援）→ 停下確認，不逕自連 prod。

## 1. Preflight：讀站台 env（缺則停）

站台網址/帳密來自使用者的 shell 啟動檔（`~/.zshrc` 或 `~/.bashrc`），命名 `WEBBROWSER_<FE|BE>_<URL|USER|PASS>`（見 [reference/site-env.md](reference/site-env.md)）。依使用者要操作前台或後台組 key，讀不到就**停下、指出缺哪個 key、請補進 shell 啟動檔**，**絕不把值印進對話或 log**。

```bash
# 例：要進 dev 後台
: "${WEBBROWSER_BE_URL:?請在 shell 啟動檔（~/.zshrc 或 ~/.bashrc）設定 WEBBROWSER_BE_URL}"
: "${WEBBROWSER_BE_USER:?請在 shell 啟動檔（~/.zshrc 或 ~/.bashrc）設定 WEBBROWSER_BE_USER}"
: "${WEBBROWSER_BE_PASS:?請在 shell 啟動檔（~/.zshrc 或 ~/.bashrc）設定 WEBBROWSER_BE_PASS}"
```

## 2. 登入

- `browser_navigate` 到對應 `_URL`。
- 用 `browser_type` 逐欄填帳號、密碼（值取自 env，勿回顯），`browser_click` 登入鈕。
- 登入後還停在登入頁（新後台是 `/auth/login`）或出現錯誤訊息，就回報登入失敗，不重試。

## 3. 瀏覽與操作

- 導覽/讀取：`browser_navigate` / `browser_click` / `browser_snapshot`（a11y 樹，優先）/ `browser_evaluate` / `browser_take_screenshot`。
- 寫入（允許）：`browser_fill_form` / `browser_type` / 送出、儲存、刪除等——**依使用者實際要求執行**。
- 破壞性操作（刪除、批次改動）前，**先簡述將做什麼再執行**（dev 亦然，避免誤操作）。

## 4. 側錄

要把這一段操作存成可重播腳本 → 交給 [script-recorder](../script-recorder/SKILL.md)。

## 冷啟對話範例

> **User**：用 web-browser 進 dev 後台，把玩家 X 的狀態改成停用
>
> **AI**：（觸發 web-browser）Preflight 讀 `WEBBROWSER_BE_URL/USER/PASS` → 齊。`browser_navigate` 後台 → `browser_type` 帳密登入 → 導到玩家管理 → 找到 X → 先說「將把 X 狀態改為停用並送出」→ 執行 → 回報結果。
>
> （若 env 缺）**AI**：停下——shell 啟動檔缺 `WEBBROWSER_BE_PASS`，請補上再試；我不會顯示已設定的值。
