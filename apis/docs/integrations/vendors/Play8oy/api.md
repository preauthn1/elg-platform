# Play8oy（iGaming）— API 規格

> 來源：
> - `Playboy_API_Guid_Eng_v10.1.pdf`（iGaming Document, English version, **v10.1**, 2022-05）— **主要事實來源**
> - `Playboy_API_Guid_v10.1.pdf`（同版簡中版，內容對照用）
> - `iGaming_API_Guid_v9.3.pdf` / `iGaming_API_Guid_v9.2.pdf`（舊版，僅參考）
> - `iGaming API Application Form and Integration Data(Playboy)-vworldmy.xlsx`（VWorld 站台整合申請資料）
> - `info.txt`（測試/正式環境連線資訊）
>
> 抓取日期：2026-06-03。對應 vendor：`VendorIdEnum::PLAY8OY = 30`（Transfer Wallet）。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/json`
- **驗證方式**：IP 白名單 + Header 簽章（`merchantName` + `Sign`，見[簽章](#簽章)）

### 請求 Header

| Header | 必填 | 說明 |
|---|---|---|
| `merchantName` | ✅ | 商戶管理員名稱（Merchant admin name）；缺少回 `-10005 Bad Request.` |
| `Sign` | ✅ | SHA256 raw bytes → Base64 簽章；缺/錯回 `-10005 Bad Request.` |
| `Content-Type` | ✅ | `application/json` |

### 通用回應格式

```jsonc
{
  "Code": 0,
  "Message": "Success.",
  "Data": { /* 視 API 而定，可選 */ }
}
```

- `Code = 0` 表成功（本專案實作以字串 `'0'` 比較）。
- 錯誤碼為**負值**（見各端點錯誤碼）。
- 官方 JSON key 大小寫：REST 章節（§3）一致使用首字母大寫（`Code` / `Message` / `Data`）。

### HTTP / 特殊回應碼

| Code | 說明 |
|---|---|
| `-10005` | Bad Request（缺 `merchantName` 或簽章驗證失敗） |
| `-10010` | Maintenance time（系統維護中，`Data` 帶維護時段） |

### URL 組成

實際端點 = `{API_URL}` + 下表 Path。
例：Demo `API_URL = http://example.invalid:8002/`，建立玩家 = `http://example.invalid:8002/player/create`。

### 環境資訊

| 項目 | 測試（Demo） | 正式（VWorld） |
|---|---|---|
| API URL | `http://example.invalid:8002/` | `https://example.invalid/` |
| API KEY (PrivateKey) | `FSfOtJZGexIrsDnKwK%J` | `FSfOtJZGexIrsDnKwK%J` |
| merchantName | `vworldmy` | `vworldmy` |
| Prefix | `vwm_` | `vwm_` |
| Backend | — | `example.invalid` |

> 上述為 VWorld 站台實際值，僅供整合對照；正式金鑰屬機敏資訊，請勿外流。其他白標以各自 `vendor_infos` 為準。

---

## Merchant API

所有端點皆 `POST {API_URL}{path}`，Header 帶 `merchantName` + `Sign`（見[簽章](#簽章)）。

### API 清單（21 支）

| # | 功能 | Path | Driver 呼叫 |
|---|---|---|---|
| 1 | New players | `player/create` | — |
| 2 | Inquire player | `player/exists` | — |
| 3 | Obtain player data | `player/info` | ✅ `getBalance` |
| 4 | Player list | `player/list` | — |
| 5 | Frozen player account | `player/freeze` | — |
| 6 | Unfreeze player account | `player/unfreeze` | — |
| 7 | Reset password | `player/resetpassword` | ✅ `resetPassword` |
| 8 | Kick player out | `player/kick` | — |
| 9 | Deposit or withdrawal | `transaction/create` | ✅ `transferToVendor` / `transferToLocal` |
| 10 | Examine transaction results | `transaction/check` | ✅ `checkTransferStatus` |
| 11 | Obtain transaction history | `transaction/log` | — |
| 12 | Player statistics form | `report/playerstatreport` | — |
| 13 | App download link | `appurl` | ✅ 當 `GET_DOWNLOAD_URL=true` |
| 14 | Inquire manager credit limit | `admin/credit` | — |
| 15 | Inquire player gaming history | `game/log/record` | — |
| 16 | Querying System Maintenance Time | `maintenance/all` | — |
| 17 | Game list | `codetable/gamelist` | — |
| 18 | Lottery activity game record | `game/log/lottery` | — |
| 19 | Gaming history (By Agent) | `game/log/agent` | — |
| 20 | Gaming history total (By Agent) | `game/log/agentsummary` | — |
| 21 | Gaming history (merchant/agent/player) | `game/log/all` | — |

---

### 1. New players — `player/create`

建立新玩家。玩家帳號需自行帶入商戶前置碼（prefix）。

> Driver 未覆寫 `createAccount()`；程式碼路徑中沒有呼叫此端點。玩家建立方式待確認（見 [integration.md](integration.md) gaps）。

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ | 6–20 英數字，首字須為字母；須含 prefix（如 `vwm_`） |
| `Password` | varchar(14) | ✅ | 6–14 英數字 |
| `Agent` | varchar(50) | ✅ | Kiosk（站台網吧名稱），把玩家建立在該 kiosk 下 |

```jsonc
{ "PlayerAccount": "ts_M00001", "Password": "123456", "Agent": "TestMerchant" }
```

#### Response 錯誤碼

| Code | Message |
|---|---|
| 0 | Success. |
| -90002 | Unknown error |
| -90003 | Unexpected error |
| -90200 | Player creation failed (unable to save player data in the game database). |
| -90201 | Player account already exists. Please choose a different name. |
| -90202~-90210 | 帳號格式/空白/首字/字元/長度/prefix 等錯誤 |
| -90300~-90305 | Password 長度/字元/空白/無效 等錯誤 |
| -90400 | Player handle already exists. |
| -90701 | Currency is invalid. |
| -90702~-90705 | agent 不存在/不正確/API agent 不存在/kiosk 不存在 |

---

### 2. Inquire player — `player/exists`

查玩家帳號是否存在。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |

#### Response 錯誤碼

| Code | Message |
|---|---|
| -90003 | Unexpected error |
| -90208 | Player does not exist. |
| -90209 | Player exists.（HTTP 400） |

---

### 3. Obtain player data — `player/info`

取得玩家餘額、在線、凍結狀態。**本專案 `getBalance()` 使用此端點**。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |

#### Response

```jsonc
{
  "Code": 0,
  "Message": "Success.",
  "Data": { "Balance": 0, "IsOnline": false, "IsFrozen": false }
}
```

#### 錯誤碼

| Code | Message |
|---|---|
| 0 | Success. |
| -90003 | Unexpected error |
| -90208 | Player does not exist. |
| -90500 | Request GTAPI player open score balance failure. |
| -90501 | Request GTAPI player data failure. |
| -90502 | Unknown error while player is in game. |

---

### 4. Player list — `player/list`

分頁取得商戶所有玩家。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PageNumber` | int | ✅ |

#### Response

`Data.Page = { PageNumber, PageSize, TotalSize, TotalPage }`；`Data.PlayerInfo[] = { PlayerAccount, CreateTime }`。

---

### 5. Frozen player account — `player/freeze`

凍結玩家（無法登入、無法開分/洗分）。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(50) | ✅ |

#### 錯誤碼

| Code | Message |
|---|---|
| -90208 | Player does not exist. |
| -90212 | Player account has been frozen. |

---

### 6. Unfreeze player account — `player/unfreeze`

解凍玩家。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |

#### 錯誤碼

| Code | Message |
|---|---|
| -90208 | Player does not exist. |
| -90211 | Player account has not been frozen. |

---

### 7. Reset password — `player/resetpassword`

重設玩家密碼。**本專案 `resetPassword()` 使用此端點**。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |
| `Password` | varchar(14) | ✅ |

#### 錯誤碼

| Code | Message |
|---|---|
| -90208 | Player does not exist. |
| -90300 | Password is too short. |
| -90301 | Password is too long. |
| -90302 | Password contains invalid characters. |
| -90303 | Password reset failed. |

---

### 8. Kick player out — `player/kick`

把玩家踢下線。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |

#### 錯誤碼

| Code | Message |
|---|---|
| -90504 | Player was kicked out by another GM. |
| -90505 | Player is not in game. |
| -90506 | Player is not online. |
| -90507 | Kick player failed. |
| -90508 | Player has been offline. |

---

### 9. Deposit or withdrawal transaction — `transaction/create`

正數金額=存款（本地 → Play8oy），負數金額=提款（Play8oy → 本地）。**本專案 `transferToVendor` / `transferToLocal` 使用此端點**。

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | varchar(50) | ✅ | 玩家帳號 |
| `Amount` | decimal | ✅ | 金額不可為 0；正存負提 |
| `ExternalTransactionId` | varchar(20) | ✅ | 商戶產生的唯一交易號（UUIDv7 去 `-` 截前 20 碼） |

存款範例：

```jsonc
{ "PlayerAccount": "ts_M00001", "Amount": 100, "ExternalTransactionId": "SS201607070001" }
```

提款範例：

```jsonc
{ "PlayerAccount": "ts_M00001", "Amount": -50, "ExternalTransactionId": "SG201607070002" }
```

#### Response

```jsonc
{ "Code": 0, "Message": "Success.", "Data": { "ApiTransId": 100364 } }
```

#### 錯誤碼

| Code | Message |
|---|---|
| 0 | Success. |
| -90002 | Unknown error |
| -90003 | Unexpected error |
| -90208 | Player does not exist. |
| -90212 | Player account has been frozen. |
| -90500 | Request GTAPI player deposit balance failure. |
| -90501 | Request GTAPI player data failure. |
| -90600 | Invalid balance format. |
| -90601 | Transaction balance cannot be zero. |
| -90602 | Deposit amount exceeds maximum limit. |
| -90603 | Deposit amount below minimum limit. |
| -90604 | Withdrawal amount exceeds maximum limit. |
| -90605 | Withdrawal amount below minimum limit. |
| -90606 | Player balance insufficient, cannot withdrawal. |
| -90607 | Manager balance insufficient, cannot deposit. |
| -90610 | External transaction ID already exists in API system.（冪等：交易號重複） |
| -90615 | Transaction ID cannot be left blank. |
| -90616 | Transaction ID has exceeded maximum length. |
| -90617 | Player cannot withdrawal while in game. |

> 本專案實作（`TransactionResponseCodeEnum::transferResult()`）：無 JSON 回應 → `TIMEOUT`；`Code == '0'` → `SUCCESS`；三方明確拒絕碼（`-90208`、`-90212`、`-90600`～`-90607`、`-90615`～`-90617`）→ `FAILED`；其餘含 `-90002`／`-90003`／`-90500`／`-90501`／`-90610` 與未知碼 → `TIMEOUT`（結果不明不自動退款），對照表見 [integration.md](integration.md)。

---

### 10. Examine transaction results — `transaction/check`

查詢某筆交易結果（網路逾時後補查最終狀態）。**本專案 `checkTransferStatus()` 使用此端點**。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PlayerAccount` | varchar(20) | ✅ |
| `ExternalTransactionId` | varchar(20) | ✅ |

#### Response

```jsonc
{ "Code": 0, "Message": "Success.", "Data": { "ApiTransId": 100364 } }
```

#### 錯誤碼

| Code | Message |
|---|---|
| 0 | Success.（交易成功） |
| -90001 | Failure.（交易失敗） |
| -90003 | Unexpected error |
| -90608 | Unresolved.（尚未有結果，即 pending） |
| -90609 | Transaction does not exist.（交易不存在） |

> 本專案實作（`TransactionResponseCodeEnum::checkResult()`）：`Code == '0'` → `SUCCESS`；`-90001`、`-90609` → `FAILED`；`-90608`（仍處理中）、`-90003` 與其餘未知碼 → `TIMEOUT`。
> 落差：`-90609` 若在交易剛送出時短暫出現，可能誤判為 `FAILED`（見 [integration.md](integration.md) 高風險陷阱 §5）。

---

### 11. Obtain transaction history — `transaction/log`

分頁取得玩家轉帳歷史（開分/洗分/紅包）。

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `PageNumber` | int | ✅ | 頁碼 |
| `StartTime` | datetime | ✅ | 起始時間（年月日時分秒） |
| `EndTime` | datetime | ✅ | 結束時間 |

#### Response（`Data.List[]`）

| 欄位 | 說明 |
|---|---|
| `ExternalTransactionId` | 外部交易號（`OperationMethod=3` 時為 null） |
| `OperationMethod` | 1=開分、2=洗分、3=紅包 |
| `PlayerAccount` | 玩家帳號 |
| `TransAmount` | 交易金額 |
| `Result` | 0=unresolved、1=success、2=failure |
| `CreateTime` | 交易時間 |

---

### 12. Player statistics form — `report/playerstatreport`

取得每位玩家輸贏統計。

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `PageNumber` | int | ✅ | 頁碼 |
| `StartDate` | datetime | ✅ | 起始日（年月日） |
| `EndDate` | datetime | ✅ | 結束日（年月日） |

#### Response（`Data.PlayerStatReport`）

`List[]` 每筆欄位：

| 欄位 | 說明 |
|---|---|
| `OrderIndex` | 序號 |
| `PlayerAccount` | 玩家帳號 |
| `TotalBet` | 總下注 |
| `TotalWin` | 總贏（含紅包） |
| `TotalBetWin` | 總下注 − 總贏 |
| `TotalIn` | 總開分 |
| `TotalOut` | 總洗分 |
| `TotalInOut` | 總開分 − 總洗分 |
| `JPTotalWin` | JP 贏分 |
| `LotteryTotalWin` | 彩券總額（公司支付） |

`Sum` 欄位為各欄的 SUM 彙總（含 `RedEnvelopeTotalWinSUM`）。

#### 錯誤碼

| Code | Message |
|---|---|
| -90611 | Inquiry range of dates is too large. |
| -20001 | No data found. |

---

### 13. App download link — `appurl`

取得商戶 App 的 Android / iOS 下載連結。**本專案 `getGameUrl()` 在 `GET_DOWNLOAD_URL=true` 時呼叫**。

#### Request

無業務參數（簽章字串僅 `PrivateKey={key}`）。

#### Response

```jsonc
{
  "Code": 0,
  "Message": "Success.",
  "Data": {
    "Android": "https://example.invalid/Installer/Download/APIDemo_APK.ashx",
    "iOS": "https://example.invalid/Installer/Download/APIDemo_iOS.ashx"
  }
}
```

---

### 14. Inquire manager credit limit — `admin/credit`

查商戶信用額度。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `Admin` | varchar(50) | ✅ |

#### Response

```jsonc
{ "Code": 0, "Message": "Success.", "Data": { "Admin": "RD_TestEntity1A", "CreditAmount": 4940 } }
```

---

### 15. Inquire player gaming history — `game/log/record`

取得玩家注單明細（回合層級）。

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `PageNumber` | int | ✅ | 頁碼 |
| `StartDate` | datetime | ✅ | 起始（年月日時分秒.毫秒） |
| `EndDate` | datetime | ✅ | 結束；**StartDate − EndDate 必須 ≤ 10 分鐘（600 秒）** |

> 官方建議拉取頻率：每 1 分鐘抓前 20 分鐘的 1 分鐘資料，或每 5 分鐘抓前 20 分鐘的 5 分鐘資料（§3.15）。

#### Response（`Data.Record[]`）

| 欄位 | 說明 |
|---|---|
| `ThemeID` | 前四碼=遊戲 ID，後四碼=hall ID |
| `GameNO` | 局號 |
| `GameSerialID` | 序號 |
| `AccountID` | 玩家 ID |
| `UserName` | 玩家帳號 |
| `ValueBefore` | 下注前餘額 |
| `ValueAfter` | 下注後餘額 |
| `BetCoin` | 下注金額 |
| `WinAmount` | 贏分 |
| `AvailTotalBet` | 有效下注 |
| `AvailTotalWin` | 有效贏分 |
| `RemoteIP` | 玩家 IP |
| `DeviceType` | 裝置類型（見附錄 DeviceType） |
| `CreateTime` | 紀錄時間 |

#### 錯誤碼

| Code | Message |
|---|---|
| -50001~-50016 | 各種查詢參數錯誤 |
| -50020 | 查詢區間過大（超過 600 秒） |
| -90001~-90003 | Unknown/Unexpected error |

---

### 16. Querying System Maintenance Time — `maintenance/all`

#### Request

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Maintenance` | varchar(3) | ✅ | `All`（取全部維護時間） |

#### Response

`Data[]` 每筆：`{ Title, Contents, StartDatetime, EndDatetime }`。

> 維護期間所有 API 操作固定回 `-10010 Maintenance time.`，`Data` 帶維護時段。

---

### 17. Game list — `codetable/gamelist`

#### Response（`Data[]`）

| 欄位 | 說明 |
|---|---|
| `GameID` | 遊戲 ID（如 3222） |
| `GameName` | 遊戲名稱（如 `Golden Rooster`） |
| `GameTypeID` | 類型 ID（如 1001） |
| `HighRoller` | 是否高額桌 |
| `GameTypeName` | 類型名稱（如 `SLOT`） |

---

### 18. Lottery activity game record — `game/log/lottery`

取得彩券活動注單。

#### Request

| 欄位 | 類型 | 必填 |
|---|---|---|
| `PageNumber` | int | ✅ |
| `StartDate` | datetime | ✅ |
| `EndDate` | datetime | ✅ |

#### Response（`Data` list form）

除注單通用欄位外另含：`LotteryRanking`、`DrawNumber`（開獎期數）、`LotteryTicketNumber`、`PaymentMethod`（0=公司支付、1=按比例）。

---

### 19–21. By Agent / 多維注單查詢

| Path | 用途 | 額外參數 |
|---|---|---|
| `game/log/agent` | 依代理查注單明細 | `Agent` |
| `game/log/agentsummary` | 依代理查注單彙總 | `Agent` |
| `game/log/all` | merchant/agent/player 多維查詢 | `Agent`（選填）、`PlayerAccount`（選填） |

`game/log/all` Request：`PageNumber` + `StartDate` + `EndDate`（≤600 秒）+ 選填 `Agent` / `PlayerAccount`；回應 list form 同 [§15](#15-inquire-player-gaming-history--gamelogrecord)。

---

## 錯誤碼彙整

各端點錯誤碼見上方各章節。以下為跨端點通用錯誤碼：

| Code | Message | 出現場景 |
|---|---|---|
| 0 | Success. | 所有端點 |
| -90001 | Failure. | `transaction/check` 交易失敗 |
| -90002 | Unknown error | 建立玩家、轉帳 |
| -90003 | Unexpected error | 多數端點 |
| -90208 | Player does not exist. | 玩家相關端點 |
| -90212 | Player account has been frozen. | 轉帳、凍結 |
| -10005 | Bad Request. | Header 缺 `merchantName` 或簽章錯誤 |
| -10010 | Maintenance time. | 維護中 |

---

## 簽章

### 演算法總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | SHA256 |
| 輸入編碼 | UTF-8 bytes |
| 輸出編碼 | Base64（對 SHA256 **raw bytes** 做 Base64，非 hex） |
| 金鑰 | `PrivateKey`（= `vendor_infos.info.API_KEY`，商戶 API 認證碼） |
| 簽章位置 | HTTP Header `Sign` |

### 構成步驟（官方 §2.2）

**Step 1**：將所有請求 body 參數依**參數名升冪排序**，串成 query string，並在最後接上 `PrivateKey={key}`：

```
Parameter1={v1}&Parameter2={v2}&...&ParameterN={vN}&PrivateKey={key}
```

官方範例（建立玩家，參數 Agent / Password / PlayerAccount）：

```
Agent=RD_TestEntity1&Password=[REDACTED]
```

**Step 2**：對字串做 SHA256 raw bytes，再 Base64 encode：

官方 PHP 範例：

```php
$s = 'Agent=RD_TestEntity1&Password=[REDACTED]';
$signature = base64_encode(hash("sha256", $s, True));
// 結果：7XxkcLhd+AbNO2xf7M+VPNUYJ6zuYFcLn1SudAZVp10=
```

> 重點：`hash("sha256", $s, true)` 第三參數 `true` 必須帶，輸出 raw binary（32 bytes）再 Base64；若漏掉會得到 64 字元 hex 再 Base64，結果不符。

官方 example.invalid 範例：

```csharp
HashAlgorithm hashAlgorithm = new SHA256Managed();
byte[] byteAry = hashAlgorithm.ComputeHash(
    Encoding.UTF8.GetBytes("Agent=RD_TestEntity1&Password=[REDACTED]"));
string stringEncryptResult = Convert.ToBase64String(byteAry);
```

### 本專案實作（`Driver.php::sign()`）

```php
protected function sign(array $data = []): string
{
    if (count($data) > 0) {
        ksort($data);                                               // 參數名升冪排序
        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = $key . '=' . (string) $value;                // 原始值，不做 URL 編碼
        }
        $string = implode('&', $pairs) . '&PrivateKey=' . $this->apiKey;
    } else {
        $string = 'PrivateKey=' . $this->apiKey;                    // 無參數時僅 PrivateKey
    }
    $hash_hmac = hash('sha256', $string, true);                     // raw bytes

    return base64_encode($hash_hmac);
}
```

- `ksort()` 對應官方「ascending order」。
- 無業務參數的端點（如 `appurl`）僅以 `PrivateKey={key}` 為簽章字串。
- `PrivateKey` 只進簽章字串，**不進 POST body**。

### 請求範例（New Player）

```http
POST {API_URL}player/create
Content-Type: application/json
merchantName: PLAYBOY
Sign: 7XxkcLhd+AbNO2xf7M+VPNUYJ6zuYFcLn1SudAZVp10=

{
  "Agent": "RD_TestEntity1",
  "Password": "123456",
  "PlayerAccount": "t1_botest21"
}
```

### 原始字串串接（不可 URL 編碼）

簽章字串以原始值串接，與官方範例一致。舊實作用 `http_build_query()` 會對參數值做 percent-encode，`game/log/record` 的日期參數（含空白、冒號）會與驗章不符，已於 target/tickets#4434 修正（見 [integration.md](integration.md) 高風險陷阱 §2）。

---

## 附錄

### DeviceType code（Appendix 1）

| Device | Code |
|---|---|
| PC | 0 |
| Android | 100 |
| iOS | 200 |

### API Request Limits（Appendix 6，官方標示 Not activated）

| 分類 | 端點 | 每分鐘上限 |
|---|---|---|
| Report | `report/playerstatreport` | 5 |
| Information query | `player/info`、`player/exists`、`admin/credit`、`transaction/check`、`maintenance/all`、`game/log/agentsummary` | 200 |
| Trading | `player/kick`、`player/resetpassword`、`player/freeze`、`player/unfreeze`、`transaction/create`、`player/create` | 20 |
| Platform Information | `codetable/gamelist`、`appurl` | 25 |
| Bet record | `game/log/record`、`game/log/agent`、`transaction/log`、`game/log/lottery`、`player/list` | 40 |

### 中英文對照（聯絡客服用）

| 英文 | 簡中 | Path |
|---|---|---|
| New players | 新增玩家 | `player/create` |
| Obtain player data | 獲取玩家資料 | `player/info` |
| Reset password | 重設密碼 | `player/resetpassword` |
| Deposit or withdrawal transaction | 存提款交易 | `transaction/create` |
| Examine transaction results | 查詢交易結果 | `transaction/check` |
| App download link | App 下載連結 | `appurl` |
| Inquire player gaming history | 查詢玩家遊戲紀錄 | `game/log/record` |
