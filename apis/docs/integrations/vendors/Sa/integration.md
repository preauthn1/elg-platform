# Sa（vendor_id=37）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet（轉帳錢包）；玩家餘額由 SA Gaming 端保管 |
| 請求格式 | `POST` + `application/x-www-form-urlencoded`；**回應為 XML**（本批 10 家中唯一） |
| 驗證方式 | DES-CBC 加密（`q` 參數） + MD5 數位簽章（`s` 參數） |
| 成功判定 | XML `<ErrorMsgId>0</ErrorMsgId>` |
| API 端點 | 兩套：通用 API 與下注詳情查詢各用不同 host |
| 時區 | 未明示；`GetAllBetDetailsDV` 日界為 **中午 12:00 至次日 11:59:59**（非凌晨） |
| 金額精度 | 最多 **2 位小數**；超過回傳 Error 145 |
| 查詢限流 | `GetAllBetDetailsDV` / `GetAllBetDetailsForTimeIntervalDV` 每 5 分鐘最多 10 次 |
| Seamless 回調 | 本站未接入；SA Gaming 支援但本平台為 Transfer Wallet 模式，無需實作 |

## 高風險陷阱

### 1. XML 回應（與其他廠商截然不同）

SA Gaming 所有 API 回應為 **XML 格式**，而非 JSON。解析時須使用 `simplexml_load_string()` 或同等方式，不可以 `->json()` 直接讀取。XML 解析失敗時 Driver 應回傳 `TIMEOUT`（保守處理），觸發後續 `CheckOrderId` 冪等確認。

### 2. `CreditBalanceDV` 的 `CurrencyType` 必填性存疑

文件列 `CurrencyType` 為必填，但 `LoginRequest` 階段已隱含帶幣別建立帳號。本專案 `transferToVendor()` 是否省略此欄位尚待確認（見 gaps §1）。

### 3. 兩套端點分工

| 用途 | Host |
|---|---|
| 通用 API（帳號、登入、轉帳等） | `example.invalid`（測試） |
| 下注詳情查詢 | `example.invalid`（測試） |

正式環境 URL 須向 SA Gaming 申請，實作從 `vendor_infos.info.API_URL` 讀取（見 gaps §2）。

### 4. 轉帳三態對應（XML 解析失敗 = TIMEOUT）

| 條件 | `VendorTransferResultEnum` |
|---|---|
| `ErrorMsgId=0` | `SUCCESS` |
| XML 解析失敗 / 非預期格式 | `TIMEOUT`（觸發後續 CheckOrderId） |
| `ErrorMsgId` 非 0 | `FAILED` |
| CheckOrderId 回傳 `isExist=true` | `SUCCESS` |
| CheckOrderId 回傳 `isExist=false` | `FAILED` |
| CheckOrderId 其他異常 | `TIMEOUT` |

> 特別注意：`isExist` 在 XML 中為**字串** `"true"` / `"false"`，非布林值。

## OrderId 命名規則（陷阱）

| 方向 | 格式 | 範例 |
|---|---|---|
| 存入（Vendor） | `IN` + `yyyyMMddHHmmss` + `Username` | `IN20240101123456testuser001` |
| 提出（Local） | `OUT` + `yyyyMMddHHmmss` + `Username` | `OUT20240101123456testuser001` |

- OrderId 格式錯誤回 Error 127；格式重複（冪等保護）回 Error 122。
- 實作於 `Driver::getTransactionNo()` 方法。

## 轉帳錢包流程

```
LoginRequest（自動建立帳號 + 取得 Token）
       │
       ▼
CreditBalanceDV（存入，direction = transferToVendor）
       │
       ▼
玩家在 SA Gaming 端下注（SA 端持有餘額）
       │
       ▼
DebitAllBalanceDV 或 DebitBalanceDV（提回本地，direction = transferToLocal）
       │
       ▼
  狀態不明 → CheckOrderId（冪等確認）
```

- `VerifyUsername` 可用於先確認帳號是否已建立（可選步驟）。
- `KickUser` 於玩家提款前強制登出，確保 SA 端無殘留 session。

## 注單對帳

- **查詢日界**：`GetAllBetDetailsDV` 日界非凌晨，而是**中午 12:00 到次日 11:59:59**，對帳時須注意。
- **查詢限流**：`GetAllBetDetailsDV` / `GetAllBetDetailsForTimeIntervalDV` 每 5 分鐘最多 10 次，超頻率會回 Error 112。
- **保留期限**：一般注單 31 天；取消注單 2 個月 + 當月；活動點數活動結束後 3 個月。
- `TransactionID` 欄位在 Transfer Wallet 模式固定為 `-1`（Seamless 才有意義）。
- 注單同步機制（是否有排程工作或純人工查詢）見 gaps §3。

## 整合落地建議

| SA Gaming 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Sa/Driver.php`（`AbstractTransferWallet`） |
| Account Model | `app/Vendors/Models/SaAccount.php` |
| BetRecord Model | `app/Vendors/Models/SaBetRecord.php` |
| Vendor ID Enum | `VendorIdEnum::SA = 37` |
| Seamless 回調 Controller | 無（Transfer Wallet 模式，不需實作） |

### vendor_infos.info 欄位

| 欄位 | 說明 |
|---|---|
| `SECRETKEY` | Secret Key（SA Gaming 提供，32 碼） |
| `MD5KEY` | MD5 簽章用 Key（SA Gaming 提供） |
| `ENCRYPTKEY` | DES-CBC 加密 Key（8 bytes，同時作為 IV） |
| `API_URL` | 通用 API 端點（測試 = `http://example.invalid/api/api.aspx`；正式另行提供） |
| `GAME_URL` | 遊戲大廳 URL（前端跳轉用） |
| `CURRENCY` | ISO 幣別代碼（如 `THB`） |
| `LOBBY` | 大廳代碼 |

### SaBetRecord 欄位對應

| DB 欄位 | SA Gaming API 欄位 | 說明 |
|---|---|---|
| `bet_id` | `BetId` | 注單唯一 ID |
| `username` | `Username` | 玩家帳號 |
| `game_code` | `GameType` | 遊戲類型代碼 |
| `bet_time` | `BetTime` | 下注確認時間（UTC） |
| `bet` | `BetAmount` | 下注金額 |
| `valid_bet` | `Rolling` | 有效注額 |
| `win_lose` | `ResultAmount` | 輸贏金額（負數=輸） |
| `info` | 完整 response XML/JSON | 原始紀錄 |

### IP 白名單

SA Gaming 測試 / 正式環境需將下列 IP 加入代理白名單：

```
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
```

## Seamless Wallet 備查（本站未接入）

SA Gaming 另支援 Seamless Wallet 模式（6 個回調端點：`GetUserBalance` / `PlaceBet` / `PlayerWin` / `PlayerLost` / `PlaceBetCancel` / `BalanceAdjustment`），回調同樣使用 DES-CBC 加密。本站接入為 Transfer Wallet，無需實作此部分。

## gaps / 待確認

1. **`CreditBalanceDV.CurrencyType` 必填性**：文件列必填，但帳號於 `LoginRequest` 已建立；本專案 `transferToVendor()` 是否省略此欄位需向 SA Gaming 確認。
2. **正式環境 API URL**：測試環境 URL 已知，正式 URL 需向 SA Gaming 申請後寫入 `vendor_infos.info.API_URL`。
3. **注單同步機制**：文件提供多個查詢 API，但未見定時同步排程實作，待確認是否有排程工作或純靠人工查詢。
4. **`TransactionID=-1` 的 BetRecord 儲存方式**：`example.invalid` 存完整 response，與文件行為一致，已確認。
5. **下注詳情端點 `API_URL`**：`GetAllBetDetailsDV` 等需走 `example.invalid` host，正式環境此 host 是否另存一個 info key，還是 Driver 內自行替換，需確認實作。
