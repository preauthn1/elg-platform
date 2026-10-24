# Ace333SW — 廠商 API 規格（Seamless Wallet）

> **原始來源**
> - `001_ACE333 API Specifications 2.40.1.pdf`（最新版，2025-06-18，API version 2.40.1）
> - `003_ACE333 HTML5 API Specifications 1.12.pdf`（H5 整合，遊戲 URL 組合參考）
>
> 抓取日期：2026-06-03
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得）**。

ACE333 手機遊戲平台（老虎機、桌遊、捕魚機），本站台（vid 109，`VendorIdEnum::ACE333SW`）採 **Wallet API（單一錢包 / Seamless Wallet）** 接入。

---

## 通用約定

### 通訊格式

- **協定**：RESTful Web API
- **Content-Type**：`application/json`（POST 請求），GET 請求以 Query String 傳參
- **字元編碼**：UTF-8
- **大小寫**：URL 與參數 key 不區分大小寫；參數 value 區分大小寫
- **DateTime**：所有日期時間均為 **UTC**
- **金額精度**：小數 2 位（`decimal(15, 2)`）；Jackpot 貢獻額精度至小數 6 位（`decimal(22, 6)`）
- **資料保留期**：**14 天**

### 認證方式（Token）

所有 API 請求需在 HTTP Header 帶入 `token`（PROVIDER 配發的 API Service Token）：

```http
token: [REDACTED]
```

Wallet API 回調時，PROVIDER 也在 Header 帶相同 token，代理側須驗證。

### API 帳號資訊（由 PROVIDER 配發）

| 項目 | 說明 |
|---|---|
| `<API_SERVICE_TOKEN>` | 所有 PROVIDER API 呼叫需帶入 Header |
| `<Postfix ID>` | 玩家登入格式為 `accountID@postfixID`（最多 6 碼英數） |
| `<API_SERVCE_BASE_URL>` | Integration API（Operations）Base URL |
| `<API_DATAFEED_BASE_URL>` | Integration API（Data Feeds）Base URL |

### Staging 環境端點

| 類型 | URL |
|---|---|
| Integration API（Operations） | `https://example.invalid/` |
| Integration API（Data Feeds） | `https://example.invalid/` |
| 遊戲 App（Android） | `http://example.invalid:8080/` |

### Wallet API 通用回應格式（代理 → PROVIDER）

```json
{
  "transactionID": "<OPERATOR_TRANSACTION_ID>",
  "balance": 100.00,
  "error": "0",
  "description": ""
}
```

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transactionID` | string(100) | ✅ | 代理側產生的唯一交易 ID |
| `balance` | decimal(15, 2) | ✅ | 操作後玩家最新餘額 |
| `error` | string(10) | ✅ | `"0"` 成功；其他為 OPERATOR 錯誤碼 |
| `description` | string(250) | 選填 | 錯誤說明 |

---

## 玩家帳號格式

- 玩家在 ACE333 遊戲 App 登入 User ID 格式：`accountID@postfixID`
- Wallet API 回調時 `userName` 為純 `accountID`，**不含 `@postfixID`**
- `api/createplayer` 的 `accountID` 限英數及底線，底線不可為第一個字元

---

## Wallet API（PROVIDER → 代理回調）

路由前綴：`ace333sw/`（`routes/external/sw.php`）。

### 端點清單

| 方法名稱 | HTTP | Path | 說明 |
|---|---|---|---|
| Authenticate | POST | `api/authenticate` | 玩家登入驗證 + 取得餘額 |
| Bet | POST | `api/bet` | 下注（扣款） |
| BetResult | POST | `api/betresult` | 派彩（加款） |
| Refund | POST | `api/refund` | 取消下注（退款） |
| GetBalance | GET | `api/getbalance` | 查詢玩家餘額 |
| GetVersion | GET | `api/getversion` | 查詢代理 Wallet API 版本 |
| JackpotWin | POST | `api/jackpotwin` | Jackpot 中獎加款 |
| FundRequest | POST | `api/fundrequest` | 快節奏遊戲：從代理預先撥款 |
| FundReturn | POST | `api/fundreturn` | 快節奏遊戲：離開後退還餘額 |
| FundBetResult | POST | `api/fundbetresult` | 快節奏遊戲：通知下注結果（不更新餘額） |

### `roundDetails` 驗收規則

| roundDetails | 意義 | 對 Bet 的處理 |
|---|---|---|
| `spin` | 老虎機一般押注 | 扣款 |
| `bet` | 非老虎機一般押注 | 扣款 |
| `gamble` | Gamble 補充遊戲 | 扣款 |
| `free spin` | 免費轉（betAmount 為參考值） | **不扣款** |
| `jackpot` | Jackpot 中獎 | 於 JackpotWin 加款 |
| `dollar ball` | Dollar Ball 側注 | 扣款 |
| `multiple:<N>` | Bet Multiple（多倍押注） | 扣款 |
| `tournament` | 錦標賽獎勵（v2.40.1 新增） | 於 JackpotWin 加款 |
| `cash grab` | Cash Grab 活動獎勵（v2.40.1 新增） | 於 JackpotWin 加款 |
| `""（空字串）` | 非遊戲輪次 | 不影響餘額 |

---

### 1. Authenticate

玩家登入 ACE333 遊戲時 PROVIDER 呼叫，驗證玩家身份並取得初始餘額。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `userName` | string(20) | ✅ | 純 accountID（不含 @postfixID） |
| `password` | string(20) | ✅ | 玩家在代理系統的密碼 |

#### 回應（代理 → PROVIDER）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `playerID` | int | ✅ | ACE333 側玩家唯一 ID（`api/createplayer` 回傳） |
| `balance` | decimal(15, 2) | ✅ | 玩家在代理系統的最新餘額 |
| `error` | string(10) | ✅ | `0` 成功；非 0 為 OPERATOR 錯誤碼 |
| `description` | string(250) | 選填 | 錯誤說明 |

---

### 2. Bet（BetRequest）

玩家下注，PROVIDER 要求代理扣款。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | ✅ | PROVIDER 遊戲代碼 |
| `playSessionID` | string(100) | ✅ | 局號（同一局可能有多筆 BetRequest） |
| `referenceID` | string(100) | ✅ | PROVIDER 側本筆交易唯一 ID |
| `betAmount` | decimal(15, 2) | ✅ | 下注金額（>= 0） |
| `roundDetails` | string(50) | 選填 | 本局類型（見上方 roundDetails 表） |
| `created` | datetime | ✅ | 下注時間（UTC，v2.40 新增為必填） |
| `platform` | string(10) | 選填 | `MOBILE`/`WEB`/`DOWNLOAD` |

> `free spin` 時 `betAmount` 為參考值，**代理不應扣款**。

#### 回應（代理 → PROVIDER）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transactionID` | string(100) | ✅ | 代理側交易 ID |
| `balance` | decimal(15, 2) | ✅ | 扣款後玩家最新餘額 |
| `error` | string(10) | ✅ | `0` 成功；`1` 餘額不足 |
| `description` | string(250) | 選填 | 錯誤說明 |

---

### 3. BetResult

本局結束後派彩，代理將 winAmount 加回玩家餘額。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | ✅ | PROVIDER 遊戲代碼 |
| `playSessionID` | string(100) | ✅ | 局號（與 Bet 相同） |
| `referenceID` | string(100) | ✅ | PROVIDER 側本筆交易唯一 ID |
| `winAmount` | decimal(15, 2) | ✅ | 派彩金額（輸則為 0） |
| `balance` | decimal(15,2) | ✅ | Bet 後扣款剩餘（供參考，代理不應依此更新餘額） |
| `jackpotModule` | int | ✅ | Jackpot 模組代碼（349/350/351，見本文件附錄） |
| `jackpotContributionAmt` | decimal(22, 6) | ✅ | 本局 Jackpot 貢獻額（非 Jackpot 遊戲為 0） |
| `resultUrl` | string(500) | ✅ | 查看本局結果的 URL |
| `roundDetails` | string(50) | 選填 | 本局類型 |
| `created` | datetime | ✅ | 結果產生時間（UTC） |
| `platform` | string(10) | 選填 | `MOBILE`/`WEB`/`DOWNLOAD` |

#### 回應（代理 → PROVIDER）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transactionID` | string(100) | ✅ | 代理側交易 ID |
| `balance` | decimal(15, 2) | ✅ | 派彩後玩家最新餘額 |
| `error` | string(10) | ✅ | `0` 成功 |
| `description` | string(250) | 選填 | 錯誤說明 |

---

### 4. Refund

遊戲因故取消或中斷，PROVIDER 退還 Bet 扣款。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | ✅ | PROVIDER 遊戲代碼 |
| `playSessionID` | string(100) | ✅ | 局號 |
| `referenceID` | string(100) | ✅ | 本筆 Refund 的唯一 ID |
| `betRefundID` | string(100) | ✅ | 指向要退款的 BetRequest referenceID（v2.39 新增） |
| `betAmount` | decimal(15, 2) | ✅ | 退款金額 |
| `created` | datetime | ✅ | 退款時間（UTC） |
| `platform` | string(10) | 選填 | `MOBILE`/`WEB`/`DOWNLOAD` |

#### 冪等性要求（文件原文）

> "All Refund requests shall be **idempotent**, i.e., a request with the same referenceID received twice or more **shall be processed once only**."
>
> 代理需驗證：
> 1. 相同 `referenceID + playSessionID` 只處理一次
> 2. 必須有對應的 BetRequest（相同 `playSessionID`）；若無，回傳 error 9
> 3. `betRefundID` 指向的 BetRequest referenceID 需存在；若不存在，回傳 error 9

#### 回應（代理 → PROVIDER）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transactionID` | string(100) | ✅ | 代理側交易 ID |
| `balance` | decimal(15, 2) | ✅ | 退款後玩家最新餘額 |
| `error` | string(10) | ✅ | `0` 成功；`9` 找不到對應 BetRequest |
| `description` | string(250) | 選填 | 錯誤說明 |

---

### 5. GetBalance

PROVIDER 查詢玩家在代理側的最新餘額。

| HTTP | `GET api/getbalance` |
|---|---|

#### 請求（PROVIDER → 代理，Query String）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | 選填 | ACE333 側玩家 ID |

#### 回應

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `balance` | decimal(15, 2) | ✅ | 玩家最新餘額 |
| `error` | string(10) | ✅ | `0` 成功 |
| `description` | string(250) | 選填 | 錯誤說明 |

---

### 6. GetVersion

PROVIDER 查詢代理目前實作的 Wallet API 版本。

| HTTP | `GET api/getversion` |
|---|---|

無參數（token 亦不必填）。

#### 回應

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `version` | string(15) | ✅ | 目前實作版本號，如 `"2.5"` |

---

### 7. JackpotWin

玩家觸發 Jackpot 時 PROVIDER 呼叫，發放彩金（與 BetResult 分開）。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | 選填 | PROVIDER 遊戲代碼（Lobby Jackpot 可為空） |
| `playSessionID` | string(100) | 選填 | 局號（Lobby Jackpot 可為空字串） |
| `referenceID` | string(100) | ✅ | 本筆 JackpotWin 唯一 ID |
| `winAmount` | decimal(15, 2) | ✅ | Jackpot 派彩金額 |
| `jackpotModule` | int | ✅ | Jackpot 模組代碼 |
| `jackpotContributionAmt` | decimal(22, 6) | ✅ | Jackpot 貢獻額（中獎時為 0） |
| `resultUrl` | string(500) | 選填 | 結果 URL（Jackpot 為空字串） |
| `roundDetails` | string(50) | 選填 | `jackpot` / `tournament` / `cash grab` |
| `created` | datetime | ✅ | 中獎時間（UTC） |
| `platform` | string(10) | 選填 | `MOBILE`/`WEB`/`DOWNLOAD` |

**`roundDetails` 特殊情境（v2.40.1）**

| roundDetails | 情境 | gameID |
|---|---|---|
| `jackpot` | 一般進行中遊戲 Jackpot | 有效遊戲 ID |
| `jackpot` | Lobby Jackpot（Angpow / Lucky Bonus） | `""` 或 `"0"` |
| `tournament` | 錦標賽獎勵 | `"0"` |
| `cash grab` | Cash Grab 活動獎勵 | `"0"` |

---

### 8. FundRequest

快節奏遊戲專用：玩家餘額低於閾值時 PROVIDER 向代理預先申請資金。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | 選填 | 遊戲代碼 |
| `referenceID` | string(100) | ✅ | 本筆 FundRequest 唯一 ID |
| `requestAmount` | decimal(15, 2) | ✅ | 申請金額 |
| `platform` | string(10) | 選填 | 平台類型 |
| `type` | int | ✅ | 信用類型（`4500`=Normal Credit，供記錄用） |

---

### 9. FundReturn

快節奏遊戲專用：玩家離開時 PROVIDER 退還剩餘餘額。若 FundReturn 失敗，可觸發 Integration API 的 `Withdraw` 作為保險機制。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | 選填 | 遊戲代碼 |
| `referenceID` | string(100) | ✅ | 本筆 FundReturn 唯一 ID |
| `returnAmount` | decimal(15, 2) | ✅ | 退還金額 |
| `platform` | string(10) | 選填 | 平台類型 |
| `type` | int | ✅ | 信用類型（`4500`=Normal Credit） |

---

### 10. FundBetResult

快節奏遊戲專用：純通知下注結果，**代理不應更新玩家餘額**（餘額由 FundRequest/FundReturn 管理）。

#### 請求（PROVIDER → 代理）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | string(500) | ✅ | HTTP Header |
| `playerID` | int | ✅ | ACE333 側玩家 ID |
| `gameID` | string(20) | ✅ | 遊戲代碼 |
| `playSessionID` | string(100) | ✅ | 局號 |
| `referenceID` | string(100) | ✅ | 本筆交易唯一 ID |
| `betAmount` | decimal(15, 2) | ✅ | 下注金額 |
| `winAmount` | decimal(15, 2) | ✅ | 派彩金額 |
| `jackpotModule` | int | ✅ | Jackpot 模組代碼 |
| `jackpotContributionAmt` | decimal(22, 6) | ✅ | Jackpot 貢獻額 |
| `resultUrl` | string(500) | ✅ | 結果查看 URL |
| `roundDetails` | string(50) | 選填 | 本局類型 |
| `platform` | string(10) | 選填 | 平台類型 |

#### 回應

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transactionID` | string(100) | ✅ | 代理側交易 ID |
| `error` | string(10) | ✅ | `0` 成功（**無需回傳 balance**） |
| `description` | string(250) | 選填 | 錯誤說明 |

---

## Integration API（代理 → PROVIDER 主動呼叫）

### 取得遊戲 URL（H5 登入流程）

遊戲 URL 取得需先向 ACE333 取得 `accessToken`：

1. 組合查詢字串（`CLIENT_H5_SECRET_KEY`/`time`/`userName`/`password`/`currency`/`nickName`，以 `CLIENT_H5_DEKIMITER` 分隔）
2. DES-CBC 加密（key 與 IV 均為 `CLIENT_H5_ENCRYPT_KEY`）→ Base64 + URL encode → `q`
3. MD5 簽章：`md5(queryString + CLIENT_H5_MD5_KEY + time + CLIENT_H5_SECRET_KEY)` → `s`
4. POST `{CLIENT_H5_AUTH_URL}/api/Acc/Login` 帶 `{ q, s, accessToken: [REDACTED] }`
5. 回應 `status == 1` 取得 `actk`

```
{CLIENT_GAME_URL}/{gameName}/?actk={accessToken}&lang={langCode}&userName={vendor_username}&returnUrl={referer}
```

語言代碼：

| LanguageEnum | langCode |
|---|---|
| ZH_TW / ZH_CN | 2 |
| TH_TH | 3 |
| ID_ID | 5 |
| VI_VN | 7 |
| 其他（英文） | 1 |

### 常用 Integration API 端點

| API Method | HTTP | Path | 說明 |
|---|---|---|---|
| CreatePlayer | POST | `api/createplayer` | 建立玩家（首次接入必呼叫） |
| UpdatePlayer | POST | `api/updateplayer` | 更新玩家資料 |
| GetBalance | GET | `api/getbalance` | 查詢 ACE333 側餘額 |
| GameList | GET | `api/gamelist` | 查詢可用遊戲清單 |
| GetVersion | GET | `api/getversion` | 查詢 Integration API 版本 |
| CheckOrder | GET | `api/checkorder` | 查 TopUp / Withdraw 交易狀態 |
| LogOut | POST | `api/logout` | 強制登出玩家 |
| CheckPlayer | GET | `api/checkplayer` | 以帳號查詢 playerID（v2.38 新增） |
| GetTimepoint | GET | `api/gettimepoint` | UTC DateTime → Timepoint |

### Data Feeds（拉取報表）

| API Method | HTTP | Path | 說明 |
|---|---|---|---|
| PlaySessions | GET | `api/playsessions` | 查詢遊戲 session 紀錄（CSV） |
| FailedTransactions | GET | `api/failedtransactions` | 查詢未成功推送的交易（CSV） |
| CheckPlaySession | GET | `api/checkplaysession` | 以 referenceID 查單一 session（JSON） |

Data Feeds 機制：每次請求最多拉取 **1 小時**；回應第一行為新 Timepoint；最多查 14 天；頻率上限：**每 5 分鐘最多 10 次**。

---

## 對帳補漏：FailedTransactions 機制

| methodType | 對應回調 | 代理動作 |
|---|---|---|
| `BR` | BetResult | 確認後加款 |
| `RF` | Refund | 確認後退款 |
| `JW` | JackpotWin | 確認後加款 |
| `FT` | FundReturn | 確認後加款 |
| `BQ` | BetRequest | 若未收到 BetResult，退款 |
| `FQ` | FundRequest | 若已扣款但未記錄，退款 |
| `FB` | FundBetResult | **不更新餘額** |

---

## 支援幣別（Appendix D）

BDT、CNY、HKD、IDR、KHR、MMK、MYR、SGD、THB、USD、VND、AUD（全部 1:1）。

---

## OPERATOR 錯誤碼（代理回調回傳）

| Code | 說明 |
|---|---|
| 0 | 成功 |
| 1 | 餘額不足（Bet 請求回傳） |
| 2 | 找不到玩家 |
| 3 | 不允許下注 |
| 4 | 認證失敗 |
| 6 | 帳號已停用 |
| 8 | 遊戲不存在或已停用 |
| 9 | 拒絕的交易（含 Refund 無對應 BetRequest） |
| 100 | 代理系統內部錯誤 |

---

## 附錄 B — Jackpot 模組代碼

| Jackpot Module | 說明 |
|---|---|
| 349 | None（無 Jackpot） |
| 350 | Progressive Jackpot（JP Company）：由下注金額共同貢獻 |
| 351 | Position-Taking Jackpot（JP PT） |

---

## 附錄 C — Multiplayer 遊戲（Bet Multiple）

| Game Code | Game ID | Game Name |
|---|---|---|
| 00002003 | 2003 | ThreeDukesMP |
| 00002004 | 2004 | BullFightMP |
| 00002005 | 2005 | DealerPokerMP |

`roundDetails` 帶 `bet,multiple:<N>`；`betAmount` 含普通注（1）加 Bet Multiple（N）；`winAmount` 含實際派彩加 Bet Multiple 退款（若未輸）。

---

## 路由對應（本專案）

```php
Route::prefix('ace333sw')->group(function () {
    Route::post('authenticate', [Ace333SWController::class, 'authenticate']);
    Route::post('bet',         [Ace333SWController::class, 'bet']);
    Route::post('betresult',   [Ace333SWController::class, 'settle']);
    Route::post('jackpotwin',  [Ace333SWController::class, 'jackpotWin']);
    Route::get( 'getbalance',  [Ace333SWController::class, 'getBalance']);
    Route::post('refund',      [Ace333SWController::class, 'refund']);
    // FundRequest / FundReturn / FundBetResult / GetVersion — 尚未實作
});
```
