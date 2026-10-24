# Dct（DCACE）— API 規格

> 原始來源：「DCT 转帐钱包串接文档 V1.24.pdf」（DC ACE，文件版本 V1.24，2024-10-30 發布）
> 參考檔：「DCT Transfer wallet API document V1.06.pdf」（英文版，版號較舊，僅作對照）
> 抓取日期：2026-06-03
> 本專案 Vendor：`VendorIdEnum::DCT`（vid **69**），錢包型態 **Transfer Wallet（轉帳錢包）**

> 我方實作分析見 `integration.md`（陷阱、轉帳三態、落地建議、Gaps）。

DCT 由 **DC ACE（DCACE）** 提供，是一個**遊戲聚合平台**：單一 DCT 接口背後串接數十家 provider（Yggdrasil、Hacksaw、Play'n Go、Nolimit City、Evolution、SA Gaming、Smartsoft 等，見[游戏商代码（provider）](#游戏商代码provider)）。玩家餘額由 DC 端保管，採轉帳錢包流程。

---

## 目錄

- [通用約定](#通用約定)
- [2.1 登入遊戲 Login Game](#21-登入遊戲-login-game)
- [2.2 試玩遊戲 Try Game](#22-試玩遊戲-try-game)
- [2.3 獲取餘額 Get Balance](#23-獲取餘額-get-balance)
- [2.4 錢包存款 Credit（轉入 DC）](#24-錢包存款-credit轉入-dc)
- [2.5 錢包取款 Withdraw（轉出 DC）](#25-錢包取款-withdraw轉出-dc)
- [2.6 獲取玩家投注數據 Get Bet Data](#26-獲取玩家投注數據-get-bet-data)
- [2.7 強制玩家登出 Kick Player](#27-強制玩家登出-kick-player)
- [2.8 查詢玩家最後投注記錄 Get Player Last Wager](#28-查詢玩家最後投注記錄-get-player-last-wager)
- [2.9 查詢玩家轉帳記錄 Check Transfer Status](#29-查詢玩家轉帳記錄-check-transfer-status)
- [2.10 取得遊戲列表 Get Game List](#210-取得遊戲列表-get-game-list)
- [2.11 玩家下注記錄歷史回顧 Get Replay](#211-玩家下注記錄歷史回顧-get-replay)
- [2.12–2.14 FreeSpin 系列](#212214-freespin-系列)
- [簽名](#簽名)
- [響應代碼清單](#響應代碼清單)
- [wager_type 清單](#wager_type-清單)
- [附錄（國家/語系/貨幣/provider）](#附錄)

---

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | `POST` |
| Content-Type | `application/json` |
| 驗證方式 | IP 白名單（我方須提供出口 IP 給 DC）+ MD5 簽名 |
| 成功代碼 | `code = 1000` |

### 端點 Base URL

| 變數 | 用途 |
|---|---|
| `api_url` | 主要 API base（2.1~2.5, 2.7, 2.9~2.14） |
| `getBetData_url` | 投注數據 / 最後投注記錄專用 base（**2.6、2.8**） |

> Stage 環境（測試用）：`api_url=https://example.invalid`；`getBetData_url=https://example.invalid`；`brand_id=T027006`。正式值由 DC 客服提供。

### 共通請求屬性

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | 商戶品牌 ID，由 DC 提供 |
| `sign` | ✅ | String(32) | MD5 簽名，全大寫；見[簽名](#簽名)節 |

### 共通回應屬性

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `code` | ✅ | Int | 回應代碼，見[響應代碼清單](#響應代碼清單) |
| `msg` | ✅ | String(50) | 訊息 |
| `data` | 否 | Object/Array | 成功時資料 |

---

## 2.1 登入遊戲 Login Game

允許玩家登入遊戲，回傳啟動 URL。

- **Endpoint**：`{api_url}/dct/loginGame`
- **sign 拼接**：`brand_id + brand_uid + api_key`

### 請求參數

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | 商戶品牌 ID |
| `sign` | ✅ | String(32) | 簽名 |
| `brand_uid` | ✅ | String(20) | 玩家唯一識別 User ID（英數，**不分大小寫**） |
| `game_id` | ✅ | Int | 遊戲 ID |
| `currency` | ✅ | String(4) | 玩家幣別 |
| `language` | ✅ | String(7) | 語系 |
| `channel` | ✅ | String(6) | 渠道：`pc` 或 `mobile` |
| `country_code` | ✅ | String(2) | 國別（ISO 3166-1 Alpha-2） |
| `return_url` | 否 | String(50) | 主頁按鈕與重新導向 URL（部分遊戲支援） |
| `full_screen` | 否 | Boolean | 全畫面（部分遊戲支援） |

### 回應（`code=1000`）

```json
{ "code": 1000, "data": { "game_url": "http://xxxxxxxxxxx" }, "msg": "Success." }
```

---

## 2.2 試玩遊戲 Try Game

免費（不使用真錢）登入試玩。

- **Endpoint**：`{api_url}/dct/tryGame`
- **sign 拼接**：`brand_id + game_id + api_key`

### 請求參數

`brand_id`、`sign`、`game_id`(Int)、`currency`、`language`、`channel`、`return_url`(否)、`full_screen`(否)

### 回應

同 2.1，回傳 `data.game_url`。

> 若商戶不支援試玩會回 `5021 No support try game`。

---

## 2.3 獲取餘額 Get Balance

- **Endpoint**：`{api_url}/dct/getBalance`
- **sign 拼接**：`brand_id + brand_uid + api_key`

### 請求參數

`brand_id`、`sign`、`brand_uid`、`currency`

### 回應

```json
{ "code": 1000, "data": { "balance": 20.55 }, "msg": "Success." }
```

> Driver 以 `Common::numFloor($balance, 4)` 截到 4 位小數（DC 文件 balance 為 `Decimal(16,6)`，微小尾差可接受）。

---

## 2.4 錢包存款 Credit（轉入 DC）

玩家本地餘額轉入 DC 遊戲錢包。

- **Endpoint**：`{api_url}/dct/credit`
- **sign 拼接**：`brand_id + brand_uid + api_key`

### 請求參數

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | |
| `sign` | ✅ | String(32) | |
| `brand_uid` | ✅ | String(20) | 玩家 User ID |
| `amount` | ✅ | Decimal(16,6) | 充值金額 |
| `bill_no` | ✅ | String(32) | 商戶唯一交易 ID（**只能英文或數字**） |
| `currency` | ✅ | String(4) | |
| `country_code` | ✅ | String(2) | |

### 回應

```json
{ "code": 1000, "data": { "balance": 20.55 }, "msg": "Success." }
```

> ⚠️ 若出現 timeout 或 `code=1001/1002`，**建議 300 秒後**呼叫 2.9 checkTransferStatus 確認交易結果（見 `integration.md`〈轉帳流程〉）。

---

## 2.5 錢包取款 Withdraw（轉出 DC）

DC 遊戲錢包餘額轉回本地。

- **Endpoint**：`{api_url}/dct/withdraw`
- **sign 拼接**：`brand_id + brand_uid + api_key`

請求參數與 2.4 相同（`bill_no` 為取款交易 ID，`amount` 為取款金額）。

回應同 2.4。

> ⚠️ timeout 或 `code=1001/1002` 時同 2.4，300 秒後走 checkTransferStatus。

---

## 2.6 獲取玩家投注數據 Get Bet Data

- **Endpoint**：`{getBetData_url}/dct/getBetData`（注意：使用 **`getBetData_url`** 而非 `api_url`）
- **sign 拼接**：`brand_id + start_time + end_time + api_key`

### 請求限制

| 限制 | 值 |
|---|---|
| 時間區間 | `start_time`~`end_time` 間隔 ≤ **24 小時** |
| 資料範圍 | 僅提供 **6 個月內**資料 |
| 頻率限制 | 每 **3 秒**只能請求一次（違反回 `5040`） |
| 分頁 | 每頁最多 **5000** 筆；總頁數 = `page.total_count / 5000` 無條件進位；第一次 `page=1` |

### 請求參數

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | |
| `sign` | ✅ | String(32) | |
| `page` | ✅ | Int | 查詢分頁數 |
| `start_time` | ✅ | String(19) | UTC，`yyyy-MM-dd HH:mm:ss` |
| `end_time` | ✅ | String(19) | UTC，`yyyy-MM-dd HH:mm:ss` |
| `currency` | ✅ | String(4) | |
| `provider` | ✅ | String(20) | 見[游戏商代码（provider）](#游戏商代码provider) |
| `brand_uid` | 否 | String(20) | 指定玩家 User ID |

### 回應 data[] 欄位

`brand_uid`、`currency`、`wager_type`（見[wager_type 清單](#wager_type-清單)）、`amount`、`before_amount`、`after_amount`、`game_id`、`game_name`、`round_id`、`wager_id`、`jackpot_contribution`、`jackpot_win`、`description`、`create_time`、`game_result`、`tip`、`is_endround`(Boolean)

另含分頁資訊：`page.current_page`、`page.total_count`

> ⚠️ **Driver 目前未實作此 endpoint**（對帳功能缺失）。
> Provider 為 SA Gaming 且遊戲為 Pok Deng 時，玩家 1~5 投注金額扣除 2 倍，BetRecord 計算須特殊處理。

---

## 2.7 強制玩家登出 Kick Player

- **Endpoint**：`{api_url}/dct/kickPlayer`
- **sign 拼接**：`brand_id + brand_uid + api_key`

請求參數：`brand_id`、`sign`、`brand_uid`、`currency`

回應：`{ "code": 1000, "msg": "Success." }`

---

## 2.8 查詢玩家最後投注記錄 Get Player Last Wager

- **Endpoint**：`{getBetData_url}/dct/getPlayerLastWager`（注意：使用 **`getBetData_url`**）
- **sign 拼接**：`brand_id + brand_uid + api_key`

請求參數：`brand_id`、`sign`、`brand_uid`、`currency`、`provider`

回應 `data` 為單筆，欄位同 2.6（含 `tip`，不含 `is_endround`）。

---

## 2.9 查詢玩家轉帳記錄 Check Transfer Status

用於 credit/withdraw timeout 後確認交易結果。

- **Endpoint**：`{api_url}/dct/checkTransferStatus`
- **sign 拼接**：`brand_id + bill_no + api_key`

### 請求參數

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | |
| `sign` | ✅ | String(32) | |
| `brand_uid` | ✅ | String(20) | |
| `currency` | ✅ | String(4) | |
| `bill_no` | ✅ | String(32) | 存/取款交易 ID |

### 回應（`code=1000`，交易確認存在）

```json
{
  "code": 1000,
  "msg": "Success.",
  "data": {
    "bill_no": "0A5FB051217E48C89E1EA404834AD227",
    "brand_uid": "DemoUser",
    "currency": "CNY",
    "amount": 1000.25,
    "transfer_type": "in",
    "transfer_time": "2019-05-28 09:26:18"
  }
}
```

| 欄位 | 說明 |
|---|---|
| `transfer_type` | `in`（存款）/ `out`（取款） |

> `bill_no` 不存在時回 `5007 Bill no not exist` / `5042 Bet record does not exist`（= 交易未完成）。

---

## 2.10 取得遊戲列表 Get Game List

- **Endpoint**：`{api_url}/dct/getGameList`
- **sign 拼接**：`brand_id + api_key`

請求參數：`brand_id`、`sign`、`provider`(否)

回應 `data[]` 每筆：`provider`、`game_id`、`game_name`、`game_name_cn`、`release_date`、`rtp`、`game_icon`、`content_type`、`game_type`、`content`

> `game_type` 為自由文字（如 `Slot`、`Video Slot`、`InstantWin`、`Scratchcard`），無固定枚舉。本平台落地見 `integration.md`「遊戲清單同步」。

---

## 2.11 玩家下注記錄歷史回顧 Get Replay

- **Endpoint**：`{api_url}/dct/getReplay`
- **sign 拼接**：`brand_id + round_id + api_key`
- 頻率限制：各品牌每 3 秒一次

請求參數：`brand_id`、`sign`、`brand_uid`、`currency`、`provider`、`round_id`(String(64))

回應：

```json
{ "code": 1000, "msg": "Success.",
  "data": { "record": "http://{xxxxxxxxxxx}/replay/{roundid}", "record_type": "URL" } }
```

`data.record_type`：`Text`（純文字）/ `URL`（需連結查看）/ `Html`（HTML 格式）

> 不支援時回 `5023 No support get replay`。

---

## 2.12–2.14 FreeSpin 系列

| 功能 | Endpoint | sign 拼接 |
|---|---|---|
| 2.12 FreeSpin 活動設定（createFreeSpin） | `{api_url}/dct/createFreeSpin` | `brand_id + end_time + api_key` |
| 2.13 FreeSpin 活動玩家設定（addFreeSpin） | `{api_url}/dct/addFreeSpin` | `brand_id + freespin_id + api_key` |
| 2.14 FreeSpin 活動設定查詢（queryFreeSpin） | `{api_url}/dct/queryFreeSpin` | `brand_id + game_id + api_key` |

### 2.12 createFreeSpin 請求

`brand_id`、`sign`、`game_id`、`currency`、`end_time`(UTC)、`description`(否)

回應：`data.freespin_id`、`data.usable_amounts`（僅 provider ∈ [yg, hs, png, nlc, evo] 回傳）

### 2.13 addFreeSpin 請求

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `brand_id` | ✅ | String(7) | |
| `sign` | ✅ | String(32) | |
| `freespin_id` | ✅ | Long | createFreeSpin 回傳的 ID |
| `round_count` | ✅ | Int | 旋轉次數 |
| `amount` | ✅ | Decimal(16,6) | provider 為 yg 時須為 `usable_amounts` 之一 |
| `brand_uids` | ✅ | List\<String(20)\> | 玩家 ID 清單 |

### 2.14 queryFreeSpin 請求

`brand_id`、`sign`、`game_id`、`currency`

回應含 `status`：`0`=建立 / `1`=已添加玩家 / `2`=活動已生效

> FreeSpin 相關錯誤碼：`5070`~`5075`、`5074`(Provider 不提供 FreeSpin)。
> **Driver 目前未實作 FreeSpin 系列**。

---

## 簽名

演算法：

```
sign = strtoupper( MD5( <拼接字串> + api_key ) )
```

- 結果固定 String(32)，**全大寫**
- 拼接時直接串接（無分隔符，`+` 僅為串接運算子，非字面字元）
- `api_key` 僅用於簽名，不出現在請求 body

### 各 Endpoint 拼接組成

| Endpoint | sign 拼接組成 |
|---|---|
| `/dct/loginGame`（2.1） | `brand_id + brand_uid + api_key` |
| `/dct/tryGame`（2.2） | `brand_id + game_id + api_key` |
| `/dct/getBalance`（2.3） | `brand_id + brand_uid + api_key` |
| `/dct/credit`（2.4） | `brand_id + brand_uid + api_key` |
| `/dct/withdraw`（2.5） | `brand_id + brand_uid + api_key` |
| `/dct/getBetData`（2.6） | `brand_id + start_time + end_time + api_key` |
| `/dct/kickPlayer`（2.7） | `brand_id + brand_uid + api_key` |
| `/dct/getPlayerLastWager`（2.8） | `brand_id + brand_uid + api_key` |
| `/dct/checkTransferStatus`（2.9） | `brand_id + bill_no + api_key` |
| `/dct/getGameList`（2.10） | `brand_id + api_key` |
| `/dct/getReplay`（2.11） | `brand_id + round_id + api_key` |
| `/dct/createFreeSpin`（2.12） | `brand_id + end_time + api_key` |
| `/dct/addFreeSpin`（2.13） | `brand_id + freespin_id + api_key` |
| `/dct/queryFreeSpin`（2.14） | `brand_id + game_id + api_key` |

### Driver 實作（app/Vendors/Dct/Driver.php）

```php
private function sign(string $username): string
{
    $str = $this->infoDetail->BRAND_ID . $username . $this->infoDetail->API_KEY;
    return strtoupper(md5($str));
}
```

| Driver 方法 | endpoint | 傳入 sign() 的值 | 文件拼接 | 是否一致 |
|---|---|---|---|---|
| `getGameUrl` | `/dct/loginGame` | `vendorAccount->username`（= brand_uid） | `brand_id + brand_uid + api_key` | ✅ |
| `getBalance` | `/dct/getBalance` | `vendorAccount->username` | `brand_id + brand_uid + api_key` | ✅ |
| `transferToVendor` | `/dct/credit` | `vendorAccount->username` | `brand_id + brand_uid + api_key` | ✅ |
| `transferToLocal` | `/dct/withdraw` | `vendorAccount->username` | `brand_id + brand_uid + api_key` | ✅ |
| `checkTransferStatus` | `/dct/checkTransferStatus` | `$transactionNo`（= bill_no） | `brand_id + bill_no + api_key` | ✅（重用 sign() 但傳 bill_no） |

> 其餘 endpoint（tryGame / getBetData / FreeSpin 系列等）若日後補實作，各有不同拼接組成，**不可沿用現有 `sign($username)`**。

---

## 響應代碼清單

| Code | Remark | 說明 |
|---|---|---|
| 1000 | Success. | 成功 |
| 1001 | System error. | 系統錯誤（結果未定） |
| 1002 | Unknown. | 未知錯誤（結果未定） |
| 5000 | Sign error. | 驗證碼錯誤 |
| 5001 | Request param error. | 請求參數有誤 |
| 5002 | Currency not support. | 幣別不支援 |
| 5003 | Balance insufficient. | 錢包餘額不足 |
| 5004 | Amount can't less than 0. | 金額不得小於 0 |
| 5005 | Brand not exist. | 商戶不存在 |
| 5006 | Bill no already exist. | 票據記錄已存在 |
| 5007 | Bill no not exist. | 票據記錄不存在 |
| 5008 | Country code error. | 國家/地區代碼錯誤 |
| 5009 | Player not exist. | 玩家帳號不存在 |
| 5010 | Player blocked. | 玩家已被鎖定 |
| 5011 | Player no wager data. | 玩家查無下注資料 |
| 5012 | Game id not exist. | 找不到對應的遊戲 |
| 5014 | Incorrect time format. | 無效的時間格式 |
| 5015 | Incorrect provider. | 無效的 provider |
| 5016 | Incorrect amount. | 無效的金額 |
| 5017 | Api insufficient permission. | API 權限不足 |
| 5018 | Incorrect brand uid. | 無效的 brand uid |
| 5019 | Provider is maintaining | 供應商維護中 |
| 5020 | Brand locked | 商戶已被鎖定 |
| 5021 | No support try game | 不支援試玩遊戲 |
| 5023 | No support get replay | 不支援下注記錄歷史回顧 |
| 5024 | Token cannot be used | 此 Token 不能使用 |
| 5025 | Unsupported method | 不支援的方法 |
| 5040 | Request rate limit, once request per 3 seconds. | 請求限制（每 3 秒一次） |
| 5041 | Request date range limit... | 區間 ≤ 24 小時、資料僅 6 個月內 |
| 5042 | Bet record does not exist. | 下注記錄不存在 |
| 5070 | Free spin ID not exist. | Free Spin ID 不存在 |
| 5071 | Incorrect round count. | 無效的旋轉次數 |
| 5072 | The free spin already cancelled. | 此 Free Spin 已被取消 |
| 5073 | The free spin already locked. | 此 Free Spin 已被鎖定 |
| 5074 | The provider does not support free spin. | 此 Provider 不提供 Free Spin |
| 5075 | The free spin set up error. | Free Spin 設定錯誤 |

---

## wager_type 清單

| Code | 說明 |
|---|---|
| `wager` | 下注 |
| `endWager` | 完成下注 |
| `cancelWager` | 因內部錯誤取消投注（下注金額退還玩家） |
| `appendWagerResult` | 出現在 Jackpot 獎勵中 |
| `campaign` | FreeSpin 下注記錄（僅後台呈現） |
| `campaignpayout` | 玩家透過 Prepaid Ticket（FreeSpin 預付）所產生的收益 |
| `cancelEndWager` | 因內部錯誤取消派彩（已派彩金額從玩家錢包扣除） |
| `promopayout` | 遊戲商直接發送推廣獎金，直接加給玩家 |

---

## 附錄

### 國家/地區代碼（country_code）

ISO 3166-1 Alpha-2 格式。文件範例：`CN`、`HK`、`TW`、`KR`、`JP`、`TH`、`PH`、`ID`、`IN`、`VN`、`MY`、`SG`。

### 語系代碼（language，摘錄）

`zh_hans`(簡中)、`zh_hant`(繁中)、`ko`/`kr`(韓)、`en`(英)、`th`(泰)、`vi`(越)、`id`(印尼)、`ja`(日) 等（完整見原始 PDF §3.3）。

Driver `mapLanguageCode()`：ZH_TW→`zh_hant`、ZH_CN→`zh_hans`、ID_ID→`id`、VI_VN→`vi`、TH_TH→`th`、其餘→`en`

### 貨幣代碼（currency）

參考 ISO 4217，各 provider 支援幣別不同。常用：`CNY`、`USD`、`THB`、`VND`、`IDR`、`MYR`、`PHP`、`TWD`、`HKD`、`KRW`、`JPY`、`SGD`、`INR`、`BRL`、`EUR`、`USDT`；另含 `IDX`(印尼盾/1000)、`VNX`(越南盾/1000)（完整見原始 PDF §3.2）。

### 游戏商代码（provider）

DCT 為聚合平台，部分 provider 代碼（完整見原始 PDF §3.6）：

`dcace`(DCACE)、`yg`(Yggdrasil)、`relax`(Relax Gaming)、`nlc`(Nolimit City)、`png`(Play'n Go)、`hs`/`hso`/`hsl`(Hacksaw)、`aux`(Avatar UX)、`evo`(Evoplay)、`gam`(Gamomat)、`ghg`(Golden Hero)、`psh`(Push Gaming)、`ezugi`(Ezugi)、`swf`(Win Fast)、`funta`(FunTa)、`stm`(Slotmill)、`mj`(7Mojos)、`fm`(Fantasma)、`ps`(Peter & Sons)、`sb`(Spribe)、`plb`(Parlay bay)、`evl`(Evolution)、`btg`(Big Time Gaming)、`ne`(NetEnt)、`rt`(Red Tiger)、`tg`/`tgo`(Turbo Games)、`wz`(Voltent/Wazdan)、`tk`(Thunderkick)、`lm`(Lucky Monaco)、`yt`(Yolted)、`sa`(SA Gaming)、`ss`(Smartsoft)、`bg`(Bgaming)、`nm`(Novomatic)、`op`(Octoplay)、`bp`(BluePrint)

---

## Change Log（文件版本，摘錄）

| 版本 | 日期 | 變更 |
|---|---|---|
| 1.00 | 2022-06-29 | DCT API 文件建立 |
| 1.11 | 2024-01-10 | 移除 uBTC/UBC 幣別；新增多家 provider（evl/btg/ne/rt/dcace/tg/wz）；getGameList 新增 content |
| 1.12 | 2024-04-22 | credit/withdraw 增加注意事項（timeout 復核） |
| 1.24 | 2024-10-30 | 新增 hso/hsl provider；新增 response code 5025；Wazdan 改稱 Voltent (Wazdan) |
