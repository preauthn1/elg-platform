# GreatWall — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與簽名規格）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### Base URL

| 環境 | API URL | 後台 |
|---|---|---|
| 測試 | `http://example.invalid` | `https://example.invalid/IGKiosk` |
| 正式 | `http://example.invalid` | `https://example.invalid/IGKiosk/` |

### 請求 Header

```
merchantName : <商戶名>
signature    : <簽名值>
```

- 所有 API 一律 `POST`，`Content-Type: application/json`
- 驗證方式：HTTP Header `merchantName` + `signature`（演算法見下方「簽名」章節）

### 回應格式

所有 API 回 HTTP 200，業務結果看 body：

```jsonc
{
  "result": {
    "code": "0",          // "0" = 成功（字串）
    "message": "success",
    "success": true
  },
  "data": null            // 視 API 而定，可能為 object / array / null
}
```

> `code` 為**字串**。成功值絕大多數 API 為 `"0"`；3.4 玩家列表範例出現 `"00000"`（詳見 `integration.md` 高風險陷阱）。

### 系統維護回應

維護期間固定回 `code: -10010`，body 帶 `Title` / `Contents` / `StartDatetime` / `EndDatetime`。

### 時區

報表 3.14 標注自 `2022-06-01 (UTC+8)` 起生效，推測所有時間欄位為 UTC+8，未逐一明文標注。

### 玩家帳號格式

- 新增玩家（3.1）傳玩家**本名**（1-9 字元），系統自動加代理前綴
- **其餘所有 API 的 `PlayerAccount` 皆須傳「代理前綴 + 本名」完整帳號**
- 範例：代理 `JKL_0701001`，玩家 `abc123` → 完整帳號 `JKL_0701001abc123`

---

## Merchant API

### 目錄

| # | 名稱 | Endpoint |
|---|---|---|
| 3.1 | 新增玩家 | `/Api/IGame/Player/Create` |
| 3.2 | 檢查玩家 | `/Api/IGame/Player/Exists` |
| 3.3 | 取得玩家資訊（餘額） | `/Api/IGame/Player/Info` |
| 3.4 | 玩家列表 | `/Api/IGame/Player/List` |
| 3.5 | 凍結玩家 | `/Api/IGame/Player/Freeze` |
| 3.6 | 解凍玩家 | `/Api/IGame/Player/Unfreeze` |
| 3.7 | 重置密碼 | `/Api/IGame/Player/Resetpassword` |
| 3.8 | 踢除玩家 | `/Api/IGame/Player/Kick` |
| 3.9 | 開洗分交易（轉帳） | `/Api/IGame/Transaction/Create` |
| 3.10 | 檢查交易結果 | `/Api/IGame/Transaction/Check` |
| 3.11 | 取得交易記錄 | `/Api/IGame/Transaction/Log` |
| 3.12 | APP 下載連結 | `/Api/IGame/Appurl` |
| 3.13 | 查詢管理員信用額度 | `/Api/IGame/Admin/Credit` |
| 3.14 | 查詢玩家遊戲記錄（新版） | `/Api/IGame/Game/Log/Agent` |
| 3.15 | 查詢系統維護時間 | `/Api/IGame/Maintenance/All` |
| 3.16 | 遊戲清單 | `/Api/IGame/GameList` |
| 3.17 | 查詢彩票活動遊戲記錄 | `/Api/IGame/Game/Log/Lottery` |
| 3.18 | 查詢玩家遊戲記錄總數 | `/Api/IGame/Game/Log/AgentSummary` |
| 3.19 | 獲取玩家登錄代幣（H5 token） | `/Api/IGame/Player/Token` |

---

### 3.1 新增玩家 — `/Api/IGame/Player/Create`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(9) | ✅ | 玩家帳號 1-9 字元（**不含代理前綴**） |
| `Password` | Varchar(14) | ❌ | 6-14 字元，需英文+數字組合 |
| `Agent` | Varchar(50) | ✅ | 代理商 |

成功回應 `result.code = "0"`，`data: null`。

---

### 3.2 檢查玩家 — `/Api/IGame/Player/Exists`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |

回應：`-90208 玩家不存在` / `-90209 玩家已經存在`。

---

### 3.3 取得玩家資訊 — `/Api/IGame/Player/Info`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |

```jsonc
{
  "result": { "code": "0", "message": "成功", "success": true },
  "data": { "balance": 0, "isOnline": false, "isFrozen": false }
}
```

---

### 3.4 玩家列表 — `/Api/IGame/Player/List`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `CreatedDateFrom` | DateTime? | ❌ | 建立日期起；可傳 null |
| `CreatedDateTo` | DateTime? | ❌ | 建立日期迄；可傳 null |

回應 `data.page` { `pageNumber`, `totalSize` } + `data.playerInfo[]` { `PlayerAccount`, `CreateTime` }。

> ⚠️ 此 API 範例中成功碼為 `"00000"`（非 `"0"`），見 `integration.md` 高風險陷阱 #1。

---

### 3.5 凍結玩家 — `/Api/IGame/Player/Freeze`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(50) | ✅ | 完整玩家帳號 |

特有錯誤：`-90212 玩家已被凍結`。

---

### 3.6 解凍玩家 — `/Api/IGame/Player/Unfreeze`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |

特有錯誤：`-90211 玩家沒有被凍結`。

---

### 3.7 重置密碼 — `/Api/IGame/Player/Resetpassword`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |
| `Password` | Varchar(14) | ✅ | 6-14 字元，需英文+數字組合 |

特有錯誤：`-90300 ~ -90304`（密碼長度/格式/重設失敗）。

---

### 3.8 踢除玩家 — `/Api/IGame/Player/Kick`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |

特有錯誤：`-90504` / `-90505` / `-90506` / `-90507` / `-90508`（踢除失敗系列）。

---

### 3.9 開洗分交易 — `/Api/IGame/Transaction/Create`

**轉帳錢包核心 API**。Amount 正數為開分（存款），負數為洗分（提款）。

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |
| `Amount` | Decimal | ✅ | 開分 10 ~ 200,000；洗分 -10 ~ -20,000 |
| `ExternalTransactionId` | Varchar(20) | ✅ | 商戶產生的唯一交易 Id |

成功回應：

```jsonc
{
  "result": { "code": "0", "message": "成功", "success": true },
  "data": { "ApiTransId": 100364 }
}
```

開洗分專屬錯誤碼：

| Code | 說明 |
|---|---|
| `-90500` | 訪問 GTAPI 玩家開分餘額時失敗 |
| `-90501` | 訪問 GTAPI 玩家資訊失敗 |
| `-90600` | 無效金額格式 |
| `-90601` | 交易金額不能為零 |
| `-90602` | 金額超過最高開分限制 |
| `-90603` | 金額超過最低開分限制 |
| `-90604` | 金額超過最高洗分限制 |
| `-90605` | 金額超過最低洗分限制 |
| `-90606` | 玩家餘額不足以洗分 |
| `-90607` | 管理員餘額不足無法開分 |
| `-90610` | 外部交易 Id 在 API 系統重複 |
| `-90615` | 交易 Id 不能為空 |
| `-90616` | 交易 Id 長度超過限制 |
| `-90617` | 玩家在遊戲中不能洗分 |
| `-90618` | 必須間隔 15 秒（連續轉帳節流） |

---

### 3.10 檢查交易結果 — `/Api/IGame/Transaction/Check`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |
| `ExternalTransactionId` | Varchar(20) | ✅ | 商戶唯一交易 Id |

特有錯誤：`-90001 失敗` / `-90608 尚未處理` / `-90609 交易不存在`。

---

### 3.11 取得交易記錄 — `/Api/IGame/Transaction/Log`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `CreatedDateFrom` | DateTime? | ❌ | 起；可傳 null |
| `CreatedDateTo` | DateTime? | ❌ | 迄；可傳 null |

回應 `data.list[]` 欄位：

| 欄位 | 說明 |
|---|---|
| `ExternalTransactionId` | 外部交易 Id（OperationMethod=3 紅包時為空） |
| `OperationMethod` | `1:` 開分、`2:` 洗分、`3:` 紅包（福袋、新年禮物） |
| `PlayerAccount` | 玩家帳號 |
| `TransAmount` | 交易金額 |
| `Result` | `0:` 未處理、`1:` 成功、`2:` 失敗 |
| `CreateTime` | 交易時間 |

> 記錄延遲 10-30 分鐘，**只保留 1 個月**。紅包金額需用 `OperationMethod=3, Result=1` 加總，以「玩家帳戶 + CreateTime」辨別唯一 key。

---

### 3.12 APP 下載連結 — `/Api/IGame/Appurl`

無請求參數（簽名僅 `PrivateKey=...`）。

```jsonc
{
  "result": { "code": "0", "message": "成功", "success": true },
  "data": {
    "Android": "https://.../APIDemo_APK.ashx",
    "iOS": "https://.../APIDemo_APK.ashx"
  }
}
```

---

### 3.13 查詢管理員信用額度 — `/Api/IGame/Admin/Credit`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `Admin` | Varchar(50) | ✅ | 管理員帳號 |

回應 `data` { `Admin`, `CreditAmount` }。特有錯誤 `-20001 查詢不到資料`。

---

### 3.14 查詢玩家遊戲記錄（新版） — `/Api/IGame/Game/Log/Agent`

> 官方：2022-06-01 (UTC+8) 起全面使用此組。

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PageNumber` | int | ✅ | 請求頁面 |
| `StartDate` | datetime | ✅ | 起（年月日時分秒毫秒） |
| `EndDate` | datetime | ✅ | 迄 |

回應 `data.Page` { `PageNumber`, `PageSize`, `TotalSize`, `TotalPage` } + `data.Record[]`：

| 欄位 | 說明 |
|---|---|
| `ThemeID` | 前四碼遊戲 id，後四碼廳館 id |
| `GameNO` | 局號 |
| `GameSerialID` | 序號 |
| `AccountID` | 玩家 id |
| `UserName` | 玩家帳號 |
| `ValueBefore` / `ValueAfter` | 押注前/後餘額 |
| `BetCoin` | 押注 |
| `WinAmount` | 贏分（**不含紅包**；紅包需另從 3.11 `OperationMethod=3` 加總） |
| `AvailTotalBet` / `AvailTotalWin` | 有效押注 / 有效贏分 |
| `RemoteIP` | 用戶 IP |
| `DeviceType` | 見 DeviceType 代碼表 |
| `CreateTime` | 記錄時間 |

> 記錄延遲 10-30 分鐘。日期為今日時須限制在現在之前；起訖須同一日；起不可 >= 迄（錯誤碼 `-50003` / `-50004` / `-50005`）。

---

### 3.15 查詢系統維護時間 — `/Api/IGame/Maintenance/All`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `Maintenance` | Varchar(3) | ✅ | 固定傳 `All` |

回應 `data[]` { `Title`, `Contents`, `StartDateTime`, `EndDateTime` }。

---

### 3.16 遊戲清單 — `/Api/IGame/GameList`

無業務參數。回應 `data[]` { `gameID`, `gameName`, `gameTypeID`, `highRoller`, `gameTypeName` }。

---

### 3.17 查詢彩票活動遊戲記錄 — `/Api/IGame/Game/Log/Lottery`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `CreatedDateFrom` | DateTime? | ❌ | 起；可傳 null |
| `CreatedDateTo` | DateTime? | ❌ | 迄；可傳 null |

回應欄位同 3.14，另含 `LotteryRanking`、`DrawNumber`、`LotteryTicketNumber`、`PaymentMethod`（`0:` 總公出 / `1:` 按比例）。

---

### 3.18 查詢玩家遊戲記錄總數 — `/Api/IGame/Game/Log/AgentSummary`

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `StartDate` | datetime | ✅ | 起 |
| `EndDate` | datetime | ✅ | 迄 |

回應 `data.record[]` { `TotalBet`, `TotalWin`, `EffectiveTotalBet`, `EffectiveTotalWin`, `Cnt` }。

---

### 3.19 獲取玩家登錄代幣 — `/Api/IGame/Player/Token`

H5 自動登入用。瀏覽 H5 時加 `altoken=[REDACTED] 有效期 5 分鐘**。

| 參數 | 型態 | 必填 | 說明 |
|---|---|---|---|
| `PlayerAccount` | Varchar(20) | ✅ | 完整玩家帳號 |

特有錯誤：`-00006 玩家為空` / `-00007 玩家不存在` / `-00008 玩家無效`。

---

### DeviceType 代碼

| Device | 代碼 |
|---|---|
| PC | `0` |
| Android | `100` |
| iOS | `200` |

---

## 簽名

### 演算法

| 項目 | 規格 |
|---|---|
| 雜湊演算法 | SHA256（非 HMAC，純 SHA256） |
| 輸入 | 原始字串以 UTF-8 轉 byte array |
| 輸出 | Base64 |
| PrivateKey 位置 | 永遠附在字串最尾端（不參與字母排序） |
| 傳輸位置 | HTTP Header `signature`（不放進 body） |

### 組合規則

1. 取本次 API 的所有請求參數（POST body 的 key/value）
2. 參數名稱依字母**升冪排序**（A → P → Pl...）
3. 以 `key=value&key=value...` 串接
4. 最後固定附上 `&PrivateKey=<你的 PrivateKey>`
5. 對整段字串做 SHA256（raw bytes），再 Base64 編碼

無請求參數時（如 `/Api/IGame/Appurl`）：簽名字串僅為 `PrivateKey=<你的 PrivateKey>`

### 範例

組合字串：
```
Password=[REDACTED]
```

簽名結果（SHA256 raw bytes → Base64）：
```
CGEdd8AGg4WaI8B2tRjFBDaclhOcfkWUUx2RuBWXd4Q=
```

### PHP 實作參考

```php
// ksort 升冪排序，http_build_query 串接，尾綴 PrivateKey
ksort($data);
$string = count($data) > 0
    ? http_build_query($data) . '&PrivateKey=' . $privateKey
    : 'PrivateKey=' . $privateKey;
$signature = base64_encode(hash('sha256', $string, true)); // true = raw bytes
```

> ⚠️ `http_build_query` 會對特殊字元 URL-encode，若 PrivateKey 含 `%`、`@` 等字元可能與廠商期望明文不一致，見 `integration.md` 高風險陷阱 #2。

---

## 錯誤碼

### 通用 / 簽名

| Code | 說明 |
|---|---|
| `0` / `00000` | 成功 |
| `-00002` | 加密簽名無效 |
| `-00003` | 最高老板層不存在 |
| `-00004` | 商家名稱不能為空 |
| `-00005` | 加密簽名不能為空 |
| `-10010` | 系統維護中 |
| `-90001` | 失敗 |
| `-90002` | 未知的錯誤 |
| `-90003` | 例外錯誤 |

### 玩家 / 帳號

| Code | 說明 |
|---|---|
| `10001` | 玩家名稱不能為空 |
| `10002` | 密碼不能為空 |
| `10003` | 玩家帳號字符上限 9 位 |
| `-90200` | 創建玩家失敗 |
| `-90201` | 重複的玩家帳號 |
| `-90202` | 玩家帳號格式不正確 |
| `-90203` | 玩家帳號不能為空白 |
| `-90204` | 玩家帳號第一個字必須是字母 |
| `-90205` | 玩家帳號必須是字母與數字 |
| `-90206` | 玩家帳號字符上限 20 位 |
| `-90207` | 玩家帳號字符必須高於 6 位數 |
| `-90208` | 玩家不存在 |
| `-90209` | 玩家已經存在 |
| `-90210` | 帳號前綴詞不正確 |
| `-90211` | 玩家沒有被凍結 |
| `-90212` | 玩家已被凍結 |
| `-90300` | 密碼最大長度須為 14 位數字內 |
| `-90301` | 密碼最小長度須大於 6 位數字 |
| `-90302` | 密碼必須是字母與數字 |
| `-90303` | 重設密碼失敗 |
| `-90304` | 無效的密碼 |
| `-90305` | 密碼不能為空 |
| `-90400` | 玩家暱稱重覆 |
| `-90504` | 玩家被其他 GM 踢除 |
| `-90505` | 玩家不在遊戲中 |
| `-90506` | 玩家不在線上 |
| `-90507` | 登出玩家失敗 |
| `-90508` | 玩家已離線 |
| `-90701` | 無效的貨幣 |

### 交易

| Code | 說明 |
|---|---|
| `-90500` | 訪問 GTAPI 玩家開分餘額時失敗 |
| `-90501` | 訪問 GTAPI 玩家資訊失敗 |
| `-90502` | 查詢玩家是否在遊戲中時發生不明錯誤 |
| `-90600` | 無效金額格式 |
| `-90601` | 交易金額不能為零 |
| `-90602` | 金額超過最高開分限制 |
| `-90603` | 金額超過最低開分限制 |
| `-90604` | 金額超過最高洗分限制 |
| `-90605` | 金額超過最低洗分限制 |
| `-90606` | 玩家餘額不足以洗分 |
| `-90607` | 管理員餘額不足無法開分 |
| `-90608` | 尚未處理（檢查交易結果，語意為 pending） |
| `-90609` | 交易不存在（檢查交易結果） |
| `-90610` | 外部交易 Id 在 API 系統重複 |
| `-90615` | 交易 Id 不能為空 |
| `-90616` | 交易 Id 長度超過限制 |
| `-90617` | 玩家在遊戲中不能洗分 |
| `-90618` | 必須間隔 15 秒 |
| `-20001` | 查詢不到資料（管理員額度） |

### 報表查詢（3.14 / 3.17 / 3.18）

| Code | 說明 |
|---|---|
| `-50001` | 玩家遊戲記錄不存在 |
| `-50002` | 查詢開始或結束時間不能為空 |
| `-50003` | 日期為今日須限制為現在之前 |
| `-50004` | 開始與結束時間需為同一日 |
| `-50005` | 開始時間不能 >= 結束時間 |
| `-50006` | 查詢分頁不能為空或 <= 0 |
| `-50007` ~ `-50016` | 各類查詢內部失敗 / 代理不存在 |

### H5 Token（3.19）

| Code | 說明 |
|---|---|
| `-00006` | 玩家為空 |
| `-00007` | 玩家不存在 |
| `-00008` | 玩家無效 |
