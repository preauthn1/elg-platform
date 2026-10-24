# Haolong — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README）。無加密簽名（使用 `apiUser` / `apiPass` 帳密認證）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### Base URL

| 用途 | URL |
|---|---|
| API 呼叫 | `http://example.invalid`（**HTTP only，無 TLS**） |
| 官網 | `http://example.invalid` |
| H5 線上入口 | `http://example.invalid/` |
| H5 測試站 | `http://example.invalid/wap` |
| 後台 | `example.invalid`（線上帳號 `vwc`） |

> 正式環境網域以各白標 `vendor_infos.info.API_URL` 和 `API_HTML5_URL` 設定為準，非寫死於程式。

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/x-www-form-urlencoded`（實作以 `Http::...->asForm()` 送出）
- **回應格式**：**XML**，根節點 `<response>`，以 `simplexml_load_string()` 解析

### 認證方式

無加密簽名。每個 endpoint 均需帶下列帳密欄位（由實作 `Driver::post()` 自動附加）：

| 欄位 | 說明 |
|---|---|
| `apiUser` | API 等人帳號（`vendor_infos.info.API_USER`） |
| `apiPass` | API 等人密碼（`vendor_infos.info.API_PASSWORD`） |
| `user` | 代理登入名（管理型 API）或玩家登入名（`betLogin.aspx`） |
| `pass` | 對應密碼 |

> `user` / `pass` 語意因 API 而異：管理型 API（`deposit` / `withdraw` / `getProfile` 等）中為**代理帳密**；`betLogin.aspx` 中為**玩家帳密**。

### 統一回應結構

```xml
<response>
  <errorCode>0</errorCode>   <!-- 0 = 成功；非 0 見錯誤碼表 -->
  <desc/>                    <!-- 失敗時為錯誤描述（英文）；成功時通常為空 -->
  <!-- 各 API 的回傳欄位 -->
</response>
```

- `errorCode == 0` 表成功，其餘皆為失敗
- 實作端以 `(int) $result->errorCode !== 0` 判斷失敗
- 同一 `errorCode` 在不同 API 下 `<desc>` 語意可能不同，失敗時需同時參考 `<desc>`

### 金額欄位注意事項

`<balance>` 等金額欄位官方範例帶**千分位逗號**（例 `16,500.00`），使用前必須 `str_replace(',', '', $value)` 去逗號後再做精度正規化。

---

## Merchant API

### 目錄

| # | API | URL |
|---|---|---|
| 1 | GetProfile（取用戶資料） | `getProfile.aspx` |
| 2 | CreatePlayer（建立下線戶口） | `createPlayer.aspx` |
| 3 | Deposit（存款） | `deposit.aspx` |
| 4 | Withdraw（提現） | `withdraw.aspx` |
| 5b | BetLogin（取得 session） | `betLogin.aspx` |
| 5 | Bet（進入投注廳） | `bet.aspx`（組 URL，非後端呼叫） |
| 6 | GetBetList（投注記錄） | `betlist.aspx` |
| 7 | Get Win / Loss（輸贏彙總） | `winLoss.aspx` |
| 8 | Get Winning Number（中獎記錄） | `winNumber.aspx` |
| 9 | Update Password / Status（改密碼或狀態） | `changePasswordStatus.aspx` |
| 10 | Get Draw Result（開彩成績） | `result.aspx` |

---

### 1. GetProfile（取用戶資料）— `getProfile.aspx`

取得玩家戶口資料、餘額與各玩法賠率。實作 `getBalance()` 僅取用 `<balance>`。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 用戶登入名 |
| `pass` | ✅ | 用戶登入密碼 |
| `loginID` | ❌ | 下線用戶登入名 |

#### 回應（節錄）

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <loginID>testplaa</loginID>
  <fullName>testplaa</fullName>
  <status>Active</status>          <!-- Active / Suspended / Closed -->
  <balance>800.00</balance>        <!-- 可能帶千分位逗號 -->
  <currency>MYR</currency>
  <!-- 各玩法賠率：priceBig / prizeBig1..5、priceSmall / prizeSmall1..3、
       price4a/prize4a、priceA/prizeA、priceAbc/prizeAbc1..3、
       price5D/prize5D1..6、price6D/prize6D1..5、ibox24/12/6/4 -->
</response>
```

| 欄位 | 說明 |
|---|---|
| `status` | `Active` 活躍 / `Suspended` 暫停 / `Closed` 關閉 |
| `balance` | 玩家餘額（含逗號，使用前需去逗號正規化） |
| `currency` | 貨幣別（例 `MYR`） |
| `priceXxx` / `prizeXxx` | 各玩法價格與賠率 |

---

### 2. CreatePlayer（建立下線戶口）— `createPlayer.aspx`

建立新的下線玩家戶口。

> ⚠️ 此 API 未在 `Driver.php` 直接呼叫，玩家開戶流程待確認（見 `integration.md` Gaps #1）。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 用戶登入名 |
| `pass` | ✅ | 用戶登入密碼 |
| `loginID` | ✅ | 新建戶口登入名 |
| `loginPass` | ✅ | 新建戶口登入密碼（只接受數字密碼） |
| `fullName` | ✅ | 新建戶口全名 |
| `commZero` | ❌ | 佣金設置 |
| `agent` | ❌ | `0` = 玩家（默認），`1` = 代理 |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
</response>
```

---

### 3. Deposit（存款）— `deposit.aspx`

把本地餘額轉入 Haolong（玩家進入遊戲前）。對應實作 `transferToVendor()`。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 用戶登入名（代理） |
| `pass` | ✅ | 用戶登入密碼（代理） |
| `loginID` | ✅ | 下線用戶登入名（實作送玩家 username，視 `USE_PREFIX` 加前綴） |
| `amount` | ✅ | 數額 |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
</response>
```

---

### 4. Withdraw（提現）— `withdraw.aspx`

把 Haolong 餘額轉回本地（玩家離開遊戲後）。對應實作 `transferToLocal()`。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 用戶登入名（代理） |
| `pass` | ✅ | 用戶登入密碼（代理） |
| `loginID` | ✅ | 下線用戶登入名 |
| `amount` | ✅ | 數額 |

#### 回應

同 Deposit。特有錯誤：`errorCode=5`（`Invalid Amount`，提現數額超出）。

---

### 5b. BetLogin（取得 session）— `betLogin.aspx`

進入投注廳前先呼叫，取得 `sessionID` 與 `tokenCode`。對應實作 `getGameUrl()` 第一步。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 玩家登入名（視 `USE_PREFIX` 加前綴） |
| `pass` | ✅ | 玩家登入密碼（vworld 舊帳號固定 `!Pa$$w0rd`，其餘由 `getDefaultPassword()` 推導） |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <sessionID>...</sessionID>
  <tokenCode>...</tokenCode>
</response>
```

---

### 5. Bet（進入投注廳）— 組 URL，非後端呼叫

`betLogin.aspx` 完成後，以回傳值組裝玩家瀏覽器開啟的遊戲 URL。對應實作 `getGameUrl()` 第二步。

| 版本 | URL 格式 |
|---|---|
| PC | `<API_URL>bet.aspx?user={user}&lang={lang}&sessionID={sessionID}&tokenCode={tokenCode}` |
| H5 | `<API_HTML5_URL>?user={user}&lang={lang}&sessionID={sessionID}&tokenCode={tokenCode}` |

| Query 欄位 | 必須 | 說明 |
|---|---|---|
| `user` | ✅ | 玩家登入名（含 prefix） |
| `lang` | ✅ | `zh-cn` 或 `en-us` |
| `sessionID` | ✅ | 從 `betLogin.aspx` 取得 |
| `tokenCode` | ✅ | 從 `betLogin.aspx` 取得 |

---

### 6. GetBetList（投注記錄）— `betlist.aspx`

對帳用，未在 `Driver.php` 直接呼叫。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` / `apiPass` / `user` / `pass` | ✅ | 認證 |
| `loginID` | ❌ | 下線用戶登入名 |
| `dateFrom` | ✅ | 開彩日期（`yyyy-mm-dd`） |
| `dateTo` | ✅ | 開彩日期（`yyyy-mm-dd`） |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <betData>
    <betID>913537273</betID>
    <dateTime>2017-11-09 04:37:54</dateTime>
    <drawType>8</drawType>         <!-- 開彩莊家 -->
    <drawDate>2017-11-09</drawDate>
    <betNumber>5173</betNumber>    <!-- 投注號碼 -->
    <betPosition>B</betPosition>   <!-- 投注位置 -->
    <bet>1.00</bet>                <!-- 投注額 -->
  </betData>
  <!-- 多筆 betData -->
</response>
```

---

### 7. Get Win / Loss（輸贏彙總）— `winLoss.aspx`

對帳用，未在 `Driver.php` 直接呼叫。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` / `apiPass` / `user` / `pass` | ✅ | 認證 |
| `dateFrom` | ✅ | 開彩日期（`yyyy-mm-dd`） |
| `dateTo` | ✅ | 開彩日期（`yyyy-mm-dd`） |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <winLoss>
    <loginName>testpla</loginName>
    <turnover>3.00</turnover>    <!-- 總投注額 -->
    <strike>0.00</strike>        <!-- 中獎數額 -->
    <total>-3.00</total>         <!-- 總數（負數為輸） -->
  </winLoss>
</response>
```

---

### 8. Get Winning Number（中獎記錄）— `winNumber.aspx`

對帳用，未在 `Driver.php` 直接呼叫。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` / `apiPass` / `user` / `pass` | ✅ | 認證 |
| `loginID` | ❌ | 下線用戶登入名 |
| `dateFrom` | ✅ | 開彩日期（`yyyy-mm-dd`） |
| `dateTo` | ✅ | 開彩日期（`yyyy-mm-dd`） |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <betData>
    <betID>844980069</betID>
    <dateTime>2017-10-20 08:11:50</dateTime>
    <drawType>8</drawType>
    <drawDate>2017-10-21</drawDate>
    <betNumber>1050</betNumber>
    <strikePosition>1</strikePosition>
    <bet>1.00</bet>
    <strike>2,625.00</strike>    <!-- 中獎數額，可能帶千分位逗號 -->
  </betData>
  <!-- 多筆 betData -->
</response>
```

---

### 9. Update Password / Status（改密碼或狀態）— `changePasswordStatus.aspx`

更改戶口密碼或狀態。實作 `resetPassword()` 對應。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` | ✅ | API 登入名 |
| `apiPass` | ✅ | API 等人密碼 |
| `user` | ✅ | 用戶登入名 |
| `pass` | ✅ | 用戶登入密碼 |
| `loginID` | ✅ | 下線用戶登入名 |
| `newpass` | ❌ | 新密碼（放空如不需要更改；只接受數字密碼） |
| `newStatus` | ❌ | 戶口狀況：`A` 活躍 / `S` 暫停 / `C` 關閉（放空如不需要更改） |

#### 回應

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <balance>...</balance>
</response>
```

---

### 10. Get Draw Result（開彩成績）— `result.aspx`

對帳用，未在 `Driver.php` 直接呼叫。

#### 請求參數

| 欄位 | 必須 | 說明 |
|---|---|---|
| `apiUser` / `apiPass` / `user` / `pass` | ✅ | 認證 |
| `drawDate` | ✅ | 開彩日期（`yyyy-mm-dd`） |

> ⚠️ 官方文件未提供完整回傳欄位範例，見 `integration.md` Gaps #6。

---

## 錯誤碼總表

| errorCode | desc | 說明 | 適用 API |
|---|---|---|---|
| `0` | （空） | 成功 | 全部 |
| `1` | `Invalid API User/Password` | 錯誤 API 登入名或密碼 | 全部 |
| `2` | `Invalid Login ID/Password` | 錯誤用戶登入名或密碼 | 全部 |
| `3` | `Missing Parameters` | 輸入參數不齊全 | CreatePlayer, Deposit, Withdraw, BetLogin, UpdatePwd |
| `3` | `Data not Found` | 所要尋找數據不存在 | GetBetList, Win/Loss, WinningNumber |
| `4` | `Login ID Existed` | 新建戶口登入名已存在 | CreatePlayer |
| `4` | `Invalid Account` | 錯誤下線戶口 | Deposit, Withdraw |
| `4` | `Invalid New Status` | 新輸入戶口狀況格式不對（A/S/C） | UpdatePassword/Status |
| `5` | `Invalid New Password` | 新建/新登入密碼格式不對（只接受數字密碼） | CreatePlayer, UpdatePassword/Status |
| `5` | `Invalid Amount` | 提現數額超出 | Withdraw |
| `21` | `Missing/Invalid Draw Date` | 錯誤開彩日期 | GetBetList, Win/Loss, WinningNumber |
| `22` | `Login ID Not Existed` | 用戶登入名不存在 | GetProfile, GetBetList, Win/Loss, WinningNumber |
| `999` | `ex.Message` | 系統錯誤，請聯絡管理員 | 全部 |

> 同一 `errorCode` 在不同 API 下 `desc` 可能不同（例如 `3`、`4`、`5`），判斷失敗時應同時參考 `errorCode` 與 `desc`。
