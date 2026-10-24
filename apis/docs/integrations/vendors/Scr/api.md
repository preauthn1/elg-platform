# SCR（918KISS）— API 規格

> 來源：`918对外接口API3.0说明文档.pdf`（主要事實來源，文件標註日期 `20200211`，15 頁）；`SCR API_04042018.pdf`（舊版 API 文件，37 頁，僅供對照）；`对外接口API说明文档(4).docx`（早期變體版本，供 `UserAreaId` 代碼參考）；`SCR流程_0225.pdf`（安裝 / 登入流程示意）。
> 抓取日期：2026-06-03
> 對應遊戲商：**SCR（918KISS）**（vendor_id=3，**Transfer Wallet 轉帳錢包**）

---

## 通用約定

### 請求格式

- **HTTP Method**：官方文件以 GET querystring 範例呈現；**APIs 專案實作以 `POST` form（`application/x-www-form-urlencoded`）送出**（`Driver::sendRequest` 採 `Http::asForm()->post()`）。918 端兩種皆受理。
- **驗證方式**：IP 白名單 + `sign` 欄位（見[簽名](#簽名)）。
- **回應格式**：JSON。

### 通用認證欄位（每支 API 必帶）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | String | ✅ | 介面動作（如 `getUserInfo`、`setServerScore`、`editUser`） |
| `time` | long | ✅ | 13 位毫秒時間戳 `System.currentTimeMillis()`（PHP 實作為 `date('U') . '000'`） |
| `authcode` | String | ✅ | 授權碼（`vendor_infos.info.AUTH_CODE`） |
| `sign` | String | ✅ | 簽名，見[簽名](#簽名) |

> ⚠️ 欄位大小寫：官方文件表頭首字母大小寫不一致（`Action`/`action`、`Sign`/`sign`、`Time`/`time`）。APIs 專案一律使用小寫 `action`、`time`、`authcode`、`sign`，玩家帳號以 `userName`（駝峰）傳遞。以實作為準。

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `success` | Boolean | API 是否成功，成功 `true` 失敗 `false` |
| `code` | Integer | 業務狀態碼（見各 API 的 Code 表） |
| `msg` | String | 錯誤 / 成功訊息 |

> ⚠️ `success` 與 `code` 並非總是一致，且不同介面的 `code` 語意不同（例如查訂單 `code:1` 代表訂單存在、`code:0` 代表不存在；而多數介面 `code:0` 才是成功）。務必逐介面對照 Code 表，不可跨介面共用判斷邏輯。

### Base URL

| 用途 | 示例 URL | 對應介面 |
|---|---|---|
| 主 API（帳號 / 上下分 / 查詢 / 報表） | `http://example.invalid:9991/`（測試示例） | 多數介面 |
| 查訂單 | `http://example.invalid:9919/`（測試示例，文件稱 `api2URL`） | `getOrder.ashx` |

> ⚠️ **正式 Base URL 須由 918 客服 / 業務提供**，不在公開文件中。部署備忘檔（`注意事項(*站).txt`）含多組環境位址，部分已標註「錯的」，**禁止直接採用備忘檔中的位址**；一律以業務交付的正式 host 寫入 `vendor_infos.info.HOST`。

---

## merchant API

> 代理主動呼叫 918 的所有 API。每支介面都帶共同認證欄位（見[通用約定](#通用約定)），以 `POST` form 送出。端點路徑為「相對於 `HOST` 的路徑」。

> **APIs Driver 目前實作的子集**：`getUserInfo`（餘額）、`setServerScore`（上下分）、`editUser`（改密碼）。其餘介面（報表、列表、查訂單）文件有定義但 Driver 未封裝。

---

### 帳號管理

#### 1. 註冊產生玩家帳號（RandomUserName）

由系統依「玩家帳號區域」產生一組 918 玩家帳號，後續 `addUser` 須使用此回傳的 `userName`。

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/account.ashx` |
| action | `RandomUserName` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 你的代理名字 |
| `UserAreaId` | int | ✅ | 要產生的玩家帳號區域（`1`馬來 `2`泰國 `3`柬埔寨 `4`緬甸 `5`中國 `6`越南 `7`印尼） |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

> `UserAreaId` 代碼取自早期 docx 版本（`对外接口API说明文档(4).docx`）；3.0 PDF 僅標「你需要生成的玩家账号区域」未列代碼，使用前向客服確認。

---

#### 2. 新增帳號（addUser）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/account.ashx` |
| action | `addUser` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 玩家的代理（上一級） |
| `PassWd` | String | ✅ | 玩家密碼（長度 ≤ 17） |
| `pwdtype` | int | ❌ | 密碼格式，預設 `0` 加密、`1` 明文 |
| `userName` | String | ✅ | 用戶名（須來自 `RandomUserName`） |
| `Name` | String | ✅ | 用戶暱稱 |
| `Tel` | String | ✅ | 電話 |
| `Memo` | String | ✅ | 備註 |
| `UserType` | String | ✅ | `1` 正式玩家、`11` 測試玩家、`100` 代理 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

**Code**：`0` 成功、`-1` 帳號存在、`-2` 簽名錯誤、`-99` 非法操作。

```jsonc
// 失敗
{ "code": -1, "msg": "fail operation. exist", "success": false, "type": 2 }
// 成功
{ "code": 0, "msg": "successful operation.", "success": true, "type": 2 }
```

---

#### 3. 修改資料 / 改密碼（editUser）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/account.ashx` |
| action | `editUser` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `OldPassWd` | String | ✅ | 玩家舊密碼（長度 ≤ 17） |
| `PassWd` | String | ✅ | 玩家新密碼（長度 ≤ 17） |
| `pwdtype` | int | ❌ | 密碼格式，預設 `0` 加密、`1` 明文 |
| `Name` | String | ✅ | 用戶暱稱 |
| `Tel` | String | ✅ | 電話 |
| `Memo` | String | ✅ | 備註 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

**Code**：`0` 成功、`-1` 帳號或密碼錯誤、`-2` 簽名錯誤、`-99` 非法操作。

> **APIs 實作（`Driver::resetPassword`）**：`pwdtype=1`（明文）、`Tel='000000'`、`Name`/`Memo` 帶 `ScrAccount.username`，成功後回寫 `ScrAccount.password`。判定條件 `success && code === '0'`。

---

#### 4. 禁用 / 開啟帳號（disable）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/account.ashx` |
| action | `disable` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

**Code**：`0` 成功（`type:1`=禁用成功、`type:2`=開啟成功）、`-1` 異常錯誤、`-2` 簽名錯誤。

```jsonc
{ "code": 0, "msg": "successful operation.", "success": true, "type": 1 }
```

> 單一介面同時負責禁用與開啟，由回應 `type` 區分結果（呼叫時不指定方向，視當前狀態切換）。

---

### 錢包 / 上下分

#### 5. 上下分（setServerScore / setAgentScore）

**這是 Transfer Wallet 的存提核心介面。**

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/setScore.ashx` |
| action | `setServerScore`（玩家）/ `setAgentScore`（代理） |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `orderid` | String | ✅ | 50 字元內、系統內唯一編號；重複回 `-1 訂單錯誤` |
| `scoreNum` | double | ✅ | `>0` 加分（存款）、`<0` 扣分（提款） |
| `userName` | String | ✅ | 用戶名 |
| `ActionUser` | String | ✅ | 操作人 |
| `ActionIp` | String | ✅ | 上分人的 IP |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

**Code**：`0` 成功 / `-1` 訂單錯誤 / `-2` 簽名錯誤 / `-3` 正在設分 / `-4` 設分太頻繁 / `-5` 分數參數錯誤 / `-6` 系統異常 / `-7` 遊戲裡不能扣分 / `-8` 餘額不足 / `-9` 帳號不存在 / `-99` 非法操作。

```jsonc
// 文件成功範例（注意 code 為 1，與 Code 表「0 成功」矛盾）
{ "acc": "test001", "code": 1, "money": "4930.12", "msg": "success", "success": true }
```

> ⚠️ **成功 Code 矛盾（CRITICAL）**：Code 表寫 `0 成功`，但上方 JSON 範例 `code:1`。APIs 實作以 `code === '0'` 判成功。若正式機實際回 `code:1`，現行實作會誤判失敗。**上線前必須實測校正**（見 [integration.md](integration.md#critical-風險setScore-成功碼自相矛盾)）。
>
> **APIs 實作（`Driver::handleTransfer`）**：`action` 固定 `setServerScore`；存款 `scoreNum=+vendorAmount`、提款 `scoreNum=-vendorAmount`；`ActionUser=userName`、`ActionIp=Common::getIp()`；以 `code === '0'` 判成功，否則 `FAILED`；連線失敗 / 非陣列回應 → `TIMEOUT`。

---

#### 6. 查訂單（getOrder）

依 `orderid` 查上下分訂單是否成功。**逾時補償的關鍵介面。**

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/getOrder.ashx`（主機 `:9919`，與其他介面 port 不同） |
| action | 無 `action` 欄位，直接帶 `orderid` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `orderid` | String | ✅ | 訂單 id（系統內唯一） |
| `time` / `authcode` / `sign` | — | ✅ | **簽名以 `orderid` 取代 `userName`**（見[簽名 — getOrder 例外](#getoorder-例外簽名)） |

**Code**：`0` 沒有該訂單、`1` 訂單存在。

```jsonc
// 不存在
{ "Account": "", "AfterNum": "0", "C_DateTime": "", "OrderId": "569401425865871360", "ScoreNum": "0", "UserName": "", "code": 0, "success": true }
// 存在
{ "Account": "est01", "AfterNum": "9", "C_DateTime": "2018-06-20 10:29:22.777", "OrderId": "00558456183_...", "ScoreNum": "-1", "UserName": "00558456183", "code": 1, "success": true }
```

> ⚠️ **此介面簽名公式特殊**（`orderid` 替代 `userName`）；且使用獨立 port `:9919`。**APIs Driver 尚未實作此介面**。

---

### 查詢

#### 7. 查詢用戶資訊（getUserInfo / getSearchUserInfo）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/account/account.ashx` |
| action | `getUserInfo`（簡單）/ `getSearchUserInfo`（含上層巢狀層級） |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名（= `sn_LoginId`） |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

`getUserInfo` 回應主要欄位：

| 欄位 | 說明 |
|---|---|
| `Account` | 帳號 |
| `MoneyNum` / `ScoreNum` | 玩家餘額（**APIs `getBalance` 取 `MoneyNum`**） |
| `Status` | 狀態（`1` 正常） |
| `Type` | 用戶類型 |
| `success` | 是否成功 |

```jsonc
{ "Account": "test001", "MoneyNum": "214.15", "ScoreNum": "214.15", "Status": 1, "Type": 11, "idx": 2485777, "pwd": "1234", "success": true }
```

> `getSearchUserInfo` 額外回傳 `InfoID`（平台唯一玩家 idx）、`results`（玩家基礎資訊）、`upListArr`（上層層級關係，頂級 `admin`）。

---

#### 8. 查詢代理或玩家列表（agentList / playerList）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/getData/AccountList.ashx` |
| action | `agentList`（代理）/ `playerList`（玩家） |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `pageIndex` | int | ❌ | 第幾頁，預設 `1`（每頁 20 筆） |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

回應含 `total`（總筆數，供分頁）、`results[]`（每筆含 `Account`/`Agent`/`LimitNum`/`ScoreNum`/`Status`/`idx` 等）。

---

### 報表

> 報表類介面文件僅提供成功範例，未列完整錯誤碼。日期欄位格式為 `YYYY-MM-DD HH:mm:ss`。

#### 9. 玩家每日輸贏（AccountReport）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/AccountReport.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `sDate` | String(date) | ✅ | 起始日期 |
| `eDate` | String(date) | ✅ | 結束日期 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

`results[]` 按日期排序，每天的下注資訊：`Press`（總下注）、`win`（莊家贏）、`yield`（收益率）、`mydate`。

```jsonc
{ "ViewTYPE": 0, "msg": "ok", "results": [ { "mydate": "2017-08-01", "win": "0.00" } ], "success": true }
```

---

#### 10. 代理總輸贏報表（AgentMoneyLog）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/AgentMoneyLog.ashx` |

欄位同 [AccountReport](#9-玩家每日輸贏accountreport)（`userName` / `sDate` / `eDate` + 通用認證）。

`results[]` 每筆 `{ "mydate": "2017-08-15", "win": "263045.59" }`。

---

#### 11. 下屬玩家或代理報表（AgentTotalReport）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/AgentTotalReport.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userName` | String | ✅ | 用戶名 |
| `sDate` / `eDate` | String(date) | ✅ | 起 / 訖日期 |
| `Type` | String | ✅ | `ServerTotalReport`（玩家）/ `AgentTotalReport`（代理） |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

```jsonc
{ "ViewTYPE": 0, "msg": "ok",
  "results": [ { "Account": "0107506776", "idx": 44769, "memo": "", "name": "", "tel": "", "win": "-193.70" } ],
  "success": true }
```

---

#### 12. 玩家上下分明細（UserscoreLog）

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/UserscoreLog.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `pageIndex` | int | ❌ | 預設第一頁 |
| `userName` | String | ✅ | 用戶名 |
| `sDate` / `eDate` | String(date) | ✅ | 起 / 訖日期 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

`results[]` 每筆：`Account`、`ActionIP`、`Action_Type`、`AfterNum`（上下分後餘額）、`C_DateTime`、`Index_NO`、`ScoreNum`、`UserName`。外層另含 `total` / `totalScore` / `totalSum`。

---

#### 13. 玩家遊戲記錄（GameLog）

注單對帳主要來源。

| 項目 | 值 |
|---|---|
| Endpoint | `ashx/GameLog.ashx` |

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `pageIndex` | int | ❌ | 預設第一頁 |
| `pageSize` | int | ❌ | 每頁筆數，預設 `20`，最大 `1000` |
| `userName` | String | ✅ | 用戶名 |
| `sDate` / `eDate` | String(date) | ✅ | 起 / 訖日期 |
| `time` / `authcode` / `sign` | — | ✅ | 通用認證欄位 |

`results[]` 每筆注單欄位：

| 欄位 | 說明 |
|---|---|
| `id` | 注單唯一 id |
| `GameID` / `GameName` | 遊戲 ID / 名稱（如 `SeaCaptain`） |
| `ClassID` | 遊戲分類 |
| `RoundNO` | 局號 |
| `bet` / `Win` | 下注額 / 派彩額 |
| `BeginBlance` / `EndBlance` | 下注前 / 後餘額（**官方原始拼字即 `Blance`，非本平台錯誤**） |
| `LineNum` | 線數 |
| `CreateTime` | 建立時間 |
| `TableID` | 桌號 |

另回 `currMoney`（當前餘額）、`total`（總筆數）。

```jsonc
{ "ViewTYPE": 0, "currMoney": "0.00", "msg": "ok",
  "results": [
    { "id": 2719310, "GameID": 45, "GameName": "SeaCaptain", "ClassID": 3, "RoundNO": 0,
      "bet": "0.05", "Win": "0.00", "BeginBlance": "0.05", "EndBlance": "0.00",
      "LineNum": 25, "TableID": 0, "CreateTime": "2017-11-01 14:50:26", "Rownum": 1 }
  ],
  "success": true, "total": 2 }
```

---

## 簽名

SCR（918KISS）所有代理端送出的 API 請求，都必須帶 `sign` 欄位。918 端用相同公式重算後比對，不符則回 `code:-2 簽名錯誤`。

### 演算法總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | MD5 |
| 拼接順序 | `authcode + userName + time + secretKey` |
| 大小寫處理 | 拼接後整串轉小寫再 MD5（文件：`.tolower`） |
| 輸出 | 32 字元 hex 字串 |

官方公式（取自 `918对外接口API3.0说明文档.pdf`）：

```
sign = md5( (authcode + userName + time + secretKey).tolower )
```

注意：`.tolower` 套用在「拼接後的整串」上，而非個別欄位。

### 各欄位來源

| 欄位 | 來源 |
|---|---|
| `authcode` | 918 提供的授權碼（`vendor_infos.info.AUTH_CODE`） |
| `userName` | 玩家 918 帳號（`ScrAccount.player_id`）；報表 / 列表類為查詢的代理 / 玩家帳號 |
| `time` | 13 位毫秒時間戳（`date('U') . '000'`，等同 `System.currentTimeMillis()`） |
| `secretKey` | 918 提供的簽名密鑰（`vendor_infos.info.SECRET_KEY`，**不參與傳輸**） |

### APIs 專案實作

```php
private function getSignature(array $data): string
{
    $string = $data['authcode'] . $data['userName'] . $data['time'] . $this->secretKey;

    return strtoupper(md5(strtolower($string)));
}
```

對應流程：

1. 依文件順序拼接 `authcode . userName . time . secretKey`
2. `strtolower()` 整串轉小寫（對應文件 `.tolower`）
3. `md5()` 取 hex
4. `strtoupper()` 轉大寫

> ⚠️ **實作多了 `strtoupper`**：文件公式只寫到 `md5(...)`，未明示輸出需大寫。若 918 端對 `sign` 比對大小寫敏感且要求小寫，將觸發 `code:-2`。現行 Driver 若生產環境正常，代表 918 接受大寫（或大小寫不敏感）。出現大量 `-2` 時，嘗試移除 `strtoupper` 改純小寫 hex。

### getOrder 例外簽名

`getOrder.ashx` 簽名以 `orderid` 取代 `userName`：

```
sign = md5( (authcode + orderid + time + secretKey).tolower )
```

> 補實作 `getOrder` 時務必使用此特殊公式，不可沿用一般公式。

### 完整範例

```
authcode  = "lM9etaHj"
userName  = "test001"
time      = "1559025944642"
secretKey = "GTnFOjRtrtK2"

拼接      = "lM9etaHjtest0011559025944642GTnFOjRtrtK2"
轉小寫    = "lm9etahjtest0011559025944642gtnfojrtrtk2"
md5       = <32 字元 hex>
（實作再 strtoupper → 大寫 hex）
```

> 上方 `secretKey`/`auth` 取自官方文件「測試秘鑰（以後會刪除的）」段落，僅供格式示意，**非正式金鑰**。

---

## 錯誤碼

### 註冊 / 修改資料（`account.ashx`）

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 帳號存在（addUser）/ 帳號或密碼錯誤（editUser） |
| `-2` | 簽名錯誤 |
| `-99` | 非法操作 |

### 上下分（`setScore.ashx`）

| Code | 說明 |
|---|---|
| `0` | 成功（⚠️ 文件 JSON 範例為 `1`，見 [integration.md](integration.md#critical-風險setScore-成功碼自相矛盾)） |
| `-1` | 訂單錯誤（`orderid` 重複） |
| `-2` | 簽名錯誤 |
| `-3` | 正在設分，稍後 |
| `-4` | 設分太頻繁，稍後 |
| `-5` | 分數參數錯誤 |
| `-6` | 系統異常，稍後 |
| `-7` | 遊戲裡不能扣分 |
| `-8` | 餘額不足 |
| `-9` | 帳號不存在 |
| `-99` | 非法操作 |

### 禁用 / 開啟帳號（`account.ashx?action=disable`）

| Code | 說明 |
|---|---|
| `0` | 成功（`type:1`=禁用成功、`type:2`=開啟成功） |
| `-1` | 異常錯誤 |
| `-2` | 簽名錯誤 |

### 查訂單（`getOrder.ashx`）

| Code | 說明 |
|---|---|
| `0` | 無此訂單（不存在） |
| `1` | 訂單存在 |

### 簽名常見錯誤

| 症狀 | 可能原因 |
|---|---|
| `code:-2 簽名錯誤` | `secretKey` / `authcode` 錯誤；`time` 差異過大；大小寫處理不一致（見上方 `strtoupper` 說明） |
| 偶發 `-2` | `date('U') . '000'` 為秒級補三個 0，非真毫秒；若 918 時效窗口極窄須留意 |
| 全部介面 `-2` | 拼接欄位順序錯（必須 `authcode + userName + time + secretKey`） |
