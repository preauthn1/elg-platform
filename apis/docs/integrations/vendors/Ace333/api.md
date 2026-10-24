# ACE333 — 廠商 API 規格

> **原始來源**
> - `001_ACE333 API Specifications 2.39.01.pdf`（version 2.39，2024-12-16）
> - `003_ACE333 HTML5 API Specifications 1.11.01.pdf`（version 1.11，2024-08-01）
> - 補充：`info.txt`、`info_vworld.txt`、`注意事項.txt`、`測試方式.txt`
>
> 抓取日期：2026-06-03
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得）**。

ACE333 為線上博弈遊戲商（slot / arcade / table / fishing / multiplayer 等），本專案 vid 6（`VendorIdEnum::ACE333`）採 **Transfer Wallet（轉帳錢包）** 接入。

---

## 通用約定

### Integration / Transfer API

- **HTTP Method**：依 API 而定（POST 或 GET）
- **Content-Type**：`application/json`（POST 時）
- **驗證**：HTTP Header `token` = API Service Token
- **大小寫**：URL 與參數名不敏感；**參數值大小寫敏感**
- **時間**：所有 DateTime 一律 **UTC**
- **資料保留期**：Integration API 資料 **14 天** 後自動清除

### H5 遊戲啟動

- 驗證：`q`（DES+Base64+urlencode）+ `s`（MD5）+ `accessToken`
- 時間格式：UTC `yyyyMMddHHmmss`
- Login 的 accessToken **不需** URL encode；GameList 的 accessToken **需** URL encode

### Base URL

| 類型 | URL |
|---|---|
| Integration API（Operations） | `https://example.invalid:2096/`（正式，`API_URL`） |
| Integration API（Data Feeds） | `API_DATAFEED_BASE_URL`（v2.39 新增，由 ACE333 客服提供） |
| H5 Login | `https://example.invalid/api/Acc/Login`（`H5_TOKEN_URL`） |
| H5 Game | `https://example.invalid/v3/h5games/`（`H5_GAME_URL`） |
| Staging（Operations） | `https://example.invalid/` |
| Staging（Data Feeds） | `https://example.invalid/` |

---

## 簽章（H5 遊戲啟動）

> 僅適用 H5 遊戲啟動層（Login / GameList）。Integration API（TopUp / Withdraw / CheckOrder 等）使用 Header `token`，明文 JSON Body，**不使用** `q`/`s` 加密。

| 項目 | 值 |
|---|---|
| 對稱演算法 | DES（CBC）；C# 預設 CBC + PKCS7 |
| 加密金鑰 | `H5_ENCRYPTKEY`（key 同時兼 IV） |
| 簽章演算法 | MD5（十六進位小寫） |
| 簽章明文 | `QS + MD5Key + time + SecretKey` |
| 加密輸出 | Base64 + URL encode |
| Delimiter | `♂♫‼◄¶`（`H5_DEKIMITER`，特殊字元，原樣使用，不可改寫） |

### 構成步驟

1. 組 Query String（QS）：`key={secretKey}{delimiter}time={currTime}{delimiter}userName=...{delimiter}...`
2. DES-CBC 加密 QS → Base64 → `urlencode` → 得 `q`
3. 簽章：`s = MD5(QS + MD5Key + time + SecretKey)`，十六進位小寫
4. POST `H5_TOKEN_URL/api/Acc/Login` 帶 `{ q, s, accessToken: [REDACTED] }`
5. 回應 `status == 1` 取得 `actk`

### PHP 實作對照

```php
// DES-CBC，key 同時兼 IV，Base64 輸出
$encryptedQuery = openssl_encrypt($queryString, 'DES-CBC', $encryptKey, 0, $encryptKey);
$encodedQuery   = urlencode($encryptedQuery);
// %XX 轉小寫對齊 PROVIDER 端 URL decode 行為
$encodedQuery   = preg_replace_callback('/%[0-9A-F]{2}/', fn ($m) => strtolower($m[0]), $encodedQuery);
// 簽章
$signature = md5($queryString . $md5EncryptKey . $data['time'] . $secretKey);
```

---

## merchant API

### 1. Authenticate（OPERATOR 實作供 PROVIDER 呼叫）

> Transfer Wallet OPERATOR 需自行實作此 API，ACE333 在玩家登入時回呼驗證帳密。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `api/authenticate`（OPERATOR 端，如 `https://example.invalid/ace333/api/authenticate`） |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header；OPERATOR 端驗證用 |
| `userName` | string(20) | ✅ | **accountID（不含 @postfixID）** |
| `password` | string(20) | ✅ | OPERATOR 系統的玩家密碼 |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `playerID` | int | PROVIDER 系統玩家唯一 ID |
| `error` | string(10) | `0` 成功；否則 OPERATOR Error Code |
| `description` | string(250) | 錯誤描述 |

### 2. CreatePlayer

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `api/createplayer` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `accountID` | string(20) | ✅ | OPERATOR 玩家帳號（英數底線，底線不可開頭） |
| `nickname` | string(50) | ✅ | 顯示名稱 |
| `currency` | string(3) | ✅ | 幣別代碼 |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `playerID` | int | PROVIDER 玩家唯一 ID；錯誤時 `null`/`0` |
| `error` | string(10) | `0` 成功；否則 PROVIDER Error Code |
| `description` | string(250) | 錯誤描述 |

### 3. TopUp（transferToVendor）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `api/topup` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | PROVIDER 玩家 ID |
| `referenceID` | string(100) | ✅ | OPERATOR 端交易唯一 ID |
| `topUpAmount` | decimal(15,2) | ✅ | 加值金額 |
| `currency` | string(3) | ✅ | 幣別 |
| `type` | int | ❌ | 餘額類型；不傳預設 Normal Credit（`4500`） |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `transactionID` | string(100) | PROVIDER 端交易唯一 ID |
| `balance` | decimal(15,2) | 加值後餘額 |
| `error` | string(10) | `0` 成功；否則 PROVIDER Error Code |
| `description` | string(250) | 錯誤描述 |

### 4. Withdraw（transferToLocal）

Driver 在提款前先呼叫 `api/logout`（分數可能卡遊戲內，fire-and-forget）。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `api/withdraw` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | PROVIDER 玩家 ID |
| `referenceID` | string(100) | ✅ | OPERATOR 端交易唯一 ID |
| `withdrawAmount` | decimal(15,2) | ✅ | 提款金額 |
| `currency` | string(3) | ✅ | 幣別 |
| `type` | int | ❌ | 餘額類型；預設 Normal Credit（`4500`） |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `transactionID` | string(100) | PROVIDER 端交易唯一 ID |
| `balance` | decimal(15,2) | 提款後餘額 |
| `error` | string(10) | `0` 成功；否則 PROVIDER Error Code |
| `description` | string(250) | 錯誤描述 |

### 5. GetBalance

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `api/getbalance` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `currency` | string(3) | ✅ | 幣別 |
| `playerID` | int | ✅ | PROVIDER 玩家 ID |
| `type` | int | ❌ | 餘額類型；預設 Normal Credit（`4500`） |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | decimal(15,2) | 玩家最新餘額 |
| `error` | string(10) | `0` 成功；否則 PROVIDER Error Code |
| `description` | string(250) | 錯誤描述 |

### 6. GetTimepoint

把 UTC DateTime 轉成 Timepoint（UTC Epoch 毫秒）。CheckOrder 查單需要 timepoint。

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `api/gettimepoint` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `datetime` | datetime | ✅ | 格式 `yyyy-MM-dd HH:mm:ss.fff` |

**Response**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Timepoint` | integer | UTC Epoch 毫秒，例 `1509667200000`（大寫 T） |

### 7. CheckOrder（checkTransferStatus）

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `api/checkorder` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `referenceId` | string(100) | ✅ | OPERATOR 端交易 ID |
| `currency` | string(3) | ✅ | 幣別 |
| `timepoint` | integer | ✅ | 由 gettimepoint 取得；不可早於 14 天前 |

**transStatus 狀態機**

| transStatus | 名稱 | 本專案判定 |
|---|---|---|
| `170` | Unconfirmed | `TIMEOUT` |
| `180` | Pending | `TIMEOUT` |
| `177` | Confirmed | `SUCCESS` |
| `178` | Cancelled | `FAILED` |
| `179` | Removed | `FAILED` |
| 其他 | — | `TIMEOUT` |

### 8. LogOut

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `api/logout` |

**Request**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | PROVIDER 玩家 ID |
| `currency` | string(3) | ✅ | 幣別 |

**Response**：`error`（`0` 成功）、`description`（可選）。

### 9. CheckPlayer（v2.38 新增）

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `api/checkplayer` |

**Request**：`token`（Header）、`userName`（`accountID@PostfixID`）。

**Response**：`playerID`、`error`、`description`。

### 10. AccountTransactions3（對帳資料流）

Transfer API 對帳主資料來源，官方 *recommended*。以 timepoint 拉取一段期間的注單（**至多 1 小時**）。

- **HTTP GET**，base 為 `API_DATAFEED_BASE_URL`（v2.39 新增）
- 呼叫頻率：**每 5 分鐘不超過 10 次**
- 回應為 CSV：Line 1 = 新 timepoint，Line 2 = CSV header，Line 3+ = 資料；timepoint 不可早於 14 天前

**CSV 欄位順序**（2026-08-27 實測 staging 標題行）：`playerID`、`extPlayerID`、`gameID`、`methodType`、`playSessionID`、`referenceID`、`status`、`created`、`updated`、`betAmount`、`winAmount`、`jackpotModule`、`jackpotContributionAmt`、`currency`、`resultUrl`、`roundDetails`、`platform`（共 17 欄，未見官方文件提到的 `betRefundID`）。

交易類型欄名為 `methodType`（與 CheckAccountTransaction3 一致），官方文件寫的 `transactionType` 與實際回應不符。

**`methodType` 可能值**：BQ/BR/RF/JW/FB/TU/WD，**無 BT/JP**——BQ=BetRequest 分注請求、BR=BetResult 一局結果單（betAmount 為同局 BQ 總注）、RF=Refund、JW=JackpotWin、FB=FundBetResult、TU=Top up、WD=Withdraw。

### 11. CheckAccountTransaction3（單筆查單）

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `api/checkaccounttransaction3` |

**Request**：`token`（Header）、`currency`、`referenceID`。

**Response 主要欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `playerID` / `extPlayerID` | int | PROVIDER / OPERATOR 玩家 ID |
| `gameID` | string(20) | 遊戲代碼 |
| `methodType` | string(2) | BQ/BR/RF/JW/FB/TU/WD |
| `playSessionID` | string(100) | 局唯一編號 |
| `referenceID` | string(100) | 交易參考 ID |
| `status` | string(1) | `C`=Completed / `I`=Incomplete |
| `created` / `updated` | datetime | UTC |
| `betAmount` / `winAmount` | decimal(15,2) | 下注 / 派彩 |
| `jackpotModule` / `jackpotContributionAmt` | int / decimal(22,6) | JP 相關 |
| `currency` | string(3) | 幣別 |
| `resultUrl` | string(500) | 注單結果頁 URL |
| `roundDetails` | string(50) | 局資訊 |
| `platform` | string(10) | MOBILE / WEB / DOWNLOAD |

---

## 支援幣別

MYR, VND, CNY, THB, IDR, SGD, HKD, USD, BDT, MMK, KHR, AUD（全部 1:1；IDR 若 OPERATOR 以 1 代表 1000，須參照 Appendix A multiplier）。

---

## 錯誤碼

### PROVIDER Error Codes（ACE333 → OPERATOR）

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `1` | 認證失敗（帳密錯） |
| `2` | 必填欄位為空 |
| `3` | 遊戲不支援 |
| `4` | 無效玩家帳號 |
| `5` | TopUp 不允許 |
| `6` | Withdraw 不允許 |
| `7` | 長度超過上限 |
| `8` | 幣別錯誤或不支援 |
| `12` | 該幣別不允許 topup |
| `13` | 請求格式錯誤 |
| `14` | 玩家帳號已註冊 |
| `15` | accountID 不可空 |
| `16` | currency 不可空 |
| `17` | nickname 不可空 |
| `18` | 玩家註冊失敗 |
| `19` | PROVIDER API access token 已停用 |
| `20` | 找不到玩家帳號資訊 |
| `21` | 找不到玩家帳號 |
| `1000` | PROVIDER 內部錯誤 |

### OPERATOR Error Codes（OPERATOR → ACE333，主要用於 authenticate）

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `1` | 餘額不足（Bet 用） |
| `2` | 找不到玩家帳號 |
| `3` | 不允許下注 |
| `4` | 認證失敗 / token 無效或過期 |
| `6` | 玩家被停權 |
| `8` | 遊戲不存在或停用 |
| `9` | 交易被拒（v2.39 新增） |
| `100` | OPERATOR 內部錯誤 |

### Game Client Error Codes

`0001`/`0002` 連線失敗、`991` API 回應不合規、`996` OPERATOR 逾時、`999` 未知錯誤。

---

## 官方 Change Log（節錄）

| 日期 | 版本 | 變更 |
|---|---|---|
| 2024-08-08 | 2.38 | 新增 `api/checkplayer`；新增 PROVIDER error codes；新增 Appendix D 幣別 |
| 2024-12-16 | 2.39 | 新增 `API_DATAFEED_BASE_URL`；新增 operator error code 9；refund 新增 `betRefundID` |
| 2024-08-01 | H5 1.11 | 更新語言代碼；重組文件結構 |
