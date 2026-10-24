# Ia — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與簽名規格）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

## 通用約定

### Base URL

| 環境 | URL |
|---|---|
| Production | 由 Ia 客服提供（文件以 `{ia_api_domain}` 佔位，未列正式網域） |
| 設定來源 | `vendor_infos.info.API_URL` |

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | 所有 API 一律 `POST` |
| Content-Type | `text/plain`（body 為 AES 加密後的 Base64 字串，非 JSON） |
| `pch` header | 客服提供的商戶識別碼，每次請求必須帶上 |
| 加密金鑰 | `API_KEY`（AES key）、`API_IV`（AES IV）、`API_PCH`（pch header 值）皆由客服提供 |

```
POST https://{ia_api_domain}/{endpoint}
Content-Type: text/plain
pch: {API_PCH}

<Base64( AES-256-CBC( JSON( params + auth_key ), API_KEY, API_IV ) )>
```

### 回應格式（官方文檔）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | Int | `1` 成功 / `0` 失敗（**注意：實作讀 `code`，非 `status`，詳見 `integration.md`**） |
| `error_code` | Int | 錯誤碼（文件僅示例 `1001`，無完整碼表） |
| `message` | String | 錯誤資訊描述 |
| `data` | Object \| Array \| 純量 | 回傳資料 |
| `ext` | Array | 額外欄位（多為空陣列） |

```jsonc
{
  "status": 1,       // 1 是成功, 0 是失敗（官方文檔欄位；實作讀 code）
  "error_code": 1001,
  "message": "...",
  "data": { },
  "ext": []
}
```

### 限流 / 資料保留期

| 項目 | 限制 |
|---|---|
| `/index/gethotgame` | 每分鐘最多請求 **1 次** |
| `/wager/getproject`（下注記錄） | 只查最近 **3 天** |
| `/wager/getactivitylog`（活動記錄） | 只查最近 **3 天** |
| `/wager/getAccountChangeList`（賬變記錄） | 只查最近 **70 天** |

---

## merchant API

### 1. 啟動 Launch

取得玩家進入遊戲的 URL。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/lunch`（亦可 `/user/launch`） |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `client` | Int | No | 客戶端類型，默認 1（`1:PC`、`2:WAP`） |
| `lang` | Int | No | 語言，默認 1（中文），見附錄 |

#### 回應

```jsonc
{
  "status": 1,
  "error_code": 1001,
  "message": "user can not find",
  "data": {
    "url": "https://ilustre.biz/api/s?&default=xxx&lang=1&css="
  },
  "ext": []
}
```

---

### 2. 匿名啟動 Anonymous Launch

不帶 username 的試玩啟動。**專案目前未實作**。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/anonymousLunch`（亦可 `/user/anonymousLaunch`） |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `client` | Int | No | 客戶端類型，默認 1 |
| `lang` | Int | No | 語言，默認 1 |

回應同「啟動」，`data.url` 為匿名進入網址。

---

### 3. 註冊 Register

在 Ia 系統建立玩家帳號。**Driver 未顯式呼叫**（由 AbstractTransferWallet 共用機制處理，待 RD 確認）。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/register` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `register_username` | String | ✅ | 期望註冊的用戶名，限制 6~32 位字元 |
| `amount` | Float | No | 虛擬幣數量 |
| `currency_code` | String | No | 貨幣編碼（如 `RMB`），見附錄 |
| `limit_money` | Float | No | 用戶最大投注額，單位 ¥(RMB) |

#### 回應

```jsonc
{
  "status": 1,
  "error_code": 1001,
  "message": "user can not find",
  "data": [],
  "ext": []
}
```

---

### 4. 用戶登出 Logout

| 項目 | 值 |
|---|---|
| Endpoint | `/user/logout` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | ✅ | 用戶名，限制 6~32 位字元 |

#### 回應

```jsonc
{
  "status": 1,
  "message": "登出成功",
  "hide_error": 0,
  "data": [],
  "ext": []
}
```

---

### 5. 用戶餘額 Balance

查詢會員在 Ia 端的賬號餘額。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/balance` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | ✅ | 期望查詢的用戶 |

#### 回應

```jsonc
{
  "status": 1,
  "message": "user can not find",
  "data": 30.32       // 文件：餘額純量；實作讀 data.data（巢狀），見 integration.md
}
```

---

### 6. 存款 Deposit

將資金從本地錢包存入 Ia 賬戶（`transferToVendor`）。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/deposit` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `amount` | Float | ✅ | 轉帳金額 |
| `order_id` | String | ✅ | 本地唯一訂單 ID，最長 32 位（英數字） |

#### 回應

```jsonc
{
  "status": 1,       // 文件只有 status + message，無 data 物件
  "message": "user can not find"
}
```

---

### 7. 提款 Withdraw

將 Ia 賬戶金額提回本地（`transferToLocal`）。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/withdraw` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `amount` | Float | ✅ | 轉帳金額 |
| `order_id` | String | ✅ | 本地唯一訂單 ID，最長 32 位（英數字） |

#### 回應

```jsonc
{
  "status": 1,
  "message": "user can not find"
}
```

---

### 8. 訂單狀態 Search Order

對 deposit 或 withdraw 做二次確認（`checkTransferStatus`）。

| 項目 | 值 |
|---|---|
| Endpoint | `/user/searchorder` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `order_id` | String | ✅ | 要查詢的訂單 ID |
| `type` | Int | No | 請求類型，默認 -1；`1` 存款、`2` 提現、`-1` 全部 |

#### 回應

```jsonc
{
  "status": 1,
  "message": "params error",
  "data": {
    "status": 0,     // 訂單狀態：0 失敗, 1 成功
    "id": 133,       // 訂單成功時回傳 Ia 訂單 ID
    "type": 1        // 訂單類型：1 存款, 2 提現
  }
}
```

---

### 9. 下注記錄 Get Project

下注資訊 API，包含已結算與未結算。**只顯示最近 3 天記錄**。

| 項目 | 值 |
|---|---|
| Endpoint | `/wager/getproject` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `start_time` | Int | ✅ | 開始時間戳，只支持查詢最近三天 |
| `end_time` | Int | No | 結束時間戳，默認請求時間 |
| `page` | Int | No | 默認 1 |
| `limit` | Int | No | 默認 10000，最少值 10000 |
| `is_settle` | Int | No | 默認 1；`1` 結算 `0` 未結算 `-1` 所有 |
| `is_cancel` | Int | No | 是否撤單，默認 -1；`1` 撤單 `0` 正常 `-1` 所有 |
| `receive_status` | Int | No | 接收狀態，默認 0；`0` 正常 `1` 待接收 `2` 已拒收 `-1` 所有 |

#### 回應（重點欄位）

```jsonc
{
  "status": 1,
  "message": "",
  "data": {
    "list": [
      {
        "id": 2221000,
        "bonus": "0.0000",
        "amount": "50.00",
        "create_time": "2019-10-22 20:38:23",
        "update_time": "2019-10-22 20:38:23",
        "bonus_time": "2020-02-17 06:46:40",
        "cancel_time": 0,
        "is_cancel": 0,          // 1=取消 0=未取消
        "is_getprize": 1,        // 1=已結算 0=未結算
        "prize_status": 2,       // 1=贏 2=輸
        "money_type": 2,         // 1=現金 2=虛擬幣
        "is_change": 0,
        "game_type_id": 2,
        "odds": "1.9500",
        "client_type": 1,        // 1=pc 2=wap 3=app
        "category_id": 1,        // 1=彩池盤 2=固賠 3=滾球
        "desc": "第一局輸贏 Mineski.GGNetwork",
        "currency_id": 5,
        "exchange_rate": "1.0000000",
        "show_scale": 1,
        "settlement_odds": "1.9500",
        "game_name": "DOTA2",
        "username": "tony123",
        "order_id": "GAMEVCCCBAAAKL",
        "win_lose": -50,
        "event_name": "ProDota Cup Southeast Asia #5",
        "game_start_time": 1571281200
      }
    ],
    "total": 1
  }
}
```

> 對帳取用欄位：`order_id`、`amount`、`win_lose`、`is_cancel`、`is_getprize`、`prize_status`。其餘 `game_id` / `points_id` / `winner` / `cancel_user` / `isalert` 等為「內部冗餘字段」。

---

### 10. 熱門遊戲 Hot Game

**限流：每分鐘最多請求 1 次。**

| 項目 | 值 |
|---|---|
| Endpoint | `/index/gethotgame` |
| Parameters | 無 |

#### 回應（重點欄位）

```jsonc
{
  "status": 1,
  "message": "",
  "data": [
    {
      "event_name": "DPL",
      "team_name_1": "Source Code",
      "team_name_2": "FTD club",
      "team_logo_1": "https://example.invalid/upl...",
      "team_logo_2": "https://example.invalid/upl...",
      "team_1_point": 2.33,
      "team_2_point": 1.82,
      "game_start_time": 1530517074,
      "is_end": 0,       // 0=遊戲中 1=已結束
      "sale_end": 0      // 0=銷售中 1=銷售截止
    }
  ]
}
```

---

### 11. 活動記錄 Activity Log

獲取參與活動記錄（如自走棋）。**只查最近 3 天**。

| 項目 | 值 |
|---|---|
| Endpoint | `/wager/getactivitylog` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `start_time` | Int | ✅ | 開始時間戳，只支持最近三天 |
| `end_time` | Int | No | 結束時間戳 |
| `page` | Int | No | 默認 1 |
| `limit` | Int | No | 默認 10000，最少值 10000 |

#### 回應（重點欄位）

```jsonc
{
  "status": 1,
  "message": "",
  "data": {
    "list": [
      {
        "id": 47358,
        "activity_id": 1,
        "amount": "-4.0000",     // <0 輸錢, >0 加錢
        "money_type": 1,         // 1=現金 2=虛擬幣
        "currency_id": 1,
        "exchange_rate": "1.0000000",
        "show_scale": 1,
        "desc": "Auto chess refresh reduce money",
        "create_time": "2019-11-01 20:10:12",
        "username": "dillon",
        "order_id": "ACTVEHDFIKLMN",
        "activity_name": "自走棋"
      }
    ]
  }
}
```

---

### 12. 賬變記錄 Account Change List

獲取賬變記錄。**支持查詢最近 70 天**。

| 項目 | 值 |
|---|---|
| Endpoint | `/wager/getAccountChangeList` |

#### 請求參數

| Key | Type | Required | Description |
|---|---|---|---|
| `username` | String | No | 要查詢的用戶名 |
| `start_time` | Int | ✅ | 開始時間戳，最近 70 天 |
| `end_time` | Int | No | 結束時間戳 |
| `page` | Int | No | 默認 1 |
| `limit` | Int | No | 默認 1000 |

#### 回應（重點欄位）

```jsonc
{
  "status": 1,
  "message": "",
  "data": {
    "total": 2,
    "per_page": 1,
    "current_page": 1,
    "last_page": 2,
    "data": [
      {
        "order_id": 24175,
        "user_id": 108,
        "username": "sisen123",
        "project_id": "GAMEVCCCECIEKL",
        "order_no": "MONEYVCEBHFKLMN",
        "order_type": "加入遊戲",
        "money_info": "renmingbi",
        "amount": -155,              // >0 收入, <0 支出
        "convert_amount": "-155.00",
        "available_balance": "7802429.6500",
        "ip": "0.0.0.0",
        "create_time": "2020-05-25 11:21:51"
      }
    ]
  }
}
```

> 分頁資訊在 `data` 層（`total`/`per_page`/`current_page`/`last_page`），明細在 `data.data`（與 §9/§11 的 `data.list` 結構不同）。

---

## 簽名

Ia 每個請求需雙層加密：**內層 MD5 auth_key** + **外層 AES-256-CBC**。

### 內層：auth_key（MD5 簽章）

構成步驟：

1. **按鍵名正序排序（ksort）並以 `key=value,key=value` 格式拼接**：

   ```
   // 例：{'username':'test001','amount': 303.22}
   amount=303.22,username=test001
   ```

2. **對拼接字串做 MD5**：

   ```
   auth_key = md5('amount=303.22,username=test001')
   ```

3. **將 `auth_key` 加回原參數物件**：

   ```jsonc
   {
     "amount": 303.22,
     "username": "test001",
     "auth_key": "<md5_str>"
   }
   ```

4. **將含 `auth_key` 的完整 JSON 做 AES 加密（見下節）後 POST**。

> 文件 §1.2 範例中 `303.22` 與 `203.22` 不一致，疑為筆誤；實作以 `key=value` 原值拼接為準。

PHP 實作（`Driver::encrypt()`）：

```php
ksort($params);
$params['auth_key'] = md5(
    implode(',', array_map(
        fn ($k, $v) => "$k=$v",
        array_keys($params),
        $params
    ))
);
$params = json_encode($params);
$cryptText = openssl_encrypt($params, 'aes-256-cbc', $this->infoDetail->API_KEY, OPENSSL_RAW_DATA, $this->infoDetail->API_IV);
return base64_encode($cryptText);
```

### 外層：AES-256-CBC 加密

| 項目 | 值 |
|---|---|
| 演算法 | AES |
| 密碼套件 | `aes-256-cbc`（OpenSSL）— **文件未明寫，為實作採用值，待客服確認** |
| 金鑰 | `vendor_infos.info.API_KEY`（客服提供） |
| IV | `vendor_infos.info.API_IV`（客服提供） |
| 明文 | 含 `auth_key` 的 JSON 字串 |
| 輸出編碼 | Base64 |
| HTTP body | Base64 字串，`Content-Type: text/plain` |

### 請求頭

所有請求必須帶 `pch` header：

```
pch: {API_PCH}       // 值由客服提供，存於 vendor_infos.info.API_PCH
```

---

## 錯誤碼

文件僅示例 `error_code: 1001`，無完整碼表。實作不解析 `error_code`，以 `code`（非文檔的 `status`）欄位判斷成敗。

完整錯誤碼需向 Ia 客服索取。

---

## 附錄：語言代碼 / 幣種代碼

### 語言（`lang` 參數）

| id | code | 名稱 |
|---|---|---|
| 1 | `cn` | 簡體中文 |
| 2 | `en` | 英文 |
| 3 | `tw` | 繁體中文 |
| 4 | `th` | 泰語 |
| 5 | `vn` | 越南語 |
| 6 | `in` | 印尼語 |
| 7 | `jp` | 日語 |
| 8 | `kr` | 韓語 |

> 實作 `mapLanguageCode()` 對應 1~6，日語(7)/韓語(8) 未對應，fallback 為 `2`（EN）。

### 幣種（`currency_id` / `currency_code`）

| id | code | 名稱 | | id | code | 名稱 |
|---|---|---|---|---|---|---|
| 1 | `RMB` | 人民幣 | | 10 | `KRW` | 韓幣 |
| 2 | `THB` | 泰銖 | | 11 | `AUD` | 澳大利亞元 |
| 3 | `USD` | 美金 | | 12 | `IDD` | 印尼盾 1 |
| 4 | `MYR` | 馬拉西亞令吉 | | 13 | `BND` | 文萊元 |
| 5 | `IDR` | 印尼盾 k | | 14 | `MMK` | 緬元 1 |
| 6 | `VND` | 越南盾 k | | 15 | `VND1` | 越南盾 1 |
| 7 | `INR` | 印度盧比 | | 16 | `SGD` | 新加坡幣 |
| 8 | `TWD` | 新臺幣 | | 17 | `JPY` | 日元 |
| 9 | `HKD` | 港幣 | | | | |
