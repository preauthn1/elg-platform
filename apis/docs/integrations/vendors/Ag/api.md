# Ag（Asia Gaming）— 廠商 API 規格

> **原始來源**
> - `20211112 ag_gi_doc_7.8_cn.pdf`（AG 平台 API 文档 v7.8，主要事實來源）
> - `20220606_AG_gi_gametype_cn.pdf`（AG 平台參數文档：gametype / oddtype / currency / language）
> - 補充參考：`Asia Gaming/AGIN_DataAPI_V3.34.pdf`（注單拉取 Data API，本專案 Driver 未使用，供對帳 RD 參考）
>
> 抓取日期：2026-06-03
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得）**。

Asia Gaming（AG）為亞洲知名真人視訊（百家樂 / 龍虎 / 輪盤 / 骰寶等）與電子遊戲商。本專案以 **Transfer Wallet（轉帳錢包）** 接入，vendor_id=1，`VendorIdEnum::AG`。

---

## 通用約定

### 請求格式

- **HTTP Method**：`POST`（`doBusiness.do` / `forwardGame.do` 都用 form post，參數放 query string，request body 為空）
- **傳輸結構**：所有業務參數先用 `\/` 分隔組成明文，DES-ECB 加密 → Base64 後當作 `params`，再以 `MD5(params + MD5_Encrypt_key)` 算出 `key`，組成 URL：
  ```
  http://<server>/doBusiness.do?params=<DES-Base64>&key=<MD5>
  ```
- **回應格式**：XML，形如 `<result info="…" msg=""/>`，以 `info` 屬性判定結果、`msg` 為失敗原因（成功時 `msg` 為空）
- **驗證方式**：DES 加密 + MD5 簽章（見下方「簽章」節）；轉帳錢包不需代理提供回調

### `User-Agent` 標頭（必填）

呼叫 `doBusiness.do` 時**必須**帶 `User-Agent: WEB_LIB_GI_<cagent>`（官方 §4.5）。

> **呼叫 `forwardGame.do` 時不需要此標頭。**

### 域名分流

| API | 域名 | `vendor_infos.info` key |
|---|---|---|
| `doBusiness.do`（建號 / 查餘額 / 轉帳 / 查單） | **gi** 域名 | `BUSINESS_HOST` |
| `forwardGame.do`（進入遊戲頁面） | **gci** 域名 | `FORWARD_HOST` |

### Base URL

官方文檔以 `<server>` 佔位，未提供正式網址；正式域名（gi / gci）與 port 由 AG「上線說明文檔」提供。

### Timeout

> 使用 HttpClient 呼叫 `doBusiness.do` / `forwardGame.do` 時，**請設定 timeout ≥ 30 秒**（官方 §3.4 / §3.6 / §4.3）。

---

## 簽章

### 演算法

| 項目 | 值 |
|---|---|
| 對稱演算法 | DES（單 DES，ECB 模式，不需 IV） |
| Padding | PKCS#7（區塊 8 bytes） |
| DES 金鑰 | `DES_KEY`（由 AG 上線說明文檔提供，例：`12341234`） |
| 明文格式 | `key=value\/key=value\/...`（`\/` 分隔，**尾部不加**） |
| 加密輸出 | Base64 |
| 簽章 | `key = MD5(params + MD5_Encrypt_key)`，32 位**小寫** hex |

### 構成步驟

1. 組明文：`cagent=XXXX\/loginname=XXXX\/method=lg\/...`（`\/` 分隔符，尾部不加）
2. DES-ECB 加密 → Base64 → 得 `params`
3. `key = MD5(params + "MD5_Encrypt_key")`（32 位小寫 hex，大寫會 key_error）
4. URL：`http://<server>/doBusiness.do?params=<params>&key=<key>`

### 一致性驗證範例（官方 §4.8）

```
DES Key = 12341234
明文    ：cagent=81288128\/method=tc
Java/PHP 密文（Base64）：IGcOAYEQN88F1NFLtBOK29lcQSW2a8b/G8UgSaeEyaA=
```

### PHP 實作

```php
// DesService（DES-ECB，手動 PKCS7，Base64）
$data = $this->pkcsPadding($data, 8);
$cryptText = openssl_encrypt($data, 'DES-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, '');
return base64_encode($cryptText);

// Driver::sendRequest()
$dataString = http_build_query($data, '', '/\\\\/');  // 分隔符 \/
$param      = $desService->encrypt($dataString);       // DES-ECB + Base64
$key        = md5($param . $this->md5Key);             // 32 位小寫 hex
$fullUrl    = $url . '?params=' . $param . '&key=' . $key;
```

---

## XML 回應狀態碼（通用）

| `info` | 含義 |
|---|---|
| `0` / `000` | 成功（`GetBalance` 成功時 `info` 直接帶餘額，如 `100.01`） |
| `1` | 失敗，訂單未處理狀態 → 等 5 秒後重試或查單 |
| `2` | 因無效的轉帳金額導致的失敗 |
| `key_error` | `MD5_Encrypt_key` 錯、或 MD5 大寫、或 params 不一致 |
| `network_error` | 網路問題 → 建議每 5 秒重試一次，最多 10 次，仍失敗聯絡 AG 客服 |
| `duplicate_transfer` | 重複轉帳（同 `billno` 已存在） |
| `not_enough_credit` | 餘額不足（預備轉帳） |
| `account_add_fail` | 建號失敗（密碼不正確或帳號已存在） |
| `error` | 其他錯誤，參考 `msg` |

---

## merchant API

### 1. CheckOrCreateGameAccount（`method=lg`）

於 AG 建立遊戲帳號；若帳號已存在則僅驗證帳密。

| 項目 | 值 |
|---|---|
| Endpoint | `/doBusiness.do` |
| method | `lg` |

**明文參數（DES 前）**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼常數 |
| `loginname` | ✅ | 遊戲帳號，< 20 字元，僅數字 / 字母 / 底線 |
| `method` | ✅ | `lg` |
| `actype` | ✅ | `1`=真錢、`0`=試玩（試玩預設 2000 CNY 不可轉帳，建立後不可改） |
| `password` | ✅ | < 20 字元，禁特殊字元 `' " ₩ / , > < & # -- % , ? $ 空格 全形 TAB NULL 換行` |
| `oddtype` | ❌ | 盤口（見附錄 B；用預設盤口可省略） |
| `cur` | ✅ | 幣別（見附錄 C；建立後不可改） |

**XML 回應 `info`**：`0` 成功 / `key_error` / `network_error` / `account_add_fail` / `error`（看 `msg`）。

> 本專案 Driver.php 未見 `method=lg` 呼叫，建號落點待確認（見 integration.md）。

---

### 2. GetBalance（`method=gb`）

讀取遊戲帳號可用餘額，支援 2 位小數。

| 項目 | 值 |
|---|---|
| Endpoint | `/doBusiness.do` |
| method | `gb` |

**明文參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼 |
| `loginname` | ✅ | 遊戲帳號 |
| `method` | ✅ | `gb` |
| `actype` | ✅ | `1` 真錢 / `0` 試玩 |
| `password` | ✅ | 同建號密碼規則 |
| `cur` | ✅ | 幣別 |

**XML 回應**：成功時 `info` 屬性本身即餘額字串（如 `100.01`）；`key_error` / `network_error` / `error`。

---

### 3. PrepareTransferCredit（`method=tc`）

兩階段轉帳第一階段：**僅驗證參數**，不真正更新額度。

| 項目 | 值 |
|---|---|
| Endpoint | `/doBusiness.do` |
| method | `tc` |

**明文參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼 |
| `method` | ✅ | `tc` |
| `loginname` | ✅ | 遊戲帳號 |
| `billno` | ✅ | `cagent + 序列`（序列唯一 13~16 位數字）|
| `type` | ✅ | `IN`（網站→遊戲）/ `OUT`（遊戲→網站）|
| `credit` | ✅ | 額度，小數 2 位（AGIN 支援 `100.01`；BBIN 只能整數） |
| `actype` | ✅ | `1` 真錢 / `0` 試玩（試玩不可轉帳） |
| `password` | ✅ | 同建號密碼規則 |
| `cur` | ✅ | 幣別 |
| `fixcredit` | △ | 不可用額度；**僅 AGTEX 平台需要** |
| `gameCategory` | △ | `1`=對戰 / `0`=對賭；**僅 AGTEX 平台需要** |

**XML 回應 `info`**：`0` 成功 / `key_error` / `duplicate_transfer` / `network_error`（每 5 秒重試，最多 10 次）/ `not_enough_credit` / `error`。

---

### 4. TransferCreditConfirm（`method=tcc`）

兩階段轉帳第二階段：AG 真正更新客戶額度、完成轉帳。

| 項目 | 值 |
|---|---|
| Endpoint | `/doBusiness.do` |
| method | `tcc` |

**明文參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼 |
| `loginname` | ✅ | 遊戲帳號 |
| `method` | ✅ | `tcc` |
| `billno` | ✅ | **同 `tc` 的 billno** |
| `type` | ✅ | 同 `tc`（`IN`/`OUT`）|
| `credit` | ✅ | 同 `tc`；**若 tc/tcc credit 不一致，以 tcc 為準** |
| `actype` | ✅ | 同 `tc` |
| `flag` | ✅ | `1`=tc 成功；`0`=tc 出錯 |
| `password` | ✅ | 同建號密碼規則 |
| `cur` | ✅ | 幣別 |
| `fixcredit` / `gameCategory` | △ | 僅 AGTEX 平台需要 |

**XML 回應 `info`**：`0` 成功 / `1` 失敗（訂單未處理，等 5 秒後再呼叫 tcc）/ `2` 無效金額 / `key_error` / `duplicate_transfer` / `network_error` / `error`。

> timeout ≥ 30 秒（官方 §3.4）。

---

### 5. QueryOrderStatus（`method=qos`）

查詢某筆轉帳訂單的當前狀態。**AGIN 平台每次轉帳都必須可調用**。

> 適用平台：AGIN / MG（與 AGIN 共用錢包）。
> 不適用：沙巴 / IPM / OG / HG / BBIN 平台。

| 項目 | 值 |
|---|---|
| Endpoint | `/doBusiness.do` |
| method | `qos` |

**明文參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼 |
| `billno` | ✅ | 待查的轉帳單號 |
| `method` | ✅ | `qos` |
| `actype` | ✅ | 帳戶類型（`1` 真錢 / `0` 試玩） |
| `cur` | ✅ | 幣別 |

**XML 回應 `info`**：`0` 成功 / `1` 失敗（未處理）/ `2` 無效金額 / `key_error` / `network_error` / `error`。

> 本專案 Driver 未實作 `qos`，`AbstractTransferWallet::checkTransferStatus()` 預設拋例外（見 integration.md）。

---

### 6. forwardGame（進入遊戲頁面）

不走 XML 流程；直接組 URL 由前端跳轉。

| 項目 | 值 |
|---|---|
| Endpoint | `/forwardGame.do`（**gci 域名**）|
| 回應 | 無 XML，成功即進入遊戲頁面 |
| User-Agent | **不需要**此標頭（`doBusiness.do` 才需要）|

**明文參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `cagent` | ✅ | 代理編碼 |
| `loginname` | ✅ | 遊戲帳號 |
| `password` | ✅ | 遊戲帳號密碼 |
| `actype` | ✅ | `1` 真錢 / `0` 試玩 |
| `dm` | ✅ | 返回的網站域名（**不支援 IP、不支援 `/`**） |
| `sid` | ✅ | `cagent + 序列`，13~16 位數字 |
| `lang` | ✅ | 語言代碼（見附錄 D）|
| `gameType` | ✅ | 遊戲廳 / 桌台編號（見附錄 A；AGIN 為空則進整合頁面）|
| `oddtype` | ❌ | 盤口（見附錄 B）|
| `cur` | ✅ | 幣別 |
| `mh5` | ❌ | `mh5=y` 代表 AGIN 移動網頁版；不帶則進 PC FLASH |
| `session_token` | ❌ | iframe 接入時**必須**帶（驗證合法性）；非 iframe 可省略 |

URL 組裝與 `doBusiness.do` 相同（DES+MD5），但不帶 User-Agent。

---

## 附錄 A — gametype 參數

> 來源 `20220606_AG_gi_gametype_cn.pdf` §1。

### AGIN 平台（節錄）

| 值 | 遊戲類型 |
|---|---|
| `0` | 大廳 |
| `1` | 旗艦廳（AGQ 廳） |
| `2` | 國際廳（AGIN 廳） |
| `3` | 多台（自選多台） |
| `4` | 包桌（VIP 包桌） |
| `5` | 競咪廳 |
| `8` | 電子遊戲（XIN 電子遊戲大廳） |
| `9` | AGIN 活動專廳 |
| `11` | HTML5 大廳（AGIN 移動網頁版大廳） |
| `12` / `13` | 旗艦廳 / 國際廳 HTML5 百家樂 |
| `15` | 國際廳 HTML5 龍虎 |
| `17` | AGIN 比賽廳 |
| `18` | HTML5 視訊遊戲大廳 |
| `21` / `22` | 旗艦廳 / 國際廳 百家樂 |
| `23` / `24` | 旗艦廳 / 國際廳 龍虎 |
| `25` / `26` | 旗艦廳 / 國際廳 輪盤 |
| `27` | 旗艦廳 骰寶 |
| `30` / `31` | 包桌百家樂 / LED 包桌百家樂 |
| `32` | 牛牛 |
| `33` | 21 點 |
| `34` | 直播廳 |
| `36` | 炸金花 |
| `37` | 斗牛 |
| `38` / `39` / `40` | 歐洲廳 / 新世界廳 / 歐洲廳百家樂 |
| `44` | 三公 |

### 指定桌台（節錄）

| 前綴 | 廳別 | 範例 |
|---|---|---|
| `C0xx` | 國際廳桌台 | `C001` 國際廳百家樂 C1 台 |
| `B0xx` | 旗艦廳桌台 | `B001` 旗艦廳百家樂 B1 台 |
| `V0xx` | 競咪百家樂 | `V012` 競咪百家樂 L12 台 |
| `D0xx` | 歐洲廳桌台 | `D001` 歐洲廳輪盤 D1 台 |
| `M0xx` | 歐洲廳百家樂 | `M053` |
| `E1xx`~`E7xx` | 區塊鏈桌台 | `E101` 區塊鏈百家樂 E101 台 |
| `N0xx` | 卡卡灣廳 | `N006` 卡卡灣廳百家樂 N6 台 |
| `P0xx` | 聯盟廳 | `P018` 聯盟廳百家樂 P18 台 |
| `T0xx` | 包桌 | `T023` |
| `L0xx` | 競咪包桌 | `L010` |

### 電子 / 老虎機（節錄）

| 值 | 遊戲 |
|---|---|
| `501(101)` | 水果拉霸 |
| `502(102)` | 新視頻撲克 |
| `504` | 運財羊 |
| `WA01(WHGWA01)` | 鑽石女王 |

### 捕魚 / YOPLAY

| 值 | 遊戲 |
|---|---|
| `HMPL` / `HMFP` / `HM2D` / `HM3D` / `HMSH` | AG 捕魚廳 / 捕魚樂園 / 捕魚王 2D / 捕魚王 3D / 天際獵人 |
| `YP800`~`YP850` | YOPLAY 平台各遊戲 |

### 其他平台

| 平台 | gametype |
|---|---|
| BBIN | `1` 真人娛樂 / `5` 電子遊藝 |
| SBTA（沙巴體育） | `TASSPTA` |

> 完整桌台清單見原始 PDF 第 2~8 頁。

---

## 附錄 B — oddtype 參數

> 預設值 `A`。下方範圍為人民幣（CNY）；其他幣別下注範圍請參考 AG 國際廳後台「系統公共盤口」。

| 值 | 下注範圍（CNY） |
|---|---|
| `A`（預設） | 20 ~ 50000 |
| `B` | 50 ~ 5000 |
| `C` | 20 ~ 10000 |
| `D` | 200 ~ 20000 |
| `E` | 300 ~ 30000 |
| `F` | 400 ~ 40000 |
| `G` | 500 ~ 50000 |
| `H` | 1000 ~ 100000 |
| `I` | 2000 ~ 200000 |

---

## 附錄 C — currency 參數

| 幣種 | `cur` 值 | 幣值轉換（轉帳） |
|---|---|---|
| 人民幣 | `CNY` | 1:1 |
| 韓元 | `KRW` | 1:1 |
| 馬來西亞幣 | `MYR` | 1:1 |
| 美元 | `USD` | 1:1 |
| 日元 | `JPY` | 1:1 |
| 泰銖 | `THB` | 1:1 |
| 比特幣 | `BTC` | 1:1 |
| 印尼盾 | `IDR` | **1:1000**（轉入 AG 須 ÷1000，轉出須 ×1000） |
| 越南盾 | `VND` | **1:1000**（轉入 AG 須 ÷1000，轉出須 ×1000） |
| 歐元 | `EUR` | 1:1 |
| 印度盧比 | `INR` | 1:1 |
| 巴西雷亞爾 | `BRL` | 1:1 |
| 英鎊 | `GBP` | 1:1 |
| 澳元 | `AUD` | 1:1 |
| 緬元 | `MMK` | **1:100**（轉入 AG 須 ÷100，轉出須 ×100） |
| 菲律賓比索 | `PHP` | 1:1 |
| 加拿大元 | `CAD` | 1:1 |

---

## 附錄 D — language 參數

| 語言 | `lang` 值 | 本專案 `mapLanguageCode` |
|---|---|---|
| zh-cn（簡體中文） | `1` | `LanguageEnum::ZH_CN` |
| zh-tw（繁體中文） | `2` | `LanguageEnum::ZH_TW` |
| en-us（英語） | `3` | `LanguageEnum::EN`（亦為 default） |
| euc-jp（日語） | `4` | — |
| ko（韓語） | `5` | — |
| th（泰文） | `6` | `LanguageEnum::TH_TH` |
| vi（越南文） | `8` | `LanguageEnum::VI_VN` |
| khm（柬埔寨語） | `9` | — |
| id（印尼語） | `11` | `LanguageEnum::ID_ID` |
| prt（葡萄牙語） | `23` | — |
