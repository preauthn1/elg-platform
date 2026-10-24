# Lfc888（BT Gaming）— 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與簽名規格）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Header**：`Accept: application/json`、`Content-Type: application/json; charset=UTF-8`
- **驗證方式**：IP 白名單（代理需提供允許的 IP）＋ `check_code` 簽章
- **必帶欄位**：所有 API 都需 `account_id`（廠商編號）與 `check_code`
- **時區**：所有時間採 RFC3339 格式且為 **UTC-4**（美東）

### Base URL

| 環境 | API URL |
|---|---|
| 測試（Staging） | `https://example.invalid/v2_2` |
| 正式（Production） | `https://example.invalid/v2_2` |

### 通用回應結構

```jsonc
{
  "status": {
    "code": 1000,                          // 1000 = 成功；否則為錯誤碼
    "message": "Success.",
    "time": "2021-05-18T19:03:59-04:00"    // RFC3339（UTC-4）
  },
  "data": { /* 成功時資料；失敗時無 data */ }
}
```

成功判定：`status.code === 1000`

---

## Merchant API

### API 端點總覽

| 章節 | 端點 | 用途 |
|---|---|---|
| 04-02 | `/user/get_balance` | 查詢玩家餘額 |
| 04-03 | `/user/deposit_amount` | 儲值玩家額度（代理 → LFC） |
| 04-04 | `/user/withdraw_amount` | 提領玩家額度（LFC → 代理） |
| 04-05 | `/user/get_transaction_status` | 查詢交易狀態 |
| 06-01 | `/agent/user_login` | 玩家登入遊戲（取 game_url） |
| 06-02 | `/agent/lobby_login` | 玩家登入遊戲大廳 |
| 06-03 | `/agent/user_logout` | 玩家登出 |
| 06-04 | `/agent/get_gamelist` | 取得遊戲列表 |
| 07-01 | `/record/get_game_histories` | 取得區間內遊戲紀錄 |
| 07-02 | `/record/get_player_histories` | 取得玩家遊戲紀錄 |
| 07-03 | （見 PDF） | 玩家財務報表 |
| 07-04 | （見 PDF） | 財務匯總報表 |
| 07-05 | `/record/get_single_betting_record` | 透過注單局號查詢單局紀錄 |

---

### 04-02 Get user balance — 查詢玩家餘額

URL：`<API URL>/user/get_balance`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `username` | String | ✅ | 玩家遊戲帳號 |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：

| 參數 | 類型 | 說明 |
|---|---|---|
| `balance` | Float | 玩家餘額 |
| `currency` | String | 幣別 |

---

### 04-03 Deposit amount — 儲值玩家額度（代理 → LFC）

URL：`<API URL>/user/deposit_amount`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `username` | String | ✅ | 玩家遊戲帳號 |
| `deposit_amount` | **String** | ✅ | 儲值額度（最大 12 位數、小數後 2 位）。**v2.3.7（2025-07-18）起由 Float 改為 String** |
| `external_order_id` | String | ✅ | 外部交易流水號（**必須唯一不可重複**） |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：

| 參數 | 類型 | 說明 |
|---|---|---|
| `balance` | String | 玩家餘額 |
| `currency` | String | 幣別 |
| `order_id` | String | 遊戲商端交易流水號 |
| `amount` | String | 交易金額 |
| `external_order_id` | String | 外部交易流水號 |

---

### 04-04 Withdraw amount — 提領玩家額度（LFC → 代理）

URL：`<API URL>/user/withdraw_amount`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `username` | String | ✅ | 玩家遊戲帳號 |
| `withdraw_amount` | **String** | ✗ | 提款額度（最大 12 位數、小數後 2 位）。**v2.3.7 起由 Float 改為 String**。`take_all=true` 時不可帶此參數（衝突 → `6109`） |
| `take_all` | Boolean | ✅ | `true`=提領全部；`false`=不提領全部 |
| `external_order_id` | String | ✅ | 外部交易流水號（**必須唯一不可重複**） |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：同 04-03（`balance`、`currency`、`order_id`、`amount`、`external_order_id`）

> `check_code` 組成提醒：`take_all` 須以字串 `"false"`/`"true"` 納入；選填的 `withdraw_amount` 若有帶入才納入排序。

---

### 04-05 Get transaction status — 查詢交易狀態

URL：`<API URL>/user/get_transaction_status`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `external_order_id` | String | ✅ | 外部交易流水號 |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：

| 參數 | 類型 | 說明 |
|---|---|---|
| `external_order_id` | String | 外部交易流水號 |
| `order_id` | String | 遊戲商端交易流水號 |
| `type` | String | 交易類型（`deposit`=存款 / `cashout`=提款） |
| `amount` | String | 該筆交易額度 |
| `balance` | String | 交易後玩家餘額 |
| `status` | String | **交易狀態：`pending`=擱置 / `transferred`=交易已完成** |

> 此 API 為 transfer wallet 補單關鍵：deposit / withdraw 逾時時，用 `external_order_id` 回查 `status` 確認最終結果。禁止直接重打原請求。

---

### 06-01 Launch game by game_code — 玩家登入遊戲

URL：`<API URL>/agent/user_login`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `username` | String | ✅ | 玩家遊戲帳號（4~50 英數字） |
| `game_code` | String | ✅ | 遊戲代碼 |
| `plat_username` | String | ✗ | 運營商用戶暱稱（支援中文、英文、數字，不支援特殊符號）。**此參數不作為 check_code 組成** |
| `lang` | String | ✗ | 遊戲語系（不支援則固定 `en`） |
| `back_url` | String | ✗ | 遊戲內返回大廳的回傳網址 |
| `routing` | String | ✗ | 線路選擇：`world`=預設 / `zh-cn`=中國用戶 / `id`=印尼線路 |
| `cur_type` | Integer | ✗ | 幣別顯示：`0`=法幣 / `1`=代幣（預設） |
| `demo` | Boolean | ✗ | `true`=試玩網址 / `false`=否（預設） |
| `device` | String | ✗ | `mobile`=行動裝置 / `desktop`=桌上裝置（預設） |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：

| 參數 | 類型 | 說明 |
|---|---|---|
| `game_url` | String | 遊戲網址 |

---

### 06-02 Enter the game lobby — 玩家登入遊戲大廳

URL：`<API URL>/agent/lobby_login`

請求參數（除 `account_id`/`username`/`check_code` 外）：`lang`、`routing`、`prior_gametype`（進入大廳後優先顯示遊戲類型，預設 `slot`）、`cur_type`、`home_url`、`device`。

回應 `data`：`usertoken`（玩家進入大廳用驗證碼）、`lobby_url`（大廳網址）

---

### 06-03 User logout — 玩家登出

URL：`<API URL>/agent/user_logout`

請求參數：`account_id`、`username`、`check_code`

---

### 06-04 Get game list — 取得遊戲列表

URL：`<API URL>/agent/get_gamelist`

請求參數：`account_id`、`check_code`

回應 `data`：以 `{game_type}`（如 `slot`、`table`）為 key，值為遊戲陣列，每筆含 `game_vendor_name`、`game_code`、`en`、`tw`、`cn`、`maintain`（是否維護中）

---

### 07-01 Query game histories — 取得區間內遊戲紀錄

URL：`<API URL>/record/get_game_histories`

> 報表 API 時間區間以**系統結算時間**搜尋，且**不可跨日查詢**。時間參數一律 RFC3339（UTC-4）。

請求參數：`account_id`、`datetime_from`、`datetime_to`、`transaction_type`（選填：`0`總現金 / `1`遊戲 / `2`活動 / `3`紅利，預設 0）、`page`、`page_count`（選填 1~200，逾範圍以 200 替代）、`game_type`（`ALL`代表全部）、`lang`、`check_code`

回應 `data`：`total`、`bet_count`、`data_updated_time`、`histories[]`

每筆 `histories` 含：

| 欄位 | 說明 |
|---|---|
| `round_id` | 局號 |
| `order_id` | 唯一交易單號 |
| `transaction_type` | 交易類型 |
| `special` | 特殊屬性（Array：`["FreeGame"]`/`["BuyFeature"]`/`["BuyFeature","FreeGame"]`，一般遊戲不傳） |
| `bet` | 下注金額 |
| `win` | 贏得金額（P2P 時固定 0） |
| `valid_bet` | 有效下注 |
| `rake_amount` | 抽水金額（牌桌用） |
| `diff` | 輸贏差額 |
| `username` | 玩家帳號 |
| `player_name` | 玩家暱稱 |
| `game_vendor_name` | 遊戲供應商 |
| `game_code` | 遊戲代碼 |
| `game_type` | 遊戲類型 |
| `detail_url` | 注單詳情 URL |
| `cn_detail_url` | 注單詳情 URL（中文） |
| `bet_time` | 投注時間（RFC3339 UTC-4） |
| `end_time` | 結算時間（RFC3339 UTC-4） |
| `result_time` | 結果時間（RFC3339 UTC-4） |
| `balance_after` | 結算後餘額 |
| `activity` | 活動數據（JSON，僅活動數據才有） |

---

### 07-02 Query player histories — 取得玩家遊戲紀錄

URL：`<API URL>/record/get_player_histories`

請求參數：同 07-01，並多 `username`（必填）。回應結構同 07-01。

---

### 07-05 Query betting record — 透過注單局號查詢單局紀錄

URL：`<API URL>/record/get_single_betting_record`

請求參數：

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | ✅ | 廠商編號 |
| `order_id` | String | ✅ | 交易單號（唯一） |
| `lang` | String | ✗ | 語系，預設 `en` |
| `check_code` | String | ✅ | 檢查碼 |

回應 `data`：單筆注單，欄位同 07-01 histories 單筆，另含 `data_updated_time`

---

## 簽名

### 演算法總覽

| 項目 | 值 |
|---|---|
| Hash 演算法 | `MD5`（輸出 **32 位小寫** hex 字串） |
| 金鑰 | `security_code`（由 LFC888 系統端提供） |
| 明文 | `security_code=<值>&<其餘參數依字母順序>` |
| 排除欄位 | `check_code` 本身、以及 `plat_username`（06-01 特例） |
| 參數連接 | `&`，採 query string 形式（`key=value`） |

### 構成步驟

1. 以 `security_code=<值>` 起頭（固定排最前面）
2. 排除 `check_code` 本身；`plat_username` 若帶入也排除
3. 選填參數若未帶入，不列入組成
4. 其餘實際使用的請求參數依**字母順序** ksort 排列，以 `&` 接在後面
5. 布林值（如 `take_all`）轉成字串 `"true"` / `"false"`
6. 對整串字串做 `MD5`，得到 32 位小寫 hex 即為 `check_code`

### 官方範例（06-01 Launch game）

請求參數：

```
account_id    = 177139407429071
username      = gastima_109
game_code     = ts001
device        = mobile
security_code = 78k7ff8s45aa34b5c0g9f01b11b3dde1
```

明文（`security_code` 起頭 + 其餘依字母序）：

```
security_code=78k7ff8s45aa34b5c0g9f01b11b3dde1&account_id=177139407429071&device=mobile&game_code=ts001&username=gastima_109
```

```
check_code = MD5("security_code=...&account_id=177139407429071&device=mobile&game_code=ts001&username=gastima_109")
           = "8d5f83468cf8d4c0f1d9f0e6d4872bdb"
```

### 交易 API 範例（04-02 Get user balance）

```
security_code=552faed692eccd5bff7312ab413feb4a&account_id=266697458896558&username=user_cwy
→ check_code = "ff124fe6ccb7c4b141e392d0d41c4937"
```

### 特殊規則：`plat_username` 不納入 check_code

`06-01 Launch game` 的選填參數 `plat_username` **不作為 check_code 組成**，即使帶入也必須在計算前排除。

---

## 錯誤碼

### Gaming 端 API（章節 05 ~ 07）

| Code | 訊息 | 說明 |
|---|---|---|
| `1000` | Success. | 請求執行成功 |
| `2001` | Unexpected error. | 發生預期外錯誤 |
| `4001` | Game is under maintenance. | 遊戲維護中 |
| `4002` | Game does not exist. | 遊戲不存在 |
| `4003` | Request too often, please try again later. | 操作頻繁（需間隔 1 秒以上） |
| `4004` | Please use -04:00 UTC format. | 請使用美東時間格式 |
| `4005` | The game does not provide demo | 遊戲不提供試玩 |
| `4051` | Activity time overlap | 活動時間重疊 |
| `4052` | Contains unsupported games({arg}) | 內含不支援遊戲 |
| `4053` | Activity does not exist or has ended | 活動不存在或已結束 |
| `4102` | Your IP address is not allowed to access the API. | IP 未在白名單 |
| `4103` | Check code is wrong. | 驗證碼錯誤 |
| `4104` | Agent account does not exist. | 代理商不存在 |
| `4105` | Agent is locked. | 代理被鎖定 |
| `4201` | Username or Password is wrong. | 玩家帳號或密碼錯誤 |
| `4202` | User account does not exist. | 玩家不存在 |
| `4203` | User account already signed up. | 玩家已註冊 |
| `4204` | Betting record does not exist. | 遊戲紀錄不存在 |
| `4206` | User account is locked. | 玩家被鎖定 |
| `4302` | Invalid parameter format.( {arg} ) | 特定參數格式錯誤 |
| `4303` | Invalid parameter value.( {arg} ) | 特定參數值錯誤 |

### 交易相關 API（章節 04）

| Code | 訊息 | 說明 |
|---|---|---|
| `1000` | Success. | 請求執行成功 |
| `6101` | Withdraw transaction failed. | 提款交易失敗 |
| `6102` | Deposit transaction failed. | 存款交易失敗 |
| `6104` | The external order id already exists. | 外部交易流水號已存在 |
| `6105` | The transaction is not found. | 查無該交易紀錄 |
| `6107` | Invalid deposit amount value. | 存款金額數值錯誤 |
| `6108` | Invalid withdraw amount value. | 提款金額數值錯誤 |
| `6109` | take_all and withdraw_amount conflict | `take_all=true` 與 `withdraw_amount` 衝突 |
| `6110` | User transaction status is locked | 玩家交易狀態被鎖定 |
| `6111` | Failed to get balance | 取餘額失敗 |
| `6112` | Excessive trading frequency | 交易過於頻繁 |
