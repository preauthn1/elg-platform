# MegawinSW — API 規格

> 來源：
> - 原始 README（`_MW电子_无缝_API_1.0.8.pdf`，92 頁，版本 V1.0 ~ V1.0.8；含回調 API 細節與 RSA+AES-128-ECB 加密演算法）
>
> 整合日期：2026-06-03
> 錢包模式：**Single Wallet（單一錢包 / 無縫錢包）**
> Vendor ID：**112（MegawinSW）**

MegawinSW（MW 電子）為電子遊戲（老虎機、捕魚機等）遊戲商，採無縫錢包（Seamless）模式接入，vid 112。

---

## 通用約定

| 項目 | 值 |
|---|---|
| HTTP Method | 所有 API 一律 `POST` |
| Content-Type | `application/json` |
| 驗證方式 | IP 白名單 + RSA+AES-128-ECB 混合加密（見下方簽名章節） |
| 時區 | **北京時間（UTC+8）**，文件明確標注「服務器驗證的時間是北京時間」 |
| 時間格式 | `yyyy-MM-dd HH:mm:ss`（日期 `yyyy-MM-dd`；時間戳使用 Unix timestamp 精確到秒） |
| Base URL | **動態取得**（須先呼叫 `Domain` API）；不可寫死，存於 `vendor_infos.info.SITE_URL` |
| HTTP 回應碼 | 一律 200；成功以 body `ret == "0000"` 判斷 |

### 請求結構（EC → MW 與 MW → EC）

所有請求 Body 均為：

```jsonc
{
  "key":  "<RSA 加密後的 AESKEY，Base64>",
  "data": "<AESKEY 加密後的 JSON，Base64>"
}
```

EC → MW Header 額外帶：

```jsonc
{ "method": "<API名稱>", "siteId": "<站點ID>" }
```

MW → EC（Callback）Header 額外帶：

```jsonc
{ "method": "<API名稱>", "mwCode": "<MW指派的識別碼>" }
```

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功；其他代碼見錯誤碼章節 |
| `msg` | string | 失敗時的錯誤描述 |

### MW 三層組織架構

| 概念 | 說明 |
|---|---|
| **Site** | 站點最高層；`siteId` 由 MW 接入時提供，不可自行更改 |
| **Merchant（代理）** | Site 下的代理；`merchantId` 可在 MW 後台自行新增 |
| **Firm（渠道）** | Merchant 下的渠道，對應一種幣別；`firmId` 可在 MW 後台選擇幣別新增 |

---

## 簽名（RSA + AES-128-ECB 混合加密）

雙向都使用 RSA 傳遞 AES 金鑰、AES-128-ECB 加密資料。本專案封裝於 `app/Vendors/MegawinSW/Externals/CipherService.php`。

### EC → MW（主動呼叫 MW）

1. 產生隨機 `AESKEY`（16 bytes）
2. 以 **MW 的 RSA 公鑰** 加密 `AESKEY`，Base64 → `key`
3. 以 `AESKEY` 做 AES-128-ECB 加密 JSON payload，Base64 → `data`
4. 送出 `{ "key": "...", "data": "..." }`

### MW → EC（回調解密）

1. MW 以 **EC 的 RSA 公鑰** 加密 AESKEY，帶在回調 body `key` 欄位
2. EC 用**自己的 RSA 私鑰**解密 `key` 得 AESKEY
3. 用 AESKEY 做 AES-128-ECB 解密 `data` 得 JSON payload
4. EC 回傳的 response 同樣需加密（以 MW 公鑰加密 AESKEY）

`CipherService::encrypt($payload, $mwPublicKey)` / `CipherService::decrypt($key, $data, $ecPrivateKey)` 封裝以上流程。

> ⚠️ EC 回調錯誤時（如 IP 不合法），回應是否仍需加密——文件未說明，實作 fallback 回純文字 JSON，待向 MW 確認。

---

## 無縫錢包接入流程

### 整站接入（進大廳）

```
EC Server                          MWG Server
    │── Oauth（jumpType=0）────────────▶│  取得大廳網址
    │◀── interface2 ──────────────────── │
    │（用戶進入遊戲）                     │
    │◀── AuthenticateToken ─────────────│  MW 確認用戶身份
    │─── ret:0000 + userData ──────────▶│
    │◀── GetBalance ────────────────────│  MW 取得用戶餘額
    │─── userGold ─────────────────────▶│
```

### 下注流程

```
Client            MWG Server            EC Server
  │── 下注請求 ──▶│                         │
  │               │── PlaceBet ───────────▶│  預扣金額
  │               │◀── wagerDataResp ───────│
  │               │（計算結果）              │
  │               │── SettleBet ──────────▶│  結算（正式增減餘額）
  │               │◀── wagerDataResp ───────│
  │◀── 結果通知 ──│                         │
```

若用戶取消：MW 改呼叫 `UnsettleBet` 代替 `SettleBet`，EC 需退回預扣金額。

---

## EC 主動呼叫 MW 的 API

### Domain — 取得動態 Base URL

**Endpoint**：`POST {SITE_URL}/as-lobby/api/nmw/common/Domain`（路徑固定，不依賴動態 domain）

#### Request Data

| 欄位 | 必填 | 說明 |
|---|---|---|
| `timestamp` | TRUE | Unix 時間戳（北京時間，精確到秒） |
| `lang` | FALSE | 語言，預設 `cn` |

#### Response Data

| 欄位 | 說明 |
|---|---|
| `ret` | `"0000"` 成功 |
| `domain` | 平台地址，後續所有 API 使用此 domain（含 `as-lobby` 路徑前綴） |

---

### Oauth — 取得遊戲/大廳網址（`getGameUrl` 對應）

**Endpoint**：`POST {domain}/api/nmw/common/Oauth`

#### Request Data

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `uid` | TRUE | string | EC 用戶唯一識別，區分大小寫，長度 < 32 字元 |
| `utoken` | TRUE | string | EC 用戶授權碼，授權後不可變更，**固定 32 字元** |
| `firmId` | TRUE | number | 渠道 ID |
| `currency` | TRUE | string | 幣別（須與 `firmId` 一致） |
| `clientDate` | TRUE | string | EC 發起時間，`yyyy-MM-dd HH:mm:ss` |
| `lang` | FALSE | string | 語言，預設 `cn` |
| `jumpType` | FALSE | number | 跳轉頁面：`0` 遊戲大廳（預設）、`1` 查詢頁面 |
| `gameId` | FALSE | number | 僅接入單一遊戲時傳入（透過 GameInfo 獲得） |
| `isssl` | FALSE | string(1) | 是否 HTTPS：`0`=false、`1`=true，預設 `1` |

#### Response Data

| 欄位 | 說明 |
|---|---|
| `interface2` | 完整登入網址（直接使用，V1.0.4 新增，推薦使用） |
| `interface` | 舊版網址（需拼接 domain，已不推薦） |

> `uid` 由 EC 管理；`utoken` 由 EC 生成，每個用戶獨立，授權後不可再變更。`gameId` 透過 `GameInfo` API 取得，帶入直接返回該遊戲頁面。

---

### GameInfo — 取得遊戲列表

**Endpoint**：`POST {domain}/api/nmw/common/GameInfo`

#### Request Data

| 欄位 | 必填 | 說明 |
|---|---|---|
| `timestamp` | TRUE | Unix 時間戳 |
| `lang` | FALSE | 語言，預設 `cn` |
| `deviceType` | FALSE | 平台：`0` H5（預設） |

#### Response Data（`games[]`）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gameId` | number | 遊戲 ID |
| `gameName` | string | 遊戲名稱 |
| `gameIcon` | string | 遊戲小圖示 URL |
| `gameIcon2` | string | 懸停圖示（大圖）URL |
| `gameState` | number | 維護狀態：`0` 運營中、`-1` 維護、`1` 關閉 |
| `gameStatus` | number | 遊戲狀態：`0` 一般、`1` 熱門、`2` 推薦、`3` 新遊戲 |
| `beginTime` | number | 開始時間（0~23），與 `endTime` 同為 0 代表 24 小時開放 |
| `endTime` | number | 結束時間（0~23） |
| `gameRuleUrl` | string | 遊戲規則頁 URL |
| `gameCategory` | number | 遊戲分類：`0` 活動、`1` 快速、`2` 小遊戲、`3` 消除類、`4` 街機、`5` 老虎機、`6` 桌面、`7` 捕魚（V1.0.8 新增） |

> 建議每週更新一次遊戲列表，不需每次用戶登入都重新取得。

---

### KickUser — 踢出用戶

**Endpoint**：`POST {domain}/api/nmw/common/KickUser`

#### Request Data

| 欄位 | 必填 | 說明 |
|---|---|---|
| `uid` | TRUE | 用戶 ID |
| `firmId` | TRUE | 渠道 ID |

#### Response Data

`ret`、`msg`

> 備注：回傳成功表示 MW 找到用戶並嘗試踢出，不代表確實踢出。

---

### SiteUserGameLog — 取得注單記錄（對帳用）

**Endpoint**：`POST {domain(as-service)}/api/nmw/common/SiteUserGameLog`

> 注意：此 API 的 domain 需將 `as-lobby` 替換為 `as-service`。

#### Request Data

| 欄位 | 必填 | 說明 |
|---|---|---|
| `beginTime` | TRUE | 開始時間（`yyyy-MM-dd HH:mm:ss`） |
| `endTime` | TRUE | 結束時間（`yyyy-MM-dd HH:mm:ss`） |
| `page` | TRUE | 頁碼，從 1 開始 |
| `merchantId` | FALSE | 指定代理時傳入 |
| `firmId` | FALSE | 指定渠道時傳入 |
| `lang` | FALSE | 語言 |

**查詢限制**：
- 僅可查詢 **七天內**的資料
- 時間區間僅可查詢 **五分鐘以內**的資料
- 頁數超出時回傳 `ret: "2015"`（已到最後一頁）

#### Response Data（`userGameLogs[]`）

| 欄位 | 說明 |
|---|---|
| `wagerId` | 下注單號（唯一） |
| `gameRoundId` | 下注局號（唯一），對應 PlaceBet/SettleBet/UnsettleBet 的 `gameRoundId` |
| `uid` | 用戶 ID |
| `gameId` | 遊戲 ID（轉入訂單 `gameId=-1`；轉出訂單 `gameId=-2`） |
| `gameType` | 遊戲類型：`0` 正常、`1` 免費遊戲、`10` 比倍、`5` 購買特色遊戲 |
| `playMoney` | 玩分（精確到小數點後兩位） |
| `winMoney` | 贏分（精確到小數點後兩位） |
| `initBet` | 原始押注（精確到小數點後兩位） |
| `commission` | 時間內抽水（精確到小數點後兩位） |
| `logDate` | 記錄時間（`yyyy-MM-dd HH:mm:ss`，北京時間） |
| `currency` | 幣別 |
| `category` | 遊戲類型：`1` 捕魚、`2` 棋牌、`3` 電子遊戲機 |

---

## 回調 API（MW → EC）

> 以下 endpoint 由 EC 實作並提供給 MW 呼叫，路由見 `routes/external/sw.php`（prefix: `megawin`）。
> 解密流程見上方「簽名」章節。

### MW 回調路由對應

| API 名稱 | EC Endpoint | Controller 方法 |
|---|---|---|
| AuthenticateToken | `POST /megawin/AuthenticateToken` | `authenticateToken()` |
| PlaceBet | `POST /megawin/PlaceBet` | `placeBet()` |
| SettleBet | `POST /megawin/SettleBet` | `settleBet()` |
| UnsettleBet | `POST /megawin/UnsettleBet` | `unsettleBet()` |
| GetBalance | `POST /megawin/GetBalance` | `getBalance()` |

---

### AuthenticateToken — 驗證 Token

#### 解密後 Request Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `utoken` | string | 玩家授權碼（對應 Oauth 時的 `utoken`） |
| `mwCode` | string | MW 商戶識別 |

#### 解密後 Response Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功 |
| `userData.uid` | string | 玩家 ID |
| `userData.uname` | string | 玩家名稱 |
| `userData.currency` | string | 幣別 |
| `userData.userGold` | decimal | 玩家餘額（已乘 rate） |
| `userData.userTest` | string | 是否測試帳號（`Y`/`N`） |

---

### PlaceBet — 投注

> `wagerData` 為陣列，**一次請求可能帶多筆**，需逐筆處理。

#### 解密後 Request Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `mwCode` | string | 商戶識別 |
| `wagerData` | array | 投注資料陣列（可多筆） |
| `wagerData[].wagerId` | string | 注單唯一識別（冪等 key） |
| `wagerData[].gameId` | number | 遊戲代號 |
| `wagerData[].gameRoundId` | string | 局號 |
| `wagerData[].betAmount` | decimal | 投注金額（幣值單位，需 ÷rate） |
| `wagerData[].addDate` | string | 投注時間（UTC+8） |

#### 解密後 Response Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功 |
| `wagerDataResp[].wagerId` | string | 注單識別（回傳原值） |
| `wagerDataResp[].userGold` | decimal | 扣款後玩家餘額（已乘 rate） |

---

### SettleBet — 結算

> `wagerData` 為陣列，**一次請求可能帶多筆**，需逐筆處理。

#### 解密後 Request Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `mwCode` | string | 商戶識別 |
| `wagerData` | array | 結算資料陣列（可多筆） |
| `wagerData[].wagerId` | string | 注單唯一識別（對應 PlaceBet 的 wagerId） |
| `wagerData[].gameId` | number | 遊戲代號 |
| `wagerData[].gameRoundId` | string | 局號 |
| `wagerData[].betAmount` | decimal | 投注金額 |
| `wagerData[].payoutAmount` | decimal | 派彩金額（需 ÷rate） |
| `wagerData[].result` | string | 結果：`L`=輸、`W`=贏、`D`=和、`N`=未結算 |
| `wagerData[].orderDate` | string | 結算時間（UTC+8） |

#### 解密後 Response Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功 |
| `wagerDataResp[].wagerId` | string | 注單識別（回傳原值） |
| `wagerDataResp[].userGold` | decimal | 結算後玩家餘額（已乘 rate） |

---

### UnsettleBet — 結算撤銷

> `wagerData` 為陣列，**一次請求可能帶多筆**，需逐筆處理。

#### 解密後 Request Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `mwCode` | string | 商戶識別 |
| `wagerData` | array | 撤銷資料陣列（可多筆） |
| `wagerData[].wagerId` | string | 注單唯一識別 |
| `wagerData[].gameId` | number | 遊戲代號 |
| `wagerData[].gameRoundId` | string | 局號 |
| `wagerData[].payoutAmount` | decimal | 需撤銷的派彩金額 |

#### 解密後 Response Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功 |
| `wagerDataResp[].wagerId` | string | 注單識別（回傳原值） |
| `wagerDataResp[].userGold` | decimal | 撤銷後玩家餘額（已乘 rate） |

---

### GetBalance — 取得餘額

#### 解密後 Request Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `mwCode` | string | 商戶識別 |
| `utoken` | string | 玩家授權碼 |

#### 解密後 Response Payload

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ret` | string | `"0000"` 成功 |
| `userGold` | decimal | 玩家當前餘額（已乘 rate） |

---

## 錯誤碼

| 代碼 | 代碼訊息 | 說明 |
|---|---|---|
| `2000` | 參數異常 | 空值/NULL、格式錯誤，必填項目未填或格式有誤 |
| `2001` | Domain 取得失敗 | 請通知 MW 端確認 |
| `2002` | 用戶不存在 | `uid`、`utoken` 輸入錯誤 |
| `2003` | 站點信息未配置 | `siteId` 輸入錯誤 |
| `2004` | 代理信息未配置 | `merchantId` 輸入錯誤 |
| `2005` | 渠道信息未配置 | `firmId` 輸入錯誤 |
| `2006` | 當前帳號被禁用 | 用戶被凍結 |
| `2007` | 修改 Logo 功能禁用 | |
| `2008` | 解密數據錯誤 | 確認 EC 私鑰是否輸入錯誤；提供加密 Key、Data 給 MW 確認 |
| `2015` | 已到最後一頁 | `SiteUserGameLog` 分頁已無更多資料 |
| `2100` | 轉帳功能維護中 | 站點關閉轉帳 |
| `2101` | 轉帳訂單處理中 | 同筆訂單收到多次時發生 |
| `2102` | 轉帳貨幣失敗 | 約 1 秒後重送，每次重送需重新產生訂單號 |
| `2103` | 訂單已存在 | 每次請求都需要產生新訂單號 |
| `2104` | 訂單不存在 | 找不到訂單 |
| `2105` | 金額不足 | 用戶金額不足 |
| `2106` | 操作額度超出上限 | 轉入/轉出金額超過限制 |
| `9999` | 系統繁忙 | 約 1 秒後重送，若多次失敗請通知 MW 端確認 |

---

## 語言代碼

| 語言 | MW `lang` 值 |
|---|---|
| 簡體中文 | `cn`（預設） |
| 繁體中文 | `hk` |
| 英文 | `en` |
| 泰文 | `th` |
| 日文 | `jp`（V1.0.5 新增） |
| 葡萄牙文 | `pt`（V1.0.5 新增） |
| 西班牙文 | `es`（V1.0.5 新增） |

> 完整語言與貨幣列表請至 MW 後台【其他資訊】>【支援語言/支援貨幣(無縫)】查看。

---

## Change Log

| 版本 | 日期 | 變更 |
|---|---|---|
| V1.0 | 2021/12/03 | 第一次版本 |
| V1.0.1 | 2022/12/21 | `SiteUserGameLog` 新增 `firmId` 與 `merchantId` |
| V1.0.2 | 2023/02/01 | 新增貨幣：墨西哥比索 |
| V1.0.3 | 2023/03/28 | `WagerUrl` 新增 `timeZone`；`SiteUserGameLog` 回傳新增 `gameRoundId`；新增 `GameRoundUrl`、`GetSlotBonusGameInfo` |
| V1.0.4 | 2023/06/14 | `Oauth` 新增 `interface2`；`WagerUrl` 新增 `wagerUrl2`；`InfoUrl` 新增 `infoUrl2`；`GameRoundUrl` 新增 `gameRoundUrl2` |
| V1.0.5 | 2023/08/23 | 新增語言：日文、葡萄牙文、西班牙文 |
| V1.0.6 | 2023/10/11 | 語言列表與貨幣列表移置 MW 後台顯示 |
| V1.0.7 | 2024/03/06 | `SiteUserGameLog` 的 `gameType` 新增購買特色遊戲；`GetBalance` 新增 `utoken` 欄位 |
| V1.0.8 | 2024/09/04 | `GameInfo` 新增 `gameCategory` 參數；調整下注與結果確認流程圖 |
