# CT — API 整合文件

> **來源**：公開文件站（WebFetch 摘取），細節以官方站為準。
> 原始來源：<https://example.invalid/static/doc/cn/api.html>
> 抓取日期：2026-06-03
>
> 我方實作分析見 `integration.md`

CT855 視訊（CT）為線上視訊遊戲商，提供百家樂、龍虎、輪盤、骰寶等視訊桌台，採 **Transfer Wallet（轉帳錢包）** 模式接入。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/json`
- **驗證方式**：每次請求帶 `token`（MD5 簽章）+ `random`（一次性隨機字串），見本文件〈簽名〉節

### Base URL

| 環境 | URL |
|---|---|
| Production | `http://example.invalid`、`http://example.invalid`、`http://example.invalid`（三選一，玩家選最快） |

> 實際使用 URL 由 `vendor_infos.info.API_URL` 設定；實作以此欄位為準。

### Endpoint 格式

所有 endpoint 以 `/{agentName}/` 作為路徑後綴：

```
POST http://example.invalid/api/login/{agentName}/
POST http://example.invalid/api/transfer/{agentName}/
```

### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章，見本文件〈簽名〉節 |
| `random` | String | ✅ | 用於 token 生成的一次性隨機字串 |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `codeId` | Integer | 錯誤碼，`0` 代表成功 |
| `token` | String | 本次回應的 token（用途依 API 而異，見各節說明） |
| `random` | String | 本次回應的 random |

---

## 簽名

CT 所有代理端 API 請求，必須在每個請求中帶入 `token` 與 `random` 欄位完成身份驗證。

### 演算法

| 項目 | 值 |
|---|---|
| 演算法 | MD5 |
| Token 公式 | `MD5(agentName + API_key + random)` |
| random | 每次請求重新生成的一次性隨機字串 |

### 生成步驟

```php
// Step 1 — 生成隨機字串
$random = md5(uniqid());

// Step 2 — 計算 Token
$token = [REDACTED] . $apiKey . $random);

// Step 3 — 帶入請求
$params = [
    'random' => $random,
    'token'  => $token,
    'member' => ['username' => $username],
];
```

### 回應中的 Token（注意同名不同義）

| API | 回應 token 用途 |
|---|---|
| **login** | 玩家進入遊戲的 URL token（需附加到遊戲 URL 末端）—— **非驗證 token** |
| 其他 API | 回應的 `token`/`random` 一般不需使用 |

> ⚠️ **高風險陷阱**：login 回應的 `token` 與請求的 `token` 同名，但意義截然不同。login 回應 `token` 是給玩家進遊戲用的，不可當作下一次請求的驗證 token 使用。

### 常見錯誤

| 症狀 | 可能原因 |
|---|---|
| `codeId: 2` Token verification failed | token 計算錯誤（串接順序、大小寫、API_key 錯誤） |
| `codeId: 320` API Key error | API_key 不正確 |
| `codeId: 400` Client IP restricted | 發送請求的 IP 未加入 CT 白名單 |
| `codeId: 405` Request too frequent | 超過速率限制（getReport 最小間隔 10 秒；online 最小 30 秒） |

---

## 轉帳錢包流程

CT 為純 Transfer Wallet 模式，CT 端持有玩家餘額。

```
玩家首次進入（帳號不存在）
  │
  ▼
1. signup → 建立 CT 帳號
  │
  ▼
2. transfer（正數）→ 存款進 CT
  │
  ▼
3. login → 取得遊戲進入 URL + token（組合：list[N] + token）
  │
  ▼
4. 玩家遊玩（CT 端持有餘額）
  │
  ▼
5. 玩家離開 → transfer（負數）→ 從 CT 提款回本地
  │
  ▼
6. getBalance 確認 CT 端餘額歸零
```

> 轉帳結果不確定時，呼叫 `checkTransfer` 確認最終狀態（見本文件〈轉帳〉節）。

---

## 注單同步流程

CT 採**拉取（pull）**模式，不提供 callback：

1. 定期呼叫 `getReport`（最小間隔 10 秒，每次最多 1,000 筆）
2. 確認所有注單已寫入本地 DB 後，呼叫 `mark` 標記
3. 僅處理 `isRevocation=1`（已結算）寫入 TBR；`isRevocation=2`（對冲）需扣除對應注單；`isRevocation=0/3` 暫存

> ⚠️ **mark 前必須確認注單已寫入本地 DB**，mark 後注單不再出現於 getReport，漏寫入=永久遺失。

---

## 會員管理

### 1. signup — 會員註冊

建立新的 CT 會員帳號。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/signup/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `data` | String | ✅ | 目標投注限制組（如 A-G，預設為 A） |
| `member.username` | String | ✅ | 帳號（4-30 字元，必須以字母開頭） |
| `member.password` | String | ✅ | MD5 加密後的密碼 |
| `member.currencyName` | String | ✅ | 幣別代碼（見本文件〈支援幣別〉節；建立後**不可修改**） |
| `member.winLimit` | Double | ✅ | 每日贏分上限（0 = 無限制） |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<KEY>",
  "random": "<random>",
  "data": "<分配到的投注限制組>"
}
```

#### 失敗回應

| codeId | 說明 |
|---|---|
| 101 | 帳號格式無效 |
| 109 | 註冊失敗 |
| 116 | 帳號已被佔用 |
| 321 | 投注限制組不存在 |
| 322 | 幣別類型不存在 |

---

### 2. login — 會員登入 / 取得遊戲 URL

登入 CT 並取得遊戲進入 URL 與 token。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/login/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `lang` | String | 否 | 語言代碼（en/cn/tw/kr/th/vi） |
| `member.username` | String | ✅ | 帳號 |
| `member.password` | String | 否 | MD5 加密密碼（若與存檔不同則自動更新） |
| `limit.min` | String/Double | 否 | 進場最低下注額 |
| `limit.max` | String/Double | 否 | 進場最高下注額 |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<遊戲 token>",
  "list": [
    "https://<ct-domain>/play?token=",
    "https://<ct-domain>/wap?token=",
    "https://<ct-domain>/app?token="
  ]
}
```

> `list[0]`=PC URL，`list[1]`=WAP URL，`list[2]`=App URL。
> 實際遊戲 URL = `list[N] + token`（回應的 token 附加在 URL 末端）。
>
> ⚠️ **此處 `token` 是遊戲 URL token，非驗證 token**（見本文件〈簽名〉節）。

#### 失敗回應

| codeId | 說明 |
|---|---|
| 100 | 帳號已鎖定 |
| 102 | 帳號不存在 |
| 105 | 密碼錯誤 |
| 107 | 帳號不可用 |
| 108 | 登入失敗 |

---

### 3. free — 試玩登入

取得無需帳號的試玩遊戲 URL。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/free/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `lang` | String | ✅ | 語言代碼 |
| `device` | Integer | ✅ | 裝置類型（1=Web, 2=iOS, 3=Android, 5=WAP） |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<試玩 token>",
  "list": ["<flash URL>", "<wap URL>", "<app URL>"]
}
```

#### 失敗回應

| codeId | 說明 |
|---|---|
| 150 | 試玩帳號已耗盡 |

---

### 4. update — 更新會員資訊

更新帳號密碼、每日贏分上限、或帳號狀態。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/update/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `member.username` | String | ✅ | 帳號 |
| `member.password` | String | 否 | 新密碼（MD5 加密） |
| `member.winLimit` | Double | 否 | 新的每日贏分上限（0 = 無限制） |
| `member.status` | Integer | 否 | 帳號狀態（0=停用, 1=正常, 2=鎖定/禁止下注） |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<KEY>",
  "random": "<random>"
}
```

---

### 5. getBalance — 取得會員餘額

查詢指定會員在 CT 端的目前餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/getBalance/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `member.username` | String | ✅ | 帳號 |

#### 成功回應

```json
{
  "codeId": 0,
  "member": {
    "username": "player001",
    "balance": 1000.00
  }
}
```

#### 失敗回應

| codeId | 說明 |
|---|---|
| 102 | 帳號不存在 |
| 114 | 會員不存在 |

---

### 6. updateLimit — 更新投注限額組

調整指定會員所屬的投注限額分組。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/updateLimit/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `data` | String | ✅ | 目標限額組（A-G） |
| `member.username` | String | ✅ | 帳號 |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<KEY>",
  "random": "<random>"
}
```

#### 失敗回應

| codeId | 說明 |
|---|---|
| 321 | 投注限制組不存在 |

---

## 轉帳

### 7. transfer — 轉帳

將餘額存入或提出 CT 玩家帳號。`amount` 正數=存款、負數=提款，兩者呼叫同一 endpoint。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/transfer/{agentName}/` |

> ⚠️ **正數=存款入 CT（transferToVendor），負數=從 CT 提款（transferToLocal）**，同一 endpoint，金額符號決定方向。

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `data` | String | ✅ | 轉帳流水號（≤35 字元，本地唯一；相同流水號重複使用收 `323`） |
| `member.username` | String | ✅ | 帳號 |
| `member.amount` | Double | ✅ | 金額（正數=存款, 負數=提款, 最多 3 位小數） |

#### 成功回應

```json
{
  "codeId": 0,
  "data": "<轉帳流水號>",
  "member": {
    "username": "player001",
    "balance": 1500.00
  }
}
```

#### VendorTransferResultEnum 對應

| codeId | 意義 | 回傳值 |
|---|---|---|
| `0` | 轉帳成功 | `SUCCESS` |
| `324` | 轉帳確定失敗 | `FAILED` |
| 其他 | 未知 / 逾時 | `TIMEOUT`（需呼叫 checkTransfer 確認） |

#### 失敗回應

| codeId | 說明 |
|---|---|
| 119 | 代理點數不足 |
| 120 | 餘額不足 |
| 323 | 轉帳流水號已被使用 |
| 324 | 轉帳失敗 |

> **冪等與重試**：遇到逾時或非 0/324 的回應時，必須呼叫 `checkTransfer` 查詢最終狀態，切勿直接重試（避免重複轉帳）。

---

### 8. checkTransfer — 查詢轉帳狀態

用轉帳流水號查詢某次轉帳的最終結果。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/checkTransfer/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `data` | String | ✅ | 要查詢的轉帳流水號 |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<KEY>",
  "random": "<random>"
}
```

#### 狀態判斷

| codeId | 意義 | VendorTransferResultEnum |
|---|---|---|
| `0` | 轉帳成功（已確認） | `SUCCESS` |
| `324` | 轉帳確定失敗 | `FAILED` |
| 其他 | 未知狀態 | `TIMEOUT` |

---

## 注單報表

### 9. getReport — 拉取注單

拉取代理旗下所有未標記的注單。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/getReport/{agentName}/` |
| 最小呼叫間隔 | **10 秒** |
| 每次最大筆數 | **1,000 筆** |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |

#### 成功回應

```json
{
  "codeId": 0,
  "token": "<KEY>",
  "random": "<random>",
  "list": [ ]
}
```

> `list` 中每筆均為 BetDetail 物件（見本文件〈附錄：BetDetail〉節）；`list` 為空陣列代表目前無新注單。呼叫 `mark` 後下次拉取不再出現。

---

### 10. mark — 標記注單

將已寫入本地 DB 的注單 ID 標記為已處理。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/mark/{agentName}/` |

> ⚠️ **標記前必須確認所有 ID 均已寫入本地 DB**。標記後注單不再出現於 `getReport`，遺漏寫入將造成資料永久遺失。

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `list` | Array | ✅ | 待標記的注單 ID 集合（`BetDetail.id` 值） |

#### 成功回應

```json
{
  "codeId": 0
}
```

---

## 在線管理

### 11. online — 取得在線會員

查詢目前在線上遊玩的會員清單。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/online/{agentName}/` |
| 最小呼叫間隔 | **30 秒** |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |

#### 成功回應

```json
{
  "codeId": 0,
  "list": [
    {
      "username": "player001",
      "nickname": "P1",
      "currencyName": "TWD",
      "ip": "0.0.0.0",
      "device": 1,
      "login": "2026-06-03 12:00:00",
      "memberId": 12345,
      "balance": 500.00
    }
  ]
}
```

---

### 12. offline — 踢出會員

強制斷線指定會員。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/offline/{agentName}/` |

#### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | ✅ | MD5 簽章 |
| `random` | String | ✅ | 一次性隨機字串 |
| `list` | Array | ✅ | 要踢出的會員 ID 集合（`memberId`） |

#### 成功回應

```json
{
  "codeId": 0
}
```

---

## 附錄：錯誤碼

| codeId | 說明 |
|---|---|
| 0 | 成功 |
| 1 | 參數錯誤 |
| 2 | Token 驗證失敗 |
| 4 | 非法操作 |
| 10 | 日期格式錯誤 |
| 11 | 資料格式錯誤 |
| 97 | 無操作權限 |
| 98 | 操作失敗 |
| 99 | 未知錯誤 |
| 100 | 帳號已鎖定 |
| 101 | 帳號格式無效 |
| 102 | 帳號不存在 |
| 103 | 帳號正在使用中 |
| 104 | 密碼格式無效 |
| 105 | 密碼錯誤 |
| 106 | 新舊密碼相同 |
| 107 | 帳號不可用 |
| 108 | 登入失敗 |
| 109 | 註冊失敗 |
| 113 | 指定代理帳號不是代理 |
| 114 | 會員不存在 |
| 116 | 帳號已被佔用 |
| 117 | 會員所屬公司不存在 |
| 118 | 指定代理不存在 |
| 119 | 代理點數不足 |
| 120 | 餘額不足 |
| 121 | 贏分上限必須 ≥ 0 |
| 150 | 試玩帳號已耗盡 |
| 300 | 系統維護中 |
| 320 | API Key 錯誤 |
| 321 | 投注限制組不存在 |
| 322 | 幣別類型不存在 |
| 323 | 轉帳流水號已被使用 |
| 324 | 轉帳失敗 |
| 325 | 代理狀態不可用 |
| 326 | 代理所屬視訊組不存在 |
| 328 | API 類型不存在 |
| 329 | 代理資訊不完整 |
| 400 | 客戶端 IP 受限（未加入白名單） |
| 401 | 網路延遲 |
| 402 | 連線已關閉 |
| 403 | 客戶端來源受限 |
| 404 | 資源不存在 |
| 405 | 請求太頻繁 |
| 406 | 請求逾時 |
| 407 | 遊戲地址不存在 |
| 500 | 空指標例外 |
| 501 | 系統例外 |
| 502 | 系統繁忙 |
| 503 | 資料操作例外 |

---

## 附錄：注單欄位說明（BetDetail）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `id` | Long | 注單唯一 ID → `ct_bet_records.betId` |
| `lobbyId` | Integer | 廳別（見下表） |
| `tableId` | Integer | 桌台號碼 |
| `shoeId` | Long | 靴號 |
| `playId` | Long | 局號 |
| `GameType` | Integer | 遊戲分類類型 |
| `GameId` | Integer | 遊戲 ID（見下表） |
| `memberId` | Long | 玩家 CT 內部 ID |
| `betTime` | String | 下注時間（格式：`yyyy-MM-dd HH:mm:ss`，時區待確認） |
| `calTime` | String | 結算時間（格式：`yyyy-MM-dd HH:mm:ss`，未結算時為 null） |
| `winOrLoss` | Double | 派彩金額（已扣除下注金額，負數為輸） |
| `betPoints` | Double | 下注金額 |
| `availableBet` | Double | 有效下注金額 |
| `userName` | String | 玩家帳號 |
| `result` | String | 遊戲結果（格式未說明，見 Gaps） |
| `betDetail` | String | 注單明細（格式未說明，見 Gaps） |
| `ip` | String | 玩家 IP |
| `ext` | String | 遊戲唯一 ID（格式未說明，見 Gaps） |
| `isRevocation` | Integer | 結算狀態（見下表） |
| `balanceBefore` | Double | 下注前餘額 |
| `parentBetId` | Long | 對冲原始注單 ID（`isRevocation=2` 時使用） |
| `currencyId` | Integer | 幣別 ID |
| `deviceType` | Integer | 裝置類型 |

### isRevocation 結算狀態

| 值 | 說明 | 處理方式 |
|---|---|---|
| `0` | 未結算（注單進行中） | 暫存，不寫 TBR |
| `1` | 已結算 | 寫入 TBR |
| `2` | 已撤銷（對冲注單） | `parentBetId` 指向被對冲的原始注單，需從原注單扣除 |
| `3` | 凍結（v1.1.5 新增） | 暫存，不寫 TBR |

### lobbyId 廳別對照

| lobbyId | 廳別 |
|---|---|
| `2` | 亞洲廳 |
| `3` / `4` | 直播廳 |
| `5` | 性感廳（Sexy Hall） |
| `6` | PH 廳 |
| `7` | 國際廳 |
| `8` | 區塊鏈廳 |

### GameId 遊戲對照

| GameId | 遊戲 |
|---|---|
| `1` | 百家樂（Baccarat） |
| `3` | 龍虎（Dragon-Tiger） |
| `4` | 輪盤（Roulette） |
| `5` | 骰寶（Sicbo/Dice） |
| `14` | 色碟（Color Disc） |
| `20` | Andar Bahar |
| `41`-`48` | 撲克類遊戲 |

---

## 附錄：語言代碼

| Code | 語言 |
|---|---|
| `en` | English |
| `cn` | 簡體中文 |
| `tw` | 繁體中文 |
| `kr` | 韓文 |
| `th` | 泰文 |
| `vi` | 越南文 |

APIs 實作 `getLanguage()` 對應：

| `LanguageEnum` | CT lang 值 |
|---|---|
| `EN` | `en` |
| `ZH_TW` | `tw` |
| `ZH_CN` | `cn` |
| 其他 | fallback `en` |

---

## 附錄：裝置類型代碼

| `device` | 說明 |
|---|---|
| `1` | Web（桌機） |
| `2` | iOS |
| `3` | Android |
| `5` | WAP |

---

## 附錄：支援幣別

`currencyName` 可用值（以實際 CT 後台設定為準）：

`CNY`、`USD`、`MYR`、`HKD`、`THB`、`SGD`、`PHP`、`TWD`、`VND`、`JPY`、`KWR`、`AUD`、`BND`、`INR`、`EUR`、`KRW2`、`MMK`、`MMK2`、`VND2`、`IDR2`、`USDT`、`BDT`、`TEST`

---

## Gaps（待補齊）

| # | 項目 | 說明 |
|---|---|---|
| 1 | 注單保留期 | 文件未明確說明 CT 保留注單多少天；需向客服確認 |
| 2 | 時區 | `betTime`/`calTime` 時間戳未說明時區；Model 欄位命名含 Utc，但待驗證 |
| 3 | `result`/`betDetail` 格式 | 文件未說明編碼格式（JSON 字串？純文字？） |
| 4 | `ext` 欄位格式 | 文件說是「遊戲唯一 ID」，但格式不明 |
| 5 | lobbyId 完整列舉 | 文件列出 2/3/4/5/6/7/8，是否有其他值未知 |
| 6 | GameType 完整對照 | GameType 值包含 1-5、14、20、41-48，無完整說明 |
| 7 | `signup` 的 `data` 欄位 | 文件說是「目標投注限制組」（A-G），完整可用值未確認 |
