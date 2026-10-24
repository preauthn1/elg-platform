# WudiSW — 廠商 API 規格

> 原始來源：`API單一錢包對接文件v2.3.8_20250731.pdf`（供應商官方文件）
> 抓取日期：2026-06-03
>
> 我方實作分析、整合陷阱、BetRecord 狀態機、冪等性設計與落地對應見同目錄 `integration.md`。

WudiSW 為多類型遊戲供應商（老虎機、捕魚機、牌桌、對戰棋牌、街機、視訊），採用**單一錢包（Seamless Wallet）**模式接入。vendor_id = 170。玩家餘額由代理平台端維護，遊戲中每次額度變動均透過供應商主動呼叫代理端 API 完成。

兩套 API URL 分開：
- **PPP URL**（代理提供給 WudiSW）：交易回調 API（單錢包），WudiSW 呼叫代理
- **API URL**（WudiSW 提供）：Gaming 端 API（會員相關 / 報表），代理呼叫 WudiSW

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/json; charset=UTF-8`
- **Accept**：`application/json`

### 回應結構（通用）

所有 API（包含代理呼叫 WudiSW、WudiSW 呼叫代理）的回應均以 `status` 物件包裝：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status.code` | Integer | `1000` 成功；其他為錯誤碼 |
| `status.message` | String | 成功時為 `"Success."`；失敗時為錯誤說明 |
| `status.datetime` 或 `status.time` | String | 回應時間戳記，RFC3339 格式，**UTC-4 時區** |
| `data` | Object / Array | 成功時的回傳資料 |

> 注意：回調 API（04-03 / 04-04）的 `status` 欄位中時間鍵為 `datetime`，gaming 端 API（06-xx / 07-xx）為 `time`，兩組欄位名不同，實作時需對應。

### 時區

文件全篇時間均採 **RFC3339 格式、UTC-4** 時區（美東時間）。

### Base URL

| 用途 | 說明 |
|---|---|
| PPP URL（單錢包回調） | 代理提供給 WudiSW；格式範例：`https://example.invalid/wudi` |
| API URL（gaming 端） | 由 WudiSW 客服提供；格式範例：`https://example.invalid` |

---

## 身份驗證

### auth_code（WudiSW 回調驗證）

WudiSW 呼叫代理端（04-03 / 04-04）時，請求中附帶 `auth_code`；代理必須驗證其正確性。

**演算法：**

| 項目 | 值 |
|---|---|
| 演算法 | MD5（32 位小寫） |
| 密鑰 | `md5_key`（WudiSW 提供的交易 API 密鑰） |

**Step 1** — 從請求參數中**排除**以下欄位：`trans_details`、`betform_details`、`auth_code`

**Step 2** — 剩餘參數依**字母順序**排列，組成 `{key}={value}` 並以 `&` 連接（結尾保留 `&`）

**Step 3** — 拼接 `md5_key`：

```
auth_code = MD5(params_string + md5_key)
```

**完整範例（transfer 請求）：**

請求 body：
```json
{
  "username": "test001",
  "tran_id": "test00000338",
  "amount": "10.5",
  "currency": "CNY",
  "transfer_type": "start",
  "game_type": "fish",
  "game_code": "VGSEAFOOD2",
  "trans_details": "{\"round_id\":\"test-00991\",\"trans_amount\":\"10.5\"}",
  "auth_code": "08766af8617443c49c1cf84c602e6a40"
}
```

Step 1 — 排除三欄位，保留並按字母排序：

```
amount=10.5&currency=CNY&game_code=VGSEAFOOD2&game_type=fish&tran_id=test00000338&transfer_type=start&username=test001
```

Step 2 — 拼接 `md5_key`（假設為 `00d3719bcfe9677a27f0e1152a2dff538404ae1a`）：

```
amount=10.5&currency=CNY&game_code=VGSEAFOOD2&game_type=fish&tran_id=test00000338&transfer_type=start&username=test001&00d3719bcfe9677a27f0e1152a2dff538404ae1a
```

Step 3 — `MD5(初始字串) = "08766af8617443c49c1cf84c602e6a40"`

**PHP 對應實作（WudiSWService::validate）：**

```php
public function validate(Request $request): bool
{
    $params = $request->all();
    // 排除三個欄位
    unset($params['trans_details'], $params['betform_details'], $params['auth_code']);

    // 按字母順序排列
    ksort($params);

    // 組成 params_string（結尾帶 & 接 md5_key）
    $paramsString = '';
    foreach ($params as $key => $value) {
        $paramsString .= "{$key}={$value}&";
    }

    // 比對
    return md5($paramsString . $this->infoDetail->MD5_KEY) === $request->input('auth_code');
}
```

> 注意：實作中 `params_string` 結尾保留 `&`，與文件範例（直接接 `md5_key`）效果等同。

### check_code（gaming 端 API 驗證）

gaming 端 API（06-xx / 07-xx，代理呼叫 WudiSW）使用**另一套** `check_code`，密鑰為 `security_code`：

```
初始字串 = "security_code=" + security_code + "&" + 其餘參數（字母排序，排除 check_code）
check_code = MD5(初始字串)
```

**範例**（06-01 Launch game by game_code）：

```
security_code=78k7ff8s45aa34b5c0g9f01b11b3dde1
&account_id=177139407429071
&device=mobile
&game_code=ts001
&username=gastima_109
```

`check_code = MD5(上方字串) = "8d5f83468cf8d4c0f1d9f0e6d4872bdb"`

> 未使用到的選填參數**不需**列入組成。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| WudiSW 回 `4103 Check code is wrong` | `security_code` 錯誤，或字母排序有誤，或選填未用參數被帶入 |
| 代理驗證 `auth_code` 失敗 | `md5_key` 錯誤，或 `trans_details` / `betform_details` 未正確排除 |
| 兩端 MD5 不一致 | 注意 `amount` 是字串類型，`"10.5"` 與 `"10.50"` 會產生不同 hash |

---

## gaming 端狀態碼

代理呼叫 WudiSW gaming API 時收到：

| 代碼 | 訊息 | 說明 |
|---|---|---|
| `1000` | Success. | 請求執行成功 |
| `2001` | Unexpected error. | 發生預期外錯誤 |
| `4001` | Game is under maintenance. | 該遊戲維護中 |
| `4002` | Game does not exist. | 該遊戲不存在 |
| `4003` | Request too often, please try again later. | 操作頻繁，間隔 1 秒以上 |
| `4004` | Please use -04:00 UTC format. | 請使用美東時間格式 |
| `4005` | The game does not provide demo | 遊戲不提供試玩網址 |
| `4102` | Your IP address is not allowed to access the API. | IP 未在白名單 |
| `4103` | Check code is wrong. | 驗證碼錯誤 |
| `4104` | Agent account does not exist. | 代理商不存在 |
| `4105` | Agent is locked. | 代理被鎖定 |
| `4201` | Username or Password is wrong. | 玩家帳號或密碼錯誤 |
| `4202` | User account does not exist. | 該玩家不存在 |
| `4203` | User account already signed up. | 該玩家已註冊 |
| `4204` | Betting record does not exist. | 查詢之遊戲紀錄不存在 |
| `4206` | User account is locked. | 該玩家被鎖定 |
| `4302` | Invalid parameter format.({arg}) | 特定參數格式錯誤 |
| `4303` | Invalid parameter value.({arg}) | 特定參數值錯誤 |
| `4051` | Activity time overlap | 活動時間重疊 |
| `4052` | Contains unsupported games({arg}) | 內含不支援遊戲 |
| `4053` | Activity does not exist or has ended | 活動不存在或已結束 |

## 單錢包交易狀態碼（代理回覆）

WudiSW 呼叫代理時，代理需在回應中填入以下狀態碼：

| 代碼 | 訊息 | 說明 | 使用限制 |
|---|---|---|---|
| `1000` | Success. | 請求執行成功 | — |
| `5001` | Authorization invalid. | 錯誤的驗證 | — |
| `5002` | Bad format parameters ({arg}) | 特定引數格式錯誤 | — |
| `5101` | Insufficient balance. | 玩家餘額不足 | — |
| `5102` | Have unfinished transactions. | 尚有交易處理中（收到重複請求但事務尚未處理時使用） | — |
| `5103` | Player account is suspended. | 玩家帳號被停權 | **`transfer_type=end/refund` 時不可使用** |
| `5104` | Game has been closed. | 遊戲已被關閉 | **`transfer_type=end/refund` 時不可使用** |
| `5105` | Transaction do not exist. | 交易不存在 | **`transfer_type=start` 時不可使用** |
| `5106` | The transction was settled. | 交易已處理（用於重複加扣款行為） | — |
| `5107` | Duplicate tran_id. | 重複的 tran_id | — |
| `5201` | Something wrong. | 發生錯誤（message 需顯示詳細失敗原因） | — |

---

## Seamless Wallet 回調 API（WudiSW → 代理）

WudiSW 主動 POST 至代理端（PPP URL）的以下兩個 endpoint：

| # | 用途 | APIs 路由 |
|---|---|---|
| 1 | 查詢玩家餘額（04-03） | `POST /wudi/get_user_balance` |
| 2 | 額度變動通知（04-04） | `POST /wudi/transfer` |

### 通用約定

#### 請求格式

```http
POST <PPP URL>/get_user_balance
Accept: application/json
Content-Type: application/json; charset=UTF-8
```

請求 body 為 JSON 明文（非加密）。

#### 安全驗證

1. **IP Whitelist**：代理驗證 WudiSW 的來源 IP（`Common::verifyIPWhitelistByVendorId(VendorIdEnum::WUDISW->value)`）
2. **`auth_code`**：代理驗證請求中的 MD5 簽名（見上方身份驗證章節）

#### 回應格式（代理 → WudiSW）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `status.code` | Integer | O | `1000` 成功；失敗時填入對應狀態碼 |
| `status.message` | String | O | 成功時 `"Success."`；失敗時填入錯誤說明 |
| `status.datetime` | String | O | 回應時間戳記，RFC3339 格式（UTC-4） |
| `data` | Object | 視情況 | 成功時依各 endpoint 規範回傳 |

> HTTP 狀態碼必須回傳 **`200`**（即使業務邏輯失敗）。WudiSW 依 `status.code` 判斷成功與否。

---

### 1. Get user balance（04-03）

玩家進入遊戲或每局遊戲開始前，WudiSW 透過此 API 取得玩家在代理端的餘額。

**Request Body（WudiSW → 代理）：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `tran_id` | String | O | 唯一交易編號，回應時須帶回 |
| `username` | String | O | 玩家帳號 |
| `currency` | String | O | 玩家幣別 |
| `auth_code` | String | O | 驗證碼（MD5） |

```jsonc
{
  "tran_id": "GB100136500745811299-GB-1629281328913",
  "username": "test001",
  "currency": "CNY",
  "auth_code": "5cd09ad3f8f15ef1d75d6d0812fc10a8"
}
```

**Success Response（代理 → WudiSW）：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `data.balance` | String | O | 玩家當前餘額（v2.3.7 起由 Float 改為 String） |
| `data.currency` | String | O | 幣別 |
| `data.tran_id` | String | O | 和請求時的 `tran_id` 相同值 |

```jsonc
{
  "status": {
    "code": 1000,
    "message": "Success.",
    "datetime": "2021-05-18T19:03:59-04:00"
  },
  "data": {
    "balance": "300.0",
    "currency": "CNY",
    "tran_id": "GB100136500745811299-GB-1629281328913"
  }
}
```

**Error Response：**

```jsonc
{
  "status": {
    "code": 5002,
    "message": "Bad format parameters (username)",
    "datetime": "2021-05-18T19:03:59-04:00"
  }
}
```

**APIs 實作對應（WudiSWController@getUserBalance）：**

1. 呼叫 `WudiSWService::getBalance($username)`
2. 檢查帳號 `status == 0`（停用）→ 拋出 5103
3. 呼叫 `BetHelper::isMemberFlowLimitAllowed` 確認可下注 → 失敗拋出 5103
4. 回傳 `(float) $balance`（目前回傳 Float，文件 v2.3.7 起要求 String，見 integration.md）

---

### 2. Transfer amount（04-04）

通知代理端玩家的額度變動，所有下注、結算、退款、獎勵均透過此 endpoint 傳達，以 `transfer_type` 區分行為。

**Request Body（WudiSW → 代理）：**

#### 頂層欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `tran_id` | String | O | 每次請求的唯一交易編號，回應時須帶回 |
| `username` | String | O | 玩家帳號 |
| `amount` | String | O | 交易金額，最多小數點後 2 位；`amount > 0` 為加款，`amount < 0` 為扣款，可為 `0` |
| `currency` | String | O | 玩家幣別 |
| `transfer_type` | String | O | 交易動作（見下方說明） |
| `game_type` | String | O | 遊戲類型（見遊戲類型對照表） |
| `game_code` | String | O | 遊戲代碼 |
| `trans_details` | String | O | JSON 格式，交易細節（見下方說明） |
| `betform_details` | String | X | JSON 格式，結算注單內容；`transfer_type=start` 或 `refund` 時無值（`"{}"`) |
| `auth_code` | String | O | 驗證碼 |

#### transfer_type 說明

| 值 | 動作 |
|---|---|
| `start` | 下注扣款（老虎機/街機/視訊）或轉出至牌桌（牌桌/魚機/對戰棋牌）；`amount < 0` |
| `end` | 派彩（老虎機/街機/視訊）或一場遊戲結束回款（牌桌/魚機/對戰棋牌）；`amount > 0` |
| `refund` | 退款（僅退 `start` 的金額，若已收到 `end` 則不會再收到 `refund`） |
| `adjust` | 調帳（系統異常補償，依 `amount` 正負加扣款；若無法調整回 `5201`）**（注意：目前實作未處理此 type）** |
| `reward` | 額外獎金（不搭配 `start`，直接依 `amount` 正負加扣款） |

#### trans_details 欄位（JSON 字串）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `round_id` | String | 注單局號 |
| `order_id` | String | 交易單號（唯一） |
| `trans_amount` | String | 同 `amount` |
| `request_time` | String | 請求時間戳記（僅 `start`、`end`、`refund` 回傳，v2.3.8 新增） |

#### betform_details 欄位（JSON 字串；`transfer_type=start/refund` 時為 `"{}"` 空物件）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `round_id` | String | 注單局號 |
| `order_id` | String | 交易單號（唯一） |
| `transaction_type` | Integer | 交易類型：`1` 遊戲數據、`2` 活動數據、`3` 紅利數據 |
| `special` | Array | 特殊獎項：`["FreeGame"]`、`["BuyFeature"]`、`["BuyFeature","FreeGame"]`；一般遊戲不傳送 |
| `bet` | String | 下注總額度 |
| `win` | String | 派彩（P2P 類型時僅顯示 `0`） |
| `valid_bet` | String | 有效下注金額 |
| `rake_amount` | String | 抽水金額（牌桌遊戲使用） |
| `diff` | String | 玩家輸贏額度 |
| `username` | String | 玩家帳號 |
| `player_name` | String | 玩家名稱 |
| `game_type` | String | 遊戲類型 |
| `game_code` | String | 遊戲代碼 |
| `detail_url` | String | 細單網址 |
| `bet_time` | String | 遊戲開始時間（RFC3339，UTC-4） |
| `end_time` | String | 遊戲結束時間（RFC3339，UTC-4） |
| `result_time` | String | 注單結算時間（RFC3339，UTC-4） |
| `activity` | JSON | 活動相關資訊（非活動數據則無此參數） |

**請求範例（transfer_type = start）：**

```jsonc
{
  "tran_id": "GB100136500745811299-TR-1629281328913",
  "username": "test001",
  "amount": "-100.0",
  "currency": "CNY",
  "transfer_type": "start",
  "game_type": "slot",
  "game_code": "G_001",
  "trans_details": "{\"round_id\":\"KG-example-001\",\"order_id\":\"KG-example-tran001\",\"trans_amount\":\"-100.0\"}",
  "betform_details": "{}",
  "auth_code": "55ca5bc37be4de323e22132707c603fd"
}
```

**請求範例（transfer_type = end）：**

```jsonc
{
  "tran_id": "GB100136500745811299-TR-1629281328914",
  "username": "test001",
  "amount": "200.0",
  "currency": "CNY",
  "transfer_type": "end",
  "game_type": "slot",
  "game_code": "G_001",
  "trans_details": "{\"round_id\":\"KG-example-001\",\"order_id\":\"KG-example-tran001\",\"trans_amount\":\"200.0\"}",
  "betform_details": "{\"round_id\":\"KG-example-001\",\"order_id\":\"KG-example-tran001\",\"tansction_type\":1,\"special\":[\"BuyFeature\",\"FreeGame\"],\"bet\":\"100.0\",\"win\":\"200.0\",\"valid_bet\":\"100.0\",\"rake_amount\":\"0.0\",\"diff\":\"100.0\",\"username\":\"GA_test001\",\"player_name\":\"test001\",\"game_type\":\"slot\",\"game_code\":\"G_001\",\"detail_url\":\"https://test_0S7kLpqhFOPy.com\",\"bet_time\":\"2023-03-26T06:40:49-04:00\",\"end_time\":\"2023-03-26T06:40:50-04:00\",\"result_time\":\"2023-03-26T06:40:51-04:00\"}",
  "auth_code": "41cc5add7af6980228632adc97c4eaeb"
}
```

**請求範例（transfer_type = refund）：**

```jsonc
{
  "tran_id": "GB100136500745811299-TR-1629281328915",
  "username": "test001",
  "amount": "100.0",
  "currency": "CNY",
  "transfer_type": "refund",
  "game_type": "slot",
  "game_code": "G_001",
  "trans_details": "{\"round_id\":\"KG-example-001\",\"order_id\":\"KG-example-tran001\",\"trans_amount\":\"100.0\"}",
  "betform_details": "{}",
  "auth_code": "6598ed6050c504673a2ac32af2983f17"
}
```

**請求範例（transfer_type = reward）：**

```jsonc
{
  "tran_id": "GB100136500745811299-TR-1629281328914",
  "username": "test001",
  "amount": "100.0",
  "currency": "CNY",
  "transfer_type": "reward",
  "game_type": "slot",
  "game_code": "G_001",
  "trans_details": "...",
  "betform_details": "{\"round_id\":\"bonus_challenge_14455_KG-example\",\"order_id\":\"bonus_challenge_14455_KG-example-001\",\"tansction_type\":2,...,\"activity\":{\"activity_id\":\"bc00001\",\"activity_type\":\"bonus_challenge\",\"activity_name\":\"紅利活動\",\"start_at\":\"2023-03-26T06:40:49-04:00\",\"end_at\":\"2023-03-26T06:40:50-04:00\"}}",
  "auth_code": "41cc5add7af6980228632adc97c4eaeb"
}
```

**Success Response（代理 → WudiSW）：**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `data.balance` | String | O | 處理後玩家最新餘額（v2.3.7 起由 Float 改為 String） |
| `data.currency` | String | O | 幣別 |
| `data.tran_id` | String | O | 和請求時的 `tran_id` 相同值 |

```jsonc
{
  "status": {
    "code": 1000,
    "message": "Success.",
    "datetime": "2021-05-18T19:03:59-04:00"
  },
  "data": {
    "balance": "300.0",
    "currency": "CNY",
    "tran_id": "GB100136500745811299-TR-1629281328913"
  }
}
```

**Error Response：**

```jsonc
{
  "status": {
    "code": 5101,
    "message": "Insufficient balance.",
    "datetime": "2021-05-18T19:03:59-04:00"
  }
}
```

### 補充事項（04-05）

#### (1) transfer_type 流程規則

1. 一個 `start` 必搭配一個 `end` 或 `refund`，透過相同的 `order_id` 視為一組。

2. **Free game（Free spin）**：同一 `round_id` 中，free game 的 `start` 請求 `amount` 必為 `0`。

   ```
   {transfer_type=start, amount=-1, round_id=r001, order_id=o001}
   {transfer_type=end,   amount=1,  round_id=r001, order_id=o001}
   {transfer_type=start, amount=0,  round_id=r001, order_id=o002}  # free spin
   {transfer_type=end,   amount=1,  round_id=r001, order_id=o002}  # free spin
   ```

3. **adjust**：可透過 `round_id` 找出對應回合，依 `betform_details` 內容進行改單。發生機率較小，可能發生於伺服器故障、時間差等異常狀況。

4. **reward**：用於額外獎金派發，不會跟 `start` 一起出現，直接依 `amount` 正負加扣款。

#### (2) 錯誤碼使用規範

| transfer_type | 禁止使用的錯誤碼 |
|---|---|
| `start` | `5105`（Transaction do not exist） |
| `start`（且 amount=0） | **只能回 1000**；回其他狀態碼可能導致玩家被限制遊戲 |
| `end`、`refund` | `5103`（玩家帳號被停權）、`5104`（遊戲已被關閉） |
| `adjust` | **只能回 1000**；若無法調整需手動處理才回 `5201`，否則玩家可能被限制遊戲 |

#### (3) 超時與重送規則

- **API 判定超時為 15 秒**：若代理端 `start` 回應超時，WudiSW 將發送 `refund`。
- 當遇到線路異常、請求逾時等非預期錯誤，導致無法結單（`end`）或退款（`refund`）時，WudiSW 會**重送交易**。代理端需根據當前注單狀態回覆：
  - 交易完成：回覆 `1000`（成功）
  - 交易已處理過：回覆 `5106`（交易已處理）
  - 交易不存在：回覆 `5105`（交易不存在）

---

## gaming 端 API（代理 → WudiSW）

> 所有 gaming 端 API 均需帶 `check_code`，演算法見上方身份驗證章節。所有請求需帶 `account_id`。

### 06-01 Launch game by game_code

| 項目 | 值 |
|---|---|
| URI | `<API URL>/agent/user_login` |

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | O | 廠商編號 |
| `username` | String | O | 玩家遊戲帳號（4~50 英數字） |
| `game_code` | String | O | 遊戲代碼 |
| `check_code` | String | O | 驗證碼 |
| `plat_username` | String | X | 運營商用戶暱稱（僅支援部分遊戲）；不作為 check_code 組成 |
| `lang` | String | X | 語系；預設 `en` |
| `back_url` | String | X | 遊戲內返回大廳的回傳網址 |
| `routing` | String | X | 線路選擇：`world`（預設）、`zh-cn`、`id` |
| `cur_type` | Integer | X | 幣別顯示：`0` 法幣、`1` 代幣（預設） |
| `demo` | Boolean | X | `true` 回傳試玩網址 |
| `device` | String | X | `mobile` / `desktop`（預設） |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `data.game_url` | String | 遊戲網址 |

---

### 06-02 Enter the game lobby

| 項目 | 值 |
|---|---|
| URI | `<API URL>/agent/lobby_login` |

**Request：**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `account_id` | String | O | 廠商編號 |
| `username` | String | O | 玩家遊戲帳號（4~50 英數字） |
| `check_code` | String | O | 驗證碼 |
| `lang` | String | X | 語系 |
| `routing` | String | X | 線路選擇 |
| `prior_gametype` | String | X | 大廳優先顯示遊戲類型，預設 `slot` |
| `cur_type` | Integer | X | 幣別顯示 |
| `home_url` | String | X | 大廳內返回貴司平台的網址 |
| `device` | String | X | `mobile` / `desktop`（預設） |

**Response：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `data.usertoken` | String | 玩家進入大廳用的驗證碼 |
| `data.lobby_url` | String | 大廳網址 |

---

### 06-03 User logout

URI：`<API URL>/agent/user_logout`

Request：`account_id`、`username`、`check_code`（均必填）

---

### 06-04 Get game list

URI：`<API URL>/agent/get_gamelist`

Request：`account_id`、`check_code`（均必填）

Response：`data` 以遊戲類型（`slot`、`table`、`fish`…）為 key，每個遊戲含 `game_vendor_name`、`game_code`、`en`（英文名）、`tw`（繁體名）、`cn`（簡體名）、`maintain`（維護中：`true`/`false`）。

---

## 報表 API

> 時間參數均使用 RFC3339 格式、**UTC-4**。查詢不可跨日。每頁最多 200 筆。

### 07-01 Query game histories

URI：`<API URL>/record/get_game_histories`

以結算時間（`result_time`）搜尋，支援 `transaction_type` 篩選（0=全部、1=遊戲、2=活動、3=紅利）。回應含 `histories[]`，每筆含 `round_id`、`order_id`、`bet`、`win`、`valid_bet`、`diff`、`result_time`、`balance_after` 等。

### 07-02 Query player histories

URI：`<API URL>/record/get_player_histories`

同 07-01，但需額外帶 `username` 篩選單一玩家。

### 07-03 Query player finances

URI：`<API URL>/record/get_player_finances`

取得時間區間內玩家報表（聚合統計），回應含 `bet_count`、`bet_amount`、`valid_amount`、`reward_amount`、`profit_amount`、`rake_amount`。

### 07-04 Query Finances

URI：`<API URL>/record/get_finances`

以特定條件取得時間區間內報表，支援 `finances_type` 分組：`101`=username、`102`=username+game_code、`201`=game_code、`202`=game_code+username。

### 07-05 Query betting record

URI：`<API URL>/record/get_single_betting_record`

透過 `order_id` 查詢單局遊戲紀錄。

---

## 遊戲類型對照表

| game_type | 說明 |
|---|---|
| `fish` | 捕魚機 |
| `table` | 牌桌 |
| `p2p` | 對戰棋牌 |
| `slot` | 老虎機 |
| `arcade` | 街機 |
| `live` | 視訊 |

---

## Change Log

| 版本 | 日期 | 重要變更 |
|---|---|---|
| v2.0.0 | 2021/09/14 | 初版 |
| v2.1.0 | 2021/10/29 | 授權碼生成方式改版（auth_code 機制） |
| v2.2.0 | 2022/12/12 | 移除 Authorization Header；改用 `auth_code`；`balance` 改為字串類型 |
| v2.2.2 | 2023/03/27 | 新增 `transfer_type=adjust` |
| v2.2.5 | 2023/09/08 | 新增狀態碼 5106、5107 |
| v2.2.6 | 2024/03/21 | `cn_routing` 改為 `routing` |
| v2.3.3 | 2024/11/12 | 新增 `transfer_type=reward`；betform_details 新增 `transaction_type`、`activity` |
| v2.3.4 | 2024/12/04 | 06-01 新增 `plat_username` |
| v2.3.7 | 2025/05/08 | 多支 API 新增 `Special` 回應參數、交易後餘額 `balance_after` |
| v2.3.7 | 2025/07/18 | 04-03 / 04-04 回應 `balance` 從 Float 改為 String |
| v2.3.8 | 2025/07/31 | 04-04 Transfer amount 回應新增 `request_time` |
