# AcewinSW（Seamless / Single Wallet）— API 整合文件

> 原始來源：Google Doc「AceWin_Macross_API_Single_Wallet_CH_BingoPlus.docx」（fileId: `1j6kPTFfOgpVScxPBymiCiTiucP6IsuRX`）
> 抓取日期：2026-06-03；文件版本：v1.8.7（2026-01-09）
> 我方實作分析見 [integration.md](integration.md)

---

## 通用約定

### 方向 A：AcewinSW → 本平台（回調）

| 項目 | 值 |
|---|---|
| HTTP Method | `POST` |
| Content-Type | `application/json` |
| 驗證 | IP 白名單（主要）；HTTP Basic Auth（可選，見〈附錄 E〉） |
| 成功判定 | `errorCode == 0`；HTTP Status 一律 200（即使業務失敗） |

回調回應共通欄位：

| Parameter | Type | Require | Description |
|---|---|---|---|
| `errorCode` | int | Yes | `0` = 成功；其他見各 endpoint 錯誤碼 |
| `message` | string | No | 失敗時的說明文字 |
| `username` | string | Yes | 玩家帳號唯一識別 |
| `currency` | string | Yes | 玩家持有貨幣代碼 |
| `balance` | double | Yes | 目前餘額（已 × `vendor_infos.rate` 換算） |
| `token` | string | No | 若 token 更新，以此欄位回傳 |

### 方向 B：本平台 → AcewinSW（管理 API）

| 項目 | 值 |
|---|---|
| HTTP Method | `GET`（進遊戲）、`GET`（管理查詢） |
| Content-Type | `application/x-www-form-urlencoded`（POST 時） |
| 驗證 | `AgentId` + `Key`（見〈簽名〉節） |
| Base URL | `vendor_infos.info.AUTH_URL`（由 AceWin 業務提供） |

管理 API 共通請求欄位：

| Parameter | Type | Require | Description |
|---|---|---|---|
| `AgentId` | string | Yes | 代理編號（站長 Id，對應 `vendor_infos.info.CLIENT_ID`） |
| `Key` | string | Yes | 動態簽章（見〈簽名〉節） |

管理 API 共通回應欄位：

| Parameter | Type | Description |
|---|---|---|
| `ErrorCode` | int | `0` = 成功；其他值見各 API 說明 |
| `Message` | string | 狀態描述 |
| `Data` | Object / Array / null | 成功時的資料；失敗時為 `null` |

### 時區

所有 AcewinSW 時間欄位均為 **UTC-4**。管理 API 查詢時間參數也需以 UTC-4 傳入。

---

## Seamless Wallet 回調 API

> AcewinSW 主動呼叫本平台的三個 endpoint。

### auth — 驗證帳號

AcewinSW 在呼叫 `LoginWithoutRedirect` 後，向本平台發送此請求以驗證 Token 合法性並取得玩家帳號與餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| 路由（本平台） | `POST /sw/acewin/auth` |
| Controller | `AcewinController::auth()` |

#### Request Body（AcewinSW → 本平台）

| Parameter | Type | Require | Description |
|---|---|---|---|
| `reqId` | string | Yes | 請求識別唯一代碼，用於追查 API 呼叫狀況 |
| `token` | string | Yes | 代理產生的 session token（最長 800 字元，英數字） |

```jsonc
{
  "reqId": "011d3624-90cd-477b-bf05-b8b2c623ee45",
  "token": "dc7b7f79187bbc17cc2994965a736a9c"
}
```

#### Success Response（本平台 → AcewinSW）

```jsonc
{
  "errorCode": 0,
  "message": "token verify success",
  "username": "rd4qc_01",
  "currency": "THB",
  "balance": 100.0,
  "token": "dc7b7f79187bbc17cc2994965a736a9c"
}
```

#### 錯誤碼

| errorCode | Description |
|---|---|
| 0 | 成功 |
| 4 | Token 已過期或無效 |
| 5 | 其他錯誤（詳情放到 `message`） |

#### 實作說明

- 透過 `token` 在 `acewin_accounts.session_token` 查找玩家
- 帳號 `status == 0` → 回 errorCode=5（User not found）
- 成功回傳 `WalletService::getMemberWallets()::getTotalBalance()` × `vendor_infos.rate`

---

### bet — 玩家注單

**下注與結算合一**：同一請求同時包含 `betAmount`（扣款）與 `winloseAmount`（派彩）。

餘額計算公式：
```
若 下注前餘額 >= betAmount：
    下注後餘額 = 下注前餘額 - betAmount + winloseAmount
否則：
    回 errorCode=2（餘額不足）
```

> **魚機注意**：因魚機押注速度快，以固定秒數結算並一次呼叫；遊戲過程中玩家餘額無法變動。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| 路由（本平台） | `POST /sw/acewin/bet` |
| Controller | `AcewinController::bet()` |

#### Request Body（AcewinSW → 本平台）

| Parameter | Type | Require | Description |
|---|---|---|---|
| `reqId` | string | Yes | 遊戲商請求識別唯一代碼（最長 50 字元） |
| `token` | string | Yes | 代理 api access token |
| `currency` | string | Yes | 貨幣名稱 |
| `game` | int | Yes | 遊戲代碼 |
| `round` | bigint | Yes | 注單唯一值（`WagersId`）；**冪等鍵**，對應 `acewin_bet_records.bet_id` |
| `wagersTime` | bigint | Yes | 下注時間戳記（Unix timestamp） |
| `betAmount` | double | Yes | 押注金額（正數） |
| `winloseAmount` | double | Yes | 派彩金額（已含遊戲內 JP 派彩；無派彩時為 `0`） |
| `inGameJPAmount` | double | Yes | 遊戲內 JP 派彩參考值（**不參與加扣款**；無中 JP 預設 `0`） |

```jsonc
{
  "reqId": "unique-request-id-001",
  "token": "dc7b7f79187bbc17cc2994965a736a9c",
  "currency": "THB",
  "game": 1001,
  "round": 100029127135,
  "wagersTime": 1742454877,
  "betAmount": 10.0,
  "winloseAmount": 0.0,
  "inGameJPAmount": 0.0
}
```

#### Success Response（本平台 → AcewinSW）

```jsonc
{
  "errorCode": 0,
  "message": "OK",
  "username": "rd4qc_01",
  "currency": "THB",
  "balance": 90.0,
  "txId": null
}
```

| Parameter | Type | Require | Description |
|---|---|---|---|
| `txId` | bigint | No | 代理提供的交易識別唯一值（可選） |

#### 錯誤碼

| errorCode | Description |
|---|---|
| 0 | 注單成功 |
| 1 | 該注單已承認（重複 `round`，冪等回應） |
| 2 | 玩家餘額不足 |
| 3 | 參數無效（詳情放到 `message`） |
| 4 | Token 已過期或無效 |
| 5 | 其他錯誤（詳情放到 `message`） |

#### 實作說明

- 以 `round` 查 `acewin_bet_records.bet_id`；已存在 → 回 errorCode=1
- `isMemberAbleToBet()` 先行驗證；`lockForUpdate` 玩家錢包後扣款
- `betAmount` / `winloseAmount` 除以 `vendor_infos.rate` 換算本地幣
- `winLose = winloseAmount - betAmount`
- `bet_records.status` 直接設為 `SETTLE`（下注結算合一，不分兩次）；寫 TBR
- FreeSpin 判斷：`betAmount = 0` 時不需餘額判斷，仍寫 BR

---

### cancelBet — 取消注單

當 bet API 發生異常（如 timeout），AcewinSW 在 **1 分鐘後**發出 cancelBet，每分鐘一次，**最多重試 10 次**。AcewinSW 的 timeout 定為 **5 秒**。

> cancelBet 可於玩家離線後發送，token 可能已失效。因此同時攜帶 `userId`，本平台優先以 `userId` 識別玩家。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| 路由（本平台） | `POST /sw/acewin/cancelBet` |
| Controller | `AcewinController::cancelBet()` |

#### Request Body（AcewinSW → 本平台）

| Parameter | Type | Require | Description |
|---|---|---|---|
| `reqId` | string | Yes | 遊戲商請求識別唯一代碼（最長 50 字元） |
| `currency` | string | Yes | 貨幣名稱 |
| `game` | int | Yes | 遊戲代碼 |
| `round` | bigint | Yes | 注單唯一值（`WagersId`）；對應原始 bet 的 `round` |
| `betAmount` | double | Yes | 押注金額 |
| `winloseAmount` | double | Yes | 派彩金額 |
| `userId` | string | Yes | 玩家帳號唯一值（token 失效時用此識別玩家） |
| `token` | string | Yes | 該注單發生時的 token |

```jsonc
{
  "reqId": "unique-request-id-002",
  "currency": "THB",
  "game": 1001,
  "round": 100029127135,
  "betAmount": 10.0,
  "winloseAmount": 0.0,
  "userId": "rd4qc_01",
  "token": "dc7b7f79187bbc17cc2994965a736a9c"
}
```

#### Success Response（本平台 → AcewinSW）

```jsonc
{
  "errorCode": 0,
  "message": "OK",
  "username": "rd4qc_01",
  "currency": "THB",
  "balance": 100.0,
  "txId": null
}
```

#### 錯誤碼

| errorCode | Description |
|---|---|
| 0 | 取消成功 |
| 1 | 該注單已取消（重複 `round`，冪等回應） |
| 2 | 注單無效（`round` 不存在） |
| 3 | 參數無效（詳情放到 `message`） |
| 4 | Token 已過期或無效 |
| 5 | 其他錯誤（詳情放到 `message`） |
| 6 | 注單已成立不可取消 |

#### 實作說明

- 玩家識別優先順序：有 `userId` 用 `vendor_username` 查，無則用 `token`
- 以 `round` 查 `acewin_bet_records.bet_id`：
  - 找不到 → 回 errorCode=2
  - 已為 ROLLBACK → 回 errorCode=1
  - 已為 SETTLE（成立）→ 規格要求回 errorCode=6，**本平台未實作**：`bet` 下注結算合一、注單建立即 SETTLE，
    照字面實作會讓 cancelBet 永遠無效（見 integration.md 陷阱 3）
  - `betAmount` 與原注金不符 → 回 errorCode=3（`Cancel amount mismatch`），錢包與注單都不動
- 正常流程：`bet_records.status` → `ROLLBACK`（值=9），`bet`／`valid_bet`／`win_lose` 歸零後退款，寫 TBR
  （投注量 0，撤銷的注單不計入流水與返利，target/op_tickets#3078）

---

## Management API（本平台主動呼叫）

> 本平台以 `AgentId` + `Key` 主動向 AcewinSW 呼叫。Key 產生方式見〈簽名〉節。

### 進入遊戲（LoginWithoutRedirect）

| URL | `<AUTH_URL>/singleWallet/LoginWithoutRedirect` | Method | `GET` |
|---|---|---|---|

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `Token` | string | Yes | 本平台產生的 session token（最長 800 字元，英數字） |
| `GameId` | int | Yes | 遊戲唯一識別值 |
| `Lang` | string | Yes | UI 語系（見〈附錄 A〉） |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

#### Response Data

| Parameter | Type | Description |
|---|---|---|
| `ErrorCode` | int | `0` = 成功 |
| `Message` | string | 狀態描述 |
| `Data` | string | 遊戲入口網址（JSON encoded，需再 decode 才可使用；連結只可使用一次） |

#### 錯誤碼

| ErrorCode | Message | Description |
|---|---|---|
| 0 | Success | 成功 |
| 2 | Error Authority | 驗證失敗（Key 產生錯誤） |
| 4 | IpMapper unavailable | 權限不足 |
| 9 | api auth fail | 遊戲商向代理發送 auth 驗證失敗 |
| 9 | Site master not found | AgentId 不存在 |
| 9 | Site master mismatched | 玩家帳號與 AgentId 不匹配 |
| 18 | Game (XXX) disabled | 遊戲未開放 |
| 104 | invalid currency from auth | auth 回傳錯誤或禁用的貨幣 |
| 107 | Not single wallet | 錢包種類異常 |

---

### 強制登出玩家（KickMember）

| URL | `<AUTH_URL>/api1/KickMember` | Method | `GET` |
|---|---|---|---|

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `Account` | string | Yes | 玩家唯一識別值（對應 auth 回傳的 `username`） |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

#### 錯誤碼

| ErrorCode | Description |
|---|---|
| 0 | 成功 |
| 1 | 參數錯誤 |
| 2 | 驗證失敗 |
| 4 | 請求來源不合法 |
| 10 | 系統維護中 |
| 101 | 會員帳號不存在/不在線上 |

---

### 強制登出所有玩家（KickMemberAll）

| URL | `<AUTH_URL>/api1/KickMemberAll` | Method | `GET` |
|---|---|---|---|

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `GameId` | int | No | 遊戲唯一識別值；不傳則踢出所有會員 |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

---

### 取得注單統計（GetBetRecordSummary）

| URL | `<AUTH_URL>/api1/GetBetRecordSummary` | Method | `GET` |
|---|---|---|---|

> 查詢範圍限 **1 個月**內。

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `StartTime` | DateTime | Yes | 格式 `YYYY-MM-DDThh:mm:ss`，**UTC-4** |
| `EndTime` | DateTime | Yes | 格式 `YYYY-MM-DDThh:mm:ss`，**UTC-4** |
| `FilterAgent` | Boolean | No | `true` = 只回傳該 `AgentId` 所屬幣種；未帶 = 回傳總代理旗下各幣種分別加總 |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

#### Response Data（陣列）

| Parameter | Type | Description |
|---|---|---|
| `BetAmount` | decimal(16,4) | 投注總金額 |
| `PayoffAmount` | decimal(16,4) | 派彩總金額 |
| `WagersCount` | int | 注單數量 |
| `Currency` | string | 幣別 |
| `WinlossAmount` | decimal(16,4) | 淨值 |
| `Turnover` | decimal(16,4) | 有效投注（為 BetAmount 正數） |

---

### 查詢單筆注單（GetBetRecordByUuid）

| URL | `<AUTH_URL>/api1/GetBetRecordByUuid` | Method | `GET` |
|---|---|---|---|

> 已取消的注單無法透過此 API 查詢。

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `WagersId` | bigint | Yes | 注單唯一值（對應回調 `round`） |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

#### Response Data

| Parameter | Type | Description |
|---|---|---|
| `Account` | string | 玩家唯一識別值 |
| `WagersId` | bigint | 注單唯一值 |
| `GameId` | int | 遊戲唯一識別值 |
| `WagersTime` | DateTime | 投注時間（UTC-4） |
| `BetAmount` | decimal(16,4) | 投注金額 |
| `PayoffTime` | DateTime | 派彩時間（UTC-4） |
| `PayoffAmount` | decimal(16,4) | 派彩金額 |
| `Status` | int | `1` = 贏；`2` = 輸 |
| `SettlementTime` | DateTime | 對帳時間（UTC-4） |
| `GameCategoryId` | int | 遊戲類型（見〈附錄 D〉） |
| `Type` | int | 注單類型（見〈附錄 C〉） |
| `AgentId` | string | 玩家所屬站長唯一識別值 |

---

### 取得遊戲列表（GetGameList）

| URL | `<AUTH_URL>/api2/GetGameList` | Method | `GET` |
|---|---|---|---|

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章 |

#### Response Data（陣列）

| Parameter | Type | Description |
|---|---|---|
| `GameId` | string | 遊戲唯一識別值 |
| `name` | object | 多語言名稱：`zh-CN` / `zh-TW` / `en-US` |
| `GameCategoryId` | int | 遊戲類型（見〈附錄 D〉） |
| `Sort` | int | 推薦排序（由小至大） |

---

### 取得注單詳細結果連結（GetGameDetailUrl）

| URL | `<AUTH_URL>/api1/GetGameDetailUrl` | Method | `GET` |
|---|---|---|---|

> **簽名特例**：`GameId` 欄位在計算 `Key` 時**須忽略**，只用 `WagersId + AgentId` 計算。詳見〈簽名〉節。

#### Request

| Parameter | Type | Require | Description |
|---|---|---|---|
| `WagersId` | bigint | Yes | 注單唯一值 |
| `GameId` | string | Yes | 遊戲唯一識別值（**計算 Key 時忽略此欄位**） |
| `AgentId` | string | Yes | 代理編號 |
| `Key` | string | Yes | 動態簽章（計算時不含 `GameId`） |

#### Response Data

| Parameter | Type | Description |
|---|---|---|
| `Url` | string | 遊戲詳細結果頁面 URL |

---

## 簽名

> 適用於本平台 → AcewinSW 方向（管理 API、進遊戲）。  
> AcewinSW → 本平台的回調**不使用此簽名**，採 HTTP Basic Auth（可選）。

### 演算法總覽

| 項目 | 說明 |
|---|---|
| 演算法 | MD5 兩段式（先算 KeyG，再算 Key） |
| 時間基準 | **UTC-4** |
| 時間格式 | `yyMMd`（年末兩位 + 月兩位補零 + 日不補零） |
| Key 結構 | `{6 個任意字元}` + `MD5(請求參數串 + KeyG)` + `{6 個任意字元}` |

### Step 1 — 產生 KeyG

```
KeyG = MD5( DateTime.Now.ToString("yyMMd") + AgentId + AgentKey )
```

- `DateTime.Now` 取當下 **UTC-4** 時間
- 格式 `yyMMd`：年末兩位、月**補零**（`02`）、日**不補零**（`7`、`18`）
- 範例：UTC+7 的 `2018/9/30 10:00` → UTC-4 為 `2018/9/29 23:00` → 時間字串 `180929`

| 時間（UTC-4） | 格式化結果 |
|---|---|
| 2018/2/7 | `18027` |
| 2018/2/18 | `180218` |
| 2019/01/01 | `19011` |

### Step 2 — 組請求參數串

將所有請求參數（**依 API 文件表格欄位順序**）以 `key1=value1&key2=value2` 串起，**最後加上** `AgentId=xxx`。

```
// 以 LoginWithoutRedirect 為例（欄位順序：Token, GameId, Lang）
Token=[REDACTED]
```

### Step 3 — 計算 Key

```
md5string = MD5( 請求參數串 + KeyG )
Key = Str::random(6) + md5string + Str::random(6)
```

前後各 6 個任意字元（隨機產生）；AcewinSW 驗證時忽略任意字元，只取中間 32 字元（MD5）。

### 完整範例

**計算 KeyG**

| 項目 | 值 |
|---|---|
| AgentKey | `be8e08f95357d215921f91c6a533f74d3194de52` |
| AgentId | `abcd_RMB` |
| 加密時間（UTC-4） | `2019/01/01` → `19011` |

```
KeyG = MD5("19011abcd_RMBbe8e08f95357d215921f91c6a533f74d3194de52")
     = 9424ffdd6de016a5f90a97a55d99c717
```

**以 LoginWithoutRedirect 為例**

| 項目 | 值 |
|---|---|
| Token | `54873b0b480a532cf9fa417c72849e09` |
| GameId | `1` |
| Lang | `zh-cn` |
| AgentId | `abcd_RMB` |

```
請求參數串 = "Token=[REDACTED]"
md5string  = MD5( 請求參數串 + KeyG )
           = MD5("Token=[REDACTED]")
           = 3e4c3b321eac16f20633f683be08d237
Key = "123456" + "3e4c3b321eac16f20633f683be08d237" + "abcdef"
    = "1234563e4c3b321eac16f20633f683be08d237abcdef"
```

### PHP 實作（APIs 專案）

```php
private function getSignature(string $agentId, string $agentKey, array $params): string
{
    // UTC-4 時間，格式 yyMMd（月補零，日不補零）
    $now = Carbon::now()->timezone('-4')->format('ymj');
    $keyG = md5($now . $agentId . $agentKey);

    // 請求參數串（已含 AgentId）
    $paramString = http_build_query($params);

    // 前後各 6 個隨機字元
    return Str::random(6) . md5($paramString . $keyG) . Str::random(6);
}
```

> `Carbon::format('ymj')`：`y` = 年末兩位、`m` = 月補零、`j` = 日不補零，完全符合規格。

### 特別注意事項

| 注意點 | 說明 |
|---|---|
| GetGameDetailUrl | `GameId` 在計算 Key 時必須從 params 中排除 |
| 時區 | 嚴格使用 UTC-4；伺服器為 UTC+8 需特別換算（差距 12 小時） |
| 月份補零、日期不補零 | 最常見踩坑點：月 `02` 正確、日 `7` 正確（非 `07`） |
| AgentId 永遠在參數串末尾 | 所有 Management API 皆如此，不可提前 |

---

## 錯誤碼彙整

### 回調 API（AcewinSW → 本平台）

| errorCode | auth | bet | cancelBet | Description |
|---|---|---|---|---|
| 0 | ✓ | ✓ | ✓ | 成功 |
| 1 | — | ✓ | ✓ | 重複請求（冪等回應）/ cancelBet 已取消 |
| 2 | — | ✓ | ✓ | bet=餘額不足；cancelBet=注單不存在 |
| 3 | — | ✓ | ✓ | 參數無效 |
| 4 | ✓ | ✓ | ✓ | Token 已過期或無效 |
| 5 | ✓ | ✓ | ✓ | 其他錯誤 |
| 6 | — | — | ✓ | 注單已成立不可取消 |

### Management API（本平台 → AcewinSW）

| ErrorCode | Description |
|---|---|
| 0 | 成功 |
| 1 | 參數錯誤 |
| 2 | 驗證失敗（Key 錯誤）/ Error Authority |
| 4 | IpMapper unavailable / 請求來源不合法 |
| 9 | auth 失敗 / AgentId 不存在 / 帳號不匹配 |
| 10 | 系統維護中 |
| 18 | 遊戲未開放 |
| 101 | 會員帳號不存在/不在線上 |
| 104 | invalid currency from auth |
| 107 | Not single wallet |

---

## 附錄

### 附錄 A — 語系參數

| 語系 | 代碼 |
|---|---|
| 簡中 | `zh-cn` |
| 英語 | `en-us` |
| 泰文 | `th-th` |
| 越南 | `vi-vn` |
| 馬來 | `ms-my` |
| 西班牙語 | `es-es` |

### 附錄 B — 貨幣代碼（部分列舉）

USD / THB / MMK / INR / IDR / MYR / VND / kVND（1:1000）/ kIDR（1:1000）/ JPY / BND / SGD / PHP / AUD / KZT / BRL / TND / MXN / PKR / KHR / kKHR（1:1000）/ LAK / kLAK（1:1000）/ KRW / EUR / USDT / GBP / AED / OMR / SAR / NZD / ZAR / hMMK（1:100）…（共 60+ 種）

> `k` 開頭幣種以 1:1000 換算；`h` 開頭以 1:100 換算。代理需在傳入前自行轉換。

### 附錄 C — 注單類型

| 代碼 | 注單類型 |
|---|---|
| 1 | 一般下注 |
| 19 | FreeSpin |
| 28 | SpinBonus |
| 29 | ShotBonus |
| 30 | FreeShots |
| 31 | Jackpot_Grand |
| 32 | Jackpot_Mega |
| 33 | Jackpot_Major |
| 34 | Jackpot_Minor |
| 35 | Jackpot_Mini |

> FreeSpin 判斷：`betAmount = 0`。

### 附錄 D — 遊戲類型

| 代碼 | 遊戲類型 |
|---|---|
| 1 | Slot 類 |
| 3 | 活動 |
| 5 | 魚機類 |
| 7 | 街機類 |
| 8 | 押分機類 |

### 附錄 E — HTTP Basic Authentication（可選驗證）

AcewinSW 在 callback request header 中加入：

```
Authorization: Basic <Base64(username:password)>
```

username 與 password 由本平台提供，存放於 `vendor_infos.info.CLIENT_AUTH_ACCOUNT`，格式 `username:password`。本平台 `parseOperatorId()` 從 `Authorization` 標頭解析比對。

> 本平台以 `getVendorInfo()` 比對解出的 `username:password` 與 `CLIENT_AUTH_ACCOUNT`，不符即回 errorCode=5
> `Invalid client`。但請求**不帶** `Authorization` 時比對會被略過（只剩 IP 白名單），現況記 warning log
> `ACEWIN_get_request_missing_client_credential`；要改成拒收需先與 AcewinSW 確認回調是否一律帶憑證
> （target/op_tickets#3077，落差說明見 integration.md 陷阱 6）。

---

## Change Log（官方文件版本歷程摘要）

| 版本 | 日期 | 變更 |
|---|---|---|
| 1.8.7 | 2026-01-09 | 更新附錄 B 貨幣代碼（新增 NZD） |
| 1.8.6 | 2025-12-23 | 附錄 C 新增注單類型（ShotBonus、FreeShots） |
| 1.8.5 | 2025-12-05 | 附錄 C 新增注單類型（FreeSpin、SpinBonus） |
| 1.8.4 | 2025-11-18 | 附錄 B 新增貨幣（OMR、SAR、KHR） |
| 1.7.4 | 2024-05-24 | 補充驗證帳號 auth 回傳 HTTP Code 必為 200 |
| 1.7.0 | 2024-03-05 | 補充貨幣代碼 k 開頭規則 |
| 1.0.0 | 2023-07-11 | 第一個版本 |
