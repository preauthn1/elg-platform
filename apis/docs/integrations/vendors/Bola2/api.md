# Bola2 (BolaBola) — 廠商原始 API 規格

> 來源：`BOLA-API-1.1.1.pdf`（CHANG HONG 出品，版本 1.1.1 / 2021-07-05）
> 參考版本：`BOLA-API-1.1.0.pdf`（1.1.0 / 2021-07-01）、`BOLA-API-1.0.1.pdf`（1.0.1 / 2021-06-11）
> 抓取日期：2026-06-03
>
> 我方實作分析見 [integration.md](integration.md)（差異、陷阱、落地建議）。

---

## 目錄

- [通用約定](#通用約定)
- [使用者 API](#使用者-api)
  - [1. Signup（建立帳號）](#1-signup建立帳號)
  - [2. Login（取得遊戲 URL）](#2-login取得遊戲-url)
  - [3. Update User Profile（更新資料）](#3-update-user-profile更新資料)
- [錢包 API](#錢包-api)
  - [4. Deposit / Withdraw（轉帳）](#4-deposit--withdraw轉帳)
  - [5. Check Wallet Balance（查餘額）](#5-check-wallet-balance查餘額)
- [報表 API](#報表-api)
  - [6. Request Transaction Report（單一玩家）](#6-request-transaction-report單一玩家)
  - [7. Request Transaction Report For All Users（全部玩家）](#7-request-transaction-report-for-all-users全部玩家)
- [簽名](#簽名)
- [錯誤碼](#錯誤碼)
- [附錄：語言代碼](#附錄語言代碼)

---

## 通用約定

### 請求格式

- **HTTP Method**：多數 API 為 `POST`；`Update User Profile` 為 `PUT`
- **Header**：`Content-Type: application/json`
- **Base URL**：文件以 `.../` 表示，未提供正式網域；實際由 `vendor_infos.info.API_URL` 提供

每個請求 Body 均包含簽名三欄位，與業務參數平鋪同一層 JSON（見〈[簽名](#簽名)〉節）：

```jsonc
POST <API_URL><Endpoint>
Content-Type: application/json

{
  "AgentName": "AgentName",
  "Random": "048172",
  "HashKey": "<MD5(AgentName + Random + API Key)>",
  // ...業務參數
}
```

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `AgentName` | String | ✅ | 代理帳號名稱（`vendor_infos.info.AGENT_NAME`） |
| `Random` | String | ✅ | 6 位隨機數字字串（每次請求重新產生） |
| `HashKey` | String | ✅ | 簽章（見〈[簽名](#簽名)〉節） |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Int | 狀態碼（見〈[錯誤碼](#錯誤碼)〉節） |
| `Success` | Boolean | API 是否成功 |
| `Message` | String / Object | 視 API 而定：遊戲 URL、餘額金額字串、交易 ID |
| `Data` | Object / Array | 部分 API（Signup / Update / Report）回傳結構化資料 |

---

## 使用者 API

### 1. Signup（建立帳號）

> 文件 §1。現行 Driver 未呼叫此 API（見 [integration.md](integration.md) Gaps）。

| 項目 | 值 |
|---|---|
| Endpoint | `.../user/signup` |
| Method | `POST` |

#### 業務參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `Username` | ✅ | 玩家帳號 |
| `Password` | ✅ | 玩家密碼 |
| `DisplayName` | ❌ | 顯示名稱 |
| `ContactNo` | ❌ | 聯絡電話 |
| `Email` | ❌ | 電子郵件 |
| `Language` | ❌ | 語言代碼（見〈[附錄：語言代碼](#附錄語言代碼)〉節） |

#### Success Response

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "",
  "Data": {
    "Username": "example",
    "DisplayName": "",
    "ContactNo": "",
    "Email": "",
    "Language": "E"
  }
}
```

> v1.0.1 更新：「Signup process, remove usertype indicator（Multi-Level Agent Enhancement）」，故 Signup 不再帶 usertype。

---

### 2. Login（取得遊戲 URL）

> 文件 §2。對應 Driver `getGameUrl()`。

| 項目 | 值 |
|---|---|
| Endpoint | `.../user/login` |
| Method | `POST` |

#### 業務參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `Username` | ✅ | 玩家帳號 |
| `Password` | ✅ | 玩家密碼 |

#### Success Response

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "{URL}"
}
```

> 實作以 `Message` 的 URL 重導向至 BolaBola 遊戲頁，並在 URL 後串接 `&lang={語言代碼}`。

---

### 3. Update User Profile（更新資料）

> 文件 §3。現行 Driver 未呼叫此 API。

| 項目 | 值 |
|---|---|
| Endpoint | `.../user/update/{username}` |
| Method | `PUT`（注意：非 POST） |

#### 業務參數

文件此節僅列回應，請求業務參數比照 Signup 的可選欄位（`DisplayName` / `ContactNo` / `Email` / `Language`）。

#### Response

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "",
  "Data": {
    "Username": "example",
    "DisplayName": "",
    "ContactNo": "",
    "Email": "",
    "Language": "E"
  }
}
```

---

## 錢包 API

### 4. Deposit / Withdraw（轉帳）

> 文件 §4。對應 Driver `transferToVendor()`（deposit）與 `transferToLocal()`（withdraw）。

| 項目 | 值 |
|---|---|
| Deposit Endpoint | `.../transfer/deposit/{username}` |
| Withdraw Endpoint | `.../transfer/withdraw/{username}` |
| Method | `POST` |

#### 業務參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `Amount` | ✅ | 轉帳金額 |
| `Remark` | ❌ | 備註（實作傳空字串 `""`） |

#### Success Response

```jsonc
{
  "Status": 201,
  "Success": true,
  "Message": "TransactionId"
}
```

> ⚠️ 轉帳**成功**為 `Status 201`（非 200），`Message` 為交易 ID。實作以 `Status === 201` 判定 `SUCCESS`，其餘回 `FAILED` / `TIMEOUT`。

---

### 5. Check Wallet Balance（查餘額）

> 文件 §5。對應 Driver `getBalance()`。

| 項目 | 值 |
|---|---|
| Endpoint | `.../user/getbalance/{username}` |
| Method | `POST` |

#### Success Response

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "90.00"
}
```

> `Message` 為餘額金額字串。實作以 `Common::numFloor(Message, 4)` 正規化。
> ⚠️ 文件 endpoint 全小寫 `getbalance`，實作呼叫駝峰 `getBalance`；待確認伺服器路由是否大小寫敏感（見 [integration.md](integration.md) Gaps）。

---

## 報表 API

### 6. Request Transaction Report（單一玩家）

> 文件 §6。

| 項目 | 值 |
|---|---|
| Endpoint | `.../report/game/{username}` |
| Method | `POST` |
| Query 參數 | `page`（分頁，預設 1）、`size`（每頁筆數，預設 50） |

#### Success Response

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "",
  "Data": [
    {
      "UserId": "12345678",
      "LoginUsername": "example",
      "DisplayName": "example",
      "TransactionId": "987654321",
      "RoundId": "1234",
      "RoundDate": "2021-06-30 00:00:00",
      "TransactionDate": "2021-06-30 15:31:03",
      "Sequence": "618",
      "BetContent": "Number 9",
      "Result": "2",
      "Stake": "10.00",
      "Tax": "0.00",
      "Payout": "-10.00",
      "StatusDescr": "输"
    }
  ]
}
```

> `TransactionDate`、`BetContent`、`Result` 三欄為 v1.1.1 新增。

---

### 7. Request Transaction Report For All Users（全部玩家）

> 文件 §7。對應 APIs 對帳拉取（依日期區間）。

| 項目 | 值 |
|---|---|
| Endpoint | `.../report/all/` |
| Method | `POST` |
| Query 參數 | `date_start`（起始日 `yyyy-MM-dd`）、`date_end`（結束日 `yyyy-MM-dd`）、`page`（預設 1）、`size`（預設 50） |

#### Success Response

回傳結構與 §6 相同（`Data` 為注單陣列），`DisplayName` 欄位可能不包含：

```jsonc
{
  "Status": 200,
  "Success": true,
  "Message": "",
  "Data": [
    {
      "UserId": "12345678",
      "LoginUsername": "example",
      "TransactionId": "987654321",
      "RoundId": "1234",
      "RoundDate": "2021-06-30 00:00:00",
      "TransactionDate": "2021-06-30 15:31:03",
      "Sequence": "618",
      "BetContent": "Small",
      "Result": "3",
      "Stake": "10.00",
      "Tax": "0.00",
      "Payout": "9.50",
      "StatusDescr": "赢"
    }
  ]
}
```

> v1.0.2 更新：「Add all user transaction report filter by date range」，新增日期區間篩選。

#### 注單欄位說明

| 欄位 | 說明 |
|---|---|
| `UserId` | 玩家內部 ID |
| `LoginUsername` | 登入帳號 |
| `DisplayName` | 顯示名稱（§6 才有） |
| `TransactionId` | 交易 ID |
| `RoundId` | 局號 |
| `RoundDate` | 局時間 |
| `TransactionDate` | 交易時間（含時分秒） |
| `Sequence` | 序號 |
| `BetContent` | 投注內容（如 `Number 9`、`Small`） |
| `Result` | 結果碼（官方完整對照表缺失，見〈[錯誤碼](#錯誤碼)〉節） |
| `Stake` | 投注金額（字串） |
| `Tax` | 稅金（字串） |
| `Payout` | 派彩（字串，負值表輸） |
| `StatusDescr` | 結果描述（中文「赢」/「输」） |

> ⚠️ `Result` 與 `StatusDescr` 無官方完整對照表，對帳時以 `Stake` / `Payout` 數值為準，`StatusDescr` 僅供輔助判讀。

---

## 簽名

Bola2 採 **MD5 字串串接** 簽章，無對稱加密。

### 演算法

```
HashKey = MD5( AgentName + Random + API Key )
```

| 項目 | 值 |
|---|---|
| 演算法 | MD5（32 字元小寫 hex 輸出） |
| 串接順序 | `AgentName` → `Random` → `API Key`（直接字串相接，無分隔符） |
| `AgentName` | `vendor_infos.info.AGENT_NAME` |
| `Random` | 每次請求重新產生，6 位數字字串（含前導零） |
| `API Key` | `vendor_infos.info.API_KEY`，**不直接傳輸**，僅參與雜湊 |

### PHP 實作（現行 Driver）

```php
$random = sprintf('%06d', random_int(0, 999999));   // 6 位數字，含前導零
$postData = array_merge([
    'AgentName' => $this->infoDetail->AGENT_NAME,
    'Random'    => $random,
    'HashKey'   => md5($this->infoDetail->AGENT_NAME . $random . $this->infoDetail->API_KEY),
], $postData);
```

### 完整請求範例（以 Login 為例）

```jsonc
POST <API_URL>/user/login
Content-Type: application/json

{
  "AgentName": "AgentName",
  "Random": "048172",
  "HashKey": "<md5(AgentName + 048172 + API Key)>",
  "Username": "player001",
  "Password": "<getDefaultPassword 產生>"
}
```

---

## 錯誤碼

### Status Code

| Code | 說明 |
|---|---|
| `200` | Success（一般 API 成功） |
| `201` | Created（Deposit / Withdraw 轉帳成功） |
| `400` | Bad Requested（請求參數缺漏或格式不符） |
| `403` | Unauthorized（HashKey 算錯 / AgentName 錯誤） |

> 文件僅列出上述 4 個碼，無餘額不足、帳號不存在等業務細分碼。

### 常見錯誤排查

| 症狀 | 可能原因 |
|---|---|
| `Status 403` Unauthorized | `HashKey` 串接順序錯 / `API_KEY` 錯 / `AgentName` 錯 |
| `Status 400` Bad Requested | Body 欄位缺漏或格式不符 |
| 偶發失敗 | `Random` 重複使用（應每次請求重新產生） |
| `404`（推測） | `getbalance` vs `getBalance` 大小寫問題，見 [integration.md](integration.md) |

---

## 附錄：語言代碼

實作 `mapLanguageCode()` 將本地語言對應為 Bola2 代碼，串接於遊戲 URL（`&lang={code}`）：

| 本地語言 | Bola2 代碼 |
|---|---|
| 繁中（ZH_TW） | `C` |
| 簡中（ZH_CN） | `C` |
| 馬來文（MS_MY） | `M` |
| 其他 / 預設 | `E`（English） |

> Signup / Update 文件範例的 `Language` 欄位使用同一組單字母代碼（範例值 `"E"`）。
