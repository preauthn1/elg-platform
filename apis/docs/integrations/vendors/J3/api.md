# J3 — 三方 API 規格

> 來源：整合自原始廠商對接文檔（README）。認證方式為 agent/agentpassword（無加密簽名）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | 所有 API 一律 `POST` |
| Content-Type | `application/x-www-form-urlencoded`（表單格式） |
| 認證方式 | 每次請求帶入 `agent`（代理帳號）與 `agentpassword`（代理密碼）作為 POST 欄位；**無另立簽名演算法** |
| 回應格式 | JSON |

```
POST http://example.invalid/api/j3/{endpoint}
Content-Type: application/x-www-form-urlencoded

agent=<agentLogin>&agentpassword=[REDACTED] API 附加參數）
```

### Base URL

| 環境 | URL |
|---|---|
| 文件範例 | `http://example.invalid/api/j3/` |
| Production | 由 J3 客服提供（文件未另行說明） |
| 設定來源 | `vendor_infos.info.API_URL`（Driver 自動 `rtrim('/')` ） |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `"success"` 表示成功，其他值（如 `"failed"`）表示失敗 |
| `message` | String | 操作說明或錯誤原因 |
| `data` | Mixed | 成功時的回傳資料；失敗時通常為空字串 `""` |

成功範例：

```json
{"status":"success","message":"success","data":"VEVTVEFBQXw5OTk5OXwwUjJDWTYwR0IyTE58MjA0NjMw"}
```

失敗範例：

```json
{"status":"failed","message":"Invalid Member","data":""}
```

---

## merchant API（Agent API）

### 1. 建立玩家 createuser.php

在 J3 端建立新玩家帳號。

| 項目 | 值 |
|---|---|
| Endpoint | `createuser.php` |
| Method | `POST` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理帳號 |
| `agentpassword` | String | ✅ | 代理密碼 |
| `login` | String | ✅ | 新玩家帳號 |

#### 回應

文件未提供成功/失敗的回應範例。依通用格式推定，成功時 `status: "success"`。

---

### 2. 轉入籌碼 topup.php

將代理錢包餘額轉入 J3 玩家帳號。

| 項目 | 值 |
|---|---|
| Endpoint | `topup.php` |
| Method | `POST` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理帳號 |
| `agentpassword` | String | ✅ | 代理密碼 |
| `login` | String | ✅ | 玩家帳號 |
| `amount` | Numeric | ✅ | 轉入金額 |

#### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `"success"` / 其他 |
| `message` | String | 說明訊息 |
| `data` | Mixed | 文件未說明具體內容；實作以 `isset($json['data'])` 判斷成功 |

---

### 3. 轉出籌碼 withdraw.php

將 J3 玩家帳號餘額轉回代理錢包。

| 項目 | 值 |
|---|---|
| Endpoint | `withdraw.php` |
| Method | `POST` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理帳號 |
| `agentpassword` | String | ✅ | 代理密碼 |
| `login` | String | ✅ | 玩家帳號 |
| `amount` | Numeric | ✅ | 轉出金額 |

#### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `"success"` / 其他 |
| `message` | String | 說明訊息 |
| `data` | Mixed | 文件未說明具體內容；實作以 `isset($json['data'])` 判斷成功 |

---

### 4. 查詢餘額 getbalance.php

查詢指定玩家在 J3 端的目前餘額。

| 項目 | 值 |
|---|---|
| Endpoint | `getbalance.php` |
| Method | `POST` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理帳號 |
| `agentpassword` | String | ✅ | 代理密碼 |
| `login` | String | ✅ | 玩家帳號 |

#### 回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `"success"` / 其他 |
| `message` | String | 說明訊息 |
| `balance` | Numeric | **推定**：實作優先讀取此欄位 |
| `data` | Mixed | fallback 餘額來源（若 `balance` 不存在則讀此欄位） |

> 文件未說明餘額欄位名稱。實作以 `$json['balance'] ?? $json['data']` 雙重 fallback 讀取，待客服確認實際欄位名。

---

### 5. 取得遊戲 Token gettoken.php

取得玩家進入 J3 大廳所需的一次性 Token。

| 項目 | 值 |
|---|---|
| Endpoint | `gettoken.php` |
| Method | `POST` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `agent` | String | ✅ | 代理帳號 |
| `agentpassword` | String | ✅ | 代理密碼 |
| `login` | String | ✅ | 玩家帳號 |

#### 成功回應

```json
{
  "status": "success",
  "message": "success",
  "data": "VEVTVEFBQXw5OTk5OXwwUjJDWTYwR0IyTE58MjA0NjMw"
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `"success"` |
| `message` | String | `"success"` |
| `data` | String | Base64 格式的遊戲進入 Token |

#### 失敗回應

```json
{"status":"failed","message":"Invalid Member","data":""}
```

#### 組裝遊戲 URL

取得 Token 後，組裝大廳 URL：

```
{GAME_URL}?token=[REDACTED]
```

範例：

```
https://j3lobby.btly.fun/mlj3/?lang=2&token=[REDACTED]
```

> `GAME_URL` 由 `vendor_infos.info.GAME_URL` 提供；`lang` 對照：EN→0、ZH_TW/ZH_CN→1、MS_MY→2、其他→0。

---

## Additional API（玩家憑證用，未整合）

> 以下 API 使用**玩家自身帳密**（`username`、`password`、`uniqcode`、`secucode`），**非**代理的 `agent` / `agentpassword`。目前 J3 Driver 僅實作 Agent API（轉帳錢包流程），此節為文件完整性收錄，**未在 Driver 中實作**。

### 6. 會員登入 signin.php

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 玩家密碼 |
| `uniqcode` | String | ✅ | 玩家唯一碼 |
| `secucode` | String | ✅ | 玩家安全碼 |

回應欄位：`StatID`（0=失敗 / 1=成功）、`Status`（狀態描述）、`PrinterAdd`（配對印表機位址）

### 7. 下注 bet.php

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 玩家密碼 |
| `uniqcode` | String | ✅ | 玩家唯一碼 |
| `text` | String | ✅ | 下注內容（Base64，多行以 `\|` 分隔） |

回應欄位：`StatID`（0=失敗 / 1=成功 / 99=錯誤）、`BetID`、`Receipt`、`Message`、`Amount`

### 8. 刪除注單 delete.php

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 玩家密碼 |
| `uniqcode` | String | ✅ | 玩家唯一碼 |
| `betid` | String | ✅ | 欲刪除的注單 ID |

回應欄位：`StatID`（0=失敗 / 1=成功 / 2=無法刪除 / 99=錯誤）、`BetID`、`Receipt`、`Message`

### 9. 重印收據 reprint.php

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 玩家密碼 |
| `uniqcode` | String | ✅ | 玩家唯一碼 |
| `date` | String | ✅ | 開獎日期（drawdate） |
| `targetbill` | String | ✅ | 該日的注單號碼 |

回應欄位：`StatID`、`Status`、`ReceiptCount`、`Receipts`（陣列，每筆含 `invoiceid`、`isVoid`、`date`、`input`、`invoice`、`amount`）

### 10. 中獎報表 report.php

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | String | ✅ | 玩家帳號 |
| `password` | String | ✅ | 玩家密碼 |
| `uniqcode` | String | ✅ | 玩家唯一碼 |
| `date` | String | ✅ | 查詢日期 |

回應欄位：`StatID`（0=失敗 / 1=成功 / 99=錯誤）、`Status`、`Winlist`（陣列元素欄位文件未說明）

---

## 錯誤碼

J3 無數字錯誤碼系統。失敗判定：

- **Agent API**：`status !== "success"`，實作以 `ApiException` 拋出，錯誤原因從 `message` 欄位讀取。
- **Additional API**：`StatID !== 1`，錯誤原因從 `Message` / `Status` 欄位讀取。
