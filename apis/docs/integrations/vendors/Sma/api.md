# Sma（Slotmania 77）— API 規格

> 原始來源（HTML，YApi 匯出）：
> - `Slotmania API - v20230920.html`（**以此版為主**）
> - `Slotmania API - v202309.html`、`slotmania-api-20230803.html`（舊版，比對用）
> 來源資料夾：`/Users/rb/Workspace/遊戲商/77.Slotmania/`
> 文件站 URL（來自 `api.url`）：<https://example.invalid/ApiDocumentFile/>
> 抓取日期：2026-06-03

---

## 通用約定

### 請求格式

- **HTTP Method**：除 `openGame`（GET）外，其餘 API 一律 `POST`。
- **Content-Type**：`application/json`（POST body 為 JSON）。
- 所有請求都必須帶 `appId`、`timestamp`、`sign` 三個欄位（部分 GET 介面放 query string）。
- `timestamp`：毫秒級 Unix timestamp。**伺服器只接受 5 分鐘內的 timestamp**。
  - 實作備註（Driver 第 28 行）：「如果時間戳太接近當下時間，三方 API 會回傳錯誤，所以 `now()->subSeconds(3)`」——Driver 取 `getPreciseTimestamp(3)`（毫秒）並回退 3 秒。此為 RD 經驗值，官方文件未載明，列為待確認。

### 通用回應結構

除 `openGame`（回傳 URL 字串）外，皆為 JSON：

```jsonc
{
  "msg": "message",
  "code": 0,
  "data": {}        // 或 array；失敗時可能為 null
}
```

### Base URL

| 環境 | URL |
|---|---|
| Production | **文件未提供**（由 Sma 客服 / 維運提供，存於 `vendor_infos.info.API_URL`） |
| 文件站 | `https://example.invalid/ApiDocumentFile/`（僅文件託管，非 API endpoint） |

> 各 API endpoint 為 `API_URL` + Path（例：`{API_URL}/api/v2/ext/wallet/deposit`）。Driver 以 `rtrim($apiUrl, '/') . $endpoint` 組合，`getGameUrl` 則直接 `$apiUrl . 'api/v2/ext/player/openGame?...'`。

---

## 簽名

排除 `sign` → 參數名稱自然排序（`ksort`）→ `key=value` 以 `&` 拼接 → 末端直接接 Secret Key → MD5。

| 項目 | 值 |
|---|---|
| 雜湊演算法 | MD5（hex 輸出，32 字元小寫） |
| 金鑰來源 | Secret Key（`vendor_infos.info.API_SECRET_KEY`），直接字串拼接於參數末端 |
| 簽名欄位 | `sign` |
| timestamp 規範 | 毫秒級 Unix timestamp；伺服器只接受 **5 分鐘內**的 timestamp |

### 構成步驟（官方）

1. 先將請求參數中的 `sign` 欄位排除。
2. 將剩餘欄位按**參數名稱**進行自然排序。
3. 將排序後的參數以 `key=value` 組合，再由 `&` 字元拼接，得出「參數部分」。
4. 以有序組合將「參數部分」與給定的 **Secret Key** 直接拼接（無分隔符）。
5. 將拼接後的字串做 **MD5**，作為 `sign`。
6. POST 的 JSON 參數與 GET 的 URL 參數都以相同方式排序簽名。

### 官方範例

Secret Key（範例）：`appsecret`

參數（已排序、排除 sign）：

```
appid=d14gwqjsow6ppxiw&currency=USD&timestamp=1690343209000&username=example
```

拼接 Secret Key 後：

```
appid=d14gwqjsow6ppxiw&currency=USD&timestamp=1690343209000&username=exampleappsecret
```

MD5 結果：`97bc8768fc6e3dae184389fa60438b6a`

### PHP 實作（`app/Vendors/Sma/Driver.php` `generateSignature()`）

```php
private function generateSignature(array $data): array
{
    unset($data['sign']);
    ksort($data);                                       // 參數名稱自然排序
    $signature = urldecode(http_build_query($data));    // key=value&key=value
    $signature .= $this->secretKey;                     // 末端直接接 Secret Key
    $signature = md5($signature);                       // MD5 hex
    $data['sign'] = $signature;

    return $data;
}
```

> ⚠️ **欄位大小寫敏感**：文件部分介面寫 `appid`（小寫，如 createAccount、checkToken），部分寫 `appId`（如 login、deposit）。Driver 一律送 `appId`。雙方 key 必須一致，否則 `code = 4 Invalid Signature`。列為待確認。

---

## merchant API

代理（本平台）主動呼叫 Sma 的 API。除 `openGame` 為 GET 外，皆為 POST + JSON body。所有請求都需帶 `appId`、`timestamp`、`sign`。共同欄位在各表中省略，僅列各介面額外欄位。

### 公共分類

#### gameList（遊戲列表）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/gameList` |
| Method | `POST` |

僅需共同欄位。回傳 `data` 為遊戲陣列：

| `data[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `id` | integer | 遊戲 id（對應 `openGame` 的 `id`） |
| `name` | string | 遊戲名稱 |
| `type` | string | 遊戲種類；文件未列枚舉，RD-Studio 舊指令實測只處理 `slots`、`fishing` 兩值 |
| `squareIcon` / `portraitIcon` / `loadingPic` | string | 方形 / 畫像 / 載入圖 |
| `gameHeight` / `gameWidth` | integer | 遊戲長寬 |

> 成功判定沿用舊指令實測值 `code == 200`（非通用範例的 `0`）。本平台落地見 `integration.md`「遊戲清單同步」。

#### playerList（取得玩家）

查詢該代理下所有玩家。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/playerList` |
| Method | `POST` |

額外欄位：`pageNo`（頁碼，從 1 起）、`pageSize`（每頁大小）。

| `data[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `uid` | string | 玩家 id |
| `username` | string | 玩家帳號 |
| `currency` | string | 幣種 |
| `locked` | boolean | 鎖定狀態 |
| `lastLoginTime` | number | 最後登入 Unix 時間戳（秒，Long） |

#### createAccount（創建帳戶）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/createAccount` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家名稱 |
| `currency` | string | ❌ | **已棄用，傳入不生效**（ISO 4217） |

> 文件此介面 body 寫 `appid`（小寫）。

**Success Response**

```jsonc
{ "msg": "success", "code": "0", "data": { "user": { "uid": 0 } } }
```

### 玩家接口

#### login（登入）

> 對應 Driver `getToken()`。**設定 token 過期會導致遊戲中玩家突然下線。**

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/player/login` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |

**Success Response**

```jsonc
{ "code": 200, "msg": "...", "data": { "username": "...", "token": "..." } }
```

> Driver 以 `(string) code != '200'` 判失敗（拋 `$result['msg']`），成功取 `data.token`。

#### checkToken（令牌有效性檢查）

對應 Driver `checkToken()`。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/player/checkToken` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 用戶名 |
| `token` | string | ✅ | 令牌 |

> 文件此介面 body 寫 `appid`（小寫）、`timestamp` 標為 string。

**回應碼判定（Driver 註解 + 實作）**

| `code` | 含義 |
|---|---|
| `0` | 有效令牌 |
| `5` | 無效令牌（Token Expired） |

> Driver 以**嚴格整數** `$result['code'] === 0` 判定有效；非 0（含 5）視為無效，會觸發重新 `login`。

#### kick（強制下線）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/player/kick` |
| Method | `POST` |

請求欄位：`username`（玩家帳號）。本專案 Driver **未使用**。

#### openGame（進入遊戲）

對應 Driver `getGameUrl()`。**唯一的 GET 介面**，回傳遊戲 URL（文件註：回傳 URL 並設 HttpCode=304）。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/player/openGame` |
| Method | `GET`（參數放 query string） |

**Query 欄位**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `appid` | ✅ | （Driver 送 `appId`） |
| `timestamp` | ✅ | 毫秒時間戳 |
| `sign` | ✅ | 簽名 |
| `back` | ✅ | 從遊戲返回的 URL，**需 base64 加密**（Driver：`base64_encode(BACK_URL)`） |
| `token` | ✅ | 令牌 |
| `id` | ✅ | 遊戲 id（Driver：`params->gameCode`） |
| `lang` | ❌ | 語言（範例 `EN`） |
| `closeBack` | ✅ | `0`＝不關閉返回按鈕，`1`＝關閉返回按鈕 |

**語言碼（lang）**

官方明列：`cn`＝中文、`en`＝英文、`th`＝泰文、`vi`＝越南文。

Driver `mapLanguageCode()` 額外映射（**文件未列，待確認**）：

| 本地語言 | 送出 lang |
|---|---|
| zh-TW / zh-CN | `cn` |
| ms-MY | `my` |
| id-ID | `ida` |
| vi-VN | `vi` |
| th-TH | `th` |
| 其他 | `en` |

#### block（禁用帳戶）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/player/block` |
| Method | `POST` |

請求欄位：`username`（玩家帳號）。本專案 Driver **未使用**。

### 轉帳錢包接口 Transfer

> 僅 Transfer Wallet 模式使用。Sma 端保管玩家餘額；本地透過 deposit/withdraw 上下分。

#### wallet/init（初始化分數）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/wallet/init` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |
| `balance` | number | ✅ | 初始餘額 |
| `walletMode` | integer | ✅ | `TRANSFER=1`、`SEAMLESS=2` |
| `requestId` | string | ✅ | UUID |

> 本專案 Driver **未實作 init 呼叫**（未見於 `Driver.php`）；若需要在建立玩家時初始化錢包，須補上。列為 gap。

#### wallet/deposit（上分）

對應 Driver `transferToVendor()`（玩家本地餘額 → Sma）。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/wallet/deposit` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |
| `balance` | number | ✅ | 上分金額（Driver：`params->vendorAmount`） |
| `requestId` | string | ✅ | UUID（Driver：`params->transactionNo`） |

**回應（介面描述）**

```jsonc
// 成功
{ "code": 200, "data": "0", "message": "请求成功" }
// 失敗：余額不足
{ "code": 26, "data": null, "message": "balance not enough" }
// 失敗：錢包未初始化
{ "code": 27, "data": null, "message": "wallet not initialize" }
```

> 介面描述文字誤寫成「下分」（疑文件複製未改字，deposit=上分）。`code 26/27` 不在全域 Response Code 表內（文件不一致）。Driver 僅認 `code==200` 為 `SUCCESS`，其餘 `FAILED`，無法確認結果則 `TIMEOUT`。

#### wallet/withdraw（下分）

對應 Driver `transferToLocal()`（Sma 餘額 → 玩家本地）。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/wallet/withdraw` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |
| `balance` | number | ✅ | 下分金額 |
| `requestId` | string | ✅ | UUID |

**回應**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | number | `200` 成功 |
| `msg` | string | success |
| `data` | string | 下分後餘額：**0 或正數為成功；負數為下分失敗** |

> 介面頂部描述同樣寫 `code 200 成功 / 26 / 27`。Driver 只依 `code==200` 判定，未解析 `data` 餘額正負。

#### wallet/queryBalance（查詢餘額）

對應 Driver `getBalance()`。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/wallet/queryBalance` |
| Method | `POST` |

**Request 欄位**

Query：`appId`、`timestamp`、`sign`、`username`（必填）。Body：`username` 必填。

**回應（文件）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | string | 回應碼 |
| `msg` | string | 訊息 |
| `data` | object | 含 `data.balance`（number，餘額） |

> **落差**：文件 `data` 為 object（`data.balance`），但 Driver `getBalance()` 直接把 `$result['data']` 當數值（`is_numeric`、`* 100`、`Common::numFloor(..., 2)` 無條件捨去到小數第 2 位）。待以實際回應驗證實際結構。

### 統計接口

> 用於對帳。各介面 begin/end 為毫秒時間戳。

#### report/gameBet（下注記錄）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/report/gameBet` |
| Method | `POST` |

**Request 欄位（body）—— 兩種查詢模式擇一**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `username` | string | 使用 `requestId` 時勿傳 |
| `begin` / `end` | number | 記錄時間（毫秒）；不使用 `requestId` 時必傳，使用時勿傳 |
| `pageNo` / `pageSize` | number | 不使用 `requestId` 時必傳 |
| `requestId` | string | 記錄 id（精準查單，與時間範圍模式互斥） |

**回應 `data.list[]`**

| 欄位 | 說明 |
|---|---|
| `requestId` | 下注 id |
| `currency` | 幣種 |
| `gameId` / `gameName` / `gameType` | 遊戲資訊 |
| `bet` | 下注額（文件 desc 誤標「赢钱」） |
| `win` | 派彩（文件 desc 誤標「输钱」） |
| `startScore` / `endScore` | 下注前 / 後分數 |
| `time` | 下注時間 |

> `bet`/`win` 的 desc 文字疑與欄位名相反（文件 typo），請以欄位名為準並向客服確認。

#### report/activePlayer（活躍玩家）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/report/activePlayer` |
| Method | `POST` |

請求欄位：`begin`、`end`（毫秒，含端點；**只能查 6 個月內，區間 ≤ 1 天**）、`pageNo`、`pageSize`。回傳 `data.list[]` 為 username 字串陣列、`data.total`。

#### report/daily（每日統計）

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/report/daily` |
| Method | `POST` |

**Request 欄位（body）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ❌ | 指定玩家；空=全部 |
| `begin` | number | ✅ | 毫秒；只能查 6 個月內。**以日界彙總**：輸入 2023-08-01 10:10:10（1690855810000）→ 查詢範圍 2023-08-01 00:00:00 ~ 23:59:59 |
| `pageNo` / `pageSize` | integer | ✅ | 分頁 |

**回應 `data.list[]`**

| 欄位 | 說明 |
|---|---|
| `username` | 玩家 |
| `totalPay` | 總下注 |
| `totalWin` | 總派彩 |
| `starScore` / `endScore` | 起 / 迄分數（文件欄位名為 `starScore`，疑 typo 應為 `startScore`） |

#### report/queryOrder（轉帳記錄查詢）

對帳轉帳 / TIMEOUT 收斂用。

| 項目 | 值 |
|---|---|
| Path | `/api/v2/ext/report/queryOrder` |
| Method | `POST` |

**查詢模式**

- **精準查詢**：填 `requestId`；忽略 begin/end/username/pageNo/pageSize。
- **範圍查詢**：填 `begin`/`end`/`username`/`pageNo`/`pageSize`；忽略 `requestId`。**只能查 3 個月內，區間 ≤ 3 天**。
- `opType`（金額變化類型）：`1` 游戲、`2` 轉入、`3` 轉出、`4` 金額同步；**不使用時請勿加入 body**，未加則回傳全部類型。

**回應 `data.list[]`**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `requestId` | string | UUID |
| `username` | string | 用戶名 |
| `startBalance` / `balance` | number | 處理前 / 後餘額 |
| `time` | number | 處理時間戳（毫秒，Long） |
| `state` | string | 處理狀態（見下表） |
| `opType` | number | 金額變化類型（同上） |

**`state` 狀態機**

| `state` | 含義 |
|---|---|
| `0` | 未進分 |
| `200` | 進分成功 |
| `1` | 更新出账方分數中（中間態） |
| `2` | 更新入账方分數中（中間態） |
| `-1` | 出账方分數更新失敗 |
| `-2` | 入账方分數更新失敗 |

#### 其他統計接口

文件另有以下統計介面（本專案未使用，欄位從略）：

| 介面 | Path |
|---|---|
| 牌面查询接口 | `/api/v2/ext/ext`（文件僅列 Path，無欄位內容 — gap） |
| 五分钟玩家统计 | `/api/v2/ext/report/fiveMinutes` |
| 每小时玩家统计 | `/api/v2/ext/report/hourly` |

---

## 錯誤碼

官方 Response Code 表（v20230920）：

| Code | Meaning |
|---|---|
| `200` | Success |
| `500` | System Failure |
| `1` | New User is created |
| `2` | User does not exist |
| `3` | Parameter Error |
| `4` | Invalid Signature |
| `5` | Token Expired |
| `6` | RequestId Has Already Been Used |
| `7` | RequestId Format Error |
| `8` | Exceeded the Maximum Limit |
| `9` | Signing Out Failed |
| `10` | Invalid Request |
| `11` | Unknown Database Error |
| `12` | User Already Exist |
| `13` | Topup fail |
| `14` | Agent Insufficient credit |
| `15` | No data found |
| `16` | Query is rate limit / Throttled |
| `17` | System is under maintenance |
| `18` | AppID doesn't exist |
| `19` | Error retrieving Bonus figure |
| `20` | Error retrieving player balance |
| `26` | balance not enough（deposit/withdraw 介面描述，不在全域表中） |
| `27` | wallet not initialize（deposit/withdraw 介面描述，不在全域表中） |

> `code` 型別與「成功值」不一致：全域表以 `200` 為成功，但 `checkToken` 介面描述以 `code = 0` 為有效令牌、`code = 5` 為無效令牌。Driver 對 deposit/withdraw/login/queryBalance 一律以 `(string) code === '200'` 判定成功，對 `checkToken` 以 `code === 0`（嚴格 int 比較）判定有效。各介面回應碼語意不統一。
