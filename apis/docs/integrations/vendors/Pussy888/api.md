# Pussy888 — API 規格

> 來源：`1_API说明文档.pdf`（標題「Api Pussy888 3.0」，14 頁）；補充：`pussy888.txt`（測試帳號環境配置，非規範文件）。整理日期：2026-06-03。
>
> 本廠商為 **Transfer Wallet（轉帳錢包）** 模式（vendor_id=23，`VendorIdEnum::PUSSY`）；無 Seamless 回調。本平台分析、陷阱與落地對應見同目錄 `integration.md`。

---

## 通用約定

### 請求格式

- **HTTP Method**：GET query string（`account.ashx?action=...&...`）
- **回應格式**：JSON
- **驗證**：`authcode`（授權碼）+ `sign`（MD5 簽名）

### 兩組 Base URL

| 代號 | 網域示例 | 用途 |
|---|---|---|
| `AipUrl1` | `example.invalid` | 帳號 / 設分（setScore）/ 列表 |
| `AipUrl2` | `example.invalid` | 功能 API（報表 / 明細 / 查分） |

> 正式網域以客服提供為準。文件另出現 `example.invalid:9991` 等佔位字串，不可直接使用。

### 通用請求欄位

所有 endpoint 共用（除特別說明外皆必填）：

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定動作參數（如 `getUserInfo`、`setServerScore`、`addUser`） |
| `userName` | String | ✅ | 用戶名（玩家帳號）；部分 endpoint 以 `loginUser` 表示當前登入代理 |
| `time` | long | ✅ | 13 位毫秒時間戳（`System.currentTimeMillis()`） |
| `authcode` | String | ✅ | 授權碼（我方提供） |
| `sign` | String | ✅ | 簽名，見下方「簽名」章節 |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `success` | Boolean | API 是否成功（`true` / `false`） |
| `code` | Integer | 狀態 / 錯誤碼（各 endpoint 說明） |
| `msg` | String | 錯誤提示訊息 |
| `money` / `MoneyNum` / `ScoreNum` | String | 餘額（字串，兩位小數，如 `"4930.12"`） |

> 文件備註：「Json 裡返回的字段很多不用的，保留在那」——回應 payload 含大量未使用欄位，解析時只取需要欄位。

---

## Merchant API

### 帳號

#### 1. 用戶 / 代理註冊（RandomUserName）

產生隨機玩家帳號（Step1）。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx` |
| `action` | `RandomUserName` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 / 代理名字 |
| `UserAreaId` | — | ❌ | 要生成的玩家帳號區域 |

---

#### 2. 添加帳號（addUser）

建立玩家或代理帳號（Step2）。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx?action=addUser` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定 `addUser` |
| `agent` | String | ✅ | 玩家的上一級代理（對應 `vendor_infos.info.AGENT_NAME`） |
| `PassWd` | String | ✅ | 玩家密碼（長度 ≤ 17） |
| `userName` | String | ✅ | 用戶名 |
| `Name` | String | ✅ | 用戶暱稱 |
| `Tel` | String | ✅ | 電話 |
| `Memo` | String | ✅ | 備註 |
| `UserType` | String | ✅ | `1` 正式玩家 / `100` 代理級別 |

Code 回應：

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 帳號存在 |
| `-2` | 簽名錯誤 |
| `-99` | 非法操作 |

```jsonc
// 失敗
{"code":-1,"msg":"fail operation. exist","success":false,"type":2}
// 成功
{"code":0,"msg":"successful operation.","success":true,"type":2}
```

---

#### 3. 修改資料（editUser）

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx?action=editUser` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定 `editUser` |
| `userName` | String | ✅ | 用戶名 |
| `OldPassWd` | String | ✅ | 舊密碼（長度 ≤ 17，**DES 加密**） |
| `PassWd` | String | ✅ | 新密碼（長度 ≤ 17，**DES 加密**） |
| `Name` | String | ✅ | 用戶暱稱 |
| `Tel` | String | ✅ | 電話 |
| `Memo` | String | ✅ | 備註 |

> ⚠️ `OldPassWd`/`PassWd` 需 **DES 加密**；DES 金鑰與模式文件未載明（待確認）。

Code 回應（同 addUser）：`0` 成功 / `-1` 帳號或密碼錯誤 / `-2` 簽名錯誤 / `-99` 非法操作。

---

#### 4. 修改密碼（ChangePwd）

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `loginUser` | String | ❌ | 當前登陸帳號，空則傳空字串或不傳 |
| `action` | String | ✅ | 固定 `ChangePwd` |
| `userName` | String | ✅ | 用戶名 |
| `OldPassWd` | String | ✅ | 舊密碼（長度 ≤ 17，**DES 加密**） |
| `PassWd` | String | ✅ | 新密碼（長度 ≤ 17，**DES 加密**） |

Code 回應：`0` 成功 / `-1` 帳號或密碼錯誤 / `-2` 簽名錯誤 / `-99` 非法操作。

---

#### 5. 修改資料 2（editUser2）

可選擇是否更新密碼。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `loginUser` | String | ❌ | 當前登陸帳號，空則傳空字串或不傳 |
| `action` | String | ✅ | 固定 `editUser2` |
| `userName` | String | ✅ | 用戶名 |
| `Flag` | String | ✅ | `0` 不更新密碼 / `1` 更新密碼 |
| `PassWd` | String | ✅ | 新密碼（長度 ≤ 17，**DES 加密**） |
| `Name` | String | ✅ | 用戶暱稱 |
| `Tel` | String | ✅ | 電話 |
| `Memo` | String | ✅ | 備註 |

Code 回應：`0` / `-1` / `-2` / `-99`（同 editUser）。

---

#### 6. 禁用 / 開啟帳號（disable）

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx?action=disable` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定 `disable` |
| `userName` | String | ✅ | 用戶名 |

> 同一 endpoint 切換禁用 / 開啟（toggle 行為），依回應 `type` 判定當前結果。

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 異常錯誤 |
| `-2` | 簽名錯誤 |

| Type | 說明 |
|---|---|
| `1` | 禁用成功 |
| `2` | 開啟成功 |

```jsonc
{"code":0,"msg":"successful operation.","success":true,"type":1}
```

---

### 錢包 / 上下分

#### 7. 上下分（setServerScore / setAgentScore）

本平台 `transferToVendor` / `transferToLocal` 對應此 API（§三 充值上下分接口）。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/setScore.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | `setServerScore`（玩家）/ `setAgentScore`（代理） |
| `scoreNum` | double | ✅ | **`>0` 加分（存款）**，**`<0` 扣分（提款）** |
| `userName` | String | ✅ | 用戶名 |
| `ActionUser` | String | ✅ | 操作人（= `vendor_infos.info.AGENT_NAME`） |
| `ActionIp` | String | ✅ | 上分人的 IP（= `Common::getIp()`） |

| Code | 說明 | 語意分類 |
|---|---|---|
| `0` | 成功 | success |
| `-1` | 訂單錯誤 | failure |
| `-2` | 簽名錯誤 | failure |
| `-3` | 正在設分，稍後 | **pending / TIMEOUT** |
| `-4` | 設分太頻繁，稍後 | **pending / TIMEOUT** |
| `-5` | 分數參數錯誤 | failure |
| `-6` | 系統異常，稍後 | **pending / TIMEOUT** |
| `-7` | 遊戲裡不能扣分 | failure |
| `-8` | 餘額不足 | failure |
| `-9` | 帳號不存在 | failure |
| `-99` | 非法操作 | failure |

```jsonc
{"acc":"test001","code":1,"money":"4930.12","msg":"success ","success":true}
```

> ⚠️ **回應範例 `code:1` 與 `success:true` 並存**，與 Code 表（`0` 成功）不一致；見 `integration.md` 高風險陷阱 §1。

---

### 查詢

#### 8. 查詢用戶資訊（嵌套層級 getSearchUserInfo）

§四，查詢用戶信息（嵌套層級），回傳玩家基礎資訊 + 層級關係。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx?action=getSearchUserInfo&userName=test8` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定 `getSearchUserInfo` |
| `userName` | String | ✅ | 用戶名 |

回應重點欄位：`InfoID`（平台唯一 int idx）、`results`（含 `MoneyNum`/`ScoreNum`/`Account`/`Agent`/`Status`）、`upListArr`（層級關係，頂級 admin）。

```jsonc
{"InfoID":1026,"UserTYPE":"player","msg":"ok","results":[{"Account":"01111111113","Agent":"MyTest001","MoneyNum":"2290.00","Name":"player1","ScoreNum":"2290.00","Status":1,"Type":1,"idx":1026,"success":false}],"upListArr":[...]}
```

---

#### 9. 查詢用戶資訊（簡單 getUserInfo）

本平台 `getBalance()` 對應此 API，取回應 `MoneyNum`。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/account/account.ashx?action=getUserInfo&userName=test8` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 固定 `getUserInfo` |
| `userName` | String | ✅ | 用戶名 |

```jsonc
{"Account":"01111111113","Agent":"MyTest001","MoneyNum":"2190.00","Name":"player1","ScoreNum":"2190.00","Status":1,"Type":1,"UserAreaID":0,"idx":1026,"Tel":"13000000000","success":true}
```

| 欄位 | 說明 |
|---|---|
| `MoneyNum` | 玩家當前餘額（字串，兩位小數）— **本平台餘額來源** |
| `ScoreNum` | 分數（多與 MoneyNum 同值） |
| `success` | 是否成功 |

---

#### 10. 查詢代理或玩家列表（agentList / playerList）

§五，查詢代理或玩家列表。

| 項目 | 值 |
|---|---|
| URL | `AipUrl1/ashx/getData/AccountList.ashx?userName=admin&action=agentList&pageIndex=1` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | `playerList`（玩家）/ `agentList`（代理） |
| `userName` | String | ✅ | 用戶名 |
| `pageIndex` | int | ✅ | 第幾頁（每頁 20 筆，預設 1） |

回應：`success` / `msg` / `Total`（總數）/ `results[]`（含 `Account`/`Agent`/`LimitNum`/`ScoreNum`/`Status`/`idx`）。

---

#### 11. 設分查詢（AreaMoney）

刷設分界面的分數資訊；走 **AipUrl2**。

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/AreaMoney.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `loginUser` | String | ✅ | 當前代理（固定） |
| `userName` | String | ✅ | 用戶名 |

> ⚠️ **此 endpoint 簽名串含 `loginUser`**（放最前面），與其餘 endpoint 不同，見下方「簽名」章節。

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 訂單錯誤 |
| `-2` | 簽名錯誤 |
| `-3` | 帳號不存在 |
| `-99` | 非法操作 |

```jsonc
{"account":"01111111113","areaid":0,"idx":1026,"maxsetmoney":"184599.00","money":"1990.00","msg":"","success":true}
```

| 欄位 | 說明 |
|---|---|
| `maxsetmoney` | 最大可設多少分 |
| `money` | 當前分 |

---

### 報表

> 報表類 endpoint 走 **AipUrl2**。本平台**尚未實作**這些對帳 API；下列為文件規範，供日後接入。

#### 12. 玩家每天輸贏總賬（AccountReport）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/AccountReport.ashx?sDate=2017-08-01 00:00:00&eDate=2017-08-15 23:59:59&userName=test001` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `sDate` | 日期 | ✅ | 起始日期 |
| `eDate` | String | ✅ | 結束日期 |

回應：`results[]` 按日期排序，含 `mydate`（日期）/ `win`（莊家贏）/ `Press`（總下注）/ `yield`（收益率）。

```jsonc
{"ViewTYPE":0,"msg":"ok","results":[{"mydate":"2017-08-01 ","win":"0.00"}],"success":true}
```

---

#### 13. 代理總輸贏報表（AgentMoneyLog）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/AgentMoneyLog.ashx?sDate=2017-08-15&eDate=2017-08-17&userName=tom1` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `sDate` | 日期 | ✅ | 起始日期 |
| `eDate` | String | ✅ | 結束日期 |

```jsonc
{"ViewTYPE":0,"msg":"ok","results":[{"mydate":"2017-08-15 ","win":"263045.59"}],"success":true}
```

---

#### 14. 下屬玩家或代理報表（AgentTotalReport）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/AgentTotalReport.ashx?sDate=2017-08-15&eDate=2017-08-15&userName=xxx&Type=ServerTotalReport` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `sDate` | 日期 | ✅ | 起始日期 |
| `eDate` | String | ✅ | 結束日期 |
| `Type` | String | ✅ | `ServerTotalReport`（玩家）/ `AgentTotalReport`（代理） |

```jsonc
{"ViewTYPE":0,"msg":"ok","results":[{"Account":"0107506776","idx":44769,"memo":"","name":"","tel":"","win":"-193.70"}],"success":true}
```

---

#### 15. 查玩家上下分明細（UsersscoreLog）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/UsersscoreLog.ashx?sDate=2017-08-01 00:00:00&eDate=2017-08-03 23:59:59&userName=test001&pageIndex=1` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `pageIndex` | int | ✅ | 頁碼，預設第一頁 |
| `userName` | String | ✅ | 用戶名 |
| `sDate` | 日期 | ✅ | 起始日期 |
| `eDate` | String | ✅ | 結束日期 |

```jsonc
{"msg":"ok","results":[{"Account":"96Sxxx","ActionIP":"13.112.xx2","Action_Type":0,"AfterNum":"10.00","C_DateTime":"2017-11-01 14:43:42.583","Index_NO":14089582,"Rownum":1,"ScoreNum":"10.00","UserName":"test001"}],"success":true,"total":2,"totalScore":"20","totalSum":"20"}
```

| 欄位 | 說明 |
|---|---|
| `AfterNum` | 操作後餘額 |
| `ScoreNum` | 本次上下分數 |
| `Action_Type` | 操作類型 |
| `C_DateTime` | 操作時間 |
| `Index_NO` | 流水號 |

---

#### 16. 查玩家上下分 Translog（AllScoreLog）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/AllScoreLog.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `pageIndex` | int | ✅ | 頁碼，預設第一頁 |
| `userName` | String | ✅ | 用戶名 |

回應結構同 UsersscoreLog（`results[]` + `total` / `totalScore` / `totalSum`）。

---

#### 17. 查玩家遊戲記錄（GameLog）

| 項目 | 值 |
|---|---|
| URL | `AipUrl2/ashx/GameLog.ashx?sDate=2017-11-01 00:00:00&eDate=2017-11-01 23:59:59&userName=test001&pageIndex=1` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `pageIndex` | int | ✅ | 頁碼，預設第一頁 |
| `userName` | String | ✅ | 用戶名 |
| `sDate` | 日期 | ✅ | 起始日期 |
| `eDate` | String | ✅ | 結束日期 |

> ⚠️ PDF 第 13 頁 `results[]` 欄位被截斷，無完整下注欄位範例；對帳實作前須向客服取完整回應格式。

---

## 簽名

### 主流程（絕大多數 endpoint）

```
sign = strtoupper( md5( strtolower( authcode + userName + time + secretKey ) ) )
```

| 欄位 | 說明 |
|---|---|
| `authcode` | 授權碼（`vendor_infos.info.API_AUTHCODE`） |
| `userName` | 玩家帳號 |
| `time` | 13 位毫秒時間戳（與請求帶出的 `time` 同一值） |
| `secretKey` | 密鑰（`vendor_infos.info.API_SECRETKEY`） |

步驟：四字串拼接 → 整串 `strtolower()` → `md5()` → `strtoupper()`。

> 文件僅規範輸入轉小寫（`.tolower`），**未明示輸出大小寫**；本平台實作輸出大寫，已通過驗證，不修改。

### 例外：設分查詢（AreaMoney）

`loginUser` 放最前面，多一個欄位：

```
sign = strtoupper( md5( strtolower( loginUser + authcode + userName + time + secretKey ) ) )
```

### 本平台實作（`Driver.php`）

```php
private function sign($authcode, $username, $time, $secretKey): string
{
    $sign = "$authcode$username$time$secretKey";
    $sign = strtolower($sign);

    return strtoupper(md5($sign));
}
```

> 文件未提供官方 test vector，以實作通過驗證為事實依據。

---

## 錯誤碼

### 全域錯誤碼

| Code | 說明 |
|---|---|
| `-99` | 非法操作（所有接口在非法操作時返回） |
| `-102` | URL 不存在 |

### setScore 錯誤碼（最完整一組）

| Code | 說明 | 語意分類 |
|---|---|---|
| `0` | 成功 | success |
| `-1` | 訂單錯誤 | failure |
| `-2` | 簽名錯誤 | failure |
| `-3` | 正在設分，稍後 | **pending / TIMEOUT** |
| `-4` | 設分太頻繁，稍後 | **pending / TIMEOUT** |
| `-5` | 分數參數錯誤 | failure |
| `-6` | 系統異常，稍後 | **pending / TIMEOUT** |
| `-7` | 遊戲裡不能扣分 | failure |
| `-8` | 餘額不足 | failure |
| `-9` | 帳號不存在 | failure |
| `-99` | 非法操作 | failure |

### addUser / editUser / disable 錯誤碼

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 帳號存在 / 帳號或密碼錯誤 / 異常錯誤（依 endpoint 各異） |
| `-2` | 簽名錯誤 |
| `-99` | 非法操作 |

### AreaMoney 錯誤碼

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 訂單錯誤 |
| `-2` | 簽名錯誤 |
| `-3` | 帳號不存在 |
| `-99` | 非法操作 |
