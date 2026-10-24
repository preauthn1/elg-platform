# Joker — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與簽名規格）。
> ⚠️ 本文件對應 Joker（vid=2），與 JokerBaccarat（vid=177/235）的 3DES+MD5 接入完全不同。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/json`
- **Endpoint 型態**：**單一 endpoint**，由 body 的 `Method` 欄位區分行為
- **驗證方式**：IP 白名單 ＋ URL query 上的 `AppID` + `Signature`（HMAC-SHA1）
- **編碼**：UTF-8

```
POST {api-url}?AppID={appid}&Signature={signature}
Content-Type: application/json

{ "Method": "...", "Timestamp": 1453353995, ... }
```

### Base URL

| 環境 | API URL | AppID | 遊戲 / 前台 URL |
|---|---|---|---|
| 測試站 | `http://example.invalid:81` | `TF6L` | `http://example.invalid` |
| 正式站（MYR / V-World） | `http://example.invalid:81` | `F1M3` | `http://example.invalid` |

> Joker 依 **AppID** 區分測試 / 正式並套用各自 IP 白名單。

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Method` | String | ✅ | API 行為代碼（見下方各節） |
| `Timestamp` | Integer | ✅ | UNIX 秒（`time()`） |
| `Username` | String | 視 API | 玩家用戶名，格式 `<AppID>.<username>`，4~20 字母數字底線、不分大小寫 |
| `RequestID` | String | 轉帳類必填 | 唯一密鑰（最大 50 字母數字），用於冪等驗證 |

### 通用回應

- 成功 / 失敗以 **HTTP 狀態碼** 判斷
- 成功 body 依 API 不同
- 失敗 body：`{"Message":"..."}`（除錯訊息）

### HTTP 狀態碼

| Code | 說明 |
|---|---|
| `2xx (200–299)` | 請求成功接收、理解、接受 |
| `4xx (400–499)` | 請求語法錯誤或無法滿足（含 `404` = TCH/TFCH 的 RequestID 不存在） |
| `5xx (500–999)` | 三方系統錯誤 → 轉帳類應改打 TCH 驗證 |

### 資料保留期 / 限流

- **TS/TSM 資料延遲 10 分鐘**；每頁 1500 筆，需 `NextId` 翻頁。
- **歷史資料保留 14 天**；TS 一次只能查 1 小時。

---

## Merchant API

### Methods 總覽

| Method | 分類 | 用途 |
|---|---|---|
| `ListGames` | 遊戲 | 取得遊戲列表 |
| `PLAY` | 遊戲 | 取得遊戲令牌（可同時轉入） |
| `GC` | 錢包 | 查詢玩家餘額 |
| `TC` | 錢包 | 轉移信用（雙向，正數＝轉入，負數＝轉出） |
| `TCH` | 錢包 | 驗證轉移信用（逾時 / 5xx 後確認 TC 是否成立） |
| `WAC` | 錢包 | 提款玩家所有信用 |
| `TS` | 報表 | 按小時檢索事務 |
| `TSM` | 報表 | 按分鐘檢索事務 |
| `TRX` | 報表 | 檢索事務摘要 |
| `RWL` | 報表 | 檢索輸贏 |
| `History` | 報表 | 取得單筆遊戲歷史回放 URL |

---

### 5.1 ListGames — 取得遊戲列表

```json
{ "Method": "ListGames", "Timestamp": 1453353995 }
```

回應（`ListGames` 陣列）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `GameCode` | String | 遊戲別名（用於 PLAY 時帶入） |
| `GameName` | String | 遊戲名稱 |
| `GameType` | String | `Slot` / `Fishing` / `E-Casino` / `Single Player` / `Multiplayer` 等 |
| `Special` | String | 包含關鍵字 `new` / `hot` |
| `Order` | String | 排序次序 |
| `DefaultWidth` / `DefaultHeight` | Integer | 預設寬高 |
| `Image1` | String | 橫向圖 |
| `Image2` | String | 縱向圖 |

---

### 5.2 PLAY — 玩遊戲（取得遊戲令牌）

第一次對某 Username 呼叫時，Joker 端自動建立玩家。支援兩種型態：

**a) 無轉帳金額**

```json
{ "Method": "PLAY", "Username": "DEMO", "Timestamp": 1453353995 }
```

回應：`{ "Token": "s1yc3uqnaxycn", "Username": "DEMO" }`

**b) 有轉帳金額（取令牌同時轉入）**

```json
{
  "Method": "PLAY",
  "Username": "DEMO",
  "Timestamp": 1453353995,
  "RequestID": "xea1rt9gebhwy",
  "Amount": "1000.00"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Method` | String | ✅ | `PLAY` |
| `Username` | String | ✅ | 玩家用戶名 |
| `Timestamp` | Integer | ✅ | UNIX 秒 |
| `RequestID` | String | b 型態必填 | 唯一密鑰，最大 50 字母數字 |
| `Amount` | String | b 型態必填 | 正數，最多兩位小數 |

回應（b）：`Token` / `Username` / `RequestID` / `Credit` / `BeforeCredit` / `OutstandingCredit` / `Time`

> **令牌只能使用一次**。拿到 `Token` 後組遊戲 URL：
> `{game-url}?token=[REDACTED] 639-1}`

---

### 5.3 GC — 取得信用（餘額）

```json
{ "Method": "GC", "Username": "DEMO", "Timestamp": 1453353995 }
```

回應：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Username` | String | 玩家用戶名 |
| `Credit` | Decimal | 當前信用餘額 |
| `OutstandingCredit` | Decimal | 剩餘（未結算）信用 |

---

### 5.4 TC — 轉移信用

代理錢包 ↔ Joker 雙向轉帳。

```json
{
  "Method": "TC",
  "Username": "DEMO",
  "Timestamp": 1453353995,
  "RequestID": "xea1rt9gebhwy",
  "Amount": "1000.00"
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Method` | String | ✅ | `TC` |
| `Username` | String | ✅ | 玩家用戶名 |
| `Timestamp` | Integer | ✅ | UNIX 秒 |
| `RequestID` | String | ✅ | 唯一密鑰，最大 50 字母數字 |
| `Amount` | String | ✅ | **正數＝轉入 Joker**；**負數＝從 Joker 轉出**；最多兩位小數 |

回應：`Username` / `RequestID` / `Credit` / `BeforeCredit` / `OutstandingCredit` / `Time`

> 官方建議容錯流程：`Amount < 0` → 失敗；`Amount == 0` → 成功（不打 API）；HTTP 5xx → 改打 TCH 驗證。

---

### 5.5 TCH — 驗證轉移信用

TC 逾時 / 5xx 後，以相同 `RequestID` 確認該筆轉帳是否成立。

```json
{ "Method": "TCH", "Timestamp": 1453353995, "RequestID": "xea1rt9gebhwy" }
```

- **成功**：`HTTP 200`，回 `Username` / `RequestID` / `Amount` / `Time`
- **未成立**：`HTTP 404 Not Found`（`RequestID` 不存在 = 轉帳未成立）

---

### 5.6 WAC — 提款所有信用

把玩家在 Joker 的全部餘額轉回代理。

```json
{ "Method": "WAC", "Username": "DEMO", "Timestamp": 1614757291, "RequestID": "sfsg234dfdfsdfg11df" }
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Method` | String | ✅ | `WAC` |
| `Username` | String | ✅ | 玩家用戶名 |
| `Timestamp` | Integer | ✅ | UNIX 秒 |
| `RequestID` | String | ✅ | 唯一密鑰，最大 50 字母數字 |

回應：`Username` / `RequestID` / `Amount` / `Time`（`Amount=0` 表示玩家無餘額）

---

### 6.1 TS — 按小時檢索事務

```json
{ "Method": "TS", "StartDate": "2020-05-06 15:00", "EndDate": "2020-05-06 16:00", "NextId": "", "Timestamp": 1453353995 }
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `StartDate` | String | ✅ | 格式 `yyyy-MM-dd HH:mm`（精確到小時，分鐘被忽略） |
| `EndDate` | String | ✅ | 格式 `yyyy-MM-dd HH:mm`（exclusive，不含此時刻） |
| `NextId` | String | ✗ | 分頁游標，首次傳空字串 |
| `Timestamp` | Integer | ✅ | UNIX 秒 |

限制：每頁 **1500** 筆；資料延遲 **10 分鐘**；換時區加 `&timeZone=SA%20Western%20Standard%20Time`

回應 `data`（字典，key 為事務類型）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OCode` | String | 事務唯一識別符 |
| `Username` | String | 關聯玩家 |
| `GameCode` | String | 遊戲代碼 |
| `Description` | String | 描述 |
| `Type` | String | 類型 |
| `Amount` | Decimal | 下注金額 |
| `Result` | Decimal | 下注結果（輸贏 = Result − Amount） |
| `Time` | DateTime | 事務時間 |

---

### 6.2 TSM — 按分鐘檢索事務

與 6.1 相同，但 **StartDate / EndDate 精確到分鐘**。`Method` 固定 `TSM`，其餘欄位、分頁、延遲、時區規則同 TS。

---

### 6.3 TRX — 檢索事務摘要

```json
{ "Method": "TRX", "StartDate": "2020-05-06", "EndDate": "2020-05-07", "Username": "optional", "Timestamp": 1453353995 }
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `StartDate` / `EndDate` | ✅ | 格式 `yyyy-MM-dd` |
| `Username` | ✗ | 依用戶名篩選 |

回應 `data` 陣列每筆：`Date` / `Username` / `CurrenyCode`（原文拼字）/ `TotalAmount` / `TotalResult` / `TotalDeposit` / `TotalWithdraw`

---

### 6.4 RWL — 檢索輸贏

```json
{ "Method": "RWL", "StartDate": "2020-05-06", "EndDate": "2020-05-07", "Username": "optional", "Timestamp": 1453353995 }
```

回應 `Winloss` 陣列每筆：`Username` / `Amount` / `Result`

---

### 6.5 History — 取得歷史回放 URL

```json
{ "Method": "History", "OCode": "s1yc3uqnaxycn", "Language": "en", "Type": "Game", "Timestamp": 1453353995 }
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `OCode` | ✅ | 來自 TS / TSM 的事務代碼 |
| `Language` | ✗ | ISO 639-1（如 `en` / `zh` / `th`） |
| `Type` | ✅ | 事務類型，如 `Game` |

回應：`{ "Url": "//History?Signature=...&Key=...&Type=Game&Timestamp=...&Lang=en" }`

---

## 簽名

### 演算法總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | HMAC-SHA1 |
| 金鑰 | `Secret`（AppID 對應的 SecretKey，由 Joker 客服提供） |
| 明文 | 請求 body `fields` 依 **key 字母順序排序**，組成 `key1=value1&key2=value2&…`（值不做 URL-encode） |
| 輸出編碼 | `urlencode(base64_encode(HMAC_SHA1_raw))` |

公式：

```
Base64(HMAC_SHA1(KeySorted(key1=value1&key2=value2&…), secret_key))
```

### 構成步驟（PHP 版）

**Step 1 — 依 key 排序，組 query string**

```php
ksort($fields);
$rawData = urldecode(http_build_query($fields, '', '&'));
// http_build_query 做 URL-encode，外層 urldecode() 還原回原值（空格、冒號等不編碼）
```

**Step 2 — HMAC-SHA1 → Base64 → URL-encode**

```php
$hash = base64_encode(hash_hmac('sha1', $rawData, $secretKey, true));
$signature = urlencode($hash);
// 簽名含 + / = 等特殊字元，必須 urlencode() 後放 query string
```

**Step 3 — 組 URL**

```
{api-url}?AppID={appid}&Signature={signature}
```

body 仍以 JSON 送出原始 `fields`。

### 官方驗章樣本

| 項目 | 值 |
|---|---|
| Method | `TS` |
| StartDate | `2020-01-06 10:00` |
| EndDate | `2020-01-06 12:23` |
| Timestamp | `1589186127` |
| NextId | `` |
| SecretKey | `xkzw7kejiqz7c` |
| rawData | `EndDate=2020-01-06 12:23&Method=TS&NextId=&StartDate=2020-01-06 10:00&Timestamp=1589186127` |
| Base64 hash | `iKlVa9rKHUcISV/cGoLKBtrh8hM=` |
| 最終 Signature | `iKlVa9rKHUcISV%2FcGoLKBtrh8hM%3D` |

---

## 錯誤碼

| HTTP Code | 說明 | 處理方式 |
|---|---|---|
| `2xx (200–299)` | 請求成功 | 正常處理 |
| `400` | 請求語法錯誤 | 檢查欄位格式 |
| `404` | TCH / TFCH：RequestID 不存在 | 轉帳確定未成立，視為失敗 |
| `4xx 其他` | 無法滿足請求 | 見回應 body `Message` |
| `5xx (500–999)` | 三方系統錯誤 | 轉帳類改打 TCH 確認，勿重試原請求 |

### 常見 FAQ 錯誤訊息

| 訊息 | 原因 |
|---|---|
| `Forbidden IP: x.x.x.x` | 該 IP 未列入白名單 |
| `Invalid access. Domain example.invalid not allowed` | 開遊戲的網域未在白名單 |
| `Bad request: session not found` | 令牌已使用過（令牌只能用一次）或已完成 |
| `Access from your location not allowed (IP, ZZ, APPID)` | 玩家所在地受限（越南、台灣、菲律賓等） |
