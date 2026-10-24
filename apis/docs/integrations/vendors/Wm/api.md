# Wm（World Match / WM Casino）— 廠商 API 規格

> 原始來源（官方 PDF，繁中／簡中雙語版）：
> - `WM Casino - Wallet Web API (SC) 20190509.pdf`（**本專案實作的權威依據**，Version 1.0 / 2018-06-05）
> - `WM Casino - External Wallet API (SC) 20191228.pdf`（Version 1.0 / 2019-04-12，整合背景與遊戲啟動 URL 參考）
>
> 抓取日期：2026-06-03
>
> 我方實作分析、落差清單與整合落地建議見同目錄 `integration.md`。

Wm 是 World Match（亦稱 WM Casino，供應商法人 Octavian）的遊戲平台，提供真人百家樂、電子（slot）、捕魚等遊戲。本專案以**轉帳錢包（Transfer Wallet）** 模式接入（vendor_id=16）：玩家餘額由本地（operator）保管，進入遊戲前先透過 `Credit` 把資金轉入 WM 端的玩家錢包，離開時透過 `Debit` 轉回。

World Match 提供兩種接入文件（WAPI 與 EWAPI），方向相反。本專案只採用 **Wallet Web API（WAPI）**。

---

## ⚠️ 重要：兩份文件角色對照

| 文件 | 錢包由誰保管 | 誰呼叫誰 | 本專案是否採用 |
|---|---|---|---|
| **Wallet Web API（WAPI）** | 玩家錢包的 WMC 端副本由 **WM 保管**，operator 透過 REST 主動 Credit / Debit 推送資金 | **operator → WM**（operator 是 client，WM 提供 REST endpoint） | ✅ **本專案實作即此模式** |
| External Wallet API（EWAPI） | 玩家錢包由 **operator（licensee）保管** | **WM → operator**（operator 須自建 External Wallet Service 供 WM 回調 GetCash / TransferCash…） | ❌ 未採用（但遊戲啟動 URL 與術語沿用此文件） |

> 實作 `Driver.php` 呼叫的所有 endpoint（`/Platform/Feed/Wallet/Auth|Balance|Credit|Debit|Transaction/{LICENSEE_TOKEN}`）皆來自 **Wallet Web API**。
>
> WAPI 文件自述為「single-wallet-per-user」REST 服務，但**對 operator 而言這是轉帳錢包流程**：必須先把錢轉進 WM 才能玩，故本專案以 `AbstractTransferWallet` 實作。請勿與「單一錢包（Seamless）」混淆——Seamless 是 External Wallet API 的另一種模式，本專案未使用。

---

## 通用約定（Wallet Web API）

### 請求格式

- **協定**：HTTPS（WM 要求安全通道）
- **HTTP 動詞**：寫入操作（建玩家 / Credit / Debit）用 `POST`，查詢類（User details / Balance / Auth / Transaction）用 `GET`
- **Content-Type**：`application/json; charset=utf-8`（建玩家 Note 7 明文要求）
- **資料格式**：JSON
- **小數分隔符**：一律 `.`（dot）

### URL 結構

```
https://{SERVER_NAME}/Platform/Feed/Wallet/{Action}/{LICENSEE_TOKEN}[?查詢參數]
```

| 環境 | SERVER_NAME |
|---|---|
| 測試 | `example.invalid`（遊戲啟動參考）；WAPI 範例用 `devservices.wmdev.eu` |
| 正式 | `example.invalid` |

> ⚠️ WAPI 文件本身的範例僅以 `https://server/...` 佔位，未列正式 REST Base URL；上表來自 EWAPI 遊戲啟動章節，實際 host 以 WM 提供的環境設定表為準。本專案以 `vendor_infos.info.SERVER_NAME` 設定，不寫死。

### 認證方式（無 per-request 簽名）

Wm WAPI **沒有 per-request 訊息簽名**，認證靠三者：

| 機制 | 說明 |
|---|---|
| `LICENSEE_TOKEN` | operator 的識別 token，**32 碼英數 hex hash**，由 WM 配發。**直接放在 URL 路徑**：`/Platform/Feed/Wallet/{Action}/{LICENSEE_TOKEN}`。token 無效回 `TOKEN_NOT_VALID`（400）。 |
| IP 白名單 | WM 端依 operator 來源 IP 限制存取 |
| HTTPS / SSL | 所有通訊必須走 HTTPS |

本專案實作對照（`app/Vendors/Wm/Driver.php`）：

```php
// LICENSEE_TOKEN 拼進 URL 路徑，無額外簽名 header
$url = "{$this->apiUrl}/Platform/Feed/Wallet/{$endpoint}/{$this->detailData->LICENSEE_TOKEN}";

Http::logAction($action)
    ->withoutRedirecting()
    ->asJson()
    ->post($url, $data)   // $data 不含任何 signature 欄位
    ->json();
```

- `apiUrl` = `trim($info['SERVER_NAME'], '/')`
- 查詢類（Auth / Balance / Transaction）以 `?id=` 或 `?ref=` 帶查詢參數，同樣無簽名。
- 一律使用 `Http::logAction($action)` macro 保留請求紀錄（action 名以 `WM_` 前綴）。

#### 遊戲啟動憑證（單次性 AuthKey）

啟動遊戲所需的玩家憑證透過 **Auth** 操作即時取得，**每次啟動前都要重取**：

```
GET /Platform/Feed/Wallet/Auth/{LICENSEE_TOKEN}?id={UserID}
→ { "AuthUser": "...", "AuthKey": "...", "AuthSkin": "..." }
```

`AuthKey` 是**單次使用 token（single-use，32 碼）**，每次遊戲啟動都會變。WAPI Auth Note 1 明示：為避免載入遊戲時認證失敗，務必在每次啟動前呼叫 Auth 取最新憑證。

### HTTP 狀態碼（WAPI）

| Code | WM 錯誤名稱 | 說明 |
|---|---|---|
| `200` | — | 成功（回應 body 帶 `Status: "GRANTED"` 等資料） |
| `400` | Bad Request | 輸入無效（token 無效、欄位格式錯、金額超範圍） |
| `403` | `USER_NOT_AUTHORIZED` | 玩家未啟用，出現於 Balance / Auth / Credit / Debit |
| `404` | `USER_NOT_FOUND` / `TRANSACTION_NOT_FOUND` | 玩家或交易不存在 |
| `500` | `USER_FAILURE` / `TRANSACTION_FAILURE` / `UNEXPECTED_ERROR` | 伺服器內部錯誤 |

---

## 錢包流程（Transfer Wallet）

WAPI Tutorial（文件第 6 章）的 operator 端標準流程：

```
建玩家 User(POST)  ── 冪等，同一 UserID 重送只建一次
      │
      ▼
存款 Credit(POST)  ── 把 operator 錢包資金轉進 WM 玩家錢包（進入遊戲前）
      │
      ▼
取憑證 Auth(GET)   ── 取得單次性 AuthKey（每次啟動遊戲前都要重取）
      │
      ▼
啟動遊戲（real 模式 launch URL，帶 authuser/authkey/authskin）
      │
      ▼
玩家遊戲（不與 operator 通訊，WM 端扣加 WM 錢包）
      │
      ▼
查餘額 Balance(GET) ── 結束後讀 WM 錢包剩餘
      │
      ▼
提款 Debit(POST)   ── 把 WM 錢包剩餘轉回 operator 錢包
```

### Driver 方法對應

| 流程節點 | Driver 方法 | 實際呼叫 | 備註 |
|---|---|---|---|
| 取啟動憑證 + 組遊戲 URL | `getGameUrl()` | `GET /Platform/Feed/Wallet/Auth/{LICENSEE_TOKEN}?id={user_id}` | 用回傳的 `AuthUser`/`AuthKey`/`AuthSkin` 組啟動 URL |
| 查 WM 餘額 | `getBalance()` | `GET /Platform/Feed/Wallet/Balance/{LICENSEE_TOKEN}?id={user_id}` | 取 `Balance`，`Common::numFloor(.., 4)` 正規化 |
| 存款（轉入 WM） | `transferToVendor()` → `transfer(1, ..)` | `POST /Platform/Feed/Wallet/Credit/{LICENSEE_TOKEN}` | body：`Reference`/`UserID`/`Amount`/`Currency`；`Amount = Common::numFloor(vendorAmount, 2)` |
| 提款（轉回本地） | `transferToLocal()` → `transfer(2, ..)` | `POST /Platform/Feed/Wallet/Debit/{LICENSEE_TOKEN}` | 同上欄位 |
| 查轉帳結果 | `checkTransferStatus()` | `GET /Platform/Feed/Wallet/Transaction/{LICENSEE_TOKEN}?ref={transactionLogId}` | `Status == "GRANTED"` → SUCCESS |

---

## merchant API（代理 → WM Wallet Web API）

### User Operations 玩家操作

#### 1. User creation 建立玩家

在 WMC 內建立玩家（WMC 玩家是 operator 玩家的本地副本）。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/User/{LICENSEE_TOKEN}` |
| Method | `POST`（無 query；header 需 `Content-Type: application/json; charset=utf-8`） |

**請求 body（全部必填）**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `UserID` | String | 外部唯一玩家 ID（UID），≤50 chars。**冪等鍵** |
| `UserName` | String | 外部玩家名稱，≤32 chars |
| `Skin` | String | Skin（代理／下線）code，≤32 chars |
| `Currency` | String | 幣別 ISO code，≤4 chars |
| `IPv4` | String | 玩家 client 的 IPv4（如 `0.0.0.0`） |
| `TestUser` | Boolean | `true`=測試帳號 / `false`=玩家 |

**成功回應**：

```jsonc
{
  "UserID": "XYZ",
  "UserToken": "AE5B6EA44169AB06286FD0BD0F77EB1B",
  "UserName": "demo",
  "Skin": "DEMO",
  "Currency": "EUR",
  "Role": "PLAYER",                 // PLAYER | TEST
  "Status": "ENABLED",              // ENABLED | DISABLED | DELETED
  "CreationDate": "2018-05-31",     // YYYY-MM-DD
  "CreationIPv4": "0.0.0.0"
}
```

**重點規則（Notes）**：

- **冪等**：同一 `UserID` 重送只在第一次建立，回應相同（`wm_accounts.user_id` 即據此設計，避免同 username 重覆建立）。
- 若玩家已存在但 username / skin / currency 不符 → `USER_MISMATCH`。
- `Currency` 必須在該 licensee 與 skin 設定的幣別集合內，否則 `CURRENCY_NOT_SUPPORTED`。

#### 2. User details 玩家資料

取得建立玩家時產生的資訊。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/User/{LICENSEE_TOKEN}?id={UserID}` |
| Method | `GET`（無 body） |

**成功回應**：欄位同建立玩家（`UserID` / `UserToken` / `UserName` / `Skin` / `Currency` / `Role` / `Status` / `CreationDate` / `CreationIPv4`）。

- 玩家即使未啟用（disabled）也回有效結果。
- 玩家不存在 → `USER_NOT_FOUND`（404）。

#### 3. User balance 玩家餘額

回傳 WMC 端玩家現金錢包餘額。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/Balance/{LICENSEE_TOKEN}?id={UserID}` |
| Method | `GET`（無 body） |

**成功回應**：

```jsonc
{ "UserID": "XYZ", "Balance": 1234.56, "Currency": "EUR" }
```

- `Balance` 為 decimal（`.` 分隔）。
- 玩家未啟用 → `USER_NOT_AUTHORIZED`（403）。

本專案實作（`Driver::getBalance()`）：

```php
$url = "{$this->apiUrl}/Platform/Feed/Wallet/Balance/{$LICENSEE_TOKEN}?id={$user_id}";
// 取 $response['Balance']，缺則拋 ApiException
return Common::numFloor($response['Balance'], 4);   // 正規化 4 位
```

#### 4. User credentials 玩家啟動憑證（Auth）

取得啟動遊戲 URL 用的憑證集（**單次性**，每次啟動前重取）。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/Auth/{LICENSEE_TOKEN}?id={UserID}` |
| Method | `GET`（無 body） |

**成功回應**：

```jsonc
{ "AuthUser": "XYZ", "AuthKey": "AB136511840F4A71BACCE2C4E723668B", "AuthSkin": "DEMO" }
```

| 欄位 | 說明 |
|---|---|
| `AuthUser` | 外部唯一玩家 ID（UID） |
| `AuthKey` | **單次使用 token**（32 chars），每次啟動都變 |
| `AuthSkin` | 玩家所屬 skin code |

- 玩家未啟用 → `USER_NOT_AUTHORIZED`（403）。
- **Note 1**：每次遊戲啟動前必須重新呼叫 Auth 取最新 `AuthKey`，否則載入會認證失敗。

`getGameUrl()` 先打 Auth 取 `AuthUser`/`AuthKey`/`AuthSkin`，再組遊戲 URL（見「遊戲啟動 URL」章節）。

---

### Transaction Operations 交易操作

#### 5. Transfer to WMC（Credit）存款

把現金轉進 WMC 玩家錢包（operator → WM）。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/Credit/{LICENSEE_TOKEN}` |
| Method | `POST`（無 query） |

**請求 body（全部必填）**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Reference` | String | 轉帳的外部唯一參考（UID），≤50 chars。**冪等鍵** |
| `UserID` | String | 外部唯一玩家 ID（UID），≤50 chars |
| `Amount` | Decimal | 轉帳金額（`.` 分隔） |
| `Currency` | String | 幣別 ISO code，≤4 chars |

**成功回應**：

```jsonc
{
  "Reference": "123",
  "UserID": "XYZ",
  "TransactionID": 200012345,       // WMC 交易數字 ID, long 64-bit
  "Type": "CREDIT",                 // CREDIT | DEBIT
  "Status": "GRANTED",              // 成功固定 GRANTED；失敗改回 {Error,Description}
  "Amount": 1234.5,
  "InitialBalance": 0.0,            // 轉帳前 WMC 錢包餘額
  "FinalBalance": 1234.5,           // 轉帳後 WMC 錢包餘額
  "Currency": "EUR",
  "DateTime": "2018-05-31 15:43:21" // YYYY-MM-DD HH:MM:SS
}
```

**重點規則**：

- **冪等**：同 `Reference` 重送只執行一次（逾時重試安全）。
- 既存交易但 type/amount/userID 不符 → `TRANSACTION_MISMATCH`。
- 幣別與建玩家幣別不符 → `CURRENCY_MISMATCH`。
- 玩家未啟用 → `USER_NOT_AUTHORIZED`（403）。
- 金額須 > 0；Debit 時不可超過玩家錢包餘額，否則 `AMOUNT_OUT_OF_RANGE`。

#### 6. Transfer to operator（Debit）提款

把現金從 WMC 玩家錢包轉回 operator 平台（WM → operator）。

| 項目 | 值 |
|---|---|
| URL | `/Platform/Feed/Wallet/Debit/{LICENSEE_TOKEN}` |
| Method | `POST`（無 query） |

請求 body 與成功回應**欄位結構同 Credit**，差別僅 `Type: "DEBIT"`、`InitialBalance`/`FinalBalance` 為扣款前後值。規則同 Credit（冪等、mismatch、currency、authorized、amount range）。

本專案 Credit / Debit 共用實作（`Driver::transfer()`）：

```php
// type==1 → deposit / endpoint=Credit ；否則 → withdraw / endpoint=Debit
$data = [
    'Reference' => $params->transactionLogId,                    // 交易紀錄主鍵
    'UserID'    => $this->vendorAccount->user_id,
    'Amount'    => Common::numFloor($params->vendorAmount, 2),    // 2 位
    'Currency'  => $this->detailData->CURRENCY,
];
$url = "{$this->apiUrl}/Platform/Feed/Wallet/{$endpoint}/{$LICENSEE_TOKEN}";

// 成功判斷：回應陣列「不含 Error」→ SUCCESS
// 含 Error → FAILED；非陣列／缺欄位 → TIMEOUT
```

#### 7. Transaction details 交易查詢

依外部 `ref` 查單筆轉帳的完整資訊（含最終狀態），用於對帳與逾時補查。

| 項目 | 值 |
|---|---|
| URL（建議） | `/Platform/Feed/Wallet/Transaction/{LICENSEE_TOKEN}?ref={trx_ref}` |
| 同義 URL | `/Platform/Feed/Wallet/Credit/{LICENSEE_TOKEN}?ref={trx_ref}`（僅查 CREDIT）<br>`/Platform/Feed/Wallet/Debit/{LICENSEE_TOKEN}?ref={trx_ref}`（僅查 DEBIT） |
| Method | `GET`（無 body） |

- **`Transaction`** 回 CREDIT 與 DEBIT 兩類，為建議使用的查詢端點。
- `Credit` 端點只查 CREDIT，查 DEBIT 會回 `TRANSACTION_NOT_FOUND`（反之亦然）。

**成功回應**：欄位同 Credit/Debit 成功回應（`Reference` / `UserID` / `TransactionID` / `Type` / `Status` / `Amount` / `InitialBalance` / `FinalBalance` / `Currency` / `DateTime`）。

- 交易不存在 → `TRANSACTION_NOT_FOUND`（404）。

本專案實作（`Driver::checkTransferStatus()`）：

```php
$url = "{$this->apiUrl}/Platform/Feed/Wallet/Transaction/{$LICENSEE_TOKEN}?ref={$transactionNo}";
// $transactionNo = 交易紀錄主鍵
if ($response['Status'] == 'GRANTED') return VendorTransferResultEnum::SUCCESS;
// 缺 Status / 非陣列 → TIMEOUT；其餘 → FAILED
```

---

## 遊戲啟動 URL（real 模式）

> 來源：External Wallet API 第 11.2 章 + 本專案 `Driver::getGameUrl()`。

WAPI 取得 `AuthUser`/`AuthKey`/`AuthSkin` 後，組真錢（real）啟動 URL。EWAPI 文件的標準格式：

```
https://{server_name}/games/real/{licensee_id}/{game_id}/{configuration_id}/
  ?authuser={AuthUser}&authkey={AuthKey}&authskin={AuthSkin}
  &language={language_code}&age={age_flag}&toggle={toggle_flag}
  &display={display_type}&lobbyurl={lobby_url}
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `authuser` | ✅ | = WAPI Auth 回的 `AuthUser`（外部 user ID，非 username） |
| `authkey` | ✅ | = `AuthKey`（單次 token） |
| `authskin` | ✅ | = `AuthSkin`（skin code） |
| `language` | ❌ | 2 碼 ISO 或 `ZH-HANS`（簡中）/ `ZH-HANT`（繁中）；預設 `EN` |
| `age` | ❌ | 啟動年齡提示，預設 `false` |
| `toggle` | ❌ | 行動裝置方向提示開關，預設 `true` |
| `display` | ❌ | `no value`(popup) / `inline` / `iframe`，預設 popup |
| `lobbyurl` | ❌ | HTML5 行動版返回大廳 URL（須 URL-encode） |

本專案實際組法（`Driver::getGameUrl()`，採 GAME_URL_MODE=2 新式）：

```
{GAME_URL}games/{LICENSEE_CODE}/{GAME_MODE}/{gameInfo.Code}/
  ?authuser={AuthUser}&authkey={AuthKey}&authskin={AuthSkin}
  &language={mapLanguageCode()}&display=iframe
```

- `getGameUrl()` 先打 `GET /Platform/Feed/Wallet/Auth/{LICENSEE_TOKEN}?id={user_id}` 取憑證。
- 路徑形態為「`LICENSEE_CODE`/`GAME_MODE`/`game code`」。
- `display=iframe` 固定。
- `language` 由 `mapLanguageCode()` 對應（見 integration.md 語言落差說明）。

---

## 錯誤碼

WAPI 第 5 章完整錯誤碼（失敗時回 `{"Error":"<CODE>","Description":"<說明>"}`）：

| Error | HTTP | 說明 | 出現操作 |
|---|---|---|---|
| `TOKEN_NOT_VALID` | 400 | LICENSEE_TOKEN 無效 | 全部 |
| `USER_NULL` | 400 | 輸入玩家為 null | User creation |
| `USER_FAILURE` | 500 | 建立玩家失敗 | User creation |
| `USER_NOT_FOUND` | 404 | 玩家不存在 | creation / details / balance / credentials / Credit / Debit |
| `USER_NOT_AUTHORIZED` | 403 | 玩家未啟用，不允許執行 | balance / credentials / Credit / Debit |
| `USER_MISMATCH` | 400 | 輸入資料與既有玩家不符 | User creation |
| `USERNAME_NOT_VALID` | 400 | username 空或無效（≤32） | User creation |
| `USERID_NOT_VALID` | 400 | UserID 空或無效（≤50） | creation / details / balance / credentials / Credit / Debit |
| `SKIN_NOT_VALID` | 400 | skin code 空或無效（≤32） | User creation |
| `SKIN_NOT_FOUND` | 400 | skin 不存在 | User creation |
| `CURRENCY_NOT_VALID` | 400 | 幣別非有效 3 碼 ISO | creation / Credit / Debit |
| `CURRENCY_NOT_SUPPORTED` | 400 | licensee／skin 不支援此幣別 | User creation |
| `CURRENCY_MISMATCH` | 400 | 交易幣別與玩家幣別不符 | Credit / Debit |
| `IPADDRESS_NOT_VALID` | 400 | IP 空或非 IPv4 | User creation |
| `TRANSACTION_NULL` | 400 | 輸入交易為 null | Credit / Debit |
| `TRANSACTION_FAILURE` | 500 | 該 reference 的 CREDIT/DEBIT 不成功 | Credit / Debit |
| `TRANSACTION_NOT_FOUND` | 404 | 交易不存在 | Credit details / Debit details / Transaction details |
| `TRANSACTION_MISMATCH` | 400 | 輸入與既存交易不符 | Credit / Debit |
| `REFERENCE_NOT_VALID` | 400 | 外部 reference 空或無效（≤50） | Credit / Debit / 各查詢 |
| `AMOUNT_OUT_OF_RANGE` | 400 | 金額須 > 0 且不可超過玩家錢包餘額 | Credit / Debit |
| `UNEXPECTED_ERROR` | 500 | 非預期錯誤（自訂描述） | 全部 |

---

## External Wallet API（EWAPI）— 本專案未使用

EWAPI 第 10 章描述的是**選配**的數字簽名機制（用於 operator 自建 External Wallet Service 與 WM 互打時）：

| 項目 | 值 |
|---|---|
| 是否必須 | **選配**（非強制；是否啟用須與 WM 共同決定） |
| `SignedData` | 未加密字串：request 為「所有 input 串接」；response 為「傳入的 SignedData + 所有 output 串接」 |
| `Signature` | 對 `SignedData` 做 **RSA + SHA1** 編碼 |
| 編碼格式 | Base64 |
| 金鑰 | 非對稱金鑰；operator 提供公鑰給 WM；WM 公鑰以 PEM / DER / XML 提供 |

本專案以 WAPI 接入，**不涉及** EWAPI 的 RSA-SHA1 簽名。EWAPI 的 Seamless / 紅利 / 免費回合 / `TransferBonus` / `CancelTransfer` 等章節與本專案無關。
