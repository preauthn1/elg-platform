# Sa Gaming — API 規格

> 來源：<https://example.invalid/language/zh/api-document-cn>（SA Gaming Web Service API 完整文檔）  
> 補充：`遊戲商/37.Sa/API相關資料在官網.txt`（測試環境設定）  
> 抓取日期：2026-06-03

---

## 通用約定

### 請求格式

- **HTTP Method**：`POST`
- **Content-Type**：`application/x-www-form-urlencoded`
- **驗證方式**：DES-CBC 加密（`q` 參數） + MD5 數位簽章（`s` 參數）
- **回應格式**：**XML**（與其他廠商不同，非 JSON）

### API 端點

| 用途 | URL（測試環境） |
|---|---|
| 通用 API（帳號管理、轉帳、登入等） | `http://example.invalid/api/api.aspx` |
| 下注詳情查詢 | `http://example.invalid/api/api.aspx` |

> 正式環境 URL 由 SA Gaming 另行提供，存於 `vendor_infos.info.API_URL`。

### POST Body 格式

```
q=<URL-Encoded-Base64-DES-加密結果>&s=<MD5-Hash>
```

所有 API 的 Query String 均需包含以下共用欄位：

| 欄位 | 類型 | 格式／說明 |
|---|---|---|
| `method` | String | API 方法名稱 |
| `Key` | String(32) | Secret Key（SA Gaming 提供） |
| `Time` | DateTime | `yyyyMMddHHmmss` |

### 金額精度

所有金額欄位最多 **2 位小數**，超過回傳 Error 145。

### 查詢限流

`GetAllBetDetailsDV`、`GetAllBetDetailsForTimeIntervalDV` 等查詢 API 限制**每 5 分鐘最多 10 次**。

---

## Merchant API

代理主動呼叫 SA Gaming 的所有 API（共 21 個端點）。

### 帳號管理

#### 1. RegUserInfo（建立帳號）

建立玩家帳號。若帳號已存在則回傳 Error 113。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `CurrencyType` | String(16) | ✅ | ISO 幣別代碼（如 `THB`） |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `Username` | 已建立的帳號 |
| `ErrorMsgId` | `0`=成功；`108`=格式錯誤；`113`=已存在；`114`=幣別無效；`133`=建立失敗 |
| `ErrorMsg` | 錯誤說明 |

---

#### 2. VerifyUsername（驗證帳號）

確認帳號是否已存在於系統中。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 欲查詢的帳號 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `Username` | 查詢帳號 |
| `IsExist` | Boolean，帳號是否存在 |
| `ErrorMsgId` | `0`=成功；`108`=格式錯誤 |

---

#### 3. GetUserStatusDV（查詢帳號狀態／餘額）

取得玩家當前狀態，包含餘額與線上狀況。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |

**XML 回應：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Username` | String | 玩家帳號 |
| `Balance` | Decimal | 可用餘額（不含未結算下注） |
| `Online` | Bool | 是否在線 |
| `Betted` | Bool | 是否有未結算注單 |
| `BettedAmount` | Decimal | 未結算注單總額 |
| `MaxBalance` | Decimal | 最大餘額上限（`0` = 無限制） |
| `MaxWinning` | Decimal | 每日最大獲利上限 |
| `ErrorMsgId` | Byte | `0`=成功；`100`=帳號錯誤；`116`=帳號不存在 |

---

#### 4. SetAllBetLimit（設定注限）

為玩家設定特定遊戲的注限規則組合。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `Currency` | String(16) | ✅ | ISO 幣別 |
| `ListSet` | String | ✅ | 逗號分隔的 RuleID 清單（來自 QueryBetLimit） |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `ErrorMsgId` | `0`=成功；`116`=帳號不存在；`157`=超出上限；`158`=重複規則；`190`=試玩帳號不支援 |

---

#### 5. QueryBetLimit（查詢注限清單）

取得指定幣別與遊戲類型的可用注限規則，供 SetAllBetLimit 使用。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Currency` | String(16) | ✅ | ISO 幣別 |
| `Gametype` | String | ✅ | 遊戲代碼或 `all` |

**XML 回應（BetLimitList 內每筆）：**

| 欄位 | 說明 |
|---|---|
| `RuleID` | Int64，注限規則 ID |
| `Min` | 最小下注額 |
| `Max` | 最大下注額 |

---

#### 6. QueryUserBetLimit（查詢個人注限）

查詢某玩家目前套用的注限規則。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `Gametype` | String | ✅ | 遊戲代碼或 `all` |

**XML 回應（BetLimitList 內每筆）：**

| 欄位 | 說明 |
|---|---|
| `RuleID` | 注限規則 ID |
| `GameType` | 遊戲類型 |
| `Min` / `Max` | 下注區間 |

---

#### 7. SetUserMaxBalance（設定最大餘額）

設定玩家帳號在 SA Gaming 端的最大餘額上限（轉帳錢包專用）。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `MaxBalance` | Decimal | ✅ | 新上限（`0` = 無限制；不可小於目前餘額） |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `ErrorMsgId` | `0`=成功；`148`=無效值；`159`=僅限轉帳錢包 |

---

#### 8. SetUserMaxWinning（設定每日最大獲利）

設定玩家每日獲利上限；超過後下注將受到限制。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `MaxWinning` | Decimal | ✅ | 每日獲利上限 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `ErrorMsgId` | `0`=成功；`142`=參數錯誤 |

---

### 登入

#### 9. LoginRequest（取得遊戲 Token）

取得玩家進入遊戲的 Token。帳號不存在時**自動建立**。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `CurrencyType` | String(16) | ✅ | ISO 幣別代碼 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `Token` | 遊戲認證 Token（用於組成遊戲 URL） |
| `DisplayName` | SA Gaming 端顯示名稱（含 `@xxx` suffix） |
| `GameLaunchDomain` | 遊戲伺服器域名 |
| `GameLaunchURL` | 完整遊戲 URL（HTML encoded） |
| `ErrorMsgId` | `0`=成功；`129`=系統維護；`130`=帳號已鎖定；`135`=遊戲無法存取 |

**遊戲 URL 組成方式：**

```
https://{GAME_URL}?username={username}&token=[REDACTED]
```

| 參數 | 說明 |
|---|---|
| `username` | 玩家帳號 |
| `token` | LoginRequest 回傳的 Token |
| `lobby` | 大廳代碼（`vendor_infos.info.LOBBY`） |
| `lang` | 語言代碼（見附錄） |
| `mobile` | `true` = HTML5 手機版 |
| `options` | 可選，逗號分隔功能：`defaulttable={HostID}`, `hidelogo=1`, `webview=1` |

---

#### 10. KickUser（強制登出）

強制將玩家踢出所有進行中的遊戲 Session。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `ErrorMsgId` | `0`=成功；`116`=帳號不存在；`125`=登出失敗 |

---

### 轉帳錢包

> 金額精度最多 2 位小數；`OrderId` 格式有固定規範（見下方）。

**OrderId 命名規則：**

| 方向 | 格式 | 範例 |
|---|---|---|
| 存入（Vendor） | `IN` + `yyyyMMddHHmmss` + `Username` | `IN20240101123456testuser001` |
| 提出（Local） | `OUT` + `yyyyMMddHHmmss` + `Username` | `OUT20240101123456testuser001` |

> 本專案實作於 `Driver::getTransactionNo()` 方法。

#### 11. CreditBalanceDV（存入）

將點數存入玩家在 SA Gaming 的帳號（帳號不存在時自動建立）。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `OrderId` | String(64) | ✅ | 格式：`IN+yyyyMMddHHmmss+Username` |
| `CreditAmount` | Decimal | ✅ | 存入金額（最多 2 位小數） |
| `CurrencyType` | String(16) | ✅ | ISO 幣別代碼 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `Username` | 玩家帳號 |
| `Balance` | 操作後餘額 |
| `CreditAmount` | 實際存入金額 |
| `OrderId` | 訂單號 |
| `ErrorMsgId` | `0`=成功；`120`=金額須大於 0；`122`=OrderId 已存在；`145`=小數超過 2 位；`159`=僅限轉帳錢包；`133`=建立帳號失敗 |

---

#### 12. DebitBalanceDV（提出）

從玩家在 SA Gaming 的帳號提出指定金額。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `OrderId` | String(64) | ✅ | 格式：`OUT+yyyyMMddHHmmss+Username` |
| `DebitAmount` | Decimal | ✅ | 提出金額（最多 2 位小數） |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `Username` | 玩家帳號 |
| `Balance` | 操作後餘額 |
| `DebitAmount` | 實際提出金額 |
| `OrderId` | 訂單號 |
| `ErrorMsgId` | `0`=成功；`106`=服務忙碌；`116`=帳號不存在；`120`=金額須大於 0；`121`=餘額不足；`122`=OrderId 已存在；`124`=DB 錯誤；`127`=OrderId 格式錯誤；`145`=小數超過 2 位；`159`=僅限轉帳錢包 |

---

#### 13. DebitAllBalanceDV（全額提出）

提出玩家在 SA Gaming 的全部餘額（不需指定金額）。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `OrderId` | String(64) | ✅ | 格式：`OUT+yyyyMMddHHmmss+Username` |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `DebitAmount` | 實際提出金額（即全部餘額） |
| `OrderId` | 訂單號 |
| `ErrorMsgId` | `0`=成功；`106`=服務忙碌；`116`=帳號不存在；`122`=OrderId 已存在；`124`=DB 錯誤；`127`=格式錯誤；`159`=僅限轉帳錢包 |

---

#### 14. CheckOrderId（確認訂單狀態）

確認某個 OrderId 是否已存在（用於轉帳後的冪等確認）。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OrderId` | String(64) | ✅ | 待確認的訂單號 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `isExist` | **字串** `"true"` / `"false"`（非布林值）；`"true"` = 訂單存在即成功 |
| `ErrorMsgId` | `0`=成功；`106`=服務忙碌；`124`=DB 錯誤；`127`=格式錯誤；`159`=僅限轉帳錢包 |

> `checkTransferStatus()` 邏輯：`ErrorMsgId=0` 且 `isExist="true"` → SUCCESS；`isExist="false"` → FAILED；其餘 → TIMEOUT。

---

#### 15. CheckOrderDetailsDV（查詢訂單明細）

取得轉帳訂單的詳細資訊，包含操作前後餘額。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OrderId` | String(64) | ✅ | 訂單號 |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `isExist` | 訂單是否存在 |
| `Date` | 交易時間（DateTime） |
| `Type` | `0`=存入；`1`=提出 |
| `Currency` | 幣別 |
| `Amount` | 交易金額 |
| `PreviousBalance` | 操作前餘額 |
| `Balance` | 操作後餘額 |

---

### 下注紀錄

#### 16. GetAllBetDetailsDV（按日查詢）

取得指定日期的所有注單紀錄。日期以**當日中午 12:00 至次日 11:59:59**為一日（非凌晨）。

**頻率限制：每 5 分鐘最多 10 次。**

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ❌ | 指定玩家；空白則查全部 |
| `Date` | String | ❌ | `yyyy-MM-dd`；空白則用 Server 當日 |

**XML 回應（每筆注單）：**

| 欄位 | 說明 |
|---|---|
| `BetTime` | 下注確認時間 |
| `PayoutTime` | 結算時間 |
| `BetAmount` | 下注金額（2 位小數） |
| `Rolling` | 有效注額 / 流水量 |
| `ResultAmount` | 輸贏金額（負數=輸） |
| `Balance` | 操作後餘額（轉帳錢包） |
| `GameType` | 遊戲類型代碼 |
| `BetType` | 下注類型 |
| `TransactionID` | Seamless 錢包 ID（Transfer Wallet 時為 `-1`） |
| `GameResult` | 詳細牌局結果 XML |

---

#### 17. GetAllBetDetailsForTimeIntervalDV（按時段查詢）

取得任意 24 小時內的注單，時間可跨日。

**頻率限制：每 5 分鐘最多 10 次。最大範圍 24 小時。**

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `FromTime` | DateTime | ✅ | `yyyy-MM-dd HH:mm:ss` |
| `ToTime` | DateTime | ✅ | `yyyy-MM-dd HH:mm:ss` |
| `Username` | String(48) | ❌ | 指定玩家 |

**回應欄位：** 與 GetAllBetDetailsDV 相同。

---

#### 18. GetAllBetDetailsForTransactionID（按 TransactionID 查詢）

依 Seamless 錢包 TransactionID 查詢注單（Seamless Wallet 模式適用）。Transfer Wallet 模式下 TransactionID 固定為 `-1`，此 API 實際意義有限。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `TransactionID` | String(16) | ✅ | PlaceBet / PlayerWin / PlayerLost 的 txnid |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `NumOfRecord` | 返回筆數 |
| `Result` | 牌局結果 XML |
| `BetDetailList` | 注單陣列 |

---

#### 19. GetUserBetItemDV（分頁查詢個人注單）

取得玩家 7 日內的注單，支援分頁（Offset）。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ✅ | 玩家帳號 |
| `FromTime` | DateTime | ✅ | 查詢起始時間 |
| `ToTime` | DateTime | ✅ | 查詢結束時間 |
| `Offset` | Int | ✅ | 分頁起始（初次為 `0`） |

**XML 回應：**

| 欄位 | 說明 |
|---|---|
| `More` | Boolean，是否有更多資料 |
| `ItemCount` | 本次返回筆數 |
| `UserBetItemList` | 注單詳情列表（含 GameResult） |

---

#### 20. GetCancelledBetDetails（已取消注單）

取得被取消的注單紀錄。不指定日期時預設返回近 2 個月 + 當月。

**額外 QS 參數：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Username` | String(48) | ❌ | 玩家帳號 |
| `GameID` | String | ❌ | 遊戲局號 |
| `FromTime` | DateTime | ❌ | `yyyy-MM-dd HH:mm:ss` |
| `ToTime` | DateTime | ❌ | `yyyy-MM-dd HH:mm:ss` |

**XML 回應（每筆）：**

| 欄位 | 說明 |
|---|---|
| `StartTime` | 遊戲開始時間 |
| `CancelledTime` | 取消時間 |
| `GameID` / `HostID` | 局號 / 桌台 ID |
| `BetTime` / `Username` / `BetID` | 下注時間 / 帳號 / 注單 ID |
| `BetAmount` / `BetType` / `BetSource` | 金額 / 類型 / 平台代碼 |
| `TransactionID` | Seamless ID（轉帳錢包時為 `-1`） |
| `BetConfirmation` | `0`=手動；`1`=自動確認 |

---

#### 21. GetActiveHostList（可用桌台清單）

取得目前可用的遊戲桌台列表。

**額外 QS 參數：** 無（僅需共用欄位）

**XML 回應（每筆桌台）：**

| 欄位 | 說明 |
|---|---|
| `HostID` | 桌台 ID |
| `GameType` | 遊戲類型 |
| `HostName` | 桌台顯示名稱 |
| `Enabled` | 後台是否啟用 |
| `GameStatus` | `0`=開放；`1`=關閉 |
| `GameIcon` | 多尺寸圖片 URL |

---

## 簽名

SA Gaming 所有代理端送出的請求均須對參數進行 **DES-CBC 加密**，並附帶 **MD5 數位簽章**，分別以 `q` 和 `s` 兩個 POST 參數傳輸。

### 演算法總覽

| 項目 | 值 |
|---|---|
| 對稱加密 | DES-CBC |
| 加密 Key | EncryptKey（8 bytes） |
| IV（初始向量） | 與 EncryptKey 相同 |
| Padding | PKCS#5 |
| 輸出編碼 | Base64 → URL Encode |
| 簽名演算法 | MD5 |

### 構成步驟

**Step 1 — 建立 Query String（QS）**

將請求參數拼接為標準 URL query string（**不含** URL encoding）：

```
method=CreditBalanceDV&Key=B1081EADF99A4E198BB4214A53CDDA14&Time=20240101123456&Username=testuser001&OrderId=IN20240101123456testuser001&CreditAmount=100.00
```

**Step 2 — DES-CBC 加密 QS**

使用 EncryptKey 對 QS 進行 DES-CBC 加密：

- Key = EncryptKey（8 bytes）
- IV = EncryptKey（與 Key 相同）
- Padding = PKCS#5

加密後位元組做 **Base64 編碼**，再做 **URL Encode**，結果即為 POST 參數 `q`。

**Step 3 — 計算 MD5 簽章**

```
s = MD5( QS + MD5Key + Time + SecretKey )
```

| 元素 | 說明 |
|---|---|
| `QS` | Step 1 建立的 query string（明文） |
| `MD5Key` | SA Gaming 提供（範例：`GgaIMaiNNtg`） |
| `Time` | 與 QS 中**完全相同**的 `yyyyMMddHHmmss` 時間戳 |
| `SecretKey` | SA Gaming 提供（範例：`B1081EADF99A4E198BB4214A53CDDA14`） |

> ⚠️ `Time` 在 QS 和 MD5 串接中必須**完全相同**，否則簽章不匹配（Error 132）。

**Step 4 — 最終 POST Body**

```
q=<URL-Encoded-Base64-DES-加密結果>&s=<MD5-Hash>
```

### PHP 實作範例（phpseclib3）

```php
// Step 1: 建立 QS
$data = [
    'method'       => 'CreditBalanceDV',
    'Key'          => $this->secretKey,
    'Time'         => $time,
    'Username'     => $username,
    'OrderId'      => $orderId,
    'CreditAmount' => $amount,
];
$qs = http_build_query($data);

// Step 2: DES-CBC 加密（PHP: phpseclib3）
$des = new DES('cbc');
$des->setKey($this->encryptKey);
$des->setIV($this->encryptKey);
$encrypted = $des->encrypt($qs);
$q = urlencode(base64_encode($encrypted));

// Step 3: MD5 簽章
$s = md5($qs . $this->md5Key . $time . $this->secretKey);

// Step 4: POST
Http::asForm()->post($apiUrl, ['q' => $q, 's' => $s]);
```

> 本專案使用 `phpseclib3\Crypt\DES`（CBC 模式），Key 與 IV 均設為 EncryptKey。  
> 見 `app/Vendors/Sa/Driver.php` 的 `sign()` 與 `sendRequest()` 方法。

### 常見錯誤

| 錯誤碼 | 症狀 | 可能原因 |
|---|---|---|
| `128` | Decryption error | EncryptKey 錯誤，或加密模式 / Padding 不符 |
| `132` | Sign unmatch | MD5Key / SecretKey 錯誤，或 Time 字串前後不一致 |
| `102` | Secret key incorrect | Key 欄位值與設定不符 |

### Seamless Wallet 回調加密（備查，本站未接入）

SA Gaming 發送給代理的回調請求同樣使用 **DES-CBC** 加密，EncryptKey 與代理呼叫 SA 時相同。代理收到後需先 URL Decode → Base64 Decode → DES Decrypt 還原明文，再驗證簽章。本站接入為 Transfer Wallet，無需實作此流程。

---

## 錯誤碼

### 代理 → SA Gaming（通用 API）

| 代碼 | 說明 |
|---|---|
| `0` | 成功 |
| `100` | Username 錯誤 |
| `101` | 帳號已鎖定 |
| `102` | Secret Key 錯誤 |
| `106` | 伺服器忙碌，稍後再試 |
| `108` | Username 長度 / 格式錯誤 |
| `112` | API 呼叫過於頻繁 |
| `113` | Username 重複 |
| `114` | 幣別不存在 |
| `116` | Username 不存在 |
| `120` | 金額必須大於 0 |
| `121` | 餘額不足 |
| `122` | OrderId 已存在（冪等保護） |
| `124` | DB 錯誤 |
| `125` | 登出失敗 |
| `127` | OrderId 格式錯誤 |
| `128` | 解密失敗 |
| `129` | 系統維護中 |
| `130` | 帳號已停用 |
| `132` | 簽章不匹配 |
| `133` | 建立帳號失敗 |
| `135` | 遊戲無法存取 |
| `142` | 參數錯誤 |
| `145` | 小數精度超過 2 位 |
| `148` | 無效值 |
| `157` | 超出注限上限 |
| `158` | 重複注限規則 |
| `159` | 此 API 僅限轉帳錢包 |
| `160` | 此 API 僅限 Seamless 錢包 |
| `190` | 試玩帳號不支援 |

### Seamless 錢包回調錯誤碼（代理回應給 SA，備查）

| 代碼 | 說明 |
|---|---|
| `0` | 成功 |
| `1000` | 帳號不存在 |
| `1001` | 幣別錯誤 |
| `1002` | 金額錯誤 |
| `1003` | 帳號已鎖定 |
| `1004` | 餘額不足 |
| `1005` | 一般錯誤 |
| `1006` | 解密失敗 |
| `9999` | 系統錯誤 |

---

## 附錄：語言代碼

| 代碼 | 語言 |
|---|---|
| `en-us` | 英文 |
| `zh-hans` | 簡體中文 |
| `zh-hant` | 繁體中文 |
| `th` | 泰文 |
| `vi` | 越南文 |
| `id` | 印尼文 |
| `my` | 緬甸文 |
| `ms` | 馬來文 |
| `hi` | 印地文 |
| `ja` | 日文 |
| `ko` | 韓文 |

> 語言代碼轉換邏輯見 `app/Vendors/Sa/Driver.php` 的 `getLanguage()` 方法。

---

## 附錄：遊戲類型代碼

| 代碼 | 遊戲名稱 |
|---|---|
| `bac` | 百家樂（Baccarat） |
| `dragontiger` | 龍虎 |
| `sicbo` | 骰寶（Sic Bo） |
| `roulette` | 輪盤 |
| `pokdeng` | 博丁（Pok Deng） |
| `deluxeblackjack` | 豪華二十一點 |
| `boundlessblackjack` | 無限二十一點 |
| `fishprawncrab` | 魚蝦蟹 |
| `thaihilo` | 泰國骰寶 |
| `ultraroulette` | 至尊輪盤 |
| `xocdia` | 色碟（Xoc Dia） |
| `carnivaltreasure` | 聚寶嘉年華 |

---

## Seamless Wallet 規格備查（本站未接入）

SA Gaming 同時支援 **Seamless Wallet** 模式，代理需實作 6 個回調端點供 SA Gaming 主動呼叫。本站目前接入為 Transfer Wallet，以下僅作技術備查。

| 回調端點 | 觸發時機 | 代理動作 |
|---|---|---|
| `GetUserBalance` | SA 需知道玩家餘額時 | 返回當前餘額 |
| `PlaceBet` | 玩家下注（3 秒內需回應） | 驗證並扣款 |
| `PlayerWin` | 玩家有淨獲利，局結算後 | 入帳獲利 |
| `PlayerLost` | 玩家無淨獲利，局結算後 | 確認流水記帳 |
| `PlaceBetCancel` | PlaceBet 超時或失敗 | 退還下注 |
| `BalanceAdjustment` | 促銷 / 禮物 / 取消禮物調整 | 對應增減餘額 |

回調使用 **DES-CBC + Base64** 加密，代理回應格式為 XML：

```xml
<?xml version="1.0" encoding="utf-8"?>
<RequestResponse>
  <username>player_name</username>
  <currency>USD</currency>
  <amount>player_balance</amount>
  <error>0</error>
</RequestResponse>
```

**重要**：每個 `txnid` 必須且只處理一次（冪等要求）；`PlaceBet` 超時 SA 會發送 `PlaceBetCancel` 退款。
