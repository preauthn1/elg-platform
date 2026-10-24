# Bolakk4d（ProBet）— 廠商 API 規格

> **原始來源**
> - `Bolakk-CM-API1.pdf`（Calling URL: `https://example.invalid`）
> - `API 補充.txt`、`其他資訊.txt`、`正式域名.txt`、`測試域名.txt`
>
> 抓取日期：2026-06-03
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得）**。

Bolakk4d（vid 104，品牌 **ProBet**）為**馬來西亞彩票（4D / Toto / Lotto 類）遊戲商**，採 **Transfer Wallet（轉帳錢包）** 接入。本廠商為彩票類，投注後須等「開彩（drawDate）」才結算。

---

## 通用約定

### 請求格式

- **HTTP Method**：`POST`（body 為 JSON 物件）
- **回應格式**：**XML**（`<response>...</response>`），以 `<errorCode>` 判定成敗
- **Calling URL**：`https://example.invalid`；各 API path 形如 `/api/{endpoint}.aspx`

> ⚠️ 文檔範例參數區寫成 JSON，回應為 XML。實作送 JSON body、收 XML，與文檔一致。

### 驗證方式（雙層帳密，無簽章）

本廠商不使用簽章，所有 API 以兩組帳密驗證：

| 欄位 | 說明 | 對應 `vendor_infos.info` |
|---|---|---|
| `apiUser` | API 登入名 | `CLIENT_ID` |
| `apiPass` | API 登入密碼 | `CLIENT_SECRET` |
| `user` | 代理登入名 | `CLIENT_AGENT` |
| `pass` | 代理登入密碼 | `CLIENT_AGENT_PASS` |
| `loginID` | 玩家登入名（部分 API） | `Bolakk4dAccount.username` |

> `betLogin.aspx`（玩家進遊戲）較特別：`user`/`pass` 帶的是**玩家**登入名與密碼（非代理）。

### 通用回應結構

```xml
<response>
  <errorCode>0</errorCode>   <!-- 0 = 成功；非 0 見錯誤碼章節 -->
  <desc/>                    <!-- 回傳結果描述 / 錯誤訊息 -->
  ...                        <!-- 各 API 專屬資料 -->
</response>
```

### 時區與資料保留

| 項目 | 規則 |
|---|---|
| 時區 | **UTC+08:00** |
| API 資料保留 | **90 天** |
| 前台資料保留 | **90 天** |

---

## 錯誤碼總表

| errorCode | desc | 含義 | 出現於 |
|---|---|---|---|
| `0` | — | 成功 | 全部 |
| `1` | Invalid API User/Password | 錯誤 API 帳密 | 全部 |
| `2` | Invalid Login ID/Password | 錯誤用戶帳密 | 全部 |
| `3` | Missing Parameters / Data not Found | 輸入參數不齊全；或資料不存在 | 多數 |
| `4` | Invalid Account / Login ID Not Existed | 錯誤下線戶口 / 用戶不存在 | Deposit、Withdraw、UpdatePwdStatus |
| `5` | Invalid Amount / Invalid New Password | 提現超出 / 密碼格式不對（只接受數目字） | Withdraw、CreatePlayer、UpdatePwdStatus |
| `21` | Missing/Invalid Draw Date | 錯誤開彩日期 | GetBetList、WinLoss、WinningNumber |
| `22` | Login ID Not Existed | 用戶不存在 | GetBetList、WinningNumber |
| `999` | ex.Message | 系統錯誤，聯絡管理員 | 全部 |

> 實作 `transfer()` 判定：`0`→SUCCESS；`1`/`2`/`3`/`4`/`5`/`999`→FAILED；其他未知碼→TIMEOUT。

---

## merchant API

> 下表「額外參數」只列各 API 在通用驗證欄位（`apiUser`/`apiPass`/`user`/`pass`）之外的專屬欄位。

### 目錄

| # | API | URL | 用途 |
|---|---|---|---|
| 1 | [GetProfile](#1-getprofile) | `/api/getProfile.aspx` | 查詢用戶資料與餘額 |
| 2 | [CreatePlayer](#2-createplayer) | `/api/createPlayer.aspx` | 建立新下線戶口 |
| 3 | [Deposit](#3-deposit) | `/api/deposit.aspx` | 存款（transferToVendor） |
| 4 | [Withdraw](#4-withdraw) | `/api/withdraw.aspx` | 提現（transferToLocal） |
| 5 | [Login / Bet](#5-login--bet) | `/api/betLogin.aspx` + 進遊戲 | 登入並進投注頁 |
| 6 | [GetBetList](#6-getbetlist) | `/api/betlist.aspx` | 投注記錄（投注日期查） |
| 6.1 | [GetBetList by Page](#61-getbetlist-by-page) | `/api/betlistPage.aspx` | 分頁投注記錄 |
| 7 | [Get Win/Loss](#7-get-winloss) | `/api/winLoss.aspx` | 輸贏彙總（開彩日期查） |
| 8 | [Get Winning Number](#8-get-winning-number) | `/api/winNumber.aspx` | 中獎紀錄（開彩日期查） |
| 9 | [Update Password/Status](#9-update-password-or-status) | `/api/changePasswordStatus.aspx` | 更改密碼 / 狀況 |
| 10 | [Get Draw Result](#10-get-draw-result) | `/api/result.aspx` | 開彩成績 |
| 11 | [Get Currency Rate](#11-get-currency-rate) | `/api/currencyRate.aspx` | 匯率資料 |
| 12 | [Get Transaction Log](#12-get-transaction-log) | `/api/txnList.aspx` | 轉帳記錄 |

---

### 1. GetProfile

查詢用戶資料；Transfer Wallet 下回傳玩家在遊戲商端的 `balance` 與各彩種賠率。

**額外參數**：`loginID`（❌，放空則查 API 本身戶口）

**Success Response（節錄）**

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <loginID>testplaa</loginID>
  <fullName>testplaa</fullName>
  <status>Active</status>          <!-- Active / Suspended / Closed -->
  <onlineStatus>online</onlineStatus>
  <balance>800.00</balance>
  <currency>MYR</currency>
  <priceBig>1.00</priceBig>
  <prizeBig1>2,625.00</prizeBig1>
  <!-- prizeBig2~5 / priceSmall / prizeSmall1~3 / price4a / priceA / priceAbc
       / price5D / prize5D1~6 / price6D / prize6D1~5 / box4/6/12/24 包牌賠率 -->
</response>
```

---

### 2. CreatePlayer

建立新下線戶口（玩家）。

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ✅ | 新建戶口名 |
| `loginPass` | ✅ | 新建戶口密碼（**只接受數目字密碼**） |
| `fullName` | ✅ | 新建戶口全名 |
| `commZero` | ❌ | 佣金設置（佣金歸零） |
| `agent` | ❌ | `0`=玩家（預設）、`1`=代理 |

Error Code：`1`/`2`/`3`/`4` Login ID Existed/`5` Invalid New Password/`999`。

---

### 3. Deposit

存款，對應 `transferToVendor`。

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ✅ | 下線用戶登入名 |
| `amount` | ✅ | 存款數額 |
| `amountm` | ✅ | 禮碼數額（固定 `0`）；⚠️ PDF 文檔欄位名為 `amountn`，實作送 `amountm`，命名落差待確認 |
| `remarks` | ❌ | 備註 |

Error Code：`1`/`2`/`3`/`4` Invalid Account/`999`。

---

### 4. Withdraw

提現，對應 `transferToLocal`。

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ✅ | 下線用戶登入名 |
| `amount` | ✅ | 提現數額 |
| `amountm` | ✅ | 禮碼數額（固定 `0`）；⚠️ PDF 文檔欄位名為 `amountn`，見上方落差說明 |
| `remarks` | ❌ | 備註 |

Error Code：`1`/`2`/`3`/`4`/`5` Invalid Amount（提現超出）/`999`。

---

### 5. Login / Bet

取得 `sessionID` + `tokenCode`，再組進遊戲 URL。

#### 5.1 betLogin

| URL | `/api/betLogin.aspx` |
|---|---|

**參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `apiUser` / `apiPass` | ✅ | API 帳密 |
| `user` | ✅ | **下線用戶（玩家）登入名** |
| `pass` | ✅ | **下線用戶（玩家）登入密碼** |

> ⚠️ 與其它 API 不同：此處 `user`/`pass` 為**玩家**帳密（非代理）。

**Success Response**

```xml
<response>
  <errorCode>0</errorCode>
  <sessionID>...</sessionID>
  <tokenCode>...</tokenCode>
</response>
```

Error Code：`1`/`2`/`999`。

#### 5.2 進遊戲頁

| 平台 | URL | 參數 |
|---|---|---|
| PC Browser | `{AUTH_URL}/api/web-bet` | `user`、`sessionID`、`tokenCode`、`lang` |
| WAP/Mobile | `{AUTH_WAP_URL}/auth` | `user`、`sessionID`、`tokenCode`、`lang` |

語言映射：`zh-cn`（中文）/ `en-us`（其餘）。`sessionID`/`tokenCode` 必須由 `betLogin.aspx` 取得。

---

### 6. GetBetList

投注記錄（**以投注日期查詢**，`API 補充.txt` 明確標注）。

| URL | `/api/betlist.aspx` |
|---|---|

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ❌ | 下線用戶登入名（放空=本身或全部下線） |
| `dateFrom` | ✅ | 開彩日期 `yyyy-mm-dd` |
| `dateTo` | ✅ | 開彩日期 `yyyy-mm-dd` |

**Success Response（節錄）**

```xml
<response>
  <errorCode>0</errorCode>
  <desc/>
  <betData>
    <betID>913537273</betID>
    <dateTime>2017-11-09 04:37:54</dateTime>  <!-- 投注時間 -->
    <drawType>8</drawType>                     <!-- 開彩莊家代碼 -->
    <drawDate>2017-11-09</drawDate>            <!-- 開彩日期 -->
    <betNumber>5173</betNumber>
    <betPosition>B</betPosition>
    <bet>1.00</bet>
  </betData>
</response>
```

Error Code：`1`/`2`/`21` Missing/Invalid Draw Date/`22` Login ID Not Existed/`3` Data not Found/`999`。

---

### 6.1 GetBetList by Page

分頁版投注記錄（**以投注日期查詢**）。

| URL | `/api/betlistPage.aspx` |
|---|---|

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ❌ | 下線用戶登入名 |
| `dateFrom` | ✅ | 開彩日期 `yyyy-mm-dd` |
| `dateTo` | ✅ | 開彩日期 `yyyy-mm-dd` |
| `page` | ✅ | 頁數 |
| `betLogin` | — | `API 補充.txt` 追加的用戶 username 欄位 |

---

### 7. Get Win/Loss

輸贏彙總（以開彩日期查詢）。

| URL | `/api/winLoss.aspx` |
|---|---|

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `dateFrom` | ✅ | 開彩日期 `yyyy-mm-dd` |
| `dateTo` | ✅ | 開彩日期 `yyyy-mm-dd` |
| `drawType` | ❌ | 彩種（放空=全部；代碼見下方 drawType 表） |

**Success Response（節錄）**

```xml
<response>
  <errorCode>0</errorCode>
  <winLoss>
    <loginName>testpla</loginName>
    <turnover>3.00</turnover>
    <commission>0.00</commission>
    <strike>0.00</strike>
    <total>-3.00</total>   <!-- 輸贏（負數為輸） -->
  </winLoss>
</response>
```

Error Code：`1`/`2`/`21` Missing/Invalid Draw Date。

---

### 8. Get Winning Number

中獎紀錄（**以開彩日期查詢**，`API 補充.txt` 明確標注）。

| URL | `/api/winNumber.aspx` |
|---|---|

**額外參數**：`loginID`（❌）、`dateFrom`（✅）、`dateTo`（✅）

**Success Response（節錄）**

```xml
<response>
  <errorCode>0</errorCode>
  <betData>
    <betID>844980069</betID>
    <dateTime>2017-10-20 08:11:50</dateTime>
    <drawType>8</drawType>
    <drawDate>2017-10-21</drawDate>
    <betNumber>1050</betNumber>
    <strikePosition>1</strikePosition>
    <bet>1.00</bet>
    <strike>2,625.00</strike>
  </betData>
</response>
```

---

### 9. Update Password or Status

更改下線戶口密碼或狀況。

| URL | `/api/changePasswordStatus.aspx` |
|---|---|

**額外參數**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `loginID` | ✅ | 下線用戶登入名 |
| `newpass` | ❌ | 新密碼（放空不更改；只接受數目字） |
| `newStatus` | ❌ | `A` 活躍 / `S` 暫停 / `C` 關閉 |

Error Code：`1`/`2`/`3` Missing Parameters/`4` Invalid New Status/`5` Invalid New Password/`999`。

---

### 10. Get Draw Result

開彩成績。

| URL | `/api/result.aspx` |
|---|---|

**額外參數**：`drawDate`（✅，`yyyy-mm-dd`）

---

### 11. Get Currency Rate

匯率資料。

| URL | `/api/currencyRate.aspx` |
|---|---|

**額外參數**：`currency`（✅，貨幣代碼）

---

### 12. Get Transaction Log

轉帳記錄。

| URL | `/api/txnList.aspx` |
|---|---|

**額外參數**：`loginID`（✅）、`remarks`（❌）

---

## drawType（彩種代碼）

用於 `winLoss.aspx` 的 `drawType` 參數及 `betlist` 回應的 `<drawType>`：

| drawType | 彩種 |
|---|---|
| 空 | 全部 |
| `1` | 萬能（Magnum） |
| `2` | 邀馬（Da Ma Cai） |
| `3` | 多多（Toto） |
| `4` | 新加坡（Singapore） |
| `5` | 沙巴（Sabah） |
| `6` | 砂朥越（Sarawak） |
| `8` | 豪龍（Lotto 88） |
| `9` | 9 Lotto |

> ⚠️ drawType=`7` 在文檔中未列出對應彩種。
