# 魔鏡提問紀錄（query log）＋ user 名字同步 設計

> 目的：記下「誰發訊息來、問了什麼」。先以純文字 log 檔落地，日後可升級 SQLite。
> 記錄目的涵蓋營運分析、使用追蹤、個人化上下文（先存原始資料，用途後續再擴充）。

## 需求

- 每次提問追加一行到 log 檔，格式：`[<date>] <user name>: <message>`
  - 範例：`[2026-07-14 10:30:45] Rhys: 怎麼更換遊戲商 banner`
  - 時間為本地時區 `YYYY-MM-DD HH:mm:ss`
- user name 優先查本地對照表；不在表上的 user 即時向 Slack API 抓取並同步回表
- 三個提問入口都要記：`app_mention`、DM、`search_related` 按鈕

## 元件

### `src/users.js` — user 名字解析與同步

- 啟動時載入 `data/users.json`（種子：複製自 dump-issues `.agents/skills/slack-discussion-sync/users.json`，22 筆 Slack user ID → 名字）
- `resolveUserName(client, userId)`：
  1. map 命中 → 回名字
  2. 未命中 → `example.invalid({ user: userId })` 取 `profile.display_name`，空則 fallback `real_name`
  3. 取得後寫進 in-memory map 並持久化回 `data/users.json`
  4. API 失敗（如缺 `users:read` scope）→ 回原始 user ID，不寫入 map（下次再試）

### `src/queryLog.js` — 提問紀錄

- `logQuery(userName, message)`：`fs.appendFile` 追加到 `logs/questions.log`，目錄不存在自動建立
- 寫入失敗只 `console.error`，不中斷回答流程

### `src/index.js` — wiring

- 三個入口在處理 query 前呼叫 `resolveUserName` + `logQuery`
- fire-and-forget：log 流程不 await 阻塞回答；user ID 來源——`app_mention`: `event.user`、DM: `message.user`、按鈕: `body.user.id`

## 其他

- `logs/` 加入 `.gitignore`
- `data/users.json` 納入版控（種子），bot 執行時就地更新
- 不做（YAGNI）：log rotation、記錄檢索結果／評分、查詢介面——升級 SQLite 時再議

## 測試

- `users.js`：map 命中／未命中走 API（mock client）／API 失敗 fallback／持久化寫回
- `queryLog.js`：格式正確、追加不覆蓋、寫入失敗不 throw
