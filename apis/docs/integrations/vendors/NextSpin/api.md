# NextSpin — API 規格

> 來源：公開文件站 <https://example.invalid/cn.php>（WebFetch 摘取）；交叉驗證 `app/Vendors/NextSpin/Driver.php`、`NextSpinController.php`
> 整合日期：2026-06-03
> 錢包模式：Transfer Wallet（轉帳錢包）
> Vendor ID：48（`VendorIdEnum::NS`）

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`（callback 除外）
- **Content-Type**：`application/json`
- **字元編碼**：UTF-8

### 通用請求 Header

| Header | 必填 | 範例值 | 說明 |
|---|---|---|---|
| `Authorization` | ✅ | `Bearer {MemberAccessToken}` | 玩家存取 token，遊戲啟動時由 EC 帶入 URL，NextSpin 用來驗證 |
| `API` | ✅ | `deposit` | **呼叫的介面名稱**（與 endpoint 一一對應，NextSpin 依此路由） |
| `DataType` | ✅ | `JSON` | 固定 `JSON` |
| `Digest` | 選填 | `MD5(RequestBody)` | 請求體完整性驗證（部分 API 要求） |
| `Accept-Encoding` | 選填 | `gzip, deflate` | — |
| `Content-Encoding` | 選填 | `gzip` | — |
| `Accept-Language` | 選填 | `en_US` | — |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `statusCode` | String | `"00"` = 成功；其他 = 失敗（見錯誤碼表） |
| `status` | Integer | 部分 API 用 `1` 表示成功 |
| `msg` | Varchar(128) | 說明訊息 |
| `merchantCode` | Varchar(10) | 商戶代碼（echo 回請求值） |
| `serialNo` | Varchar(50) | 批次號（echo 回請求值） |

**成功判斷**：HTTP 200 + `statusCode: "00"`（主要）或 `status: 1`（部分 API）

### Base URL

| 項目 | 說明 |
|---|---|
| `API_URL` | 由 NextSpin 客服提供，存於 `vendor_infos.info.API_URL` |
| `GAME_URL` | 遊戲啟動 URL 前綴，存於 `vendor_infos.info.GAME_URL` |
| 動態查詢 | 可呼叫 `getDomainList` API 取得當前有效域名清單 |

---

## 簽名

### Bearer Token（MemberAccessToken）

所有 Merchant API 在 Header 帶：

```
Authorization: Bearer {MemberAccessToken}
```

`MemberAccessToken` 存於 `vendor_infos.info.ACCESS_TOKEN`，遊戲啟動時帶入遊戲 URL。

### serialNo 格式

```
{unix_timestamp_ms}{rand(10, 99)}
```

- 13 位 Unix 毫秒時間戳 + 2 位隨機數字
- 範例：`16893456789012_47`

### Digest Header（選填）

```
Digest: MD5(requestBody)
```

部分 API 要求帶此 Header 驗證 body 完整性。

### betDetail 特殊 token

betDetail API 的 `token` 參數與 Bearer Token **不同**，需單獨計算：

```
token = [REDACTED] + "|" + secretKey)
```

`secretKey` 取自 `vendor_infos.info.SECRET_KEY`，每次呼叫 betDetail 均須重算。

---

## 錢包流程（Transfer Wallet）

NextSpin 採轉帳錢包，玩家進入遊戲前需先 deposit，離開後 withdraw：

```
玩家進遊戲
  │
  ▼
EC 呼叫 deposit（transferToVendor）→ NextSpin 帳戶增加餘額
  │
  ▼
EC 產生 token → 組合 Game URL 並重導向玩家
  │
  ▼
NextSpin 呼叫 EC authorize callback（驗證 token，EC 回傳 balance=0）
  │
  ▼
玩家遊戲中（餘額在 NextSpin 端）
  │
  ▼
玩家離開
  │
  ▼
EC 呼叫 withdraw（transferToLocal）→ 剩餘餘額轉回本地
```

### Game URL 格式

```
{GAME_URL}{Merchant_Code}/auth/?acctId={username}&language={lang}&token=[REDACTED]
```

---

## 回調 API（NextSpin → EC）

### authorize

玩家進入遊戲時，NextSpin 主動呼叫 EC 驗證 token。

- **路由**：`POST /nextspin/authorize`（定義於 `routes/external/tw.php`）
- **Controller**：`NextSpinController@authorize`

**EC 回應欄位**：

| 欄位 | 值 | 說明 |
|---|---|---|
| `balance` | **`0`（固定）** | Transfer Wallet 模式，餘額不在 EC 端 |
| `token` | MemberAccessToken | NextSpin 驗證玩家身份用 |

---

## Merchant API（EC → NextSpin）

共 17 個 API：

### 1. getAcctInfo — 查詢玩家資訊

- **Method**：GET
- **Header**：`API: getAcctInfo`

**回應欄位**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `acctId` | String | 玩家 ID |
| `currency` | String | 幣別 |
| `status` | Integer | 帳號狀態 |

---

### 2. getBalance — 查詢餘額

- **Method**：GET
- **Header**：`API: getBalance`

**回應欄位**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | Decimal | 玩家在 NextSpin 端的餘額 |
| `currency` | String | 幣別 |

---

### 3. deposit（transferToVendor）— 存款

- **Method**：POST
- **Header**：`API: deposit`
- **Digest**：建議帶

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchantCode` | ✅ | String | 商戶代碼 |
| `serialNo` | ✅ | String | 批次號（timestamp_ms + rand(10,99)） |
| `acctId` | ✅ | String | 玩家 ID |
| `amount` | ✅ | Decimal | 存款金額 |
| `currency` | ✅ | String | 幣別 |

**回應欄位**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | Decimal | 存款後 NextSpin 端餘額 |

---

### 4. withdraw（transferToLocal）— 提款

- **Method**：POST
- **Header**：`API: withdraw`
- **Digest**：建議帶

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchantCode` | ✅ | String | 商戶代碼 |
| `serialNo` | ✅ | String | 批次號 |
| `acctId` | ✅ | String | 玩家 ID |
| `amount` | ✅ | Decimal | 提款金額 |
| `currency` | ✅ | String | 幣別 |

**回應欄位**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | Decimal | 提款後 NextSpin 端餘額 |

---

### 5. transferHistory — 查詢轉帳歷史

- **Method**：GET
- **Header**：`API: transferHistory`

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `serialNo` | ✅ | String | 批次號 |

**回應欄位**：轉帳狀態記錄列表

---

### 6. checkTransfer — 查詢轉帳狀態

- **Method**：POST
- **Header**：`API: checkTransfer`

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `serialNo` | ✅ | String | 批次號 |

---

### 7. getBetHistory — 查詢注單列表

- **Method**：GET
- **Header**：`API: getBetHistory`

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `startDate` | ✅ | DateTime | 開始時間 |
| `endDate` | ✅ | DateTime | 結束時間 |
| `page` | 選填 | Integer | 頁碼 |

---

### 8. betDetail — 查詢注單明細

- **Method**：GET
- **Header**：`API: betDetail`
- **特殊**：`token` 參數 = `MD5(ticketId + "|" + secretKey)`（非 Bearer Token）

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `ticketId` | ✅ | String | 注單 ID |
| `token` | ✅ | String | `MD5(ticketId + "\|" + secretKey)` |

---

### 9. kickAcct — 踢出玩家

- **Method**：POST
- **Header**：`API: kickAcct`

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `acctId` | ✅ | String | 玩家 ID |

---

### 10. getGames — 查詢遊戲列表

- **Method**：GET
- **Header**：`API: getGames`

---

### 11. getDomainList — 查詢可用域名

- **Method**：GET
- **Header**：`API: getDomainList`

回傳當前可用的 API / 遊戲域名清單，可動態更新 `API_URL`。

---

### 12. getProfitLoss — 查詢盈虧報表

- **Method**：GET
- **Header**：`API: getProfitLoss`

**請求欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `startDate` | ✅ | DateTime | 開始時間 |
| `endDate` | ✅ | DateTime | 結束時間 |
| `agentCode` | ✅ | String | 代理代碼 |

---

### 13. getUnfinishedGameLog — 查詢未完成遊戲記錄

- **Method**：GET
- **Header**：`API: getUnfinishedGameLog`

---

### 14. getOnlinePlayerInfo — 查詢線上玩家資訊

- **Method**：GET
- **Header**：`API: getOnlinePlayerInfo`

---

### 15. lobby redirect — 遊戲大廳 URL

- **Method**：GET

**Game URL 格式**：

```
{GAME_URL}{Merchant_Code}/auth/?acctId={username}&language={lang}&token=[REDACTED]
```

---

### 16. game redirect — 單遊戲啟動 URL

- **Method**：GET

**Game URL 格式**：

```
{GAME_URL}{Merchant_Code}/auth/?acctId={username}&language={lang}&token=[REDACTED]
```

---

### 17. transferToVendor / transferToLocal（流程 API，見 §deposit / §withdraw）

---

## 錯誤碼表

| 錯誤碼 | 說明 |
|---|---|
| `50100` | Internal Server Error |
| `1100101` | Missing or invalid parameters |
| `1100201` | Player not found |
| `1100203` | Player already exists |
| `1100301` | Member account not exist |
| `1100302` | Insufficient funds |
| `1100501` | Invalid session |
| `1100601` | Transfer duplicate |
| `1110101` | Request duplicate |
| `1110201` | IP not in whitelist |
| `1110202` | Authentication error |
| `1110701` | Insufficient funds |
| `1110801` | Transfer in progress |
| `1110802` | Credit transfer failed |
| `1110803` | Debit transfer failed |

---

## 幣別支援

### 一般幣別（1:1）

AED, AMD, AOA, ARS, AUD, BDT, BND, BRL, CAD, CNY, COP, CLP, DZD, EUR, ETB, FKP, GBP, GHS, HKD, INR, JPY, KHR, KWD, KRW, MAD, MYR, MXN, NGN, NPR, PHP, PEN, PGK, PKR, RUB, SEK, SGD, THB, TND, TRY, USD, XAF, XRP

### 縮放幣別（1:1000）

| 幣別代碼 | 名稱 |
|---|---|
| IDR | 印尼盾 |
| LAK | 寮國基普 |
| MMK | 緬甸緬元 |
| MNT | 蒙古圖格里克 |
| TZS | 坦桑尼亞先令 |
| UZS | 烏茲別克蘇姆 |
| VND | 越南盾 |

### 加密貨幣

| 代碼 | 換算比例 |
|---|---|
| ET2 | milli-Ethereum 1:0.001 |
| FTN | FastToken 1:1 |
| LT2 | milli-Litecoin 1:0.001 |
| BC2 | milli-Bitcoin Cash 1:0.001 |
| TET | Tether USDT 1:1 |
| TON | Toncoin 1:0.001 |
| XB3 | micro-Bitcoin 1:0.000001 |

---

## 語言代碼

| 代碼 | 語言 |
|---|---|
| `en_US` | 英語 |
| `zh_CN` | 簡體中文 |
| `zh_TW` | 繁體中文 |
| `th_TH` | 泰語 |
| `id_ID` | 印尼語 |
| `vi_VN` | 越南語 |
| `my_MM` | 緬甸語 |
| `hi_IN` | 印地語 |
| `bn_BD` | 孟加拉語 |
| `de_DE` | 德語 |
| `es_ES` | 西班牙語 |
| `fil_PH` | 菲律賓語 |
| `ja_JP` | 日語 |
| `kh_KM` | 高棉語 |
| `ko_KR` | 韓語 |
| `pt_PT` | 葡萄牙語 |
| `sv_SE` | 瑞典語 |

> Driver `getLanguage()` 目前實作：en_US / zh_CN / zh_TW / th_TH / id_ID / vi_VN / my_MM / hi_IN；其餘 fallback `en_US`。

---

## 遊戲類型（Category）

| 代碼 | 說明 |
|---|---|
| SM | Slot Machine（老虎機） |
| TB | Table（桌遊） |
| AD | Arcade（街機） |
| BN | Bingo |

---

## 已知 Gaps

| 項目 | 說明 |
|---|---|
| API base URL | 文件站不提供正式 URL，需向 NextSpin 客服索取 |
| SECRET_KEY | 文件僅提示「向客服索要密鑰」 |
| Digest 計算範例 | 文件說明 MD5(Data) 但未提供完整範例 |
| 對帳資料保留期 | 文件未明確說明 |
| Transfer type 6~20 | type 7~14 標記「開發中」，尚未上線 |
