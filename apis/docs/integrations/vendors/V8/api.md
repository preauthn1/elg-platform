# V8 Poker（V8）— API 規格（合併版）

> **原始來源（官方文檔）**：
> - `V8 Poker API接入文档中文版_20210510.pdf`（簡中版，52 頁，版本記錄至 V1.1.9 / 2020-05-10；**本文件主要事實來源**）
> - `（正式）V-World-V8对接资料.docx`、`（测试）V-World-V8对接资料.docx`（V-World 專屬後台 / 白名單說明）
> - `附件1：加解密代码/`（官方加解密範例：Encrypt.cs / Encrypt.js / Encrypt.java / Encrypt.php / Encrypt_7.1.php）
> - 抓取日期：2026-06-03
>
> **遊戲商定位**：龍城集團（Long Cheng Group）旗下中國風棋牌遊戲平台，越南棋牌品牌；本專案以 vid 42 接入。

V8 Poker 對接採 **Transfer Wallet（轉帳錢包）** 模式：玩家餘額由 V8 端保管，玩家進入遊戲前須由代理（本平台）主動呼叫「上分」把本地餘額轉入 V8；離開時呼叫「下分」轉回本地。所有 API 皆為**代理主動呼叫 V8**，**無 V8 主動回調本平台**的下注扣款行為。

> 官方文檔「接入準備」提及一個**非必要**的「回調通知接口」，僅在玩家下線時由 V8 通知代理（參數 `agent` / `account` / `money`），與下注金流無關，本專案 Driver 未實作。

---

## 通用約定

### 請求格式

- **HTTP Method**：`GET`（所有參數放在 query string）
- **Endpoint**：依用途分兩個入口
  - `channelHandle` — 玩家 / 錢包 / 帳號類操作（登入、查餘額、上下分、查單、在線狀態、踢線、玩家總分、查積分等）
  - `getRecordHandle` — 報表 / 注單類操作（查詢遊戲注單、同桌玩家對局日誌、輸贏排行）
- **驗證方式**：IP 白名單（須提供伺服器 IP 給 V8 客服加入）＋ 加密參數 `param` ＋ MD5 簽名 `key`

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理編號（平台提供，本專案存於 `info.AGENT`） |
| `timestamp` | String | ✅ | Unix 時間戳（**帶毫秒**，13 位），取當前時間 |
| `param` | String | ✅ | 業務參數以 `key=value&...` 組成後 **AES-128-ECB 加密 → urlencode**（見 [簽名](#簽名) 章節） |
| `key` | String | ✅ | 簽名 `MD5(agent + timestamp + MD5Key)` |

> ⚠️ 官方範例 URL 不使用標準 `http_build_query()` 拼接，因 `param` 內含 base64 字元（`+` `/` `=`）會被二次編碼破壞。本專案 Driver 自行以 `params()` 方法逐段拼接。

### 通用回應格式

```jsonc
{"s":100,"m":"/channelHandle","d":{"code":0,"url":"..."}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `s` | Int | 子操作類型（回應對應請求的 `s`，例：登入回 100、查可下分回 101…） |
| `m` | String | 主操作類型（`/channelHandle` 或 `/getRecordHandle`） |
| `d` | Object | 資料結果，內含 `code`（錯誤碼，`0` 為成功）與各 API 專屬欄位 |

> 部分 API（s=12 取得帳戶餘額、s=13 上分請求申請）回應為**扁平結構**直接 `{"code":0,"money":...}`，無 `s`/`m`/`d` 外層。

### 子操作類型 `s` 一覽

| `s` | 用途 | Endpoint | 本專案 Driver |
|---|---|---|---|
| 0 | 登入遊戲（不存在則建號 + 可帶上分） | channelHandle | `getGameUrl()` |
| 1 | 查詢可下分餘額 | channelHandle | — |
| 2 | 上分（存款，本地 → V8） | channelHandle | `transferToVendor()` |
| 3 | 下分（提款，V8 → 本地） | channelHandle | `transferToLocal()` |
| 4 | 查詢訂單狀態 | channelHandle | `checkTransferStatus()` |
| 5 | 查詢玩家在線狀態 | channelHandle | — |
| 6 | 查詢遊戲注單 | getRecordHandle | —（注單同步未在 Driver 實作） |
| 7 | 查詢玩家總分（總額 / 可下分 / 在線） | channelHandle | `getBalance()`（取 `freeMoney`） |
| 8 | 踢玩家下線 | channelHandle | — |
| 12 | 取得帳戶餘額（扁平回應；另見注意） | 代理提供 URL | — |
| 13 | 上分請求申請（扁平回應） | 代理提供 URL | — |
| 14 | 查詢代理餘額（非必要） | channelHandle | — |
| 17 | 取得同桌玩家與對局日誌頁面連結 | getRecordHandle | — |
| 18 | 查詢玩家積分 List（單次 ≤1000 帳號） | channelHandle | — |
| 19 | 玩家是否在線 List（分頁，pagesize ≤1000） | channelHandle | — |

> ⚠️ **s=12 語意衝突**：`s=12` 在 §3.2.11「取得帳戶餘額」（channelHandle 變體，傳 `account`）與 §3.2.16「玩家輸贏排行」（getRecordHandle，傳 `dayTime`）共用同一 `s` 值但語意完全不同。接通時務必向 V8 客服確認最新文檔。

---

## Merchant API

代理（本平台）主動呼叫 V8 的所有 API。

```text
https://<server>:<port>/<endpoint>?agent=XX&timestamp=XX&param=XX&key=XX
```

### 1. 登入遊戲（s=0）

驗證遊戲帳號，不存在則自動建號並（可選）上分；回傳遊戲 H5 連結。本專案對應 `Driver::getGameUrl()`。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=0&account=<會員帳號>&money=<金額,0 不上分>&orderid=<流水號>&ip=<玩家IP>&lineCode=<站點標識>&KindID=<遊戲ID>` |

欄位說明：

| 欄位 | 說明 |
|---|---|
| `money` | 上分金額；不上分傳 `0`（本專案傳 0） |
| `orderid` | 流水號，格式 `代理編號 + yyyyMMddHHmmssSSS + account` |
| `ip` | 玩家請求 IP（本專案傳 `0.0.0.0`） |
| `lineCode` | 站點標識，≤10 字英數，區分同代理下不同站點；**勿一玩家一 lineCode** |
| `KindID` | 遊戲 ID，`0` = 大廳（對應表見 [KindID 對應遊戲](#kindid-對應遊戲)） |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼，`0` 成功 |
| `url` | String | 遊戲 / 大廳 H5 連結，含 `account` / `token` / `lang` / `skin` / `gameId` |

```jsonc
{"s":100,"m":"/channelHandle","d":{"code":0,"url":"http://example.invalid/index.html?account=10001_111111&token=[REDACTED]"}}
```

> **語言 `lang`**：`vi_vn`（越文）、`en_us`（英文），須小寫。本專案 `getGameUrl()` 取回 url 後以 `str_replace('lang=vi_vn', 'lang='.$language, $url)` 改寫語言，並於尾端附加 `&backUrl=<exitUrl>`。

---

### 2. 查詢可下分（s=1）

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=1&account=<會員帳號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `money` | Float | 可下分餘額 |
| `code` | Int | 錯誤碼 |

```jsonc
{"s":101,"m":"/channelHandle","d":{"money":100,"code":0}}
```

---

### 3. 上分（s=2）

本地餘額 → V8 錢包（存款）。本專案對應 `Driver::transferToVendor()`。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=2&account=<會員帳號>&money=<金額>&orderid=<流水號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼，`0` 成功 |
| `money` | Float | 上分後餘額 |

```jsonc
{"s":102,"m":"/channelHandle","d":{"code":0,"money":10000}}
```

> Driver 判定：`code != '0'` → `FAILED`；非 JSON / `code` 缺漏 / 非數字 → `TIMEOUT`；`code == '0'` → `SUCCESS`。上分失敗回滾規則見下方「上分 / 下分冪等與重試」。

---

### 4. 下分（s=3）

V8 錢包 → 本地餘額（提款）。本專案對應 `Driver::transferToLocal()`。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=3&account=<會員帳號>&money=<金額,不超過分數>&orderid=<流水號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `money` | Float | 下分後可下分金額 |

```jsonc
{"s":103,"m":"/channelHandle","d":{"money":"111111","code":0}}
```

---

### 5. 查詢訂單（s=4）

查上 / 下分訂單狀態，以 `status` 判定成敗。本專案對應 `Driver::checkTransferStatus()`。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=4&orderid=<流水號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `status` | Int | `-1` 不存在、`0` 成功、`2` 失敗、`3` 處理中 |
| `money` | Float | 交易金額 |

```jsonc
{"s":104,"m":"/channelHandle","d":{"code":0,"status":2}}
```

> Driver 對應：`status=0`→SUCCESS；`status=-1` 或 `2`→FAILED；其餘（含 `3`）→TIMEOUT。`code != '0'` 時整體歸 TIMEOUT。

---

### 6. 查詢玩家在線狀態（s=5）

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=5&account=<會員帳號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `status` | Int | `-1` 找不到帳號、`0` offline、`1` online、`2` 非正常玩家（黑名單） |

```jsonc
{"s":105,"m":"/channelHandle","d":{"code":0,"status":0}}
```

---

### 7. 查詢玩家總分（s=7）

查玩家遊戲內總分、可下分餘額、在線狀態。本專案 `Driver::getBalance()` 取此 API 的 `freeMoney`。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=7&account=<會員帳號>` |

**回應 `d`**：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `totalMoney` | Float | 總餘額 |
| `freeMoney` | Float | 可下分餘額（Driver 取此值作為 `getBalance()`） |
| `status` | Int | `-1` 不存在、`0` 不在線、`1` 在線 |
| `code` | Int | 錯誤碼 |

```jsonc
{"s":107,"m":"/channelHandle","d":{"totalMoney":100,"freeMoney":80,"status":0,"code":0}}
```

---

### 8. 踢玩家下線（s=8）

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=8&account=<會員帳號>` |

**回應 `d`**：`{"code":0}`

---

### 9. 取得帳戶餘額（s=12）

取得現金網帳戶餘額。**Endpoint 為「代理提供 / 由平台管理員添加」的自訂 URL**，回應為**扁平結構**（無 `s`/`m`/`d` 外層）。

| 項目 | 值 |
|---|---|
| Endpoint | 代理提供 URL（非 channelHandle） |
| `param` 明文 | `s=12&account=<會員帳號>` |

**回應**：

```jsonc
{"code":0,"money":87333}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | `0` 為成功 |
| `money` | Float | 餘額 |

---

### 10. 上分請求申請（s=13）

以「上分請求申請」方式發起上分。Endpoint 為代理提供 URL，回應扁平結構。

| 項目 | 值 |
|---|---|
| Endpoint | 代理提供 URL |
| `param` 明文 | `s=13&account=<會員帳號>&money=<轉帳金額>` |

**回應**：`{"code":0}`（`code = 0` 為成功）

---

### 11. 查詢代理餘額（s=14，非必要）

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=14` |

**回應 `d`**：

```jsonc
{"m":"/channelHandle","s":114,"d":{"code":0,"money":"1000000.01"}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `money` | Float | 代理餘額 |

---

### 12. 查詢玩家積分 List（s=18）

批次查多個帳號餘額，單次最多 1000 帳號（超過回錯誤碼 45）。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=18&accounts=<帳號1,帳號2,...>` |

**回應 `d`**：

```jsonc
{"m":"/channelHandle","s":118,"d":{"code":0,"list":{"accounts":["60000_yaya","60000_qwer"],"money":[100053,99987]}}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼（`45` = 超過單次最多帳號查詢數量） |
| `list.accounts` | Array[String] | 會員帳號 |
| `list.money` | Array[Float] | 對應餘額 |

---

### 13. 玩家是否在線 List（s=19）

分頁查多帳號在線狀態，`pagesize` 上限 1000。

| 項目 | 值 |
|---|---|
| Endpoint | `channelHandle` |
| `param` 明文 | `s=19&pageindex=<頁數,從1開始>&pagesize=<單次筆數,≤1000>` |

**回應 `d`**：

```jsonc
{"m":"/channelHandle","s":119,"d":{"code":0,"list":{"accounts":["60000_max","60000_CC","60000_rain","60000_kenny"],"total":4,"pageindex":1,"pagesize":100}}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼（`45` = 超過上限） |
| `list.accounts` | Array[String] | 在線會員帳號 |
| `list.total` | Long | 總比數 |
| `list.pageindex` / `list.pagesize` | Int | 分頁資訊 |

---

### 14. 查詢遊戲注單（s=6）

取遊戲對局注單（**以遊戲派獎時間為準**）。本專案 Driver 未直接實作；注單同步由排程 / 對帳工具負責。

| 項目 | 值 |
|---|---|
| Endpoint | `getRecordHandle` |
| `param` 明文 | `s=6&startTime=<毫秒時間戳>&endTime=<毫秒時間戳>` |

> **拉取規範**：拉取當前時間 30 秒之前的資料；建議區間 1~5 分鐘；最大不超過 60 分鐘；兩次拉單間隔須 >1 秒（否則錯誤碼 43）。

**回應 `d`**（各欄位為**陣列**，逐筆對應一局）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `count` | Int | 回傳列表行數 |
| `serverStartTime` / `serverEndTime` | String | 實際拉取的資料時間範圍 |
| `list.GameID` | Array | 遊戲局號 |
| `list.Accounts` | Array | 玩家帳號 |
| `list.ServerID` | Array | 房間 ID |
| `list.KindID` | Array | 遊戲 ID（對應 [KindID 對應遊戲](#kindid-對應遊戲)） |
| `list.TableID` | Array | 桌子號 |
| `list.ChairID` | Array | 椅子號 |
| `list.UserCount` | Array | 玩家數量 |
| `list.CardValue` | Array | 手牌公共牌（讀牌規則依 KindID 不同，見官方附錄 4.4） |
| `list.CellScore` | Array | 有效下注 |
| `list.AllBet` | Array | 總下注 |
| `list.Profit` | Array | 盈利 |
| `list.Revenue` | Array | 抽水 |
| `list.GameStartTime` / `list.GameEndTime` | Array | 遊戲開始 / 結束時間 |
| `list.ChannelID` | Array | 渠道 ID |
| `list.LineCode` | Array | 遊戲結果對應玩家所屬站點 |

```jsonc
{"s":106,"m":"/getRecordHandle","d":{"list":{"GameID":[...],"Accounts":["test"],"ServerID":[3602],"KindID":[620],"CellScore":[800],"AllBet":[800],"Profit":[760],"Revenue":[40]},"count":13,"code":0,"serverStartTime":"2017-03-27 13:00:00","serverEndTime":"2017-03-27 15:00:00"}}
```

> ⚠️ **金額正規化**：`CellScore` / `AllBet` / `Profit` / `Revenue` 等為 Float，由 JSON decode 取得後若要參與運算或寫入，必須依專案規範以 `Common::numAdd('0', (string) $value, 4)` 正規化，禁止直接 `(string)` 轉型。

---

### 15. 同桌玩家與對局日誌連結（s=17）

| 項目 | 值 |
|---|---|
| Endpoint | `getRecordHandle` |
| `param` 明文 | `s=17&gameid=<由查注單取得>&account=<由查注單取得>&lang=<1簡中|2英文>` |

**回應 `d`**：

```jsonc
{"m":"/getRecordHandle","s":117,"d":{"code":0,"url":"http://localhost/sameTableNGameLog?params=...&lang=1"}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `url` | String | 對局日誌頁面連結 |

---

### 16. 玩家輸贏排行（s=12 / dayTime，非必要）

> ⚠️ **s=12 語意衝突**：此處 `s=12` 與「9. 取得帳戶餘額」共用同一值，但 Endpoint 與 `param` 不同（一用 `dayTime`、一用 `account`）。接通時務必向 V8 客服確認。

官方標「非必要接口，會增加伺服器負擔，請依需求謹慎選用」。

| 項目 | 值 |
|---|---|
| Endpoint | `getRecordHandle` |
| `param` 明文 | `s=12&dayTime=<YYYY-MM-DD>` |

**回應 `d`**：

```jsonc
{"m":"/getRecordHandle","s":112,"d":{"code":0,"start":"2018-04-23 00:00:00","end":"2018-04-23 23:59:59","list":{"Accounts":["10170_user#1"],"TotalGames":[1],"ValidBet":[2000],"DeductGold":[0],"ProfitGold":[-2000]}}}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Int | 錯誤碼 |
| `Count` | String | 回傳列表行數 |
| `list.Accounts` | Array | 玩家帳號 |
| `list.TotalGames` | Array | 遊戲局數 |
| `list.ValidBet` | Array | 有效投注 |
| `list.DeductGold` | Array | 抽水 |
| `list.ProfitGold` | Array | 輸贏 |

---

## 上分 / 下分冪等與重試

- **訂單號（`orderid`）由代理依規則生成**（格式：`代理編號 + yyyyMMddHHmmssSSS + account`）。本專案 Driver 以 `transactionNo`（`VendorTransferData`）作為 `orderid`，不重複使用。
- **未收到回應時**：官方建議每 5 秒查一次 `s=4`（查單），共 3 次後若仍未明確再以查單結果為準。

### 上分失敗錯誤類型

| 情境 | 處理 |
|---|---|
| 訂單號已處理（已成功） | **不需回滾** |
| 訂單號錯誤 / 格式不符 | 失敗，**需回滾** |
| 代理商餘額不足 | 失敗，**需回滾** |

### 下分失敗錯誤類型

| 情境 | 處理 |
|---|---|
| 訂單號錯誤 | 失敗，本地不加分 |
| 玩家餘額不足 | 失敗，本地不加分 |
| 訂單號已處理 | 若本地未加分則需補加 |

### 轉帳狀態碼 `status`（`s=4` 查單回傳）

| status | 含意 | 本專案 `checkTransferStatus()` 對應 |
|---|---|---|
| `-1` | 不存在（Inexistence） | `VendorTransferResultEnum::FAILED` |
| `0` | 成功（Success） | `VendorTransferResultEnum::SUCCESS` |
| `2` | 失敗（Failure） | `VendorTransferResultEnum::FAILED` |
| `3` | 正在處理中（Processing） | `VendorTransferResultEnum::TIMEOUT`（其餘未知值亦歸 TIMEOUT） |

---

## 多幣別支援

非人民幣幣別接 API 時，須由 V8 客服**開通對應幣別線路**，並限制只有該幣別地區用戶可登入。會員上 / 下分時，本平台須**先依匯率把當地幣別換算為「遊戲幣」**再呼叫上下分接口。**傳入遊戲幣小數 2 位以後的部分會被捨棄**。

### 遊戲幣兌換比例（官方附錄 4.5）

| 幣別 | 1 單位當地幣 = ? 遊戲幣 |
|---|---|
| 人民幣 RMB | 1.000 |
| 美元 USD | 7 |
| 新台幣 TWD | 0.2 |
| 馬幣 MYR | 2 |
| 越南盾 VND | 0.0003 |
| 泰銖 THB | 0.2 |
| 印尼盾 IDR | 0.0005 |
| 日元 JPY | 0.05 |
| 韓元 KRW | 0.006 |
| 印度盧比 INR | 0.1 |
| 歐元 EUR | 8 |
| 英鎊 GBP | 10 |
| 越南盾 2 VNDK | 0.3 |
| 緬元 MMK | 0.005 |

---

## 簽名

### 演算法總覽

| 項目 | 值 |
|---|---|
| `param` 加密演算法 | AES-128-ECB（官方寫 `Encrypt.AESEncrypt`） |
| `param` Padding | PKCS7（OpenSSL `aes-128-ecb` 預設） |
| `param` 金鑰 | DESKey（平台提供，欄位沿用「DESKey」命名，實際為 AES 16-byte key） |
| `param` 輸出 | base64 → **urlencode**（含 `+` `/` `=` 須再 URL 編碼） |
| `key` 簽名演算法 | `MD5(agent + timestamp + MD5Key)`（字串直接相連，無分隔符） |
| 金鑰來源 | DESKey、MD5Key、agent 皆由 V8 平台提供 |

> ⚠️ **DES vs AES 命名落差（重要）**：官方附件 `Encrypt.php` / `Encrypt_7.1.php` 範例使用 DES（`mcrypt_encrypt(MCRYPT_DES, ...)`），且錯誤碼 22 文字寫「**DES** 解密失敗」；但各 API 章節的 `param` 說明一律標 `Encrypt.AESEncrypt(param, DESKey)`，本專案 Driver 也以 `openssl_encrypt($message, 'aes-128-ecb', $desKey, 0)` 實作。以**正式 API 章節與 Driver 實作為準 = AES-128-ECB**；「DESKey」只是金鑰欄位命名沿用舊版。**切勿複製附件 PHP 範例（DES）**，否則會觸發錯誤碼 22。接通前建議向 V8 客服再確認演算法版本。

### 構成步驟

**Step 1 — 業務參數組成 query string（明文）**

依各 API 的子操作類型 `s` 與必要欄位拼成 `key=value&...`：

```text
s=2&account=111111&money=100&orderid=10001201703061430369491111
```

**Step 2 — AES-128-ECB 加密並 urlencode（得到 `param`）**

```php
// 本專案 Driver 實作（app/Vendors/V8/Driver.php）
private function encrypt(array $data): string
{
    $message = http_build_query($data);

    return urlencode(openssl_encrypt($message, 'aes-128-ecb', $this->desKey, 0));
}
```

- `openssl_encrypt(..., 0)`：旗標 `0` 代表回傳 **base64 字串**（非 `OPENSSL_RAW_DATA`），ECB 模式不需 IV。
- 外層 `urlencode`：因 base64 結果含 `+` `/` `=`，放進 URL query 必須再編碼，否則 V8 端解密失敗（錯誤碼 22）。

**Step 3 — 計算簽名 `key`**

```php
private function sign($time): string
{
    return md5($this->agentId . $time . $this->md5Key);
}
```

串接順序固定為 `agent` → `timestamp` → `MD5Key`，**無分隔符、無其他欄位**。`timestamp` 為 13 位帶毫秒 Unix 時間戳，且**簽名用的 timestamp 必須與 query 上的 `timestamp` 完全一致**。

**Step 4 — 組成最終請求 URL**

```php
$params = [
    'agent'     => $this->agentId,
    'timestamp' => $time,
    'param'     => $param,   // 已 AES+urlencode
    'key'       => $this->sign($time),
];
$url .= '?' . $this->params($params);   // 自訂拼接，非 http_build_query
```

> **不可用 `http_build_query()` 拼接整個 URL**：`param` 已是 urlencode 後的字串，若再經 `http_build_query` 會被二次編碼（`%` → `%25`）導致 V8 端無法還原（錯誤碼 22）。

### 各 API `param` 明文速查

| `s` | 用途 | `param` 明文範例 |
|---|---|---|
| 0 | 登入遊戲 | `s=0&account=111111&money=100&orderid=...&ip=0.0.0.0&lineCode=text11&KindID=0` |
| 1 | 查可下分 | `s=1&account=111111` |
| 2 | 上分 | `s=2&account=111111&money=100&orderid=...` |
| 3 | 下分 | `s=3&account=111111&money=100&orderid=...` |
| 4 | 查單 | `s=4&orderid=10001201703021948213 16` |
| 5 | 查在線狀態 | `s=5&account=111111` |
| 6 | 查注單 | `s=6&startTime=1488781836949&endTime=1488781836949`（毫秒時間戳） |
| 7 | 查玩家總分 | `s=7&account=111111` |
| 8 | 踢玩家下線 | `s=8&account=111111` |
| 12 | 取得帳戶餘額 | `s=12&account=111111` |
| 13 | 上分請求申請 | `s=13&account=111111&money=100` |
| 14 | 查代理餘額 | `s=14` |
| 17 | 同桌玩家 / 對局日誌連結 | `s=17&gameid=60-1611126971-32952063-1&account=user&lang=1` |
| 18 | 查玩家積分 List | `s=18&accounts=qwer,yaya`（單次 ≤1000） |
| 19 | 玩家在線 List | `s=19&pageindex=1&pagesize=100`（pagesize ≤1000） |

> `orderid` 格式：`代理編號 + yyyyMMddHHmmssSSS + account`。`lang`：1 = 簡中、2 = 英文。

### 常見簽名錯誤排查

| 症狀 | 可能原因 |
|---|---|
| `code:22` DES 解密失敗 | DESKey 錯、`param` 漏 urlencode、或用 `http_build_query` 二次編碼了整個 URL |
| `code:15` 渠道驗證錯誤 | MD5Key 錯、簽名 timestamp 與 query timestamp 不一致、`agent+timestamp+MD5Key` 拼接順序錯 |
| `code:3` 驗證時間超時 | `timestamp` 非當前時間（時鐘漂移）或非 13 位毫秒 |
| `code:5` / `code:28` | 伺服器 IP 未加入 V8 白名單 / IP 被禁用 |
| `code:6` 驗證欄位丟失 | `agent` / `timestamp` / `param` / `key` 任一缺漏 |

---

## 錯誤碼

`code = 0` 為成功。

| code | 說明 |
|---|---|
| -1 | 上下分時資料庫異常回滾 |
| 0 | 成功 |
| 1 | TOKEN 丟失（重新呼叫登入接口取得） |
| 2 | 渠道不存在（檢查 agent / 渠道 ID） |
| 3 | 驗證時間超時（檢查 timestamp） |
| 5 | 渠道白名單錯誤（聯繫客服加伺服器 IP） |
| 6 | 驗證欄位丟失（檢查參數完整性） |
| 8 | 不存在的請求（檢查子操作類型 `s`） |
| 15 | 渠道驗證錯誤（MD5Key 錯 / timestamp 不一致 / key 拼接順序錯） |
| 16 | 資料不存在（當前沒有注單） |
| 20 | 帳號禁用 |
| 22 | **DES 解密失敗**（官方文字寫 DES；實際加密為 AES，見 [簽名](#簽名) 章節說明） |
| 24 | 渠道拉取資料超過時間範圍 |
| 27 | 資料庫異常 |
| 28 | IP 禁用 |
| 29 | 訂單號與訂單規則不符 |
| 30 | 取得玩家在線狀態失敗 |
| 31 | 更新的分數小於或等於 0 |
| 32 | 更新玩家資訊失敗 |
| 33 | 更新玩家金幣失敗 |
| 34 | 訂單重複 |
| 35 | 取得玩家資訊失敗（請呼叫登入接口建號） |
| 36 | kindID 有誤 |
| 37 | 登入瞬間禁止下分，導致下分失敗 |
| 38 | 餘額不足導致下分失敗 |
| 39 | 禁止同一帳號登入 / 上分 / 下分並發請求，後一請求被拒 |
| 40 | 單次上下分數量不能超過一百億 |
| 41 | 拉取對局匯總統計時間範圍有誤 |
| 42 | 代理被禁用 |
| 43 | 拉單過於頻繁（兩次間隔須 >1 秒） |
| 44 | 有相同的訂單正在處理中 |
| 45 | 金額數量不符合規則（亦用於「超過單次最多帳號查詢數量」） |
| 999 | 請求失敗 |
| 1001~1025 | 會員 / 代理 / 大廳上下分相關業務異常（如 1011 訂單已存在、1022 訂單已處理過、1023 玩家正在遊戲中不能上下分、1024 大廳會員帳號不存在等） |

---

## KindID 對應遊戲

`getGameUrl` 的 `KindID` 傳 `0` 為大廳（本專案在 `getGameUrl` 以 `params->gameCode` 傳入）。節錄：

| KindID | 遊戲 | KindID | 遊戲 |
|---|---|---|---|
| 0 | 大廳 | 610 | 鬥地主 |
| 620 | 德州撲克 | 890 | 看四張搶莊牛 |
| 720 | 二八槓 | 910 | 百家樂 |
| 830 | 搶莊牛牛 | 930 | 百人牛牛 |
| 220 | 炸金花 | 640 | 跑得快 |
| 860 | 三公 | 510 | 雷射炮捕魚 |
| 900 | 龍虎鬥 | 8280 | 幸運水果機 |
| 600 | 21 點 | … | （完整 KindID / CardValue 讀牌規則見官方附錄 4.1 / 4.4） |

> 完整遊戲清單與多語名稱另見原始資料夾 `V8游戏列表_中英越.xlsx`。
