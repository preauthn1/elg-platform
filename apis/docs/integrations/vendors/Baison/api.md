# Baison — API 整合文件

> 原始來源：`baison_api_cn_1.0.59__2_.pdf`（V 1.0.59，Copyright© BaisonGaming）、`渠道后台使用说明__2_.pdf`
> 抓取日期：2026-06-03
> 我方實作分析見 [integration.md](integration.md)

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 **GET**（官方文件如此；與一般遊戲商使用 POST 不同）
- **Endpoint**：`GET <API_URL>?channel_id=XX&timestamp=XX&param=XX&key=XX`
- **驗證方式**：IP 白名單 + 雙層加密（AES-128-ECB 加密業務參數 + MD5 校驗）

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `channel_id` | String | ✅ | 代理編號（`vendor_infos.info.ID`） |
| `timestamp` | String | ✅ | Unix **毫秒**時間戳（13 位） |
| `param` | String | ✅ | AES-128-ECB 加密後的業務參數（Base64 encoded） |
| `key` | String | ✅ | MD5(`channel_id` + `timestamp` + `MD5_KEY`) |

### 通用回應格式

```jsonc
{
  "code": 0,         // 0 = 成功；其他值見附錄 B 錯誤碼
  "msg": "",         // 錯誤提示（成功時為空字串）
  "result": {},      // 業務回傳資料（各 API 不同）
  "time": "2018-12-07 11:33:09"
}
```

### Base URL

| 環境 | 說明 |
|---|---|
| Production | 後台「運營管理 → 資料下載站 → API 接口」欄位（`vendor_infos.info.API_URL`） |
| Staging | 同位置，由 Baison 客服提供 |
| 試玩（Trial） | 獨立 `trial_server`，需聯繫客服另行取得 |

### 系統時區

未明述（**gap**），需向客服確認。

---

## Merchant API（代理主動呼叫）

### 3.2.1 登錄遊戲（action=1）

驗證遊戲帳號；帳號不存在時**自動建立**。若僅登入不上分，傳 `money=0`，無需傳 `order_id` 和 `money_type`。

**param 業務參數（加密前）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `1` |
| `account` | String | ✅ | 會員帳號（英文、數字、下劃線、中劃線任意組合，需唯一） |
| `nickname` | String | ❌ | 會員暱稱 |
| `money` | Decimal | ✅ | 上分金額；不攜帶分數傳 `0` |
| `money_type` | String | 條件必填 | 貨幣種類（`money>0` 時必填，見附錄 C） |
| `order_id` | String | 條件必填 | 流水號（格式：`代理編號+yyyyMMddHHmmssSSS+account`）；`money>0` 時必填 |
| `sub_channel_id` | String | ❌ | 子渠道 ID（最長 64 字元，小寫英文+數字；對應平台 linecode） |
| `server_type` | String | ❌ | 遊戲 ID（傳入時直接進入指定遊戲，見附錄 4.1，由客服提供） |
| `lang` | String | ❌ | 遊戲語言（見附錄 C） |
| `switch_lang` | Int | ❌ | 控制語言切換按鈕顯示（`1` 顯示，`0` 不顯示；預設 `1`） |
| `return_type` | Int | ❌ | `1`：遊戲中不顯示返回按鈕；留空：顯示 |

> `sub_channel_id` 首次傳入若不存在會自動建立，建立後**不可更改**。

**回應**

```jsonc
{
  "code": 0,
  "result": {
    "url": "https://example.invalid/?identity=...&lang=cn&channel_id=1001",
    "identity": "nYsmY6NwnWR1QDeEyMrqQighD4LUn..."
  }
}
```

| 欄位 | 說明 |
|---|---|
| `result.url` | 玩家遊戲 URL；App 調用時請在 URL 後拼接 `&is_app=1` |
| `result.identity` | 身份加密串 |

---

### 3.2.2 查詢可下分（action=4）

查詢玩家的可下分餘額（`freeMoney`）。若需同時查詢在線狀態，使用 action=6。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `4` |
| `account` | String | ✅ | 會員帳號 |
| `money_type` | String | ✅ | 貨幣種類 |

**回應**

```jsonc
{
  "code": 0,
  "result": { "freeMoney": "0.00" }
}
```

---

### 3.2.3 上分（action=2）

為玩家帳號上分（存入遊戲內餘額）。若未收到回應，呼叫 action=5 查詢訂單確認狀態。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `2` |
| `account` | String | ✅ | 會員帳號 |
| `money` | Decimal | ✅ | 上分金額 |
| `money_type` | String | ✅ | 貨幣種類 |
| `order_id` | String | ✅ | 流水號（格式：`代理編號+yyyyMMddHHmmssSSS+account`） |

**回應**

```jsonc
{
  "code": 0,
  "result": {
    "totalMoney": "700000.00",
    "freeMoney": "700000.00"
  }
}
```

**上分失敗處理**

| code | 說明 | 平台動作 |
|---|---|---|
| `5200` | 訂單已處理（上分已成功） | **不回滾**，改呼叫 action=5 確認金額 |
| `5304` | 上分失敗（訂單號格式錯誤） | 回滾用戶餘額 |
| timeout / 無回應 | 未知狀態 | 每 5 秒呼叫 action=5 查詢，最多 3 次 |

---

### 3.2.4 下分（action=20）

對玩家持有籌碼進行下分（指定金額）。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `20` |
| `account` | String | ✅ | 會員帳號 |
| `money` | Decimal | ✅ | 下分金額；傳 `0` 則全額下分；傳正數按指定金額；傳負數取絕對值 |
| `money_type` | String | ✅ | 貨幣種類 |
| `order_id` | String | ✅ | 流水號 |

**回應**

```jsonc
{
  "code": 0,
  "result": {
    "totalMoney": "0.0000",
    "freeMoney": "0.0000",
    "subMoney": "0.0200"
  }
}
```

| 欄位 | 說明 |
|---|---|
| `result.totalMoney` | 總金額 |
| `result.subMoney` | 本次下分額度 |
| `result.freeMoney` | 下分後可下分金額 |

**下分失敗處理**

| code | 說明 | 平台需否加回餘額 |
|---|---|---|
| `5001` 等 | 訂單號格式不正確，下分失敗 | 需加回 |
| `5002` | 玩家金額不足 | 需加回 |
| `5200` | 訂單已處理 | 視情況：代理 DB 未加分則加回 |
| `5301` | 玩家在遊戲中，不能操作 | 需加回 |

---

### 3.2.5 查詢訂單（action=5）

查詢上下分訂單狀態，用於轉帳結果確認（超時重查）。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `5` |
| `order_id` | String | ✅ | 流水號 |

**回應**

```jsonc
{
  "code": 0,
  "msg": "检查订单",
  "result": {
    "balance": "10000000.0000",
    "money": "10000000.0000",
    "status": 2
  }
}
```

| `result.status` | 說明 | VendorTransferResultEnum |
|---|---|---|
| `2` | 成功 | `SUCCESS` |
| `1` | 失敗 | `FAILED` |
| `0` | 訂單不存在（尚未處理） | `TIMEOUT`（繼續等待） |

> status `0` 表示訂單不存在，是中間態，不等同失敗，**不可回滾**。

---

### 3.2.6 查詢玩家在線狀態（action=7）

查詢玩家是否在線。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `7` |
| `account` | String | ✅ | 玩家帳號 |

**回應**

| 欄位 | 說明 |
|---|---|
| `result.online` | `0`：不在線；`1`：在線 |

---

### 3.2.7 查詢遊戲注單（action=9）

拉取遊戲對局注單。建議間隔至少 30 秒、每次區間不超過 60 分鐘；30 秒內重複拉取會得到重複注單。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `9` |
| `start_time` | Int | ✅ | 開始時間（Unix 秒級時間戳） |
| `end_time` | Int | ✅ | 結束時間（Unix 秒級時間戳） |
| `money_type` | String | ✅ | 貨幣種類 |
| `sub_channel_id` | String | ❌ | 子渠道 ID；有值則只返回該子渠道注單 |

**回應欄位（result.records 陣列）**

| 欄位 | 類型 | 說明 | 平台欄位對應 |
|---|---|---|---|
| `round_id` | String | 牌局唯一標識 | → `bet_id` |
| `user_id` | String | 玩家帳號 | → `username` |
| `game_id` | String | 遊戲 ID | → `game_code` |
| `room_id` | String | 房間 ID | — |
| `table_id` | String | 桌子號 | — |
| `seat_id` | String | 座位 | — |
| `user_count` | String | 玩家數量 | — |
| `card_value` | String | 手牌公共牌 | — |
| `init_balance` | String | 本局開始時余額 | — |
| `all_bet` | String | 總下注 | → `bet` |
| `avail_bet` | String | 有效下注 | → `valid_bet` |
| `profit` | String | 用戶返獎金額（已扣除服務費） | → `win_lose` |
| `revenue` | String | 服務費 | — |
| `balance` | String | 本局結束時余額 | — |
| `start_time` | String | 遊戲開始時間 | → `bet_time` |
| `end_time` | String | 遊戲結束時間 | → `payout_time` |
| `game_type` | Int | 遊戲類型（1 捕魚、2 棋牌、3 電子、4 活動） | — |
| `user_profit` | String | 用戶盈利 | — |
| `name` | String | 遊戲中文名稱 | — |
| `en_name` | String | 遊戲英文名稱 | — |
| `room_name` | String | 房間名稱 | — |
| `room_enname` | String | 房間英文名稱 | — |
| `channel_id` | String | 渠道 ID | — |
| `sub_channel_id` | String | 子渠道 ID | — |

---

### 3.2.8 查詢玩家總分（action=6）

查詢玩家的遊戲內總分、可下分餘額與在線狀態。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `6` |
| `account` | String | ✅ | 會員帳號 |
| `money_type` | String | ✅ | 貨幣種類 |

**回應**

```jsonc
{
  "code": 0,
  "result": {
    "totalMoney": "120000.00",
    "freeMoney": "120000.00",
    "online": 0
  }
}
```

| 欄位 | 說明 |
|---|---|
| `result.totalMoney` | 總余額 |
| `result.freeMoney` | 可下分余額 |
| `result.online` | `0`：不在線；`1`：在線 |

---

### 3.2.9 踢玩家下線（action=8）

將在線玩家強制離線，可選同時封禁。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `8` |
| `account` | String | ✅ | 會員帳號 |
| `forbidden_user` | Int | ✅ | `0`：踢下線；`1`：封凍並踢下線 |

---

### 3.2.10 查詢渠道余額（action=10，可選）

查詢渠道的信用余額（credits）。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `10` |

**回應**

```jsonc
{
  "code": 0,
  "result": { "credits": 100.00 }
}
```

---

### 3.2.11 查詢每日匯總用戶信息（action=11，可選）

查詢渠道的用戶每日匯總遊戲信息，每日 1 點後可拉取前一日數據。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `11` |
| `money_type` | String | ✅ | 貨幣種類 |
| `start_date` | String | ✅ | 開始日期（格式：`2019-06-04`） |
| `end_date` | String | ✅ | 結束日期（格式：`2019-06-04`） |
| `page` | Int | ✅ | 當前頁碼（預設 1） |
| `game_dimension` | Int | ❌ | `0`（預設）：按遊戲類型；`1`：按遊戲 ID |

**回應欄位（records 陣列）**

| 欄位 | 說明 |
|---|---|
| `date_label` | 日期 |
| `game_type` | 遊戲類型（1 捕魚、2 棋牌、3 電子、4 活動） |
| `game_id` | 遊戲 ID（按遊戲 ID 統計時有值） |
| `game_name` | 遊戲名稱 |
| `user_id` | 用戶 ID |
| `record_number` | 記錄數 |
| `all_bet` | 總投注 |
| `avail_bet` | 總有效投注 |
| `profit_loss` | 盈虧 |
| `jackpot` | jackpot 交收（暫無） |
| `mosaic_gold` | 累計彩金（暫無） |
| `bonus` | 累計獎池（暫無） |

---

### 3.2.12 查詢玩家是否存在（action=12，可選）

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `12` |
| `account` | String | ✅ | 玩家帳號 |

**回應**

| 欄位 | 說明 |
|---|---|
| `result.exist` | `1`：存在；帳號不存在時 `code` 回傳 `5101` |

---

### 3.2.13 根據日期查詢玩家帳變余額（action=14，可選）

查詢指定日期，玩家最後一筆帳戶流水變動後的持有籌碼余額。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `14` |
| `account` | String | ✅ | 玩家帳號 |
| `date` | String | ✅ | 查詢日期（格式：`2019-08-19`） |
| `sub_channel_id` | String | ❌ | 查詢子渠道 |
| `status` | Int | ❌ | `1`：可用；`0`：禁用 |
| `page` | Int | ❌ | 當前頁碼（預設 1） |
| `page_size` | Int | ❌ | 分頁容量（預設 20） |

**回應（user_list 陣列）**

| 欄位 | 說明 |
|---|---|
| `id` | 用戶 ID |
| `nickname` | 用戶暱稱 |
| `balance` | 用戶余額（Float） |

---

### 3.2.14 查詢遊戲列表（action=17）

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `17` |

**回應欄位（result.list 陣列）**

| 欄位 | 說明 |
|---|---|
| `game_id` | 遊戲 ID |
| `name` | 遊戲名稱 |
| `en_name` | 遊戲英文名稱 |
| `order_id` | 排序 ID |
| `status_channel` | 渠道自行控制的遊戲狀態：`1` 有效、`0` 無效 |
| `status_system` | 遊戲總控狀態：`1` 有效、`0` 無效 |

---

### 3.2.15 查詢遊戲 zip 列表（action=18）

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `18` |
| `server_type` | String | ❌ | 遊戲 ID（可選，傳入時僅查詢指定遊戲） |

**回應欄位（result.game_list 陣列）**

| 欄位 | 說明 |
|---|---|
| `server_type` | 遊戲 ID |
| `server_name` | 遊戲 zip 包名 |
| `version` | 當前版本號 |
| `download_url` | 遊戲 zip 包下載地址 |
| `md5_value` | 遊戲 zip 包 MD5 值 |

---

### 3.2.16 全額下分（action=19）

對玩家持有的**所有**籌碼進行全額下分。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `19` |
| `account` | String | ✅ | 會員帳號 |
| `money_type` | String | ✅ | 貨幣種類 |
| `order_id` | String | ✅ | 流水號 |

**回應**

| 欄位 | 說明 |
|---|---|
| `result.totalMoney` | 總金額（Float） |
| `result.freeMoney` | 下分後可下分金額（Float） |
| `result.subMoney` | 全額下分金額（Float） |

---

### 3.2.17 遊戲試玩接口（action=99）

生成試玩帳號，供玩家免登錄試玩遊戲。

> Endpoint 使用獨立試玩域名（`trial_server`），與正式 `API_URL` 不同，需聯繫客服提供。

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `99` |
| `money_type` | String | ✅ | 貨幣種類（預設 RMB） |
| `money` | Decimal | ❌ | 上分金額（預設 0，最大 1000 萬） |
| `sub_channel_id` | String | ❌ | 子渠道 ID |
| `server_type` | String | ❌ | 遊戲 ID |

---

### 3.2.18 查詢數據匯總信息（action=98，可選）

查詢渠道指定時間區間的數據匯總，按子渠道、遊戲分類統計。**注意：2 分鐘內最多請求 30 次。**

**param 業務參數**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | Int | ✅ | `98` |
| `money_type` | String | ✅ | 貨幣種類 |
| `start_time` | String | ✅ | 開始時間（格式：`2019-06-06 00:00:00`） |
| `end_time` | String | ✅ | 結束時間（格式：`2019-06-08 00:00:00`） |

**回應欄位（records 陣列）**

| 欄位 | 說明 |
|---|---|
| `all_bet` | 總下注額 |
| `avail_bet` | 總有效下注額 |
| `channel_id` | 代理商 ID |
| `sub_channel_id` | 所屬站點（子渠道 ID） |
| `money_type` | 幣種 |
| `game_type` | 遊戲類型（1 捕魚、2 棋牌、3 電子、4 活動） |
| `profit` | 總派彩 |
| `profit_loss` | 總損益 |
| `record_number` | 總局數 |

---

### 4.5 渠道回調接口（Baison → 代理，可選）

Baison 在玩家完成一局後離線時，主動回調代理提供的 URL，通知執行下分。需在接入時向客服提供回調 URL。

**回調參數**

| 欄位 | 說明 |
|---|---|
| `channel_id` | 代理編號 |
| `timestamp` | Unix 毫秒時間戳 |
| `param` | AES 加密（`action=1&account=玩家帳號`） |
| `key` | MD5 校驗 |

> 玩家在牌局中離開，需等該局結束後才判定為離線並回調。

---

## 附錄 A：action 操作類型目錄

| 接口 | action |
|---|---|
| 3.2.1 登錄接口 | `1` |
| 3.2.2 查詢可下分 | `4` |
| 3.2.3 上分 | `2` |
| 3.2.4 下分 | `20` |
| 3.2.5 查詢訂單 | `5` |
| 3.2.6 查詢玩家在線狀態 | `7` |
| 3.2.7 查詢遊戲注單 | `9` |
| 3.2.8 查詢玩家總分 | `6` |
| 3.2.9 踢玩家下線 | `8` |
| 3.2.10 查詢渠道信用余額（可選） | `10` |
| 3.2.11 查詢每日匯總用戶信息（可選） | `11` |
| 3.2.12 查詢玩家是否存在（可選） | `12` |
| 3.2.13 查詢玩家帳變余額（可選） | `14` |
| 3.2.14 查詢遊戲列表 | `17` |
| 3.2.15 查詢遊戲 zip 列表 | `18` |
| 3.2.16 全額下分 | `19` |
| 3.2.17 遊戲試玩接口 | `99` |
| 3.2.18 查詢數據匯總信息（可選） | `98` |

---

## 簽名

### param 加密（AES-128-ECB）

**Step 1 — 組裝業務參數為 query string**

```
action=1&account=test001&money=5000&money_type=RMB&order_id=10881703061430...
```

**Step 2 — AES-128-ECB 加密 + Base64**

```php
$paramStr = http_build_query($businessParams);
$encrypted = openssl_encrypt(
    $paramStr,
    'AES-128-ECB',
    $aesKey,          // 16 bytes，vendor_infos.info.AES_KEY
    OPENSSL_RAW_DATA
);
$param = base64_encode($encrypted);
```

### key 計算（MD5）

```php
$key = md5($channelId . $timestamp . $md5Key);
// 例：md5("1088" . "1584341333000" . "atwPbr4Nmmr4GjG5")
```

> `timestamp` 必須與 `param` 加密時使用的值一致，且為**毫秒級**（13 位）。

### 完整請求範例（action=1）

```
GET https://example.invalid/Api/interface?
    channel_id=1088
    &timestamp=1584340802000
    &param=b3cY+a3TYJnhwXs8bp6dbqPxaehdSKYMdkz1Nid+U6M...（Base64）
    &key=64777e310fa9a2397855544db376b78e
```

其中 `param` 明文（加密前）：

```
action=1&account=test001&money=5000&money_type=RMB&nickname=测试001
&order_id=...&sub_channel_id=demo1&server_type=1000&lang=cn&switch_lang=1
```

### 常見簽名錯誤

| 症狀 | 可能原因 |
|---|---|
| `code: 5001`（參數缺少或簽名不對） | `key` 計算錯誤、`timestamp` 非毫秒格式、`AES_KEY` 錯誤 |
| `code: 5006`（IP 不在訪問白名單） | 伺服器 IP 未加入白名單，需聯繫客服 |
| `code: 5007`（渠道被封） | 代理帳號被停用 |
| `code: 6000`（系統維護中） | 三方系統維護，可稍後重試 |

---

## 附錄 B：錯誤碼說明

| code | 說明 |
|---|---|
| `0` | 操作成功 |
| `5000` | 系統內部錯誤 |
| `5001` | 缺少參數或參數錯誤（含簽名不對） |
| `5002` | 玩家金額不足 |
| `5006` | IP 不在訪問白名單 |
| `5007` | 渠道被封禁 |
| `5008` | 渠道不存在 |
| `5017` | 用戶被踢 |
| `5100` | 用戶狀態錯誤 |
| `5101` | 不存在此用戶 |
| `5102` | 新增用戶出錯 |
| `5103` | 余額不足（下分場景） |
| `5106` | 用戶被禁止 |
| `5200` | 上下分訂單重複（訂單已處理；**上分時不可回滾**） |
| `5220` | 渠道信用分數不足（適用於買分制合作） |
| `5301` | 用戶正在遊戲中，不能操作 |
| `5304` | 上分失敗 |
| `5305` | 下分失敗 |
| `500X` | 回傳的 `msg` 欄位會有對應錯誤提示 |
| `6000` | 系統維護中 |

---

## 附錄 C：貨幣代碼與兌換比例

> 兌換比例為 V1.0.59 版本所載，以實際接入時客服確認為準。

| 幣種 | code | 兌換比例（法幣：遊戲幣） | 登入地區 |
|---|---|---|---|
| 人民幣 | `RMB` / `CNY` | 1:1 | 全球 |
| 美元 | `USD` | 1:6.2808 | 美國、加拿大 |
| 新台幣 | `TWD` | 1:0.2152 | 台灣 |
| 馬幣 | `MYR` | 1:1.6229 | 馬來西亞 |
| 越南盾 | `VND` | 1:0.0003 | 越南 |
| 泰銖 | `THB` | 1:0.2016 | 泰國 |
| 印尼盧比 | `IDR` | 1:0.0005 | 印尼 |
| 日元 | `JPY` | 1:0.0587 | 日本 |
| 韓元 | `KRW` | 1:0.006 | 韓國 |
| 印度盧比 | `INR` | 1:0.09 | 印度 |
| 虛擬幣 | `USDT` | 1:6.3 | — |
| 迪拉姆 | `AED` | 1:1.75 | 阿聯酋 |
| 雷亞爾 | `BRL` | 1:1.3 | 巴西 |
| 港幣 | `HKD` | 1:0.8 | 香港 |
| 新加坡元 | `SGD` | 1:5 | 新加坡 |
| 菲律賓比索 | `PHP` | 1:0.1 | 菲律賓 |
| 墨西哥比索 | `MXN` | 1:0.38 | 墨西哥 |
| 加元 | `CAD` | 1:5 | 加拿大 |
| 丹麥克朗 | `DKK` | 1:1 | 丹麥 |
| 歐元 | `EUR` | 1:7.5 | 歐盟 |
| 挪威克朗 | `NOK` | 1:0.6 | 挪威 |
| 俄羅斯盧布 | `RUB` | 1:0.8 | 俄羅斯 |
| 土耳其里拉 | `TRY` | 1:0.2 | 土耳其 |
| 哥倫比亞比索 | `COP` | 1:0.0015 | 哥倫比亞 |
| 尼日利亞奈拉 | `NGN` | 1:0.01 | 尼日利亞 |

> 多幣種接入：非人民幣幣種在登錄/上下分時需傳對應 `money_type`，系統自動換算為遊戲幣。遊戲幣顯示時**小數點兩位後捨棄**（如 `1.956` 顯示為 `1.95`）。
