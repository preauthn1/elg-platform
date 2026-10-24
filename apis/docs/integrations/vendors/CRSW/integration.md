# CRSW（CR 體育）（vendor_id=121）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | 待確認（文件未直接標示，需查 `VendorIdEnum` 或 `config/vendor.php`） |
| 錢包模式 | Single Wallet（免轉錢包 / Seamless Wallet） |
| 繼承類別 | `AbstractSingleWallet` |
| 術語 | EC = 本平台（接入方）；GP = CRSW（遊戲商） |
| GP → EC 回調格式 | `POST` JSON；`data` 欄位為 AES-128-ECB + Base64 加密的業務 JSON |
| EC → GP 請求格式 | `POST` JSON；`Request` 欄位為 AES-128-ECB + Base64 加密的業務 JSON |
| 成功判定（回調）| 外層 `respcode === "0000"` |
| 時區 | **美東時間（GMT-4）**，所有 `adddate` / `resultdate` / `settle_date` 均為此時區 |
| Timestamp 驗證 | GP → EC 回調：3 秒容差（超過回 `respcode:9998`）；EC → GP 請求：60 秒容差 |
| IP 白名單 | 必須（EC 提供可存取 GP API 的 IP 給 CRSW；callback 端以 `Common::verifyIPWhitelistByVendorId` 驗） |
| 加密金鑰 | `vendor_infos.info.cryptKey`（16 字元，AES-128-ECB） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/CrSportSwController.php` |
| Callback 路由 | `routes/external/sw.php`（`/api/sw/cr-sport` 及 `/api/sw/cr`） |

---

## 高風險陷阱

### 1. `PlaceBet` 是一次性回調，120 秒逾時，不重送

文件明確標注 **「同張單號只會送一次」**，逾時 120 秒，EC 未回應 GP 會送 `CancelBet` 取消而非重送 `PlaceBet`。

> 風險：EC 若對 `PlaceBet` 進行任何 retry 邏輯，可能重複扣款。必須在 `PlaceBet` 首次收到時立即處理，不依賴重試。

### 2. 業務欄位藏在 AES 加密的 `data` 欄位裡

GP → EC 的請求 body 外層只有 `data`（密文字串）、`Method`、`AGID`、`responeid`、`version`、`timestamp`；**所有業務欄位**（`wagerdata`、`username`、金額等）均在 `data` 解密後取得。若直接讀 request body 取業務欄位必定為空。

```php
// 對應 CrSportSwController::decryptData()
$decrypted = openssl_decrypt(base64_decode($request->data), 'AES-128-ECB', $cryptKey, OPENSSL_RAW_DATA);
$payload = json_decode($decrypted, true);
```

### 3. JSON 不可含換行或空格，所有值為字串型別

文件要求：JSON 字串**不得含 `\n` 或空格**；所有 value 一律以 **String** 格式傳輸（包含數字金額，如 `"100"` 非 `100`）。若金額以 `int`/`float` 傳入或 JSON 帶縮排，可能導致解密後解析失敗或驗簽不符。

### 4. Timestamp 3 秒容差，伺服器時鐘必須同步

GP 收到 EC 回應時驗 timestamp，差距 > 3 秒回 `respcode:9998`（通訊逾時）。EC callback 端回傳的 `timestamp` 必須是**當下的 UNIX 秒**，伺服器若時鐘偏差超過 3 秒，所有回調都會被拒。

### 5. UnsettleBet 需差額調整，不是直接覆蓋

`UnsettleBet` 收到時，注單已有先前的結算結果（`wingold`）。EC 必須先取出已結算的 `wingold`（即 `be_wingold`），計算新舊差額後調整餘額，**不是重新用新 `wingold` 直接覆蓋**。

### 6. RevertBet 是「取消取消」，需重新扣款

狀態機路徑：`PlaceBet(1)` → `CancelBet(2)` → `RevertBet(3)` 會還原回 `PlaceBet` 狀態，EC 需重新執行扣款。若餘額不足，同樣回 `respcode:5102`。

---

## BetRecord 狀態機

```
PlaceBet（status=1）
   │  正常路徑
   ▼
SettleBet（status=4，結算）
   │  改判路徑
   ▼
UnsettleBet（維持 status=4，差額調整）

PlaceBet（status=1）
   │  異常/取消路徑
   ▼
CancelBet（status=2，退款）
   │  還原路徑（重新下注）
   ▼
RevertBet（status=3，重新扣款，回到 PlaceBet 態）
   │
   ▼
SettleBet（status=4）
```

### DB status 對應（`crsw_bet_records.status`）

| status | 說明 |
|---|---|
| `1` | 下注（PlaceBet 成功） |
| `2` | 取消（CancelBet 成功） |
| `3` | 還原（RevertBet 成功，視同重新下注） |
| `4` | 結算（SettleBet / UnsettleBet 成功） |

### `result` 欄位對應（`crsw_bet_records.result`）

| GP 傳入 result | DB 儲存值 | 說明 |
|---|---|---|
| `"0"` | `0` | 未有結果 |
| `"L"` | `1` | 輸 |
| `"W"` | `2` | 贏 |
| `"P"` | `3` | 和 |
| `"D"` | `4` | 取消 |

---

## 冪等性

- **`wagerid`** 為主要冪等鍵（預約單使用 `reserveid`）
- `PlaceBet` 同一 `wagerid` 只送一次，EC 收到後必須立即處理；但若因網路問題 GP 重送（文件描述為「CancelBet 取消」），處理前需確認 `wagerid` 是否已存在
- `SettleBet` / `UnsettleBet` GP 會反覆重送直到 EC 回 `success` 或明確 `failed`；EC 須以 `wagerid` 去重，避免重複派彩或差額重算
- `respcode:0008`（注單處理中）會觸發 GP 重送，謹慎使用
- **`ReserveBet` / `ReserveCancel` 未實作**：現行 Controller `handlers` 陣列無此兩方法，若 GP 呼叫會無對應 handler，需確認後補實作

---

## 整合落地建議

### vendor_infos 設定對應

| CRSW 概念 | vendor_infos 欄位 | 說明 |
|---|---|---|
| AES 加解密金鑰 | `info.cryptKey` | 16 字元，AES-128-ECB 共用金鑰 |
| 代理商 ID | `info.agid` | 外層明文 `AGID` 欄位 |
| 代理帳號 | `info.username` | AGLogin 用 |
| 代理密碼 | `info.password` | AGLogin 用 |
| GP API URL | `info.apiUrl` | EC 呼叫 GP 的 Base URL |
| 代理 token | `info.token` | AGLogin 取得，36 小時有效，同一代理同時只有一個 |
| 機台類型 | `info.machine` | `PC` 或 `MOBILE`（預設 PC） |

### 路徑與模型

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/CRSW/Driver.php` |
| 玩家帳號模型 | `crsw_accounts`（`username` = EC 端 memname） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/CrSportSwController.php` |
| Callback 路由 | `routes/external/sw.php` 第 302-303 行（`/api/sw/cr-sport`、`/api/sw/cr`） |

### 回調安全驗證（Controller 實作）

1. IP 白名單：`Common::verifyIPWhitelistByVendorId`
2. AES 解密 `data` 欄位：`decryptData($request->data, $cryptKey)`
3. Timestamp 驗證：確保 `timestamp` 與伺服器時間差 ≤ 3 秒

---

## Gaps / 待確認

| 項目 | 狀態 | 說明 |
|---|---|---|
| Vendor ID | 待查 | 文件未直接標示 vid，需對照 `VendorIdEnum` 或 `config/vendor.php` |
| `CancelBet` 可取消的 status 範圍 | 待客服確認 | 實作限 `status < 4`，Controller 有 TODO：「也許應該只有 status == 1 可以取消」 |
| `RevertBet` 可還原的 status 範圍 | 待客服確認 | 實作限 `status < 4`，Controller 有 TODO：「也許應該只有 status == 2 可以還原」 |
| `SettleBet` result 限制 | 待確認 | 實作 `->whereIn('result', [1, 2, 3])` 限制，文件未提及 |
| `ReserveBet` / `ReserveCancel` | 未實作 | 文件 §3.6、§3.7 有定義，Controller `handlers` 未包含；需確認 GP 是否真的會呼叫 |
| 回調外層格式 `version` / `timestamp` 驗證 | 未實作 | 文件顯示這兩欄位，實作 `validateRequest` 未驗；需確認是否為較新規格 |
| `responeid` 欄位 | 待確認 | 文件未明述，實作有驗；待確認用途與必填規則 |
| 對帳資料保留期 | 未知 | GP 未說明；建議結算後 30 天內完成對帳 |
