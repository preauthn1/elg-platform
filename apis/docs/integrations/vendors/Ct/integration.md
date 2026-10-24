# Ct（CT855 視訊）（vendor_id=27）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）— CT 端持有餘額 |
| 請求格式 | 全 POST，endpoint prefix `/{agentName}/` |
| 簽名方式 | MD5 per-request token（`MD5(agentName + API_key + random)`） |
| 成功判定 | `codeId=0` |
| 時區 | 文件未明述（gap）；Model 欄位命名含 Utc 但待驗證 |
| 注單同步 | pull 模式（getReport + mark），無 callback |
| Callback / 路由 | **無**（Transfer Wallet 不需 callback 路由） |
| Driver | `app/Vendors/Ct/Driver.php` |
| 帳號 Model | `App\Vendors\Models\CtAccount` |
| 注單 Model | `App\Vendors\Models\CtBetRecord`（唯一鍵 `betId`） |

---

## 高風險陷阱

### 1. Login 回應的 `token` 是遊戲 URL token，非驗證 token

Login API 成功回應包含 `token` 欄位，**此 token 是給玩家進入遊戲用的 URL token**，與請求中用於身份驗證的 `token`（MD5 簽章）同名但意義完全不同。

實際遊戲 URL = `list[0]` + 回應的 `token`（附加在 URL 末端）。絕對不可將回應 token 當作下一次 API 請求的驗證 token 使用。

### 2. `transfer` 正數=存款、負數=提款（同一 endpoint，金額符號決定方向）

存款到 CT（transferToVendor）與從 CT 提款（transferToLocal）都呼叫同一個 `/api/transfer/{agentName}/`，差別僅在 `member.amount` 正負：

- 正數 → 存款入 CT
- 負數 → 從 CT 提款

實作中應確認 `Common::numMul` / 符號處理邏輯，避免方向錯誤造成重複存款或提款。

### 3. getReport + mark 必須配對，否則重複拉取

getReport 每次回傳的是「尚未 mark 的注單」；只有呼叫 mark 後，對應 ID 才不會再次出現在下次 getReport。

**核心風險**：mark 前若程序異常（DB 寫入失敗後未 mark、mark 後 DB 寫入失敗），會造成：
- 未 mark → 重複拉取同一批注單（重複寫入問題）
- 已 mark 但 DB 寫入失敗 → 注單永久遺失（因為不會再出現）

**正確順序**：getReport → 確認全部寫入 DB → mark。必須以 DB 寫入成功為前提才呼叫 mark。

### 4. checkTransfer 的三態判斷

transfer 呼叫後結果不確定時（timeout、非 0/324 的 codeId），必須呼叫 checkTransfer 確認最終狀態：

| codeId | 意義 | VendorTransferResultEnum |
|---|---|---|
| `0` | 轉帳成功（已確認） | `SUCCESS` |
| `324` | 轉帳確定失敗 | `FAILED` |
| 其他 | 未知狀態 | `TIMEOUT` |

**絕對不可** 在結果不確定時直接重試 transfer，轉帳流水號（`data`）一旦重複使用會收到 `323`（流水號已用），但若先前轉帳實際上成功了，重試會造成重複轉帳。

---

## 轉帳流程

### transferToVendor（存款入 CT）

```
1. 呼叫 transfer（amount=+正數）
   │
   ├─ codeId=0 → SUCCESS → 完成
   ├─ codeId=324 → FAILED → 不重試，告知失敗
   └─ 其他/timeout → 呼叫 checkTransfer
          │
          ├─ codeId=0 → SUCCESS
          ├─ codeId=324 → FAILED
          └─ 其他 → TIMEOUT（繼續等待或人工處理）
```

### transferToLocal（從 CT 提款）

```
1. 呼叫 transfer（amount=-負數）
   │（後續狀態判斷同上）
```

### VendorTransferResultEnum 對應

| codeId | 回傳 |
|---|---|
| `0` | `SUCCESS` |
| `324` | `FAILED` |
| 其他 | `TIMEOUT` |

---

## 冪等性

| 機制 | 說明 |
|---|---|
| 轉帳流水號（`data`） | 全局唯一；重複使用收 `323`；遇 timeout 不可重試 transfer，改查 checkTransfer |
| getReport + mark | 配對機制；mark 後不再出現；`betId` 為注單唯一鍵 |
| isRevocation | `0`=未結算 / `1`=已結算（寫 TBR）/ `2`=對冲 / `3`=凍結；僅處理 `1` |

---

## 整合落地建議

### vendor_infos 設定

| 鍵 | 說明 |
|---|---|
| `API_URL` | CT API 位址（三選一：`http://example.invalid` / `http://example.invalid` / `http://example.invalid`） |
| `AGENT_NAME` | 代理名稱（用於 endpoint prefix 與 token 簽章） |
| `API_KEY` | MD5 簽章用的 API 金鑰 |
| `LOGIN_MIN_AMOUNT` | 進場最低下注額（可選） |
| `LOGIN_MAX_AMOUNT` | 進場最高下注額（可選） |

### BetRecord 欄位對應（getReport BetDetail）

| BetDetail 欄位 | 說明 |
|---|---|
| `id` | 注單唯一 ID → `ct_bet_records.betId` |
| `betTime` | 下注時間（格式 `yyyy-MM-dd HH:mm:ss`，時區待確認） |
| `calTime` | 結算時間（未結算時為 null） |
| `winOrLoss` | 派彩（已扣下注，負數=輸） |
| `betPoints` | 下注金額 |
| `availableBet` | 有效下注金額 |
| `isRevocation` | 結算狀態（0/1/2/3，見冪等性表格） |
| `parentBetId` | 對冲原始注單 ID（`isRevocation=2` 時使用） |

### 落地檢核清單

- [ ] `vendor_infos` 設定：`API_URL`、`AGENT_NAME`、`API_KEY`、`LOGIN_MIN_AMOUNT`/`LOGIN_MAX_AMOUNT`（可選）齊全。
- [ ] signup 時 `currencyName` 確認幣別（建立後不可修改）。
- [ ] login 回應 token 邏輯：`list[0] + token`（PC）、`list[1] + token`（WAP），勿將回應 token 當驗證 token。
- [ ] transfer 正負數邏輯：確認 transferToVendor/Local 的金額符號正確。
- [ ] getReport + mark 配對：DB 寫入確認後才 mark，避免遺失。
- [ ] 注單 isRevocation 過濾：僅 `isRevocation=1` 寫 TBR；`isRevocation=2` 對冲處理；`isRevocation=0/3` 暫存。
- [ ] getReport 頻率：最小間隔 10 秒；online API 最小間隔 30 秒。
- [ ] 時區驗證：確認 `betTime`/`calTime` 實際時區（Model 欄位命名含 Utc，但待運行驗證）。

---

## Gaps

| # | 項目 | 說明 |
|---|---|---|
| 1 | 時區 | `betTime`/`calTime` 文件未說明時區；Model 命名含 Utc 但需實際驗證 |
| 2 | 注單保留期 | 文件未明確說明 CT 保留注單多少天，需向客服確認 |
| 3 | lobbyId 完整清單 | 文件列出 2/3/4/5/6/7/8，是否有其他值未知 |
| 4 | `result`/`betDetail`/`ext` 格式 | 文件未說明這三個欄位的編碼格式 |
| 5 | `signup` 的 `data` 欄位可用值 | 文件說「A-G」但完整可用值清單未確認 |
