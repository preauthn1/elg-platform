# Mt（美天棋牌）— API 規格

> 來源：《美天棋牌接口文档 V1.0.9（多币种版）.doc》（主要事實來源）
> 補充：`正式環境.docx`、`7shwe info.txt`（商戶帳密/密鑰/網域）
> 整合日期：2026-06-03
> 錢包模式：Transfer Wallet（轉帳錢包）
> Vendor ID：19

---

## 通用約定

### 請求格式

- **HTTP Method**：所有接口一律 `POST`
- **路徑風格**：參數以 **URL path segment** 排列（非 query string 或 JSON body）
  - 路徑前綴：`/services/dg/player/`
  - 範例：`{API_URL}/api/deposit2/{merchantId}/{username}/{amount}/{orderId}/{time}/{code}/{data}`
- **回應格式**：JSON（文件亦提供 XML，本專案僅用 JSON）
- **字元集**：UTF-8
- **驗證**：IP 白名單（所有接口 `IP限制 = TRUE`）＋ `code` / `pwd` 簽名

### Base URL

| 環境 | URL |
|---|---|
| Production API | `https://example.invalid` |
| 商戶管理後台 | `https://example.invalid` |

> 完整前綴：`https://example.invalid/services/dg/player/`
> 測試環境網域文件未明確標示，需向 MT 客服索取。

### 成功判斷

- 業務結果一律看回應 body 的 `resultCode`
- **`resultCode == 1` = 成功**（詳見完整錯誤碼表）
- HTTP 層由 `Http::...->throw()` 處理（非 2xx 直接拋例外）

### 時區

- 轉帳/交易回應帶 `timeZone`（示例為 `GMT+8`）
- 遊戲記錄 `gameDate` 亦附 `timeZone`
- 對帳換算須以回應的 `timeZone` 為準，不可假設 UTC

### 金額規格

- `coins` 精確到小數 4 位
- 金額一律以字串傳遞
- `getBalance` 以 `Common::numFloor($coinBalance, 4)` 正規化

---

## 簽名

### rawData → data（BASE64）

```php
$rawData = json_encode($params);       // params = 具體 API 所需參數（陣列）
$data    = base64_encode($rawData);    // 放 URL path segment
```

> 重要：`rawData` 是 **JSON encode 整個 params 陣列**後再 BASE64，不是直接 BASE64 params。

### code（MD5 摘要）

```php
$code = md5($key . $rawData);          // MD5(商戶密鑰 + rawData)，放 URL path segment
```

### time

```php
$time = time();                        // Unix timestamp（10 位秒級）
```

### pwd（玩家密碼）

```php
$password = [REDACTED] . strtotime($account->created_at)), 8, 12);
$pwd      = [REDACTED];
```

- `$account->created_at`：DB 的 `MtAccount.created_at`
- 此 `pwd` 用於 `playerCreate2` 的建帳密碼，以及 `playerPlatformUrl` 的登入鑑別

---

## 錢包流程（Transfer Wallet）

```
建立帳號: playerCreate2
  │
  ▼
取得遊戲 URL: playerPlatformUrl（含 pwd）
  │
玩家要玩 → transferToVendor → deposit2（本地餘額轉入 MT）
玩家結束 → transferToLocal → withdraw2（MT 餘額轉回本地）
轉帳不明 → getTransRecord → queryTransbyId（查詢轉帳狀態補單）
餘額查詢 → getBalance → getPlayerBalance
```

**轉帳結果（`VendorTransferResultEnum`）**：

| 條件 | 結果 |
|---|---|
| `resultCode == 1` | `SUCCESS` |
| JSON 解析失敗 | `TIMEOUT` |
| 其他 resultCode | `FAILED` |

---

## API

### 1. playerCreate2 — 建立玩家帳號

```
POST {API_URL}/api/playerCreate2/{merchantId}/{username}/{password}/{lang}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `merchantId` | 商戶號 |
| `username` | 玩家帳號 |
| `password` | `pwd`（已 MD5，見簽名章節） |
| `lang` | 語言代碼（`ZH-CN`/`ZH-TW`/`EN-US`） |
| `time` | Unix timestamp |
| `code` | MD5 簽名 |
| `data` | BASE64(rawData) |

rawData params：`{ username, lang }`

回應：`resultCode`、`playerId`

### 2. getPlayerBalance — 查詢餘額

```
POST {API_URL}/api/getPlayerBalance/{merchantId}/{username}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `username` | 玩家帳號 |

rawData params：`{ username }`

回應：`resultCode`、`money`（餘額）、`timeZone`

### 3. deposit2 — 轉入（transferToVendor）

```
POST {API_URL}/api/deposit2/{merchantId}/{username}/{amount}/{orderId}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `amount` | 轉入金額（小數 4 位） |
| `orderId` | 系統唯一轉帳單號 |

rawData params：`{ username, amount, orderId, currency? }`（多幣種版含 currency）

回應：`resultCode`、`balance`（轉入後餘額）、`timeZone`

### 4. withdraw2 — 轉出（transferToLocal）

```
POST {API_URL}/api/withdraw2/{merchantId}/{username}/{amount}/{orderId}/{time}/{code}/{data}
```

參數同 deposit2。

回應：`resultCode`、`balance`（轉出後餘額）、`timeZone`

> 新增返回碼（V1.0.5）：`12`=用戶遊戲在線、`36`=存在未處理訂單、`37`=禁止遊戲中轉出

### 5. playerPlatformUrl — 取得遊戲 URL（getGameUrl）

```
POST {API_URL}/api/playerPlatformUrl/{merchantId}/{username}/{gameCode}/{lang}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `gameCode` | 遊戲代號（`0`=所有遊戲大廳） |
| `lang` | 語言代碼 |

rawData params：`{ username, gameCode, lang }`（不含 currency）

回應：`resultCode`、`platformUrl`（遊戲入口 URL）

### 6. updatePlayerPwds — 修改玩家密碼（未實作）

```
POST {API_URL}/api/updatePlayerPwds/{merchantId}/{username}/{newPassword}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `newPassword` | 新密碼（已 MD5） |

rawData params：`{ username, newPassword }`

### 7. queryTransbyId — 查詢轉帳狀態（getTransRecord）

```
POST {API_URL}/api/queryTransbyId/{merchantId}/{orderId}/{time}/{code}/{data}
```

rawData params：`{ orderId }`

回應：`resultCode`、`status`（`1`=正常 / `2`=異常）、`timeZone`

> `resultCode` 與 `status` 為不同維度：`resultCode==1` 表查詢成功，`status` 才是轉帳本身的結果。

### 8. queryMerchantGameRecord2 — 注單查詢（未實作）

```
POST {API_URL}/api/queryMerchantGameRecord2/{merchantId}/{startDate}/{endDate}/{page}/{time}/{code}/{data}
```

| path segment | 說明 |
|---|---|
| `startDate` | 開始日期（格式：`yyyy-MM-dd`） |
| `endDate` | 結束日期 |
| `page` | 頁碼（從 1 開始） |

rawData params：`{ startDate, endDate, page }`

- **保留期：30 天**（startDate/endDate 差距上限）
- 回應：注單列表（含 gameDate、gameCode、bet、win、profit 等）

### 9. queryMerchantGameRecord3 — 注單查詢（擴充版，未實作）

```
POST {API_URL}/api/queryMerchantGameRecord3/{merchantId}/{startDate}/{endDate}/{page}/{gamehall}/{time}/{code}/{data}
```

在 queryMerchantGameRecord2 基礎上增加 `gamehall` 過濾。

rawData params：`{ startDate, endDate, page, gamehall }`

---

## 業務狀態碼（完整）

| 碼 | 說明 | 碼 | 說明 |
|---|---|---|---|
| 0 | 異常 | 16 | 遊戲代碼錯誤 |
| **1** | **成功** | 17 | 遊戲未開通 |
| 2 | 商戶不存在 | 18 | 遊戲房間未開通 |
| 3 | 商戶無效 | 20 | XML 遊戲資訊無法解析 |
| 4 | 商戶用戶不存在 | 21 | 解密出錯 |
| 5 | 商戶用戶已註冊 | 22 | 商戶用戶登入禁用 |
| 6 | 商戶用戶系統禁用 | 31 | 商戶餘額不足 |
| 7 | 密碼錯誤 | 32 | 可選參數錯誤 |
| 8 | 新密碼與舊密碼相同 | 33 | 交易編碼已存在 |
| 9 | 商戶用戶金幣餘額不足 | 36 | 存在未處理訂單 |
| 10 | 商戶用戶在線狀態 | 37 | 禁止遊戲中轉出 |
| 11 | 商戶用戶離線狀態 | 38 | 請求超時 |
| 12 | 商戶用戶遊戲在線 | 40 | 維護模式（只開餘額查詢） |
| 13 | 商戶用戶遊戲離線 | — | — |
| 14 | uId 錯誤 | — | — |
| 15 | IP 被限制 | — | — |

---

## 實作落差

1. **`merchanId` 拼字（高風險）**：Driver 送出的欄位名稱為 `merchanId`（少一個 `t`），文件及路徑參數均為 `merchantId`。MT 接口主吃 URL path segment，body 欄位可能被忽略，故未必造成功能問題，但命名不符須留意，建議確認 MT 端是否讀 body。

2. **轉帳 resultCode 對應過窄**：Driver `transfer()` 的 `match` 僅辨識 `'0'→FAILED`、`'1'→SUCCESS`、`'2'→FAILED`、`default→TIMEOUT`。確定性失敗碼（`9`=餘額不足、`6`=用戶禁用、`33`=交易編碼已存在、`31`=商戶餘額不足）目前全落入 `TIMEOUT`，可能觸發不必要的補單/重試——待 RD 確認是否需明確歸為 FAILED。

3. **未實作的 3 個 API**：`playerCreate2`（建帳）、`updatePlayerPwds`（改密）、`queryMerchantGameRecord2/3`（注單對帳）均未在 Driver 中實作。注單對帳須在保留期 30 天內取數，需另行規劃。

4. **`checkTransferStatus` 語意偏保守**：`queryTransbyId` 回應中 `resultCode=2/3`（商戶不存在/無效）屬設定問題，目前落入 `TIMEOUT` 會持續輪詢，語意略偏——可接受但建議確認。

5. **測試環境 Base URL 未在文件列出**：正式為 `https://example.invalid`，測試網域需向 MT 客服索取。
