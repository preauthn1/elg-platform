# Vpower（V-Power / Stargame）— API 規格

> 原始來源：`VPower-API_EN_20240223.docx`（Platform Integration Document V1.0.17）
> 抓取日期：2026-06-03
> 同資料夾另有 `VPower-API_EN_20220608.docx`、`VPower-API_EN.docx` 兩份舊版，本文件一律以 **20240223（V1.0.17）** 為準。

Vpower（品牌名 **Stargame**）為線上電子 / 捕魚 / 街機類遊戲商，本專案以 **Transfer Wallet（轉帳錢包）** 模式接入（`VendorIdEnum::VPOWER = 22`）。玩家餘額由 Vpower 端保管，進入遊戲前須先把本地錢包餘額透過 `creditxf` 轉入 Vpower，離場時再轉回本地。

> **注意**：本文件僅涵蓋 vid=22 轉帳錢包。本專案另有 `VpowerSW`（`VendorIdEnum::VPOWERSW = 117`）為獨立的單一錢包接入，有獨立的 Controller 與路由，不在本文件範圍內。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 **GET**（參數帶在 query string）。
- **Endpoint 形式**：`<API_URL>/open_api?Act=<act>&...&AppId=<appid>&Signature=<sig>`，以 `Act` 區分功能。
- **驗證方式**：**IP 白名單**（代理出口 IP 須先報給 Vpower 加白）＋ 每筆請求的 `Signature`（見[簽章](#簽章)）。
- **時間**：`Timestamp` 為 Unix timestamp（秒）。

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Act` | String | ✅ | 動作代碼，例如 `getacc`、`tkreq`、`creditxf` |
| `Timestamp` | Int | ✅ | 當前 Unix timestamp（秒） |
| `AppId` | String | ✅ | Vpower 分配的 App ID |
| `Signature` | String | ✅ | HMAC-SHA1 → Base64 → URL Encode（見[簽章](#簽章)） |

各 API 另有自身欄位（`Username`、`Token`、`Amount`、`RequestID` 等），詳見下方各章節。

### 通用回應

- 回應為 JSON，一律含 `ErrorCode` 欄位。
- `0` 表成功（部分建立類 API 以 `1 = New User created` 表成功，見各節）。
- 失敗時 `ErrorCode` 為非 0 的錯誤碼，完整碼表見[錯誤碼](#錯誤碼)。

### Base URL / 環境

| 項目 | 說明 |
|---|---|
| API URL | `<API Url domain>/open_api?Act=xxx`（由 Vpower 連同 API 憑證一併提供） |
| Game URL | `<Game Url domain>/open_game?...`（同上） |
| APP Server IP | 代理連線 Vpower API 的出口 IP，須交給 Vpower 加入白名單 |

> 文件未提供正式 Base URL（以 `<API_URL>` 佔位），實際值由 Vpower 客服提供並寫入 `vendor_infos.info`。

---

## Merchant API

### 玩家 Player

#### 1. Get User Account（`getacc`）

檢查玩家帳號是否存在，**不存在則自動建立**，建立時回傳加密後的密碼（建議保存，供日後改密或讓玩家手動登入 Stargame 大廳用）。

> **本專案未實作此 API**：Driver 假設帳號已存在，未呼叫 `getacc`（見 `integration.md` 陷阱 §7）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String | ✅ | 玩家帳號 |

**Success Response**：

```jsonc
{ "ErrorCode": "1", "Username": "player001", "Password": "M2Y2MUZzazhONU……" }
```

| ErrorCode | 意義 |
|---|---|
| 1 | 新玩家已建立（此 API 以 1 為成功） |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 12 | User already exist |
| 25 | Account Disabled |

> `Password` 為加密字串，加解密演算法見文件 §「User Password Encrypt & Decrypt PHP Example」（自訂 RC4-like + 雙層 base64），僅在需要讓玩家手動登入大廳時才需用到。

---

#### 2. Update or Reset Password（`updatepwd`）

變更玩家前台登入密碼。若平台不依賴玩家直接登入 Vpower 網站，可忽略此 API。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String | ✅ | 玩家帳號 |
| `Password` | String | ✅ | 加密後的舊密碼 |
| `NewPassword` | String | ✅ | 加密後的新密碼 |

**Success Response**：

```jsonc
{ "ErrorCode": "1" }
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User does not exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |

> ⚠️ 文件本節成功範例同時出現 `0`（欄位表）與 `1`（JSON 範例），以欄位表 `0=Success` 為準（待客服確認）。

---

#### 3. User Token Request（`tkreq`）

為玩家請求 Token，用於啟動遊戲與 deep linking。**每個 Token 有效 7 天**。**本專案 `Driver::getGameUrl()` 呼叫此 API。**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String | ✅ | 玩家帳號 |

**Success Response**：

```jsonc
{ "Username": "tester", "Token": "dfjapdisfjaijgdfkjapi", "ErrorCode": "0" }
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User does not exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |

---

#### 4. Check Token Validity（`tkchk`）

> **本專案未使用此 API。**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Token` | String | ✅ | 玩家 Token |

**Success Response**：`{ "ErrorCode": "0" }`

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 5 | Expired Token |

---

#### 5. Signing Out（`signout`）

強制玩家下線，主要用於提款前安全扣款（避免玩家正在遊戲中導致 `creditxf` 回 `21`）。

> **本專案未實作 `signout`**（見 `integration.md` 陷阱 §2）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String | ✅ | 玩家帳號 |

**Success Response**：`{ "ErrorCode": "0" }`

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User does not exist |
| 4 | Signature Error |
| 9 | Sign Out Failed |

---

### 錢包 Wallet

#### 6. Check User Credit Balance（`getbal`）

查詢玩家在 Vpower 端的餘額。對應 `Driver::getBalance()`（`Act=getbal`）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String | ✅ | 玩家帳號 |

**Success Response**：

```jsonc
{ "Username": "tester", "Credit": 123.50, "ErrorCode": "0" }
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User Does Not Exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |

> Driver 回傳 `(string) $json['Credit']`。

---

#### 7. Adding or Deducting Credit（`creditxf`）

加扣玩家額度。**正值=存入（`transferToVendor`）、負值=扣除（`transferToLocal`）**。

> ⚠️ 玩家**正在遊戲中時扣款會失敗（ErrorCode=21）**。官方建議先 `signout` 再扣。Driver 目前將所有非 0 ErrorCode（含 21）一律視為 `FAILED`（見 `integration.md` 陷阱 §1）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `RequestID` | String | ✅ | `Username + Timestamp(ms) + 4 位隨機數`；冪等鍵。本專案以 `transactionNo` 代替（見陷阱 §3） |
| `Username` | String | ✅ | 玩家帳號 |
| `Amount` | Number | ✅ | 轉帳金額，可為負值（扣款）；2 位小數 |

**Success Response**：

```jsonc
{
  "Username": "tester",
  "Credit": 183.50,
  "RequestID": "player0110001010010231234",
  "Time": "2019-11-20 20:01:00",
  "BeforeCredit": 243.50,
  "ErrorCode": "0"
}
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User Does not Exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 6 | RequestId Has Already Been Used（冪等：已處理過） |
| 7 | Invalid RequestId Format |
| 13 | Credit Transfer Failed |
| 14 | Insufficient User Balance |
| 21 | Player is in an active gaming session |

---

#### 8. Checking creditxf record（`CreditCheck`）

查詢 `creditxf` 轉帳紀錄。可用 `RequestID` 單筆查（其餘條件留空），或用 `Username + BeginTime + EndTime` 區間查。對應 `Driver::checkTransferStatus()`（`Act=creditcheck`）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `RequestID` | String | ✅（模式一） | 欲查詢的 RequestID |
| `Username` | String | ✅（模式二） | 玩家帳號 |
| `BeginTime` | String | ✅（模式二） | 格式 `2019-05-09 10:38:47` |
| `EndTime` | String | ✅（模式二） | 格式 `2019-05-10 10:38:47` |

> 模式一（按 RequestID）：`Username` / `BeginTime` / `EndTime` 留空字串。

**Success Response**：

```jsonc
{
  "ErrorCode": 0,
  "List": [
    {
      "account": "playerhz006",
      "request_id": "AcaDCPbXIlhmeedcdec1623052970",
      "start_score": "16.50",
      "score": "10",
      "time": "2021-06-07 16:02:58",
      "state": "1"
    }
  ],
  "Total": 1
}
```

| `List[].state` | 意義 | Driver 映射 |
|---|---|---|
| `0` | 未處理（untreated） | `TIMEOUT` |
| `1` | 成功（success） | `SUCCESS` |
| `2` | 失敗（fail） | `FAILED` |

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 15 | No Data Found |
| 16 | Exceed Max number of records |

> `List` 空回 `FAILED`，無 JSON 回應回 `TIMEOUT`。

---

### 報表 Report

#### 9. Find Active Player（`playedu`）

找出指定時間區間內有遊玩的活躍玩家。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Date` | String | ✅ | 日期 `2020-10-12` |
| `BeginTime` | String | 否 | 起始時間 `10:37:00`，可留空 |
| `EndTime` | String | 否 | 結束時間 `10:37:00`，可留空 |
| `PageIndex` | Int | ✅ | 頁碼 |
| `PageSize` | Int | ✅ | 每頁筆數 |

**Success Response**：

```jsonc
{ "ErrorCode": 0, "Total": 2, "Data": ["player001", "player002"] }
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User does not exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 16 | API Call Restricted |

> 限制：只給 `Date` 不限次；若帶 `BeginTime`/`EndTime` 則每 5 分鐘最多 10 次。

---

#### 10. Player Daily Summary（`dailysum`）

單一或全部玩家的每日彙總。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Date` | String | ✅ | `2020-10-12` |
| `Username` | String | 否 | 留空回傳全部玩家 |
| `PageIndex` | Int | ✅ | 頁碼 |
| `PageSize` | Int | ✅ | 每頁筆數 |

**Success Response**：

```jsonc
{
  "ErrorCode": 0,
  "Total": 1,
  "Data": [
    { "username": "player001", "total_pay": "0.01", "total_win": "0.02", "start_score": "10.00", "end_score": "10.01" }
  ]
}
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 2 | User does not exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 15 | No data found |

> `total_pay`=總下注、`total_win`=總派彩、`start_score`/`end_score`=期初/期末分數。

---

#### 11. Player Hourly Summary（`hoursum`）

整點彙總。傳 `SearchTime=2021-04-19 14:28:42` 會回傳該小時（14:00:00–14:59:59）全部玩家彙總。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `SearchTime` | String | ✅ | `2021-04-19 10:38:47` |

**Success Response**：

```jsonc
{ "ErrorCode": 0, "Data": { "record_count": 11, "total_pay": "5.500", "total_win": "3.000" } }
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 16 | Query Restricted |

---

#### 12. Five Minutes Gamelog Summary（`gloggrp`）

依 `SearchTime` 回傳該小時內 5 分鐘彙總。文件提醒：資料處理需時間，查詢前請預留 10 分鐘延遲。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `SearchTime` | String | ✅ | `2021-04-19 10:38:47` |
| `Username` | String | ✅ | 玩家帳號（可留空字串查全部） |
| `PageIndex` | Int | ✅ | 頁碼 |
| `PageSize` | Int | ✅ | 每頁筆數（預設 100） |

**Success Response**：

```jsonc
{
  "ErrorCode": 0,
  "Total": 1,
  "Data": [
    { "username": "test001", "record_count": 11, "total_pay": "5.500", "total_win": "3.000", "unique_id": "bc08a74b609e0bb5f22047d18a074b03" }
  ]
}
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 16 | Query Restricted |

---

#### 13. Retrieve User Game Log（`glog`）

取得 `BeginTime`–`EndTime` 區間的注單明細，分頁回傳。

> **限制**：每頁 200 筆（文件前後矛盾，另一處寫 100，以 200 為準待客服確認）；單次查詢上限 20,000 筆；時間範圍最長 7 天。
> 若查全部玩家（`Username` 留空）建議以「分鐘」為時間粒度；指定 `Username` 時建議同一天。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `GameId` | String | ✅ | 遊戲 ID（可留空查全部） |
| `Username` | String | ✅ | 玩家帳號（可留空查全部） |
| `BeginTime` | String | ✅ | `2020-09-11 10:37:00` |
| `EndTime` | String | ✅ | `2020-09-12 10:38:47` |
| `PageIndex` | String | ✅ | 頁碼 |
| `Lang` | String | 否 | 遊戲名語言：`zh` / `en`，可省略 |

**Success Response**：

```jsonc
{
  "Data": [
    {
      "uid": "玩家 ID",
      "username": "玩家帳號",
      "gameid": "遊戲 ID",
      "gameocode": "遊戲 ID（Game List）",
      "gamename": "Ocean Blue",
      "tableid": "桌台 ID",
      "pay": "下注分數",
      "win": "派彩分數",
      "uniqleid": "唯一流水 ID",
      "startscore": "下注前分數",
      "finalscore": "結算後分數",
      "time": "操作時間"
    }
  ],
  "Total": 3,
  "ErrorCode": "0"
}
```

| ErrorCode | 意義 |
|---|---|
| 0 | Success |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 8 | Exceed the maximum allowed limit |
| 15 | No data found |

---

### 遊戲 Game

#### 14. Retrieve Game List（`gamelist`）

取得遊戲清單，用於建立遊戲選單。文件建議快取並定期刷新。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Bonus` | Int | 否 | 是否回傳各遊戲 bonus 值，`1` / `0` |

**Response — `GameList[]` 欄位**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `GameType` | String | 遊戲類型（Slot / Fish 等） |
| `GameID` | String | 遊戲唯一 ID |
| `GameName` | String | 遊戲名稱 |
| `GameName_EN` | String | 英文遊戲名 |
| `isNew` | Int | 是否新上架 |
| `Bonus` | Number | 該遊戲關聯 bonus |
| `SupportedPlatForms` | String | 支援平台，如 `Desktop,Mobile` |
| `Special` | String | 標籤，如 `new,hot` |
| `DefaultWidth` / `DefaultHeight` | Int | 預設遊戲寬高 |
| `Image1` | String | 遊戲圖片路徑 |

**Success Response**：

```jsonc
{
  "ErrorCode": "0",
  "GameList": [
    {
      "GameType": "Slot",
      "GameID": "7ufj5fcktqre1",
      "GameName": "Golden Shark",
      "GameName_EN": "Golden Shark",
      "isNew": "1",
      "Bonus": "1234.50",
      "SupportedPlatForms": "Desktop,Mobile",
      "Special": "new,hot",
      "DefaultWidth": 960,
      "DefaultHeight": 630,
      "Image1": "//example.invalid/.../7ufj5fcktqre1.png"
    }
  ]
}
```

| ErrorCode | 意義 |
|---|---|
| 0 | Retrieve Successful |
| 3 | Parameter Error |
| 4 | Signature Error |
| 19 | Failed to retrieve bonus info |

> 文件附錄另有 `gameid & Game Names` 簡中對照表，但明示「永遠以 `gamelist` API 即時結果為準」，此處不複製整表。

---

#### 15. Launching Game / Deep Linking

##### 瀏覽器啟動（Launching Game）

```
<Game URL>/open_game?id=<gameid>&token=[REDACTED] exitUrl>&lang=<lang>
```

| 參數 | 說明 |
|---|---|
| `id` | 遊戲 ID（見 `gamelist`） |
| `token` | 玩家 Token（見 `tkreq`） |
| `back` | 離場跳轉 URL，**必須 base64 編碼**，且須含 `http://` / `https://` |
| `lang` | 遊戲 UI 語言，預設 English；`en` / `cn` |

> 本專案 `getGameUrl()` 組出的 query 僅含 `id`/`token`/`back`（`back` 有 base64），**未帶 `lang`**（見 `integration.md` 陷阱 §6）。

##### Deep Linking（啟動 Stargame App）

```
vpower://token=[REDACTED]
```

典型步驟：`getacc` 建帳 → `tkreq` 取 token → `creditxf` 儲值 → 組 `vpower://token=[REDACTED] 連結。玩家點擊後啟動 Stargame App 自動登入大廳；若未安裝 App 須在同頁提供 App 下載連結。

> 本專案 `getGameUrl()` 即產生 `app_open_url = 'vpower://token=' . $Token`，iOS / Android 共用，並帶 `IOS_DOWNLOAD` / `ANDROID_DOWNLOAD` 下載連結。

---

## 簽章

### 演算法

| 項目 | 值 |
|---|---|
| 雜湊演算法 | HMAC-SHA1 |
| 金鑰 | `SecretKey`（`vendor_infos.info.API_KEY`） |
| 明文 | 由請求參數依**固定順序**串成的 query string（**不含** `Signature` 本身） |
| 輸出編碼 | Base64，再 URL Encode |

不同 `Act` 的簽章明文欄位不同，順序見各 API 章節的 "Original signature string"。

### 構成步驟

**Step 1**：依該 API 規定欄位順序串成 query string（不含 `Signature`）：

```
Act=getacc&Timestamp=1581609972&Username=abc12345&AppId=vpower01
```

**Step 2**：HMAC-SHA1（raw binary），金鑰為 SecretKey：

```php
hash_hmac("sha1", $signatureString, $secretKey, true);
// 第四參數 true = 回傳 raw binary（必須）
```

> ⚠️ **文件 PHP 範例 Bug**：V1.0.17 §Signature 的 PHP 範例把參數順序寫成 `hash_hmac(<string>, "sha1", <key>, true)`，與 PHP 實際函式簽名 `hash_hmac(algo, data, key, binary)` 不符（`algo` 應為第一參數）。**本專案實作已採正確順序**，但此錯誤可能誤導後續維護者。

**Step 3**：Base64 編碼：

```php
base64_encode($hmacRawBytes);
// 範例輸出：6a1eWgGvqhmcKBFqXrJ7Pn4fPwY=
```

**Step 4**：URL Encode 後帶入請求：

```php
rawurlencode($base64String);
// 範例輸出：6a1eWgGvqhmcKBFqXrJ7Pn4fPwY%3D
```

> 本專案 `Driver::sign()` 以 `http_build_query($data)` 串明文（順序由 `$data` 陣列鍵插入順序決定），`Signature` 由 Laravel HTTP client 送出時自動 URL Encode，等效達成 Step 4。

### 各 Act 簽章明文欄位順序（本專案實作）

| Act | 簽章明文欄位順序 |
|---|---|
| `tkreq`（getGameUrl） | `Act, Timestamp, Username, AppId` |
| `getbal`（getBalance） | `Act, Timestamp, Username, AppId` |
| `creditxf`（transfer） | `Act, RequestID, Timestamp, Username, AppId, Amount` |
| `creditcheck`（checkTransferStatus） | `Act, RequestID, Timestamp, AppId` |

> ⚠️ `creditcheck` 本專案查單只用 `RequestID` 模式，明文不含空的 `Username`/`BeginTime`/`EndTime`，需確認 Vpower 端對「省略空欄位 vs 帶空值」的驗章是否一致（待 RD 確認）。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| `ErrorCode=4 Invalid Signature` | SecretKey 錯、明文欄位順序與 Vpower 期望不一致、或 `hash_hmac(..., true)` 漏了 raw 參數變成 hex |
| 偶發 `4`，特定參數值才發生 | 含特殊字元的值在串明文 vs 實際送出時 encode 不一致 |

---

## 錯誤碼

> 來源：V1.0.17 Appendix「ErrorCode & Meaning」。

| Code | 意義 |
|---|---|
| 0 | Success |
| 1 | New User is created（建立帳號成功） |
| 2 | User does not exist |
| 3 | Parameter Error |
| 4 | Invalid Signature |
| 5 | Token Expired |
| 6 | RequestId Has Already Been Used（同一 RequestID 已使用） |
| 7 | RequestId Format Error |
| 8 | Exceeded the Maximum Limit |
| 9 | Signing Out Failed |
| 10 | Invalid Request |
| 11 | Unknown Database Error |
| 12 | User Already Exist |
| 13 | Topup fail（轉帳失敗） |
| 14 | Insufficient credit（餘額不足） |
| 15 | No data found |
| 16 | Query is rate limited / Throttled（查詢被限流） |
| 17 | System is under maintenance |
| 18 | AppID doesn't exist |
| 19 | Error retrieving Bonus figure |
| 20 | Error retrieving player balance |
| 21 | Player in an active gaming session（玩家遊戲中，無法扣款） |
| 25 | Player account is deactivated |
