# RedMario / Mario Club — API 規格

> 來源：`MarioClubMan 2.0.pdf`（24/02/2025，**主要事實來源**）、`RedMarioMan 1.1.pdf`（2/3/2020，對照參考）、`測試資料.txt`（demo 後台帳密）。整理日期：2026-06-03。
>
> 本廠商為 **Transfer Wallet（轉帳錢包）** 模式（vendor_id=32，`VendorIdEnum::RED_MARIO`）；官方文件同時提供 Seamless 規格，本平台**未實作**。本平台分析、陷阱與落地對應見同目錄 `integration.md`。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/x-www-form-urlencoded`（實作以 `Http::...->asForm()->post()` 送出）
- **回應格式**：`application/json`
- **伺服器時區**：(GMT+08:00) Kuala Lumpur / Singapore

### Base URL

| 環境 | URL |
|---|---|
| 測試（v2.0） | `https://demoapi.marioclub88.online` |
| 測試（v1.1） | `https://example.invalid` |
| 正式（v1.1） | `https://example.invalid` |
| 測試後台 BO | `https://demoag.redmario.club` |

> ⚠️ v2.0 文件**僅列測試環境**，正式 Base URL 以對接時客服提供為準。所有 endpoint 皆以「Base URL + `/api/acc/...`」組成；**Base URL 尾端必須帶 `/`**（Driver 不補斜線）。

### 通用請求欄位

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `api_id` | String | 50 | ✅ | API 帳戶名，對應 `vendor_infos.info.API_ID` |
| `timestamp` | long | 13 | ✅ | UNIX 時間戳（文件標 13 位毫秒；實作以 `date('U')` 產生 10 位秒級，見 `integration.md` 陷阱 §1） |
| `sign` | String | 32 | ✅ | `md5(api_id + timestamp + apiKey)`，見下方「簽名」章節 |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | int | `0` 成功，非 0 為錯誤（見下方錯誤碼表） |
| `msg` | String | 回傳訊息（成功為 `Success.`，失敗為錯誤描述） |

---

## Merchant API

### 帳戶（Account）

#### 1. 生成新帳戶 `/api/acc/created`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 要生成的玩家帳號名 |
| `password` | String | 50 | ❌ | 玩家密碼（最少 4 字母或數字）— **v2.0 新增** |

回應：`{"code":0,"msg":"Success."}`

---

#### 2. 更新帳戶狀態 `/api/acc/update`

啟用 / 停用 / 踢出玩家帳號。

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 目標玩家帳號名 |
| `status` | String | 50 | ✅ | v1.1：`active` / `inactive`；**v2.0 新增 `kick`** |
| `password` | String | 50 | ❌ | 玩家密碼（最少 4 字母或數字）— **v2.0 新增** |

回應：`{"code":0,"msg":"Success"}`

---

#### 3. 查詢所有帳戶 `/api/acc/list`

分頁列出代理底下所有玩家帳戶與餘額。

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `page_no` | int | 11 | ✅ | 頁面號碼 |
| `record_count` | int | 11 | ✅ | 每頁筆數 |

回應（`userWalletDTO` 陣列）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `name` | String | 帳戶名 |
| `status` | String | 帳戶狀態（`active` / `inactive`） |
| `amount` | Decimal(18,4) | 帳戶目前餘額 |

```jsonc
{"code":0,"msg":"Success.","userWalletDTO":[
  {"name":"testMember","status":"active","amount":200.0000},
  {"name":"testMember2","status":"active","amount":0.0000}
]}
```

> API 訪問延遲限制：30s

---

### 轉帳錢包（Transfer Wallet）

#### 4. ★ 查詢帳戶餘額 `/api/acc/getBalance`

> Driver：`getBalance()`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 要查詢的玩家帳號名 |

回應：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | String | 帳戶目前餘額 |

```jsonc
{"code":0,"msg":"Success","balance":0.0000}
```

> 實作：`code != 0` 拋 `ApiException`；`code` 缺失/非數字拋 format error；成功回傳 `(string) $json['balance']`。

---

#### 5. ★ 帳戶存款 / 取款 `/api/acc/updateBalance`

> Driver：`transfer()` ← `transferToVendor` / `transferToLocal`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 玩家帳號名 |
| `amount` | Numeric(18,2) | — | ✅ | 充值 / 取款數目 |
| `action` | String | 50 | ✅ | `deposit`（存入 RedMario）或 `withdraw`（從 RedMario 取出） |
| `reference_id` | String | 255 | ❌ | 充值認證碼（我方 `transactionNo`，供查單對帳） |

回應：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `balance` | String | 操作後餘額 |
| `transId` | Long | 充值序列碼 |
| `transDate` | String | 時間戳 |
| `transDateStr` | String | 交易時間 |

```jsonc
{"code":0,"msg":"Success.","balance":100.0000,"transId":1,"transDate":1548950400000,"transDateStr":"01/02/2019 00:00:00"}
```

> ⚠️ **v2.0 新增 `code=109`（Wallet lock）**：玩家正進行 MARIO CLUB 促銷活動時，錢包被鎖定禁止提款與入款。現行實作對 `code != 0` 一律回 `FAILED`（含 `109`），見 `integration.md` 陷阱 §2。

---

#### 6. ★ 查玩家上下分明細 `/api/acc/getTransactionLog`

> Driver：`checkTransferStatus()`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 玩家帳號名 |
| `date_from` | String | 10 | ❌ | 起始日期 `dd/MM/yyyy HH:mm:ss` |
| `date_to` | String | 10 | ❌ | 結束日期 `dd/MM/yyyy HH:mm:ss` |
| `trans_id` | long | 13 | ❌ | 充值序列碼 |
| `reference_id` | String | 255 | ❌ | 充值認證碼（= 我方 `transactionNo`，精準過濾） |

回應（`walletLogDTO` 陣列）：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `amount` | String | 金額（正為 deposit，負為 withdraw） |
| `transDate` | String | 時間戳 |
| `transDateStr` | String | 交易時間 |
| `transType` | String | 交易類型（`deposit` / `withdraw`） |

```jsonc
{"code":0,"msg":"Success.","walletLogDTO":[
  {"amount":100.00,"transDate":1551240689000,"transDateStr":"27/02/2019 12:11:29","transType":"deposit"},
  {"amount":-100.00,"transDate":1551240710000,"transDateStr":"27/02/2019 12:11:50","transType":"withdraw"}
]}
```

> 實作：`code==0` 且 `walletLogDTO` 非空 → `SUCCESS`，否則 `FAILED`；非 JSON → `TIMEOUT`。未核對 `amount` 方向/金額（見 `integration.md` Gaps §3）。
> API 訪問延遲限制：30s

---

### 遊戲（Game）

#### 7. ★ 遊戲登入轉跳頁面 `/api/acc/gameLogIn`

> Driver：`getGameUrl()`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 玩家帳號名 |
| `game_code` | String | 10 | ✅ | 遊戲代碼（`GM001` = 遊戲大廳） |
| `language` | String | 10 | ✅ | `en` / `zh` / `idn` / `th` / `vi` |
| `return_url` | String | 255 | ❌ | 遊戲返回鍵跳轉 URL |

回應：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gameUrl` | String | 轉跳遊戲網址（PC 與 mobile 回同一 URL） |

```jsonc
{"code":0,"msg":"Success.","gameUrl":"http://.../gameMenu/game/menu?param=..."}
```

> 語言對映（`Driver::getLanguage()`）：`ZH_TW`/`ZH_CN` → `zh`；`ID_ID` → `idn`；`TH_TH` → `th`；`VI_VN` → `vi`；其餘 fallback `en`。

---

#### 8. 索取遊戲列表 `/getGames`

> 注意：此 endpoint 為 Base URL 後直接接 `/getGames`（非 `/api/acc/`）。

只需通用欄位（`api_id`/`timestamp`/`sign`）。

回應（`gameList` 陣列）：

| 欄位 | 類型 | 長度 | 說明 |
|---|---|---|---|
| `gameCode` | String | 30 | 遊戲代碼 |
| `gameName` | String | 50 | 遊戲名稱 |
| `gameType` | String | 30 | 遊戲種類（如 `SLOT`） |
| `special` | String | 30 | 新/火熱遊戲標籤（如 `new`） |
| `dtReleased` | String | 30 | 出品日期 |
| `language` | Array | — | 其它語言名稱（`{code,name}`） |
| `gameIconUrl` | String | 255 | 遊戲圖標連結（**v2.0 新增**） |

遊戲標籤圖（v2.0）：`https://asset.marioclub888.online/images/thumb/square/[GAME_CODE].jpg`（中文加 `_zh`）。

> API 訪問延遲限制：1s

---

### 報表（Report）

#### 9. 每日輸贏總帳 `/api/acc/getWinLose`

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ❌ | 玩家帳號名；空 = 代理帳戶彙總 |
| `date_from` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss` |
| `date_to` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss` |

回應（`reportDTO` 陣列）：`reportDate`、`reportDateStr`、`winLose`、`bonusMember`（玩家獎勵金，本公司承擔）、`bonusAgent`（代理獎勵金，本公司承擔）、`currency`。

> API 訪問延遲限制：30s

---

#### 10. 玩家遊戲記錄 `/api/acc/getGameLog`

逐筆遊戲記錄。

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 玩家帳號名（空 = 所有玩家） |
| `date_from` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss` |
| `date_to` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss`（**v1.1 限 72 小時；v2.0 限 24 小時**） |

回應（`userGameLogDTO` 陣列）：

| 欄位 | 說明 |
|---|---|
| `logId` | 記錄 ID |
| `loginId` | 玩家帳號 |
| `gameRoundId` | 遊戲回合 ID |
| `gameCode` / `gameName` | 遊戲代碼 / 名稱 |
| `createdDate` / `createdDateStr` | 建立時間 |
| `currencyCode` | 貨幣 |
| `betAmount` | 投注金額 |
| `winAmount` | 贏取金額 |
| `jackpotAmount` | 彩金金額 |
| `totalWinLoseAmount` | 總輸贏金額 |
| `bonusMemberAmount` | 玩家獎勵金 |
| `bonusAgentAmount` | 代理獎勵金（本公司承擔，以 PT% 分配） |

> API 訪問延遲限制：30s

---

#### 11. 玩家遊戲記錄合集 `/api/acc/getGameLogGroup`（v2.0）

**v2.0 新增**；分頁彙總遊戲記錄，每頁最多 **1500 筆**。

| 欄位 | 類型 | 長度 | 必填 | 說明 |
|---|---|---|---|---|
| `user_id` | String | 50 | ✅ | 玩家帳號名（空 = 所有玩家） |
| `date_from` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss`（**24 小時記錄限制**） |
| `date_to` | String | 10 | ✅ | `dd/MM/yyyy HH:mm:ss` |
| `page` | long | 20 | ✅ | 頁碼，從 1 起 |

回應（`userGameLogDTO` 陣列 + `page`/`pageSize`）：欄位同 `getGameLog`（不含 `logId`/`createdDate`/`createdDateStr`）。

> API 訪問延遲限制：30s

---

## 附錄：語言代碼

| 語言 | 代碼 |
|---|---|
| English | `en` |
| Chinese | `zh` |
| Indonesian | `idn` |
| Thai | `th` |
| Vietnamese | `vi` |

---

## 附錄：API 訪問延遲限制（v2.0）

連續呼叫同一 API 需間隔的秒數：

| API | 延遲 |
|---|---|
| `/api/acc/getWinLose` | 30s |
| `/api/acc/list` | 30s |
| `/api/acc/getTransactionLog` | 30s |
| `/api/acc/getGameLog` | 30s |
| `/api/acc/getGameLogPage` | 3s（v2.0 新增，規格未公開） |
| `/api/acc/getGameLogGroup` | 30s（v2.0 新增） |
| `/api/acc/created` | 1s |
| `/api/acc/update` | 1s |
| `/getGames` | 1s |

---

## 簽名

### 演算法

```
sign = md5(api_id + timestamp + apiKey)
```

三者皆為字串，直接首尾相接後取 MD5，中間**無分隔符**；輸出 32 字元十六進位字串。

| 欄位 | 說明 |
|---|---|
| `api_id` | API 帳戶名（`vendor_infos.info.API_ID`） |
| `timestamp` | UNIX 時間戳；文件標 13 位毫秒，實作以 `date('U')` 產生 10 位秒級 |
| `apiKey` | 簽章金鑰（`vendor_infos.info.API_KEY`，後台「API 設定」可查） |

### 官方範例（取自文件 Seamless 章節）

```
api_id=API_ID
timestamp=1605970770769
sign=256941f27448e3edbe79289017a19a7e
```

即 `md5("API_ID" + "1605970770769" + apiKey)`（範例 `apiKey` 未公開，無法逐字重算）。

### 本平台實作（`Driver.php`）

```php
$time = date('U');  // 秒級時間戳字串（10 位）

$data['api_id']    = $this->apiId;
$data['timestamp'] = $time;
$data['sign']      = $this->sign($time);

private function sign($time)
{
    return md5($this->apiId . $time . $this->apiKey);
}
```

> 每個 API 都以當下 `date('U')` 重新產生 `timestamp` 並計算 `sign`。

---

## 錯誤碼

### HTTP 狀態碼

| Code | 訊息 | 說明 |
|---|---|---|
| `400` | Bad Request | 錯誤的請求 |
| `401` | UNAUTHORIZED | 未經授權 |
| `403` | FORBIDDEN | 被禁止 |
| `404` | NOT FOUND | 未找到 |
| `500` | INTERNAL SERVER ERROR | 內部服務器錯誤 |

### API 回傳碼（`code`）

| Code | 訊息 | 說明 |
|---|---|---|
| `0` | success. | 成功 |
| `1` | fail. | 失敗 |
| `100` | Account Exits. | 帳戶名已存在 |
| `101` | Not Found. | 資料不存在 |
| `102` | Invalid Action. | 訪問無效（action 參數錯誤） |
| `103` | Insufficient Balance. | 餘額不足 |
| `104` | Missing Parameter. | 缺少參數 |
| `105` | Exceed max record allow. | 超過記錄允許上限 |
| `106` | Invalid account type. | 帳戶類型無效 |
| `107` | Unauthorized request. | 未經授權的請求 |
| `108` | Invalid Game Code. | 無效的遊戲代碼 |
| `109` | Wallet lock. | 錢包鎖定（玩家正進行 MARIO CLUB 促銷活動，禁止提款與入款；**v2.0 新增**） |
