# GM（GMSW，vid 237–246）— 單一錢包 API 整合文件

> 原始來源：GM 平台 showdoc 線上 API 文檔（[English](https://example.invalid/item/password/2589626733520920) / [中文](https://example.invalid/item/password/2589613905901477)，密碼 `123456`）。抓取日期：2026-06-24。對應工單 [target/tickets#2220](http://example.invalid:1688/target/tickets/-/work_items/2220)。
> 本文整理 GM **單一錢包（運營商錢包模式 / Single Wallet）** 對接內容：GM 在玩家下注 / 派彩時回調通知運營商扣加款，**玩家餘額由運營商保管**。
> GMSW 在本平台按 game platform 拆成 **10 個 vid（237–246）**，共用同一 Driver；vid 對照見附錄 A。我方實作分析與落地對應見同目錄 `integration.md`。

---

## 目錄

1. [概述](#1-概述)
2. [簽名計算](#2-簽名計算)
3. [公共參數與請求約定](#3-公共參數與請求約定)
4. [雙向成功碼約定](#4-雙向成功碼約定)
5. [API 方法總表](#5-api-方法總表)
6. [loadlist 取遊戲列表](#6-loadlist-取遊戲列表)
7. [ingame 進入遊戲](#7-ingame-進入遊戲)
8. [verify_session 玩家身份驗證](#8-verify_session-玩家身份驗證)
9. [get_balance 取玩家餘額](#9-get_balance-取玩家餘額)
10. [change_balance 改變玩家餘額](#10-change_balance-改變玩家餘額)
11. [GetGameRecordList 玩家注單記錄](#11-getgamerecordlist-玩家注單記錄)
12. [錯誤碼](#12-錯誤碼)
13. [重要注意事項](#13-重要注意事項)
14. [附錄 A：我方 vid 對照](#附錄-a我方-vid-對照)
15. [附錄 B：platform / gametype 與遊戲清單](#附錄-bplatform--gametype-與遊戲清單)

---

## 1. 概述

GM 平台聚合 slot / 百人場 / 捕魚 / Mini Game 等多 game platform（PG、JILI、PP、Spribe、Hacksaw、TADA、JDB…）。本平台以單一錢包模式接入。

- 請求格式：`application/json;charset=UTF-8` POST
- 回應格式：JSON（`application/json;charset=UTF-8`）
- HTTP code `200` 視為鏈路正常、可解析回應；其餘 HTTP error 為鏈路異常。

### 接入資訊

- 審核開戶後，於商戶後台取得 `app_id`（商戶號）與 `key`（簽名密鑰）。
- `{APIURL}`：GM 平台接口域名（測試 / 生產），於商戶後台查看。
- `{BACKENDURL}`：商戶後台 API 配置界面提供的域名（注單記錄接口專用）。
- **時區**：GM 伺服器為 `UTC+0`，資料統計需自行換算時區。
- **Token 有效期**：GM 返回的 Token 有效期 7 天，逾期需重新取得。

---

## 2. 簽名計算

每個請求於 HTTP header 帶 `sign`，計算方式：

```
sign = md5( <url 上的 query 參數> + <body 的 JSON 原始字串> + key )   // 最終小寫輸出
```

1. `url 上的 query 參數`：query string 本體（如 `trace_id=dhf1aboc1iio`），不含 `?` 與域名。
2. `body 的 JSON 原始字串`：**請求中最原始的 body 字元**，不可用 JSON 套件 decode/encode 後重組（欄位順序、空白都會影響）。
3. 串接 `query + body + key` 後計算 MD5，小寫 32 字元。

### 範例

| 項目 | 值 |
|---|---|
| 請求 url | `{APIURL}/api/luck/Balance/GetBalance?trace_id=dhf1aboc1iio` |
| 請求方式 | POST |
| body JSON | `{"player_logon_token":"b27cfe9b-f01c-11ee-a0b5-000c2901d9cc","account_id":"1002402","timestamp":1711971655}` |
| 簽名密鑰 key | `39a6581c31ef3203a22edb2daa2ab6d1` |
| 待 md5 字串 | `trace_id=dhf1aboc1iio{"player_logon_token":"...","account_id":"1002402","timestamp":1711971655}39a6581c31ef3203a22edb2daa2ab6d1` |
| md5 結果 | `e3f8dc79e875e46f6755ef540c2d24f3` |

> **注意**：文檔「單一錢包說明」節的簽名範例 body 印成 `{"b27cfe9b-...`，漏了 `"player_logon_token":` 鍵名，係文檔筆誤；以上以完整版為準。

---

## 3. 公共參數與請求約定

GM 調用運營商所有單一錢包接口時，均含下列公共參數：

| 參數 | 位置 | 狀態 | 型別 | 說明 |
|---|---|---|---|---|
| `sign` | header | Required | String | 簽名（見第 2 節） |
| `trace_id` | url（query） | Required | String | 由發起方產生，每個請求唯一 |
| `app_id` | body | Required | String | 商戶號 |
| `timestamp` | body | Required | Integer | 時間戳（秒） |

### 統一回應結構

```json
{
    "code": 1,
    "msg": "ok",
    "data": {}
}
```

| 參數 | 型別 | 說明 |
|---|---|---|
| `code` | Integer | 狀態碼（成功碼依方向不同，見第 4 節） |
| `msg` | String | 提示 / 錯誤訊息 |
| `data` | Object | 回傳資料；錯誤時不需回 `data` |

### 多語言類型（`lang`）

`en` 英語、`zh` 中文、`es` 西班牙語、`vi` 越南語、`it` 意大利語、`da` 丹麥語、`fi` 芬蘭語、`fr` 法語、`my` 緬甸語、`nl` 荷蘭語、`nb` 挪威語、`pl` 波蘭語、`ro` 羅馬尼亞語、`pt` 葡萄牙語、`tr` 土耳其語、`uk` 烏克蘭語、`de` 德語、`th` 泰語、`id` 印尼語、`ja` 日語、`ko` 韓語、`ru` 俄語、`sv` 瑞典語。預設 `en`。

---

## 4. 雙向成功碼約定

GM 文檔的接口分兩個方向，**成功碼相反**，務必區分：

| 方向 | 接口 | 成功碼 | 對應節 |
|---|---|---|---|
| **GM 提供、運營商調用** | `loadlist`、`ingame` | `code === 0` | 6、7 |
| **運營商提供、GM 回調** | `verify_session`、`get_balance`、`change_balance` | `code === 1` | 8、9、10 |
| **GM 後台提供、運營商查詢**（自成體系） | `GetGameRecordList` | `code === 1` | 11 |

> 文檔原文：「GM 提供的接口 `code` 返回 `0` 表示成功，要求商戶實現的接口 `code` 返回 `1` 表示成功。」注單記錄接口（第 11 節）雖由 GM 後台提供，但成功碼為 `1`、且 Error Code 自成一套，與公共說明無關聯。

---

## 5. API 方法總表

| 方法 | 路徑 | 方向 | 成功碼 | 說明 |
|---|---|---|---|---|
| **loadlist** | `POST {APIURL}/api/game/loadlist` | 運營商 → GM | `0` | 取遊戲列表 |
| **ingame** | `POST {APIURL}/api/usr/ingame` | 運營商 → GM | `0` | 進入遊戲，取跳轉 url |
| **verify_session** | `POST {APIURL}/api/luck/user/verify_session` | GM → 運營商 | `1` | 玩家身份驗證 |
| **get_balance** | `POST {APIURL}/api/luck/balance/get_balance` | GM → 運營商 | `1` | 取玩家餘額 |
| **change_balance** | `POST {APIURL}/api/luck/balance/change_balance` | GM → 運營商 | `1` | 改變玩家餘額（核心扣加款） |
| **GetGameRecordList** | `POST {BACKENDURL}/api/v1/merchant/outer/record/GetGameRecordList` | 運營商 → GM 後台 | `1` | 玩家注單記錄查詢 |

---

## 6. loadlist 取遊戲列表

**`POST {APIURL}/api/game/loadlist`**

GM 提供、運營商調用。成功碼 `code === 0`。

### Request

```json
{
    "app_id": "13"
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `app_id` | String | Required | 商戶唯一標識 |

### Response

```json
{
    "code": 0,
    "msg": "success",
    "data": {
        "glist": [
            { "gameid": "9", "name": "Mines", "platform": "1", "gametype": 1, "status": 1 }
        ]
    }
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `data.glist` | Array | Required | 遊戲列表 |
| `gameid` | String | Required | 遊戲 id |
| `name` | String | Required | 遊戲名稱 |
| `platform` | String | Required | 遊戲所屬平台（見附錄 B） |
| `gametype` | Integer | Required | 遊戲類型（見附錄 B） |
| `status` | Integer | Required | 遊戲狀態：0 關閉、1 開啟 |
| `code` | Integer | Required | 狀態碼 |
| `msg` | String | Required | 提示 |

---

## 7. ingame 進入遊戲

**`POST {APIURL}/api/usr/ingame`**

GM 提供、運營商調用。成功碼 `code === 0`。

### Request

```json
{
    "gameid": "9",
    "token": "50f15a92-ee8d-11ee-9a61-00ff3fae08f7",
    "lang": "en",
    "nick": "6396885064",
    "app_id": "3",
    "cid": 1,
    "back_url": ""
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `gameid` | String | Required | 所進遊戲編碼（遊戲列表的 `gameid`） |
| `token` | String | Required | 用戶驗證 token，供單一錢包身份驗證接口使用 |
| `lang` | String | Optional | 遊戲語言，預設 `en` |
| `nick` | String | Required | 用戶暱稱，最長 40 bytes |
| `app_id` | String | Required | 商戶唯一標識 |
| `cid` | Integer | Optional | 貨幣 id；多貨幣商戶必傳，非多貨幣商戶傳 `0`。可於總代後台「API 接入 > 多貨幣」查看已開通貨幣 id |
| `back_url` | String | Optional | 玩家點擊退出的回調 url；**僅當 `loadlist` 回傳 `platform` 為 2、3、4、8 時有效** |

### Response

```json
{
    "code": 0,
    "msg": "success",
    "data": {
        "gameurl": "https://example.invalid"
    }
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `data.gameurl` | String | Required | 客戶端跳轉 url |
| `code` | Integer | Required | 狀態碼 |
| `msg` | String | Required | 提示 |

---

## 8. verify_session 玩家身份驗證

**`POST {APIURL}/api/luck/user/verify_session`**

運營商提供（域名自定）、GM 調用。成功碼 `code === 1`。
Header：`sign`（Required）；Url Params：`trace_id`（Required）。

### Request

```json
{
    "app_id": "13",
    "timestamp": 1711954264,
    "operator_player_session": "50f15a92-ee8d-11ee-9a61-00ff3fae08f7",
    "ip": "0.0.0.0",
    "custom_parameter": "",
    "game_id": 9
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `app_id` | String | Required | 商戶身份識別 |
| `timestamp` | Integer | Required | 時間戳 |
| `operator_player_session` | String | Required | 三方（GM）生成的玩家登入令牌（即 `ingame` 傳出的 `token`） |
| `ip` | String | Optional | 客戶端 ip |
| `custom_parameter` | String | Optional | 運營商額外參數 |
| `game_id` | Integer | Optional | 遊戲 id |

### Response

```json
{
    "data": {
        "uname": "1003803",
        "nickname": "9999999999",
        "balance": "42766.25"
    },
    "code": 1,
    "msg": "ok"
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `data.uname` | String | Required | 玩家 id，**必須唯一** |
| `data.nickname` | String | Required | 用戶暱稱 |
| `data.balance` | Decimal | Required | 用戶餘額（最多 4 位小數） |
| `code` | Integer | Required | 狀態碼 |
| `msg` | String | Required | 提示 |

錯誤回應（不需回 `data`）：

```json
{ "code": 10002, "msg": "sign invalid" }
```

---

## 9. get_balance 取玩家餘額

**`POST {APIURL}/api/luck/balance/get_balance`**

運營商提供（域名自定）、GM 調用。成功碼 `code === 1`。
Header：`sign`（Required）；Url Params：`trace_id`（Required）。

### Request

```json
{
    "app_id": "13",
    "uname": "1003803",
    "game_id": 9,
    "timestamp": 1711954264,
    "player_login_token": "c51597bb-969f-11ef-ad19-0242ac120002"
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `app_id` | String | Required | 商戶身份識別 |
| `game_id` | Integer | Optional | 遊戲 id |
| `uname` | String | Required | 玩家帳號 id |
| `timestamp` | Integer | Required | 時間戳 |
| `player_login_token` | String | Optional | 接入方上傳的用戶 token |

### Response

```json
{
    "data": { "balance": "4299.1" },
    "code": 1,
    "msg": "ok"
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `data.balance` | Decimal | Required | 餘額（最多 4 位小數）。平台方只記錄數值，貨幣單位以商戶選擇為準 |
| `code` | Integer | Required | 狀態碼 |
| `msg` | String | Required | 提示 |

錯誤回應（不需回 `data`）：

```json
{ "code": 10002, "msg": "sign invalid" }
```

---

## 10. change_balance 改變玩家餘額

**`POST {APIURL}/api/luck/balance/change_balance`**

運營商提供（域名自定）、GM 調用。成功碼 `code === 1`。
Header：`sign`（Required）；Url Params：`trace_id`（Required）。

> **只在餘額變動時調用**：下注扣錢一次、派獎一次、驗證結束一次。每次調用以 `type` 指明原因，**每個 `type` 對應一次獨立 HTTP 調用**。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `app_id` | String | Required | 商戶身份識別 |
| `uname` | String | Required | 玩家帳號 id |
| `money` | Decimal | Required | 餘額變動（正 / 負） |
| `game_id` | Integer | Required | 遊戲 id |
| `session_id` | String | Required | 每局遊戲唯一局號 |
| `order_id` | String | Required | 每次金額改變產生的唯一訂單號（最長 64 位） |
| `timestamp` | Integer | Required | 時間戳 |
| `bet` | Decimal | Required | 下注金額，**始終為正數**，僅玩家下注時才有值 |
| `type` | Integer | Required | 調用原因（見下表） |
| `end_round` | Bool | Required | 當前局是否結束 |
| `cancel_order_id` | String | Optional | 僅 `type=2` 時有值，表示取消的是哪一筆訂單 |
| `award_order_ids` | Array | Optional | 僅 `type=3` 時有值，本輪所有注單的派獎明細 |

### `type` 說明

| type | 說明 |
|---|---|
| 1 | 遊戲下注 |
| 2 | 取消下注 |
| 3 | 遊戲返獎 |
| 4 | 驗證對局結束 |
| 5 | LUCKWIN 寶箱下發獎勵 |
| 6 | 千倍遊戲（Future）持倉費用扣減 |

**流程組合舉例**（每個 type = 一次 HTTP 調用）：

1. 下注 → 返獎 → 對局結束：`1 → 3 → 4`（大部分遊戲）
2. 下注 → 取消下注：`1 → 2`（百人場、Mini Game；取消的 `session_id` 與下注一致）
3. 下注 → 未中獎 → 對局結束：`1 → 4`
4. 下注 → 返獎 → 返獎 →…→ 對局結束：`1 → 3 → 3 →…→ 4`（連消類 slot、特殊玩法、彩票類，多次中獎）
5. `type=5`：LUCKWIN 達流水條件可隨時領寶箱，與遊戲流程無關
6. `type=6`：Future 遊戲每隔一段時間扣已下注玩家的資金費率，與遊戲流程無關

### `award_order_ids` 物件欄位（`type=3`）

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `order_id` | String | Required | 注單 id |
| `money` | Decimal | Required | 派獎金額 |

### Response

```json
{
    "data": { "balance": "4289.1" },
    "code": 1,
    "msg": "ok"
}
```

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `data.balance` | Decimal | Required | 變動後餘額（最多 4 位小數） |
| `code` | Integer | Required | 狀態碼 |
| `msg` | String | Required | 提示 |

錯誤回應（不需回 `data`）：

```json
{ "code": 10001, "msg": "balance is not enough" }
```

### 各 type 請求範例

**① 遊戲下注（type=1）**

```json
{
    "app_id": "10013", "bet": "3", "game_id": 74, "money": "-3",
    "order_id": "20240716195311drxaoz1mxx6g", "session_id": "1813180074526625845",
    "timestamp": 1721130791, "uname": "1006417", "end_round": false,
    "type": 1, "cancel_order_id": "", "award_order_ids": null
}
```

**② 取消下注（type=2）**

```json
{
    "app_id": "13", "uname": "1033201", "money": "10000", "game_id": 100001,
    "session_id": "1777333924821798912", "order_id": "10920408215323di4cal9or8ji",
    "timestamp": 1712584403, "bet": "0", "type": 2, "end_round": false,
    "cancel_order_id": "20240408215323di4cal9or8jk", "award_order_ids": null
}
```

**③ 遊戲返獎（type=3）**

```json
{
    "app_id": "10013", "bet": "0", "game_id": 100001, "money": "33.66",
    "order_id": "20240715204114drtuiv8r7ny8", "session_id": "24713",
    "timestamp": 1721047274, "uname": "1006415", "end_round": false, "type": 3,
    "cancel_order_id": "",
    "award_order_ids": [
        { "order_id": "20240715204030drtugi3tau4g", "money": "5.1" },
        { "order_id": "20240715204053drtuhqp0jthc", "money": "10.2" },
        { "order_id": "20240715204104drtuibx5hlhc", "money": "18.36" }
    ]
}
```

**④ 驗證對局結束（type=4）**

```json
{
    "app_id": "10013", "bet": "0", "game_id": 100001, "money": "0",
    "order_id": "20240715204114drtuiv8r7ny9", "session_id": "24713",
    "timestamp": 1721047274, "uname": "1006415", "end_round": true, "type": 4,
    "cancel_order_id": "", "award_order_ids": null
}
```

**⑤ LUCKWIN 寶箱下發獎勵（type=5）**

```json
{
    "app_id": "10013", "bet": "0", "game_id": 100001, "money": "2",
    "order_id": "20240628171403dq4oali2j30g", "session_id": "14973",
    "timestamp": 1719566043, "uname": "1004612", "end_round": false, "type": 5,
    "cancel_order_id": "", "award_order_ids": null
}
```

---

## 11. GetGameRecordList 玩家注單記錄

**`POST {BACKENDURL}/api/v1/merchant/outer/record/GetGameRecordList`**

GM 後台提供、運營商調用，**自成體系**（與公共說明無關聯）。成功碼 `code === 1`，Error Code 獨立（見第 12 節）。時區 `UTC+0`。
Header：`sign`（Required）；Url Params：`trace_id`（Required）。簽名演算法同第 2 節。

### Request

```json
{"app_id": "10013","uname": "1004613","game_id": "10049","start_time": 0,"end_time": 0,"page": 1,"size": 2}
```

| 參數 | 位置 | 型別 | 狀態 | 說明 |
|---|---|---|---|---|
| `app_id` | body | String | Required | 商戶號 |
| `uname` | body | String | Optional | 用戶 id（對應遊戲驗證的 `uname`） |
| `game_id` | body | String | Optional | 遊戲 id |
| `page` | body | Int | Required | 當前頁 |
| `size` | body | Int | Required | 查詢條數 |
| `start_time` | body | Int | Optional | 查詢起始時間（時間戳） |
| `end_time` | body | Int | Optional | 查詢結束時間（時間戳） |

> **分頁特別說明**：基於效能，分頁時**不返回總頁數**，需自行循環至無資料為止；單次查詢條數**上限 5000**。

### Response

```json
{
    "code": 1,
    "msg": "成功",
    "data": [
        {
            "create_time": 1719481910, "round_id": 1806264170304233472,
            "account": "2024627172", "account_id": "1004613", "game_id": "10049",
            "win": 0, "enter_money": 50049.92, "after_settlement_money": 50039.92,
            "bet": 10, "id": 264556
        }
    ]
}
```

| 欄位 | 型別 | 長度 | 說明 |
|---|---|---|---|
| `account` | String | 45 | 玩家暱稱 |
| `account_id` | String | 45 | 玩家 id |
| `game_id` | String | 20 | 遊戲 id |
| `round_id` | Int | 35 | 牌局 id |
| `round_id_str` | String | 35 | 牌局 id（字串格式） |
| `enter_money` | Decimal | | 遊戲初始金額 |
| `after_settlement_money` | Decimal | | 結算後玩家身上的錢 |
| `bet` | Decimal | | 下注金額 |
| `win` | Decimal | | 派獎金額 |
| `create_time` | Int | | 時間戳 |
| `id` | Int | 40 | 遊戲記錄 id |
| `id_str` | String | 40 | 遊戲記錄 id（字串格式） |
| `parent_id` | Integer | 40 | 上級牌局 id |
| `parent_id_str` | String | 40 | 上級牌局 id（字串格式） |
| `small_game_type` | Integer | 40 | 0 正常、1 連消、2 免費旋轉、3 重轉、4 高倍 |
| `fb` | Integer | 40 | 是否購買小遊戲：0 否、1 是 |
| `platform` | Integer | 40 | 1 spribe、2 PG、3 jili、4 PP、5 OMG、6 MINI |

> **輸贏計算**（原文）：以 `Win - Bet` 為玩家輸贏。例：`Bet:50, Win:10 → 10 - 50 = -40`。

---

## 12. 錯誤碼

### 公共 / 單一錢包（共用）

| code | 說明 |
|---|---|
| 1 | 成功 |
| 10001 | 餘額不足，下分失敗 |
| 10002 | 簽名驗證未通過 |
| 10003 | 訂單正在處理中 |
| 10004 | 訂單不存在 |
| 10005 | 訂單重複 |
| 10006 | 請求過於頻繁 |
| 10007 | 遊戲中不能上下分 |
| 10008 | 遊戲已關閉 |
| 10009 | 非法參數 |
| 10010 | 失敗 |
| 10011 | 玩家登入 token 驗證失敗 |
| 10012 | 非法的 app_id |
| 10013 | 玩家不存在 |
| 10014 | 玩家被禁止登入 |
| 10015 | 伺服器內部錯誤 |
| 10016 | 遊戲不存在 |
| 10017 | 遊戲未開通 |
| 10018 | 商戶已被禁用 |
| 10019 | 不支援的貨幣 |
| 10020 | 轉帳交易不存在 |
| 10021 | 商戶錢包類型不對 |
| 10022 | 傳的 appid 對應的商戶不存在 |

> 「單一錢包說明」節另列一份 Error Code 表，範圍僅至 10017，為上表子集。

### 注單記錄專用（GetGameRecordList）

| code | 說明 |
|---|---|
| 1 | 成功 |
| 20001 | 商戶不存在 |
| 20003 | 查詢條數超過限制 |
| 20004 | 商戶用戶資訊不一致，請核對 |
| 20005 | 未查詢到遊戲此 RTP 配置資訊 |
| 20006 | 商戶未配置此遊戲 |
| 20007 | 此遊戲未配置 RTP |
| 20008 | 簽名錯誤 |
| 20009 | 讀取 body 失敗 |
| 20010 | 簽名不合法 |
| 20011 | 商戶已關閉 |
| 20012 | 流水號錯誤 |
| 20013 | 參數錯誤 |

---

## 13. 重要注意事項

### 雙向成功碼相反

GM 提供的接口（`loadlist` / `ingame`）成功碼 `0`；運營商實作給 GM 回調的接口（`verify_session` / `get_balance` / `change_balance`）成功碼 `1`。注單記錄接口雖由 GM 後台提供，但成功碼為 `1`、Error Code 自成一套（20001–20013）。

### 簽名須用原始 body

簽名計算的 body 必須是請求最原始的 JSON 字元，不可 decode/encode 後重組；驗簽端應取原始 body（如 `$request->getContent()`）。

### platform 對照兩處矛盾

`loadlist` 版標 `5=GM`、`6=MiniGame`（且列到 28）；注單記錄版標 `5=OMG`、`6=MINI`（只列到 6）。落地以 `Driver::PLATFORM_VENDOR_MAP` 為準（採 `5=OMG`，見附錄 A）。

### 餘額精度與幣值

所有 `balance` 最多 4 位小數；平台只記錄數值，貨幣單位以商戶選擇為準。回應給 GM 的金額需以遊戲商幣值（乘匯率）表示，非平台本地幣值。

### 文檔已知瑕疵

- 簽名範例（單一錢包說明節）body 漏 `player_logon_token` 鍵名（筆誤）。
- 注單記錄欄位表 `id_str` 原文列兩次（「注單記錄 id」與「遊戲記錄 id」），本文去重保留「遊戲記錄 id」。
- 遊戲清單 JILI 的 `10040 Crazy FaFaFa` 原文重複列出兩次，本文去重。

### 流程圖未收錄

原 showdoc 文檔含多張流程圖（圖片附件），純文字無法擷取，本文未收錄。

---

## 附錄 A：我方 vid 對照

> 本節為**我方實作對應**，非三方原文。GMSW 按 game platform 拆成 10 個 vid，共用 `App\Vendors\GMSW\Driver`（已查證 `app/Enums/Vendor/VendorIdEnum.php` 與 `app/Vendors/GMSW/Driver.php`）。

| vid | VendorIdEnum case | platform | 平台 |
|---|---|---|---|
| 237 | `GMSW_PG` | 2 | PG |
| 238 | `GMSW_JILI` | 3 | JILI |
| 239 | `GMSW_PP` | 4 | PP |
| 240 | `GMSW_OMG` | 5 | OMG |
| 241 | `GMSW_MINIGAME` | 6 | MiniGame |
| 242 | `GMSW_HACKSAW` | 8 | Hacksaw |
| 243 | `GMSW_TADA` | 23 | TADA |
| 244 | `GMSW_CP` | 24 | CP |
| 245 | `GMSW_ASKME` | 25 | ASKME |
| 246 | `GMSW_JDB` | 28 | JDB |

> `platform=5` 在 GM 文檔兩處標示不同（loadlist 版 `GM`、注單記錄版 `OMG`）；我方 Driver 採 **`OMG`**。

---

## 附錄 B：platform / gametype 與遊戲清單

### platform 對照（loadlist 版）

| 值 | 說明 |
|---|---|
| 2 | PG |
| 3 | JILI |
| 4 | PP |
| 5 | GM |
| 6 | MiniGame（含 Spribe、MiniGame、Lottery、Casino） |
| 8 | Hacksaw |
| 23 | TADA |
| 24 | CP |
| 25 | ASKME |
| 28 | JDB Game |

> 注單記錄版的 platform 為 `1 spribe / 2 PG / 3 jili / 4 PP / 5 OMG / 6 MINI`，與本表不一致（見第 13 節）。

### gametype 對照

| 值 | 說明 |
|---|---|
| 1 | SLOTS |
| 2 | 百人場 |
| 3 | 捕魚 |
| 4 | MiniGame |

### 遊戲清單（文檔附帶，僅供參考，以 `loadlist` 實際回傳為準）

**GM Originals**

| 平台 | 種類 | gameid | 名稱 | EN |
|---|---|---|---|---|
| GM | Mini Game | 100003 | 幸運贏家 | LUCKY WIN |

**SPRIBE**

| 平台 | 種類 | gameid | 名稱 | EN |
|---|---|---|---|---|
| SPRIBE | Mini Game | 9 | 掃雷 | Mines |
| SPRIBE | 百人場 | 100001 | 紅飛機 | Aviator |

**PG**

| 平台 | 種類 | gameid | 名稱 | EN |
|---|---|---|---|---|
| PG | Slots | 126 | 虎虎生財 | Fortune Tiger |
| PG | Slots | 68 | 鼠鼠福福 | Fortune Mouse |
| PG | Slots | 98 | 十倍金牛 | Fortune Ox |
| PG | Slots | 39 | 金豬報財 | Piggy Gold |
| PG | Slots | 1543462 | 金錢兔 | Fortune Rabbit |
| PG | Slots | 1695365 | 金龍送寶 | Fortune Dragon |
| PG | Slots | 40 | 叢林狂歡 | Jungle Delight |
| PG | Slots | 42 | 福運象財神 | Ganesha Gold |
| PG | Slots | 84 | 賞金女王 | Queen of Bounty |
| PG | Slots | 54 | 賞金船長 | Captain's Bounty |
| PG | Slots | 48 | 雙喜臨門 | Double Fortune |
| PG | Slots | 135 | 荒野決鬥 | Wild Bounty Showdown |
| PG | Slots | 1580541 | 黑幫風雲 | Mafia Mayhem |
| PG | Slots | 89 | 招財喵 | Lucky Neko |
| PG | Slots | 104 | 亡靈大盜 | Wild Bandito |
| PG | Slots | 1682240 | 快錢來了 | Cash Mania |
| PG | Slots | 74 | 麻將 2 | Mahjong Ways 2 |
| PG | Slots | 65 | 麻將 1 | Mahjong Ways |

**JILI**

| 平台 | 種類 | gameid | 名稱 | EN |
|---|---|---|---|---|
| JILI | Slots | 10109 | 伽羅寶石 | Fortune Gems |
| JILI | Slots | 10049 | 超級王牌 | SUPER ACE |
| JILI | Slots | 10047 | 衝鋒野牛 | Charge Buffalo |
| JILI | Slots | 10035 | 瘋狂 777 | Crazy 777 |
| JILI | Slots | 10077 | 拳王 | BOXING KING |
| JILI | Slots | 10021 | 極速發發發 | FaFaFa |
| JILI | Slots | 10223 | 伽羅寶石 2 | Fortune Gems2 |
| JILI | Slots | 10110 | 阿里巴巴 | Ali Baba |
| JILI | Slots | 10100 | 真有錢 | Super Rich |
| JILI | Slots | 10040 | 瘋狂發發發 | Crazy FaFaFa |
| JILI | Slots | 10051 | 瘋狂錢來也 | Money Coming |
| JILI | Slots | 10038 | 封神 | Feng Shen |
| JILI | Slots | 10030 | 人魚甜心 | Bubble Beauty |
| JILI | Slots | 10016 | 叢林之王 | Jungle King |
