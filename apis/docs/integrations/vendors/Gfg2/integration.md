# Gfg2（vendor_id=105）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet） |
| vendor_id | `105` |
| 回調方向 | 無（純轉帳錢包，GFG2 不主動回調代理） |
| 驗證方式 | MD5，HTTP Header `Authorization: md5(paramStr + key)` |
| 成功判定 | `code == 0` |
| 時區 | UTC+8（北京時間） |
| 資料保留 | API 90 天；前台可查 30 天 |
| Base URL | `http://example.invalid/`（正式 MYR） |
| 憑證儲存 | `vendor_infos.info`：`AUTH_URL`、`CLIENT_SECRET`、`CLIENT_AGENT`、`CLIENT_ID`、`CLIENT_THEME`、`CLIENT_CLASS`、`CURRENCY` |

---

## 高風險陷阱

### companyKey 已於 2024-11-01 刪除但舊文件仍列為必填

文件 v2.2.8 在所有 API 的請求參數表中仍標注 `companyKey` 為必填。但實作中 `VendorInfoDetail` 的 `CLIENT_ID` 欄位 PHPDoc 明確標注「2024-11-01 文檔刪除參數 companyKey」，`apiCall()` 不帶此欄位。

**影響**：若新對接白標照舊文件送 `companyKey` 可能無效；若遇 code=13（非法的公司 key）需先排除此欄位。建議以實作行為為準，向 GFG2 客服確認最新文件。

### timestamp 精度：微秒（實作）vs 毫秒（文件）

文件要求 `timestamp` 為 Unix 毫秒（13 位，如 `1680608286141`）。實作 `Driver.php getTransactionNo()` 使用 `Carbon::now('Asia/Taipei')->format('YmdHisu')`，其中 `u` 為微秒（6 位），生成的訂單號時間精度高於文件規格。

**影響**：`orderId` 格式本身唯一性更強，問題不大；但若 GFG2 端嚴格驗證 `orderId` 長度或格式，可能拒絕訂單。待 RD 確認是否需對齊為毫秒格式（`v` = 毫秒）。

### queryTransferOrder endpoint 拼字（文件 typo）

官方文件原文 endpoint 為 `queryTransfeOrder`（少一個 `r`），實作中正確呼叫 `queryTransferOrder`（含 `r`）。

**影響**：若 GFG2 API 伺服器實際路徑跟著文件用錯誤拼法，轉帳狀態查詢將返回 404。目前實作應為正確，但在對接問題排查時須注意此 typo。

---

## 錢包流程（轉帳錢包）

```
玩家請求進入遊戲
      │
      ▼
呼叫 doTransferDepositTask（上分）
      │
      ├─ code=0 ──▶ 呼叫 login 取遊戲 URL ──▶ 返回 URL 給玩家
      │
      ├─ code=5 ──▶ 訂單重複（已成功）──▶ 直接取遊戲 URL
      │
      └─ code=1/其他 ──▶ 回滾本地餘額 ──▶ 返回錯誤
            若狀態不明 ──▶ 呼叫 queryTransferOrder 確認實際狀態

玩家離開遊戲
      │
      ▼
呼叫 doTransferWithdrawTask（下分）
      │
      ├─ code=0 ──▶ 加回本地餘額
      │
      ├─ code=5 ──▶ 訂單重複，若本地未加分則補加
      │
      ├─ code=6 ──▶ GFG2 餘額不足，不加本地餘額
      │
      ├─ code=10 ──▶ 訂單號格式錯誤，不加本地餘額
      │
      └─ 其他 ──▶ 呼叫 queryTransferOrder 確認實際狀態
```

---

## 冪等性

| 場景 | 處理方式 |
|---|---|
| 上分重送相同 `orderId` | GFG2 回 code=5（訂單重複），代理**不需回滾** |
| 下分重送相同 `orderId` | GFG2 回 code=5，若本地未加分則**補加玩家額度** |
| 上分失敗（code=1） | 需**回滾**本地餘額 |
| 下分失敗（code=6 餘額不足 / code=10 格式錯誤） | **不加**本地餘額 |
| 狀態不明（網路超時等） | 呼叫 `queryTransferOrder` 確認，再決定補扣 |
| 訂單唯一性 | `orderId = {CLIENT_AGENT}{YmdHisu}{vendorUsername}` 確保唯一 |

---

## 整合落地建議

| GFG2 概念 | APIs 專案對應 |
|---|---|
| API Base URL | `vendor_infos.info.AUTH_URL` |
| 簽名金鑰 `key` | `vendor_infos.info.CLIENT_SECRET` |
| 代理 ID `agent` | `vendor_infos.info.CLIENT_AGENT` |
| 公司標識 `companyKey` | `vendor_infos.info.CLIENT_ID`（格式：`{agentId}_{currency}`，如 `1784194_myr`；已廢棄但存著備用） |
| 遊戲主題 `theme` | `vendor_infos.info.CLIENT_THEME` |
| 帳號前綴 | `vendor_infos.info.CLIENT_CLASS`（帳號格式：`{CLIENT_CLASS}_{username}`） |
| 幣別 | `vendor_infos.info.CURRENCY` |
| 玩家帳號 | `gfg2_accounts.vendor_username`（若有），或 `{CLIENT_CLASS}_{username}` |
| Driver | `app/Vendors/Gfg2/Driver.php`（繼承 `AbstractTransferWallet`） |
| 帳號 Model | `app/Vendors/Models/Gfg2Account.php` |
| BetRecord Model | `app/Vendors/Models/Gfg2BetRecord.php` |
| Callback Controller | 無（GFG2 不回調代理） |
| 注單同步 | `takeBetLogs`（分頁，`size<=100`，`page` 從 0 開始，以 UTC+8 時間傳入） |
| 對帳保留期 | API 90 天 / 前台 30 天 |
| 語言映射 | `Driver.php mapLanguageCode()`（ZH_TW→`zh_tw`、VI_VN→`vi_vn`、TH_TH→`th_th`、TA→`en_in`、其他→`en_us`） |

---

## Gaps / 待 Gfg2 確認

| # | 問題 | 狀態 |
|---|---|---|
| 1 | **companyKey 是否真的廢棄**：v2.2.8 文件仍列必填，實作已移除，需客服確認最新規格 | 待確認 |
| 2 | **timestamp 精度**：文件要求毫秒，實作用微秒（`u`），是否需對齊 | 待確認 |
| 3 | **queryTransferOrder 拼字**：文件原文為 `queryTransfeOrder`，實作用 `queryTransferOrder`，確認伺服器實際 path | 待確認 |
| 4 | **額外錯誤碼 16/17/18/10001**：實作 `acceptedErrorCodes()` 包含但文件未列，確認含義與來源 | 待確認 |
| 5 | **`orderId` 最大長度**：文件未明確說明，避免超出限制 | 未知 |
| 6 | **`token` 欄位（login API）**：文件說必填，實作未見傳入，確認是否需要 | 待確認 |
| 7 | **Rate Limit 上限**：code=7 / code=602 為頻率限制，具體上限（每秒幾次）未說明 | 未知 |
| 8 | **`takeBetLogs` 超頁行為**：`page` 超出 `total/size` 後回傳空 `bets` 還是錯誤碼 | 未知 |
