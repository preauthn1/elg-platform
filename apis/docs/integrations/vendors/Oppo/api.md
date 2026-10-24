# OPPO — 第三方 API 規格書

> **原始來源**：`api_doc_zh.docx.pdf`（OPPO 接口文檔 中文版 V1.1，更新於 2025-07-27）
> 來源路徑：`/Users/rb/Workspace/遊戲商/169. Oppo/`
> 抓取日期：2026-06-03
> 本檔為忠實的第三方 API 規格重述。我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

---

## 通用約定

### 角色 / 方向

本文件描述**代理（Merchant）主動呼叫 OPPO 的所有 API**。OPPO 為 Transfer Wallet（轉帳錢包）模式：玩家餘額由 OPPO 端保管，代理需透過 `transferin` / `transferout` 主動轉帳；注單資料透過 `getGameLog` / `getGameSummary` 主動拉取。**OPPO 不主動回呼代理（無 seamless callback）。**

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | 所有 API 一律 `POST` |
| 傳輸協定 | HTTPS |
| Content-Type | `application/json` |
| 驗證方式 | 每個請求都帶 `Hash`（MD5 簽名欄位，見「簽名演算法」節） |

### 接入資訊（須向 OPPO 代理索取）

官方文件明列三項須向代理索取（文件本身僅提供佔位符）：

| 項目 | 文件佔位符 | 說明 |
|---|---|---|
| API Endpoint（Base URL） | `{{API_ENDPOINT}}` | 正式 Base URL，文件未提供具體值 |
| API Secret Key | — | 簽章金鑰 |
| Agent Name | — | 代理商標識（每個請求的 `Agent` 欄位） |

### 通用回應格式

成功：

```jsonc
{
  "data": {
    "status": 1,
    "message": "Success"
  },
  "error": null
}
```

失敗（文件未明列失敗結構，見「錯誤碼」節）：

```jsonc
{
  "data": null,
  "error": { /* 錯誤資訊，結構官方未定義 */ }
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `data` | Object \| Array \| Null | 成功時的回傳資料 |
| `data.status` | Integer | 業務狀態碼，`1` 表示成功 |
| `data.message` | String | 描述訊息（例 `"Success"`、`"Create Account Success"`） |
| `error` | Object \| Null | 失敗時的錯誤資訊；成功時為 `null` |

> 報表類 API（getGameLog / getGameSummary）`data` 為陣列並附 `pagination`，無頂層 `error` 欄位。

### Base URL

文件以 `{{API_ENDPOINT}}` 全程佔位，未提供正式 / 測試具體域名，須向 OPPO 代理索取。

### 時區

文件未明示時區。範例日期格式：`2025-06-16 00:00:00`，推測為 UTC+8 或以廠商部署地區為準（待確認）。

### 安全建議（官方原文摘要）

- 透過 HTTPS 發送請求，確保資料傳輸安全。
- 密碼與敏感資訊建議加密或採其他安全措施。
- 接口請求頻率應遵循提供方的限制，避免頻繁或批量請求導致服務拒絕。

---

## 接口總覽

| # | 接口名 | Path | HTTP Method |
|---|---|---|---|
| 1 | 創建玩家（createplayer） | `/api/createplayer` | POST |
| 2 | 停用玩家（disableplayer） | `/api/disableplayer` | POST |
| 3 | 查詢玩家信息（playerinfo） | `/api/playerinfo` | POST |
| 4 | 轉入金額（transferin） | `/api/transferin` | POST |
| 5 | 轉出金額（transferout） | `/api/transferout` | POST |
| 6 | 玩家登錄（accountlogin） | `/api/accountlogin` | POST |
| 7 | 玩家下注記錄（getGameLog） | `/api/getGameLog` | POST |
| 8 | 玩家輸贏記錄（getGameSummary） | `/api/getGameSummary` | POST |

---

## 簽名演算法

### 總覽

| 項目 | 值 |
|---|---|
| 雜湊演算法 | `MD5` |
| 大小寫處理 | 拼接後的明文整串先 `strtolower()` 再 `md5()` |
| 固定鹽值 | 字串常量 `8888`（夾在業務參數與 API Secret Key 之間） |
| 密鑰來源 | API Secret Key（向 OPPO 代理索取） |
| 輸出 | 32 字元小寫 hex 字串 |

官方通用範本（PHP）：

```php
$hashed_string = md5(strtolower($agent_name . $player_id . "8888" . $api_secret_key));
```

### 各 API 的 Hash 明文拼接順序

每個 API 拼接欄位**不完全相同**（官方各節「簽名字段參數 (以PHP為例子)」）：

| # | API | 明文拼接（依序 concat → `strtolower` → `md5`） |
|---|---|---|
| 1 | createplayer | `$agent_name . $password . $player_username . "8888" . $api_secret_key` |
| 2 | disableplayer | `$agent_name . $player_id . "8888" . $api_secret_key` |
| 3 | playerinfo | `$agent_name . $player_id . "8888" . $api_secret_key` |
| 4 | transferin | `$agent_name . $player_id . $amount . "8888" . $api_secret_key` |
| 5 | transferout | `$agent_name . $player_id . $amount . "8888" . $api_secret_key` |
| 6 | accountlogin | `$agent_name . $player_id . "8888" . $api_secret_key` |
| 7 | getGameLog | `$agent_name . $player_id . "8888" . $api_secret_key` |
| 8 | getGameSummary | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 金額（Amount）預處理（transferin / transferout）

`transferin` / `transferout` 計算 Hash 前須先對金額正規化：

```php
$amount = abs(number_format((float) $amount, 2, '.', ''));
$hashed_string = md5(strtolower($agent_name . $player_id . $amount . "8888" . $api_secret_key));
```

- `number_format(..., 2, '.', '')`：固定 2 位小數、不使用千分位分隔。
- `abs(...)`：取絕對值（金額不帶負號）。

> **歷史備註**：2025-09-09 OPPO 一度要求加密時 `Amount` 去掉多餘的 0 與小數點；2026-02-24 OPPO 改回「保持原樣」。目前官方規格（V1.1）採 `number_format(..., 2)` 固定兩位小數。

---

## 1. 創建玩家（createplayer）

建立新的玩家帳戶。系統會自動為玩家 ID 加上 `Agent` 前綴並回傳完整 `Playerid`，**後續所有 API 必須使用此回傳的 `Playerid`**。

| 項目 | 值 |
|---|---|
| Path | `/api/createplayer` |
| Method | POST |
| 簽名拼接 | `$agent_name . $password . $player_username . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識，例 `"OPPOTESTAGENT"` |
| `Password` | String | ✅ | 玩家密碼，建議符合強密碼要求 |
| `Name` | String | ✅ | 玩家暱稱或真實姓名 |
| `Tel` | String | ✅ | 玩家手機號碼或聯絡方式 |
| `Memo` | String | ❌ | 備註信息，可為空 |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Password": "123456#",
  "Name": "Jason",
  "Tel": "0123456789",
  "Memo": "test test",
  "Hash": "12451235125"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `Playerid` | String | 含 Agent 前綴的完整玩家 ID（例 `OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e`） |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Create Account Success"` |

```jsonc
{
  "data": {
    "Playerid": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e",
    "status": 1,
    "message": "Create Account Success"
  },
  "error": null
}
```

> ⚠️ 簽名拼接中的 `$player_username` 對應請求參數的何欄位（`Name` 或代理自帶的玩家代號）官方未明確標示，見 `integration.md` gaps。

---

## 2. 停用玩家（disableplayer）

停用或凍結指定玩家帳戶，使其無法繼續操作。

| 項目 | 值 |
|---|---|
| Path | `/api/disableplayer` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 要停用的玩家 ID（createplayer 回傳值） |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "test",
  "Playerid": "OPPOTESTAGENT-b124125xxx",
  "Hash": "test"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `Playerid` | String | 已停用的完整玩家 ID |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Disable Account Success"` |

```jsonc
{
  "data": {
    "Playerid": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e",
    "status": 1,
    "message": "Disable Account Success"
  },
  "error": null
}
```

---

## 3. 查詢玩家信息（playerinfo）

查詢指定玩家的帳戶信息，包含餘額、狀態等。

| 項目 | 值 |
|---|---|
| Path | `/api/playerinfo` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 玩家 ID（createplayer 回傳值） |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "test",
  "Playerid": "OPPOTESTAGENT-b124125xxx",
  "Hash": "test"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `Playerbalance` | Number | 玩家在 OPPO 端的餘額 |
| `PlayerName` | String | 玩家名稱 |
| `PlayerMobile` | String | 玩家聯絡方式 |
| `PlayerMemo` | String | 備註 |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Success"` |

```jsonc
{
  "data": {
    "Playerbalance": 0,
    "PlayerName": "chong123",
    "PlayerMobile": "11111111",
    "PlayerMemo": "test test",
    "status": 1,
    "message": "Success"
  },
  "error": null
}
```

---

## 4. 轉入金額（transferin）

為玩家帳戶轉入（存入）指定金額（本地 → OPPO）。

| 項目 | 值 |
|---|---|
| Path | `/api/transferin` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . $amount . "8888" . $api_secret_key`（`$amount` 先做 `abs(number_format((float)$amount, 2, '.', ''))`） |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 玩家 ID（createplayer 回傳值） |
| `Amount` | Number | ✅ | 轉入金額，支持小數；**只接受兩位小數** |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Playerid": "OPPOTESTAGENT-b124125xxx",
  "Amount": 5.98,
  "Hash": "asasfasfas"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `Playerbalance` | Number | 轉入後的玩家餘額（官方範例疑為佔位值，見 `integration.md` gaps） |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Success"` |

```jsonc
{
  "data": {
    "Playerbalance": 5.98,
    "status": 1,
    "message": "Success"
  },
  "error": null
}
```

---

## 5. 轉出金額（transferout）

從玩家帳戶轉出（提現）指定金額（OPPO → 本地）。

| 項目 | 值 |
|---|---|
| Path | `/api/transferout` |
| Method | POST |
| 簽名拼接 | 同 transferin（`$agent_name . $player_id . $amount . "8888" . $api_secret_key`） |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 玩家 ID（createplayer 回傳值） |
| `Amount` | Number | ✅ | 轉出金額；**只接受兩位小數** |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Playerid": "OPPOTESTAGENT-b124125xxx",
  "Amount": 5.98,
  "Hash": "asfasfasf"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `Playerbalance` | Number | 轉出後的玩家餘額 |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Success"` |

```jsonc
{
  "data": {
    "Playerbalance": 5.98,
    "status": 1,
    "message": "Success"
  },
  "error": null
}
```

---

## 6. 玩家登錄（accountlogin）

玩家透過代理商登入遊戲或平台，回傳進入遊戲的跳轉鏈接（launch URL）。

| 項目 | 值 |
|---|---|
| Path | `/api/accountlogin` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 玩家 ID（createplayer 回傳值） |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Playerid": "OPPOTESTAGENT-b124125xxx",
  "Hash": "asfasfasf"
}
```

### 回應參數

| `data.` 欄位 | 類型 | 說明 |
|---|---|---|
| `launch_url` | String | 進入遊戲的完整 URL（含 token） |
| `status` | Integer | `1` 為成功 |
| `message` | String | 例 `"Success"` |

```jsonc
{
  "data": {
    "launch_url": "https://oppo99.club/lobby?Playerid=OPPOTESTAGENT-b124125xxx&token=[REDACTED]",
    "status": 1,
    "message": "Success"
  },
  "error": null
}
```

---

## 7. 玩家下注記錄（getGameLog）

提取玩家下注記錄（**單注層級**），分頁回傳。用於每日對帳。

| 項目 | 值 |
|---|---|
| Path | `/api/getGameLog` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ✅ | 玩家 ID（createplayer 回傳值） |
| `StartDate` | String | ✅ | 開始日期，格式 `2025-06-16 00:00:00` |
| `EndDate` | String | ✅ | 結束日期，格式 `2025-06-30 23:59:59` |
| `PageIndex` | Number | ✅ | 頁數（從 1 起） |
| `PageSize` | Number | ✅ | 每頁數量 |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Playerid": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e",
  "StartDate": "2025-06-01 00:00:00",
  "EndDate": "2025-07-15 23:59:59",
  "PageIndex": 2,
  "PageSize": 10,
  "Hash": "8d0735b23b77d481bed820017d303323"
}
```

### 回應參數

回應 `data` 為陣列，並帶 `pagination`：

| `data[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `betoppo_username` | String | OPPO 端玩家帳號（含代理前綴小寫） |
| `room_name` | String | 桌台 / 房間名稱 |
| `betoppo_bet_id` | String | 注單 ID（唯一識別） |
| `betoppo_method` | String | 下注方式（例 `single`） |
| `betoppo_number` | String | 下注標的號碼 |
| `betoppo_amount` | String | 下注金額 |
| `betoppo_settle_amount` | String | 結算（派彩）金額 |
| `betoppo_validbet_amount` | String | 有效投注 |
| `betoppo_bet_before_usercredit` | String | 下注前玩家餘額 |
| `betoppo_bet_after_usercredit` | String | 下注後玩家餘額 |
| `betoppo_settle_before_usercredit` | String | 結算前玩家餘額 |
| `betoppo_settle_after_usercredit` | String | 結算後玩家餘額 |
| `betoppo_round` | String | 回合 ID |
| `betoppo_betdatetime` | String | 下注時間 |
| `betoppo_settledatetime` | String | 結算時間 |
| `betoppo_winjackpot` | Integer | 是否中 jackpot（`0` = 否） |
| `betoppo_status` | String | 注單狀態（例 `"Settled"`；完整列舉官方未載，見錯誤碼節） |
| `user_apiplayerkey` | String | 對應 createplayer 回傳的 `Playerid` |

`pagination` 欄位：

| 欄位 | 說明 |
|---|---|
| `total` | 總筆數 |
| `per_page` | 每頁筆數 |
| `current_page` | 當前頁碼 |
| `last_page` | 最後頁碼 |

```jsonc
{
  "data": [
    {
      "betoppo_username": "oppotestagent_chong123",
      "room_name": "Room 2",
      "betoppo_bet_id": "OP250000000110",
      "betoppo_method": "single",
      "betoppo_number": "9",
      "betoppo_amount": "10.00",
      "betoppo_settle_amount": "0.00",
      "betoppo_validbet_amount": "10.00",
      "betoppo_bet_before_usercredit": "100.00",
      "betoppo_bet_after_usercredit": "90.00",
      "betoppo_settle_before_usercredit": "90.00",
      "betoppo_settle_after_usercredit": "90.00",
      "betoppo_round": "OPRB250000000237",
      "betoppo_betdatetime": "2025-07-14 09:47:33",
      "betoppo_settledatetime": "2025-07-14 11:05:29",
      "betoppo_winjackpot": 0,
      "betoppo_status": "Settled",
      "user_apiplayerkey": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-sd123"
    }
  ],
  "pagination": {
    "total": 9,
    "per_page": 1,
    "current_page": 1,
    "last_page": 9
  }
}
```

> 範例中 `per_page: 1` 與請求 `PageSize: 10` 不一致，疑為官方範例資料自身問題，實際以回應為準。

---

## 8. 玩家輸贏記錄（getGameSummary）

提取玩家輸贏彙總記錄，分頁回傳。

| 項目 | 值 |
|---|---|
| Path | `/api/getGameSummary` |
| Method | POST |
| 簽名拼接 | `$agent_name . $player_id . "8888" . $api_secret_key` |

### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Agent` | String | ✅ | 代理商標識 |
| `Playerid` | String | ❌ | 玩家 ID；可省略以查詢全部玩家，但全查時 `$player_id` 簽名值待確認（見 `integration.md` gaps） |
| `StartDate` | String | ✅ | 開始日期，格式 `2025-06-16 00:00:00` |
| `EndDate` | String | ✅ | 結束日期，格式 `2025-06-30 23:59:59` |
| `PageIndex` | Number | ✅ | 頁數 |
| `PageSize` | Number | ✅ | 每頁數量 |
| `Hash` | String | ✅ | 請求簽名 |

### 請求範例

```jsonc
{
  "Agent": "OPPOTESTAGENT",
  "Playerid": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e",
  "StartDate": "2025-06-01 00:00:00",
  "EndDate": "2025-07-15 23:59:59",
  "PageIndex": 2,
  "PageSize": 10,
  "Hash": "8d0735b23b77d481bed820017d303323"
}
```

### 回應參數

回應 `data` 為陣列，並帶 `generated_datetime` 與 `pagination`：

| `data[].` 欄位 | 類型 | 說明 |
|---|---|---|
| `betoppo_username` | String | OPPO 端玩家帳號 |
| `user_apiplayerkey` | String \| Null | 對應 createplayer `Playerid`（部分舊資料可能為 `null`） |
| `total_amount` | String | 總下注金額 |
| `total_settle` | String | 總結算（派彩）金額 |
| `total_validbet` | String | 總有效投注 |
| `total_winlose` | String | 總輸贏（負值為玩家輸） |

頂層欄位：

| 欄位 | 說明 |
|---|---|
| `generated_datetime` | 報表產生時間 |
| `pagination.total` | 總筆數 |
| `pagination.per_page` | 每頁筆數 |
| `pagination.current_page` | 當前頁碼 |
| `pagination.last_page` | 最後頁碼 |

```jsonc
{
  "data": [
    {
      "betoppo_username": "oppotestagent_jason",
      "user_apiplayerkey": null,
      "total_amount": "50.00",
      "total_settle": "0.00",
      "total_validbet": "50.00",
      "total_winlose": "50.00"
    },
    {
      "betoppo_username": "oppotestagent_chong123",
      "user_apiplayerkey": "OPPOTESTAGENT-b3724634-fcb9-4953-8df3-6813e0bfc88e",
      "total_amount": "380.00",
      "total_settle": "540.00",
      "total_validbet": "380.00",
      "total_winlose": "-160.00"
    }
  ],
  "generated_datetime": "2025-07-31 00:31:01",
  "pagination": {
    "total": 2,
    "per_page": 10,
    "current_page": 1,
    "last_page": 1
  }
}
```

---

## 錯誤碼

> ⚠️ 官方文件（V1.1）**未提供任何錯誤碼列舉表**：文件僅示範 `data.status: 1`（成功）與 `error: null`，未列舉失敗時 `data.status` 的具體取值或 `error` 物件的結構。

| 已知值 | 含義 |
|---|---|
| `data.status = 1` | 成功 |
| `data.status ≠ 1` | 失敗（具體失敗原因見 `error` 欄位，官方未定義） |
| `error ≠ null` | 失敗（結構官方未說明） |

**注單狀態（`betoppo_status`）**：官方範例中僅見 `"Settled"`，未提供完整狀態列舉（如 `pending` / `void` / `refund`），需向 OPPO 客服確認。

**HTTP 狀態碼**：文件未說明 4xx/5xx 行為。

---

## Sources

- `api_doc_zh.docx.pdf`（OPPO 接口文檔 中文版 V1.1，2025-07-27），`/Users/rb/Workspace/遊戲商/169. Oppo/`，2026-06-03 讀取。
- 交叉驗證：`app/Vendors/Oppo/Driver.php`、`config/vendor.php`、`app/Vendors/Models/OppoAccount.php`、`app/Vendors/Models/OppoBetRecord.php`。
