# M8bet（vendor_id=103）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 廠商類型 | Sportsbook（體育投注），非 slot/casino |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| vendor_id | `103`（`VendorIdEnum::M8BET`） |
| 驗證方式 | `secret`（query string 明文）＋ IP 白名單，**無加密簽名** |
| Endpoint | `POST https://<host>/apijs.aspx?secret=[REDACTED] |
| 貨幣 | MYR（馬來西亞令吉） |
| 時區 | UTC+8 |
| 一天的定義 | 前日 **12:00PM** 至 當日 **11:59:59AM** |
| 注單保留期 | **30 天**（標記後不可再抓，前台 180 天） |
| 成功判定 | `errcode === 0` |
| 登入 URL 時效 | 60 秒 |
| 程式路徑（Driver） | `app/Vendors/M8bet/Driver.php`（繼承 `AbstractTransferWallet`） |
| Callback Controller | **無**（轉帳錢包，M8bet 不主動回調） |

---

## 高風險陷阱

### 1. `check_payment` 查無（errcode=1）被視為 FAILED（中間態坍縮）

`checkTransferStatus` 將任何非 0 errcode 一律回 `FAILED`，包含 errcode `1`「No result」（查無此 serial）。

問題：「轉帳尚未到達 M8bet」和「轉帳確定失敗」都會走相同 FAILED 路徑，中間態被坍縮成失敗。在網路延遲或 M8bet 端處理慢的情況下，可能導致重試邏輯誤判。

建議：確認上游 reconciliation 行為是否接受此語意；若需區分中間態，需對 errcode=1 單獨處理（回 PENDING/TIMEOUT 而非 FAILED）。

### 2. `fetch` 系列注單同步未實作（注單落地路徑不明）

Driver 僅實作 transfer/balance/login/check_payment，**未見任何 fetch / fetch_result / mark_fetched 的呼叫**。`M8betBetRecord` 資料表存在，但注單寫入來源在 Driver 中無體現。

影響：若注單從未被抓取，BetRecord 將永遠空白，且 30 天視窗到期後資料永久不可回填。

待確認：注單是否由排程或其他元件抓取，抓取後是否正確呼叫 `mark_fetched` 標記（標記後不可再抓）。

### 3. 注單 30 天視窗（標記後不可再抓）

M8bet API 數據僅保留 **30 天**。一旦呼叫 `mark_fetched` 標記，該筆資料**永久不可再取得**。

操作守則：
- 落地確認（落入 DB）後才能標記，嚴禁「抓到就標記」
- 對帳工具務必在 30 天窗口內完成；超出期限需評估改用前台（180 天）或聯繫客服

### 4. `??` 運算子優先級 bug（低嚴重度，日誌可讀性問題）

多處錯誤訊息串接：
```php
"... API error: " . $response['errtext'] ?? 'unknown error'
```
PHP 中 `.` 優先於 `??`，等同於 `("... " . $errtext) ?? 'unknown'`，右側 `?? 'unknown error'` 幾乎永不生效。此為日誌健壯性問題，不影響成功路徑判斷。相同寫法出現在 `getBalance`、`transfer`、`checkTransferStatus`、`getGameUrl`。

---

## 錢包流程（Transfer Wallet）

```
建立帳號 (create)
   │
   ▼
進入遊戲：transferToVendor → deposit（本地 → M8bet）
   │
   ▼
getGameUrl → login（取得 weburl/mobiurl，60 秒過期）
   │
   ▼
玩家於 M8bet 端投注（餘額在 M8bet 保管）
   │
   ▼
離開遊戲：transferToLocal → withdraw（M8bet → 本地）
   │
   ▼
對帳（尚未實作）：fetch / fetch_result → 落地 → mark_fetched 標記
```

**重要**：login 產生的 URL **60 秒**後即失效，前端應在取得後立即導轉。

---

## 冪等性

| 動作 | 防重機制 |
|---|---|
| `deposit`（存款） | `serial`（transactionNo）須唯一；重號回 errcode `2`「Serial exist」 |
| `withdraw`（提款） | 同上 |
| `check_payment`（查單） | 帶 `serial` 查詢，幂等 |
| `fetch` / `mark_fetched` | 以 `fid` 標記；標記後不可再抓（**不可逆**） |

`transfer` 若因網路問題無回應，應先以 `check_payment` 查單確認結果，避免用相同 serial 重試（回 FAILED）或用新 serial 重試（導致雙轉）。

---

## 整合落地建議

### Driver 方法 → M8bet action 對應表

| 本地方法 | M8bet action | 關鍵參數 | 備註 |
|---|---|---|---|
| `transferToVendor`（存款） | `deposit` | `serial=transactionNo`、`amount=vendorAmount` | errcode 0 = SUCCESS，非 0 = FAILED，異常結構 = TIMEOUT |
| `transferToLocal`（提款） | `withdraw` | 同上 | 同上 |
| `checkTransferStatus` | `check_payment` | `serial=transactionNo` | errcode 0 = SUCCESS，**非 0（含查無 errcode=1）= FAILED**（見高風險陷阱#1） |
| `getGameUrl` | `login` | `accType='MY'`、`lang=<映射後>` | 回 `result.login.weburl`（PC）/`mobiurl`（行動），60 秒過期 |
| `getBalance` | `balance` | `username` | `result` 為餘額字串，經 `Common::numFloor` |
| 建立帳號 | `create` | `username` | `M8betAccount.vendor_username` 存 M8bet 帳號 |

### vendor_infos.info 欄位對應

| info 欄位 | 對應 M8bet 概念 |
|---|---|
| `AUTH_URL` | API host（需 `trim('/')`） |
| `CLIENT_ID` | `agent`（agent name） |
| `CLIENT_SECRET` | `secret`（agent secret key）— 出現在 URL，務必確認 log 是否遮罩 |
| `CLIENT_CLASS` | 用途不明，未見於官方文件（待 RD 確認） |
| `CURRENCY` | 貨幣別（如 `MYR`） |

### 安全注意事項

`secret` 以明文 query string 傳輸，`Http::logAction` 記錄完整 URL 時會含 secret。務必確認 log channel 不外洩，或在記錄前以 `Common::maskCredentials()` 遮罩。

---

## Gaps / 待 M8bet 確認

| # | 問題 | 影響 |
|---|---|---|
| 1 | `getGameUrl` 取非 secure URL（`weburl`/`mobiurl`），官方另有 `weburlsecure`/`mobiurlsecure`（HTTPS）| 前端若強制 HTTPS 可能導致 mixed content |
| 2 | `accType` 硬編 `MY`（馬來盤），官方支援 `MY, HK, ID, EU` | 多站台或多幣種環境需參數化 |
| 3 | `lang` 映射 `ZH_TW → ZH-CN`，官方 `login` 明確支援 `ZH-TW` | 繁中玩家看到簡中介面 |
| 4 | `check_payment` errcode=1（查無）被視為 FAILED | 中間態坍縮，詳見高風險陷阱#1 |
| 5 | `fetch`/`fetch_result`/`mark_fetched` 系列未實作 | 注單落地路徑不明，詳見高風險陷阱#2 |
| 6 | `login` 未帶 `timezoneid`（官方 `29`=GMT+8）及行動版 `ref` | 前端時區顯示可能不一致 |
| 7 | `CLIENT_CLASS` 欄位用途未知 | 不確定是否影響行為 |
