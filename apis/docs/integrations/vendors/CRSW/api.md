# CRSW（CR 體育）— 廠商原始 API 規格

> 來源：
> - `CR 體育 API文件_v1.7.0.pdf`（Release: 2024/10/16，版本 1.7.0）
> - `CR體育 免轉錢包規格文件_v1.0.5.pdf`（Release: 2024/04/10，版本 1.0.5）
>
> 抓取日期：2026-06-03
>
> 我方實作分析見 [integration.md](integration.md)（差異、陷阱、落地建議）。

---

## 目錄

- [通用約定](#通用約定)
- [免轉錢包回調 API（GP → EC）](#免轉錢包回調-api-gp--ec)
  - [3.1 PlaceBet（下注）](#31-placebet下注)
  - [3.2 CancelBet（取消下注）](#32-cancelbet取消下注)
  - [3.3 RevertBet（還原下注）](#33-revertbet還原下注)
  - [3.4 SettleBet（結算）](#34-settlebet結算)
  - [3.5 UnsettleBet（重置結算）](#35-unsettlebet重置結算)
  - [3.6 ReserveBet（預約注單）](#36-reservebet預約注單)
  - [3.7 ReserveCancel（取消預約）](#37-reservecancel取消預約)
  - [GetPlaceBet（查詢注單金流）](#getplacebet查詢注單金流)
- [GP API 函數清單（EC → GP）](#gp-api-函數清單ec--gp)
  - [AGLogin](#aglogin)
  - [LaunchGame](#launchgame)
- [注單資料附錄（wagerdata 欄位）](#注單資料附錄wagerdata-欄位)
- [簽名](#簽名)
- [錯誤碼](#錯誤碼)
- [附錄](#附錄)

---

## 通用約定

### 術語對照

| 縮寫 | 說明 |
|---|---|
| EC | 接入方平台（即本平台） |
| GP | 遊戲提供者平台（CRSW） |
| token | EC 端對 GP 端 API 操作憑證，長度 char(32)，透過 AGLogin 取得，同一代理同一時間只有一個 |
| memtoken | 免轉錢包中，由 GP LaunchGame 回傳的雙方 API 操作憑證，長度 char(50) |
| memname | EC 端用戶 ID，長度上限 50，格式 `0-9 a-z A-Z _`，底線只能出現兩次且首尾須為字母 |
| password | 密碼長度 6-30，格式 `0-9 a-z A-Z`，勿用常見單字 |
| timestamp | UNIX Timestamp（毫秒），主機時間彈性容許 60 秒內 |
| remoteip | 請依照取時的真實 IP |
| gold | 金額類均取小數點後兩位無條件捨去 |
| AGID | 代理商 ID |

### 通訊協定

- **HTTP Method**：一律使用 `POST`
- **Content-Type**：`application/json;charset=utf-8`
- **時區**：遊戲所有時間均為**美東時間（GMT-4）**
- **JSON 格式限制**：不得含換行符號 `\n` 或空格；所有值一律使用 **String** 格式

### Base URL（GP API，EC 主動呼叫）

```
https://example.invalid/app/control_API/agents/api_doaction.php
```

> 此為文件公開範例，實際接入以 CRSW 提供的正式憑證為準，存於 `vendor_infos.info.apiUrl`。

### 請求結構（EC 呼叫 GP）

```json
{
  "Request": "<AES-128-ECB + Base64 加密的業務 JSON>",
  "Method": "<動作名稱>",
  "AGID": "<代理商ID>"
}
```

- `Method`、`AGID` 為明文必填；`Request` 欄位為 AES 加密後的業務參數（見〈[簽名](#簽名)〉節）

### 回應結構（GP 回應 EC）

```json
{
  "respcode": "0000",
  "status": "success",
  "method": "<動作名稱>",
  "timestamp": "...",
  "responeid": "..."
}
```

- `respcode` 為 `0000` 表示成功，其他見〈[錯誤碼](#錯誤碼)〉節

### 免轉錢包回調請求結構（GP 呼叫 EC）

```json
{
  "data": "<AES-128-ECB + Base64 加密的業務 JSON>",
  "Method": "<動作名稱>",
  "AGID": "<代理商 ID>",
  "responeid": "<請求識別碼>",
  "version": "0.01",
  "timestamp": "<UNIX 毫秒>"
}
```

- `data` 解密後包含：`remoteip`、`provider`、`wagerdata`（Array）、`version`、`timestamp`
- `wagerdata` 為陣列，單次請求可包含多筆注單

### 免轉錢包回調回應結構（EC 回應 GP）

成功：

```json
{
  "data": {
    "wagerdataresp": [
      {
        "status": "success",
        "wagerid": "132625",
        "respcode": "0000"
      }
    ]
  },
  "status": "success",
  "respcode": "0000",
  "Method": "PlaceBet",
  "timestamp": 1661491521
}
```

失敗（外層）：

```json
{
  "data": [],
  "status": "failed",
  "respcode": "9999",
  "message": "錯誤說明",
  "Method": "PlaceBet",
  "timestamp": 1661491521
}
```

**規則**：
- 外層 `respcode` 為整體請求是否成功（`0000` = 已收到並處理）
- `wagerdataresp` 每筆注單各自有 `respcode`，GP 會逐張檢查
- 若 EC 回應 `respcode:5102`（餘額不足），GP 標記該注單為扣款失敗
- 若 EC 回應 `respcode:0008`（動作處理中），GP 會再次重送

---

## 免轉錢包回調 API（GP → EC）

### 路由（EC 側）

| Path | Controller |
|---|---|
| `POST /api/sw/cr-sport` | `App\Http\Controllers\External\SingleWallets\CrSportSwController` |
| `POST /api/sw/cr` | `App\Http\Controllers\External\SingleWallets\CrSportSwController`（別名） |

> 定義於 `routes/external/sw.php` 第 302-303 行

---

### 3.1 PlaceBet（下注）

GP 通知 EC 玩家下注，EC 執行扣款並建立注單。

- **同張單號只會送一次**，逾時 120 秒
- 通知時可多張驗證（`wagerdata` 為陣列）
- 餘額不足須回應 `respcode:5102`

#### Request（`data` 解密後）

```json
{
  "remoteip": "0.0.0.0",
  "provider": "IBO",
  "wagerdata": [{
    "gtype": "FT",
    "gid": "34841",
    "wagerid": "132625",
    "reserveid": "1001779",
    "degold": "100",
    "degold_d": "100",
    "ip": "0.0.0.0",
    "wingold": "0",
    "wingold_d": "0",
    "orderdate": "2022-08-25",
    "token": "7cd93b81mb2l1697xw",
    "gold": "100",
    "gold_d": "100",
    "settle_date": "2022-08-26 03:14:44",
    "result": "0",
    "odds": "H",
    "vgold": "0",
    "resultdate": "0000-00-00 00:00:00",
    "machine": "PC",
    "resultdetail": "0",
    "adddate": "2022-08-26 01:25:20",
    "username": "mortyc137"
  }],
  "version": "0.01",
  "timestamp": "1661491521053"
}
```

`wagerdata` 欄位說明見〈[注單資料附錄](#注單資料附錄wagerdata-欄位)〉節。

#### Response

```json
{
  "data": {
    "wagerdataresp": [
      {"status": "success", "wagerid": "132625", "respcode": "0000"},
      {"status": "success", "wagerid": "132626", "respcode": "0000"}
    ]
  },
  "Method": "PlaceBet",
  "respcode": "0000",
  "status": "success",
  "timestamp": 1661491521
}
```

餘額不足時，對應注單回傳：
```json
{"status": "success", "wagerid": "132627", "respcode": "5102"}
```

---

### 3.2 CancelBet（取消下注）

GP 通知 EC 取消已下注單（PlaceBet 異常時送出），EC 退還扣款。

- 不管該單是否在 EC 方成立，均須正確回應

#### Request（`data` 解密後，`wagerdata` 欄位同 PlaceBet）

重點欄位：`result` 為 `"D"`（取消）、`resultdetail` 為取消原因代碼（如 `DR`）

#### Response

```json
{
  "data": {"wagerdataresp": [{"status": "success", "wagerid": "132688", "respcode": "0000"}]},
  "Method": "CancelBet",
  "respcode": "0000",
  "status": "success",
  "timestamp": 1661494385
}
```

---

### 3.3 RevertBet（還原下注）

取消後若需還原（重新扣款），GP 送 RevertBet。EC 需重新扣款並更新注單狀態。

- 還原需確認餘額，不足回應 `5102`
- `wagerdata` 欄位同 CancelBet；`result` 可能為非 `"D"` 值

#### Response

結構同 CancelBet Response。

---

### 3.4 SettleBet（結算）

GP 設定賽事結果後通知 EC 結算注單，EC 根據 `wingold` 進行派彩。

- 結果異常時 GP 會反覆重送直到 EC 回應成功或失敗

#### Request（`wagerdata` 重點欄位）

| 欄位 | 說明 |
|---|---|
| `wingold` | 輸贏金額含本金（贏為正，輸為 `"0"`） |
| `result` | `"W"` 贏 / `"L"` 輸 / `"P"` 和 / `"D"` 取消 |
| `resultdetail` | 詳細結果代碼（見〈[附錄：resultdetail](#resultdetail附錄-53)〉） |
| `resultdate` | 算結果日期時間（美東時區） |
| `settle_date` | 注單異動日期時間（美東時區） |

#### Response

結構同 PlaceBet Response，`Method` 為 `SettleBet`。

---

### 3.5 UnsettleBet（重置結算）

GP 對已結算注單重新設定結果（如賽事改判），EC 需依新數據重新計算並更新餘額。

- EC 必須先找出已結算注單（`status >= 4`），計算新舊 `wingold` 差額後調整餘額（非直接覆蓋）
- `be_wingold` 為前一次的輸贏金額，供差額計算使用

#### Response

結構同 SettleBet Response，`Method` 為 `UnsettleBet`。

---

### 3.6 ReserveBet（預約注單）

GP 通知 EC 預約注單（賽前投注預約）。

> **注意**：目前實作（`CrSportSwController.php`）的 `handlers` 陣列中**未包含** `ReserveBet`，若確認 CRSW 有使用此功能，需補實作（見 [integration.md](integration.md) Gaps）。

#### Request（`data` 解密後，`wagerdata` 重點欄位）

| 欄位 | 說明 |
|---|---|
| `reserveid` | 預約單號（唯一） |
| `token` | EC 提供的在線會員 token（必填，用於預約下注驗證） |

Response 中 `wagerdataresp` 回傳 `reserveid`（非 `wagerid`）。

---

### 3.7 ReserveCancel（取消預約）

GP 通知 EC 取消預約注單。

> **注意**：同 ReserveBet，目前實作**未包含** `ReserveCancel`（見 [integration.md](integration.md) Gaps）。

---

### GetPlaceBet（查詢注單金流）

EC 可主動查詢注單的金流異動歷程（以日期或單號搜尋）。

- **以日期搜尋**：不支援跨天查詢（`datestart` 與 `dateend` 需同一天）
- **以單號搜尋**：傳入 `wid`（wagerid）

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `datestart` / `dateend` | String | 搜尋日期時間（或改傳 `wid` 以單號查） |
| `token` | String | 代理登入授權碼 |
| `timestamp` | String | 主機時間（毫秒） |

Response 中 `history`（附錄 5.5）記錄每次金流異動（PlaceBet / CancelBet / RevertBet / SettleBet），含 `settle_date`、`method`、`wingold`、`be_wingold`、`failure`（扣款失敗代碼）。

---

## GP API 函數清單（EC → GP）

> EC 主動呼叫 GP 的函數清單（API v1.7.0）。免轉錢包主要使用 `AGLogin`、`CreateMember`、`LaunchGame`；對帳使用 `MemWager`、`ALLWager`、`CheckReport`。

| 方法名稱 | 說明 |
|---|---|
| `AGLogin` | 代理登入，取得 token（36 小時有效，同一代理共用同一 token） |
| `CreateMember` | 建立新會員 |
| `Deposit` | 存款（上分） |
| `Withdraw` | 提款（下分） |
| `MemLogin` | 會員登入（單取 memToken） |
| `LaunchGame` | 登入遊戲，取得遊戲連結（免轉錢包亦使用此方法） |
| `WagerUrl` | 會員今日/歷史注單頁面 URL |
| `chkMemberBalance` | 查詢會員目前餘額 |
| `ShowMember` | 單一會員資料 |
| `ShowAllMembers` | 查詢多筆會員資料（一次最多 30 位） |
| `MemWager` | 單一會員的注單資料（分頁，每頁最多 50 筆） |
| `ALLWager` | 全部會員的注單資料（分頁，每頁最多 50 筆） |
| `GetWager` | 單張注單資料（一次最多 50 筆） |
| `ChangeMemPWD` | 修改會員密碼 |
| `GetTransInfo` | 取得存提款記錄 |
| `ChkTransInfo` | 查詢存提款記錄 |
| `CheckReport` | 報表統計資料 |
| `GetCurrencyRate` | 取得匯率 |
| `GetScoll` | 取得跑馬燈 |
| `GetAllReservedOrder` | 取得區間預約注單資訊 |
| `GetReservedOrder` | 取得單張預約單資訊 |
| `GetMemReservedOrder` | 取得單一會員區間預約注單資訊 |

---

### AGLogin

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String(20) | ✅ | 代理商名稱 |
| `password` | String | ✅ | 代理商密碼 |
| `timestamp` | String | ✅ | 主機時間（毫秒） |

Response 新增欄位：`token`（char(32)，代理登入的授權碼，同一時間只有一個）

---

### LaunchGame

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `method` | String | ✅ | `LaunchGame` |
| `memname` | String(50) | ✅ | 會員名稱 |
| `password` | String | ✅ | 會員密碼 |
| `machine` | String | ✅ | `PC` 或 `MOBILE` |
| `langx` | String | ✅ | 語系（見〈[附錄：語系](#語系langx)〉） |
| `isSSL` | String(1) | ❌ | 是否使用 https（`Y`/`N`） |
| `token` | String | ✅ | 代理登入授權碼 |
| `currency` | String(4) | ✅ | 幣別（見〈[附錄：幣別](#幣別currency附錄-54)〉） |
| `remoteip` | String(15) | ✅ | 玩家真實 IP |
| `timestamp` | String | ✅ | 主機時間（毫秒） |

Response 包含：`launchgameurl`（進入遊戲的 URL）、`memToken`（會員憑證）

---

## 注單資料附錄（wagerdata 欄位）

完整欄位（附錄 5.2）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `wagerid` | String | 交易單序號（預約單不提供） |
| `reserveid` | String | 預約單序號 |
| `token` | String | EC 提供的在線會員 token（PlaceBet / ReserveBet 必填） |
| `username` | String | 交易單會員名稱 |
| `gtype` | String | 球類玩法（見〈[附錄：球類代碼](#球類gtype)〉） |
| `gid` | String | 場次流水編號 |
| `gold` | String | 下注金額（會員貨幣） |
| `gold_d` | String | 下注金額（站別貨幣） |
| `degold` | String | 實際扣除額（會員貨幣） |
| `degold_d` | String | 實際扣除額（站別貨幣） |
| `wingold` | String | 輸贏金額，含本金（會員貨幣）；贏為正，輸為 `"0"` |
| `wingold_d` | String | 輸贏金額，含本金（站別貨幣） |
| `be_wingold` | String | 前一次輸贏金額，含本金（會員貨幣；CancelBet / RevertBet / SettleBet / UnsettleBet 提供） |
| `vgold` | String | 有效金額（站別貨幣） |
| `odds` | String | 盤口類型（H=香港, M=馬來, I=印尼, E=歐洲） |
| `result` | String | 結果（`0`未結果 / `L`輸 / `W`贏 / `P`和 / `D`取消） |
| `resultdetail` | String | 結果詳細（見〈[附錄：resultdetail](#resultdetail附錄-53)〉） |
| `adddate` | String | 交易單日期時間（`yyyy-MM-DD hh:mm:ss`，美東時區） |
| `resultdate` | String | 算結果日期時間（美東時區） |
| `settle_date` | String | 注單異動日期時間（美東時區） |
| `orderdate` | String | 歸帳日期 |
| `machine` | String(6) | `PC` 或 `MOBILE` |
| `ip` | String | 玩家真實 IP |

---

## 簽名

CRSW 所有 API 的業務參數均使用 **AES-128-ECB with PKCS5PADDING** 加密後 **Base64** 編碼傳輸。

### 演算法

- **演算法**：AES / ECB / PKCS5PADDING
- **Key 長度**：128-bit（16 位元組，對應 `cryptKey`）
- **Padding**：PKCS5（PHP `openssl` 的 `OPENSSL_RAW_DATA` 模式自動處理）
- **編碼**：Base64
- **IV**：ECB 模式不使用 IV

### 金鑰來源

| 欄位 | 說明 | 儲存位置 |
|---|---|---|
| `cryptKey` | AES 加解密共用金鑰，16 字元，由 CRSW 提供 | `vendor_infos.info.cryptKey` |

### EC 呼叫 GP 加密流程

```
業務參數（明文 JSON）
   → AES-128-ECB + PKCS5PADDING 加密（Raw Data）
   → Base64 編碼
   → 放入請求的 "Request" 欄位傳出
```

Response 回傳亦為 AES 加密後的 Base64 字串，需先 Base64 解碼再 AES 解密。

### GP 呼叫 EC 解密流程

```
請求 body 的 "data" 欄位（Base64 字串）
   → Base64 解碼
   → AES-128-ECB 解密（Raw Data）
   → JSON 解碼得到業務 JSON
```

### PHP 實作

```php
// 加密（EC 呼叫 GP 時用）
// app/Vendors/CRSW/Driver.php encrypt()
function encrypt(string $string, string $key): string
{
    $data = openssl_encrypt($string, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
    return base64_encode($data);
}

// 解密（EC 呼叫 GP 取得 Response 時用）
// app/Vendors/CRSW/Driver.php decrypt()
function decrypt(string $string, string $key): string
{
    $string = base64_decode($string);
    return openssl_decrypt($string, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
}

// 解密 GP → EC 回調（免轉錢包 callback）
// app/Http/Controllers/External/SingleWallets/CrSportSwController.php decryptData()
function decryptData(string $string, string $cryptKey): array
{
    $string = base64_decode($string);
    $decrypted = openssl_decrypt($string, 'AES-128-ECB', $cryptKey, OPENSSL_RAW_DATA);
    return json_decode($decrypted, true);
}
```

### 加密範例（文件 §2.2）

**secretKey**：`5rx9ibpyqdn6xmt3`

**明文 JSON**：
```json
{"password":"cit001123","remoteip":"0.0.0.0","method":"AGLogin","username":"cit001","timestamp":1497866722526}
```

**加密後（Base64）**：
```
eQ0jla45W6zEMGoJXV4LI5GyGyRSFyq52jGS31B5FWJUOAA53cGC3Twh/3sF2m+fl3o36D54IoSYstWiNihEA06Au5QtgfcmDza2UkzD93b66htosQU6f+WHdMT9ABZHhE6oK8mfeGPoN9ZsUCSoaYbLIjZWGR2+dlXVjOxZ1y0=
```

### 安全說明

- **IP 白名單**：EC 須提供可存取 GP API 的 IP 名單給 CRSW；callback 端口同樣以 IP 白名單（`Common::verifyIPWhitelistByVendorId`）驗證 GP 來源 IP
- **Timestamp 驗證（GP 檢查 EC 回應）**：差距超過 3 秒回應 `respcode:9998`（通訊逾時）
- **Timestamp 驗證（EC 送 GP 請求）**：容許 60 秒內

---

## 錯誤碼

### respcode（代理 API）

| 代碼 | 說明 |
|---|---|
| `0000` | 成功 |
| `0001` | 無效的參數（可能超出範圍或不可接受） |
| `0002` | 無效 token（可能無效或已過期） |
| `0003` | 無效的注單 id |
| `0004` | 無效的代理 id |
| `0005` | 無效的會員 id |
| `0006` | 會員被暫停（無法下注） |
| `0007` | 餘額不足（無法下注） |
| `0008` | 注單處理中 |
| `0009` | 單號重複（未有結果） |
| `0010` | 加解密失敗 |
| `0011` | 單號重複（已有結果） |
| `0012` | 更新金額錯誤 |
| `0013` | 收到已有結果注單資料異常 |
| `0014` | 查無已有結果注單 |
| `0015` | 會員人數限制 |
| `0016` | 維護中 |
| `0017` | 系統流量較高，請重新嘗試 |
| `0018` | 新增會員幣別錯誤 |
| `0019` | 訊息 |
| `0103` | 金額達到 8 位數限制 |
| `0201` | 無效的帳號或密碼 |
| `0202` | 帳號被上鎖 |
| `0203` | 密碼錯誤多次 |
| `0204` | 您的帳戶已關閉 |
| `0205` | 訊息 |
| `0206` | 訊息 |
| `9998` | 通訊逾時，timestamp 超過 60 秒 |
| `9999` | 系統異常 |

### respcode（免轉錢包 callback，附錄 5.1）

| 代碼 | 說明 |
|---|---|
| `0000` | 成功 |
| `0001` | 無效的參數 |
| `0002` | 無效 token |
| `0003` | 無效的注單 id |
| `0004` | 無效的代理 id |
| `0005` | 無效的會員 id |
| `0006` | 會員被暫停（無法下注） |
| `0007` | 餘額不足（無法下注） |
| `0008` | 注單處理中 |
| `0009` | 單號重複（未有結果） |
| `0010` | 加解密失敗 |
| `0011` | 單號重複（已有結果） |
| `0012` | 更新金額錯誤 |
| `0013` | 收到已有結果注單資料異常 |
| `0014` | 查無已有結果注單 |
| `0016` | 維護中 |
| `0017` | Database 異常 |
| `5102` | EC 方回應餘額不足 |
| `9998` | 通訊逾時，timestamp 超過 3 秒 |
| `9999` | 系統異常 |

### 扣款失敗代碼（附錄 5.6）

| 代碼 | 說明 |
|---|---|
| （空值） | 無錯誤訊息 |
| `DZR001` | 注單未回應 |
| `DZR002` | 通訊逾時 |
| `DZR003` | 資料格式錯誤 |
| `DZR004` | 注單回覆失敗（含餘額不足） |

---

## 附錄

### 球類（gtype）

| 代碼 | 說明 |
|---|---|
| FT | 足球 |
| BK | 籃球/美足 |
| TN | 網球 |
| BS | 棒球 |
| OP | 其他 |
| VF | 虛擬足球 |
| SK | 台球 |
| MT | 跨球類過關 |
| FS | 冠軍 |
| TT | 乒乓球（v1.7.0 新增） |
| VB | 排球 |

### 結果（result）

| 代碼 | 說明 |
|---|---|
| `0` | 未有結果 |
| `L` | 輸 |
| `W` | 贏 |
| `P` | 和 |
| `D` | 取消 |

### resultdetail（附錄 5.3）

| 代碼 | 說明 |
|---|---|
| W | 贏 |
| LW | 半贏 |
| LL | 半輸 |
| L | 輸 |
| D | 取消 |
| D0 | 注單自動作廢 |
| D1 | 賽事取消 |
| D2 | 盤口/賠率錯誤 |
| D3 | 賽果錯誤 |
| D4 | 非正常投注 |
| D5 | 賽事提前 |
| D6 | 進球取消 |
| D7 | 紅卡取消 |
| D8 | 進球取消 |
| D9 | 取消 |
| DA | 刪單代碼: A |
| DB | 進球不予計算 |
| DC | 隊名錯誤 |
| DD | 賽事延賽 |
| DE | 賽事時間不正規 |
| DF | 賽事腰斬 |
| DG | 球員棄權 |
| DH | 主客場錯誤 |
| DI | 聯賽名稱錯誤 |
| DJ | 賽事無 PK/加時 |
| DK | 賽程錯誤 |
| DL | 不顯示賽程 |
| DM | 取消 |
| DN | 賽事腰斬 |
| DO | 沒有角球 |
| DP | 沒有罰牌 |
| DQ | 沒有點球 |
| DR | 投注失敗 |
| DS | 無局數 |
| DS_T | 無局數 |
| DT | 賽事取消 |
| DU | 裁判視頻判決 |
| DV | 刪單代碼 V |
| DW | 球員沒參與 |
| DX | 刪單代碼 X |
| DY | 刪單代碼 Y |
| DZ | 刪單代碼 Z |
| DZR | 存款不足 |
| P | 和局 |
| DN_T | 取消原因（v1.6.2 新增） |
| DZZ | 取消原因（v1.6.2 新增） |

### 語系（langx）

| 代碼 | 說明 |
|---|---|
| zh-tw | 繁體中文 |
| zh-cn | 簡體中文 |
| en-us | 英文 |
| ko-kr | 韓文 |
| ja-jp | 日文 |
| th-th | 泰文 |
| id-id | 印尼文 |
| vi-vn | 越南文 |
| my-mm | 緬甸文 |
| es-es | 西班牙文 |
| tr-tr | 土耳其文 |
| pt-pt | 葡萄牙文 |
| km-kh | 高棉寨文 |
| my-my | 緬甸文 |

### 幣別（currency，附錄 5.4）

| 代碼 | 說明 |
|---|---|
| RMB / CNY | 人民幣 |
| HKD | 港幣 |
| USD | 美金 |
| MYR | 馬幣 |
| SGD | 新幣 |
| THB | 泰銖 |
| GBP | 英鎊 |
| JPY | 日幣 |
| EUR | 歐元 |
| IND | 印尼盾（比值 1:1000） |
| KRW | 韓元 |
| VND | 越南盾（比值 1:1000） |
| INR | 印度盧比 |
| USDT | 泰達幣 |
| PHP | 披索 |
| BRL | 巴西雷亞爾 |
| MXN | 墨西哥披索 |
| TRY | 土耳其里拉 |
