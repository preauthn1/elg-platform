# Sexy Slot（Tiger Games）— Seamless Wallet API 整合文件

> 原始規格版本：Tiger Games API v.2.1.7（2025-07-05）
> 原始文件：`A._TIGER_GAMES_API_-_v.2.1.7.pdf`
> 內部實作路徑：`app/Vendors/SexySlotSW/`、`app/Http/Controllers/External/SingleWallets/SexySlotSWController.php`

Tiger Games 是聚合遊戲平台，整合多家遊戲供應商（KAGaming、Booongo 等）。API 採 Seamless Wallet 架構——由 Tiger Games 主動回調運營商（我方）進行餘額查詢與資金異動。

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [認證與憑證](#2-認證與憑證)
3. [Hashkey 驗簽](#3-hashkey-驗簽)
4. [Player Token 規範](#4-player-token-規範)
5. [我方呼叫的 API（Driver 端）](#5-我方呼叫的-api driver-端)
6. [回調端點（Controller 端）](#6-回調端點controller-端)
7. [錯誤碼](#7-錯誤碼)
8. [重試機制](#8-重試機制)
9. [重要注意事項](#9-重要注意事項)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| API Base URL（Stage） | `https://example.invalid/api/` |
| 請求格式（我方→TG） | `application/x-www-form-urlencoded`（game launch）/ Header Bearer Token |
| 請求格式（TG→我方回調） | `application/json`（RAW/JSON） |
| 響應格式 | JSON |
| 簽名算法 | MD5（`md5(api_key + access_token)`） |
| Token 格式 | UUID v4 去 dash，`^[0-9,A-F]{32}$`（大寫） |
| 延遲要求 | 所有回調回應須 ≤ 300ms |
| 金額精度 | 兩位小數 |

---

## 2. 認證與憑證

Tiger Games 提供以下憑證：

| 憑證 | 說明 | info key |
|---|---|---|
| USERNAME | 帳號（email 格式） | `USERNAME` |
| PASSWORD | 密碼 | `PASSWORD` |
| CLIENT_ID | 客戶 ID（int） | `CLIENT_ID` |
| CLIENT_SECRET | 客戶密鑰 | `CLIENT_SECRET` |
| CLIENT_API_KEY | 用於 hashkey 計算 | `CLIENT_API_KEY` |
| CLIENT_ACCESS_TOKEN | 靜態 access token，用於 hashkey 計算與回調驗簽 | `CLIENT_ACCESS_TOKEN` |

### Stage 環境憑證（TITANGAMING-TH167MATGA）

| 憑證 | Stage 值 |
|---|---|
| API_URL | `https://example.invalid/api/` |
| CLIENT_ID | `11533` |
| USERNAME | `user@example.invalid` |
| PASSWORD | `4bv4h9FjU52PEcZe` |
| CLIENT_SECRET | `LF3qF4cL4AtL7SRV5pw92q7xUE481mv4` |
| CLIENT_API_KEY | `m2USrPX4dAGTH0bDXQH7ccW068iN7ZiC` |
| CLIENT_ACCESS_TOKEN | `6zxT7ZpRtzEeJVVut521372RU0Bi5D1z` |
| CURRENCY | `MYR` |
| SUB CLIENT | ME77（CLIENT_ID 同 11533） |

> ⚠️ 以上為 Stage 測試環境憑證，正式環境憑證另行提供。

### Access Token 類型

Tiger Games 有兩種 access token：

| 類型 | 用途 | 取得方式 |
|---|---|---|
| **靜態（Static）** | 回調請求中的 `access_token` 欄位，用於驗簽 | 直接提供於憑證中 |
| **動態（Dynamic）** | 呼叫 TG API 時放在 Header `Authorization: Bearer {token}` | 透過 OAuth API 請求，有效期 864000 秒（10 天） |

---

## 3. Hashkey 驗簽

所有回調請求都帶有 `hashkey` 欄位，驗證步驟：

```
hashkey = md5(CLIENT_API_KEY + CLIENT_ACCESS_TOKEN)
```

範例：
```
CLIENT_API_KEY    = [REDACTED]
CLIENT_ACCESS_TOKEN = [REDACTED]

md5("3601c0a9fcf126641a49790590548aa88f971b52ccf498b67d17a7d1903ac04b")
= 99012fc38baea4119e0a1cbaf68db0d9
```

驗簽時比對請求中的 `hashkey` 與計算結果是否一致。

---

## 4. Player Token 規範

由**我方（運營商）**負責產生，格式要求嚴格：

- UUID v4 去掉 dash
- 正則：`^[0-9,A-F]{32}$`
- 長度：CHAR(32)
- 字元：`[0-9, A-F]`（大寫）

範例：`17E259F53030A5B6C62FF00765FB47C4`

- Token 不綁定特定遊戲或供應商
- Tiger Games 永遠發送最新的 token
- `refreshtoken=[REDACTED] 時，我方需回傳新的 token

---

## 5. 我方呼叫的 API（Driver 端）

### 5.1 OAuth — `POST /oauth/access_token`

取得動態 Bearer Token，用於後續 API 呼叫的 Header。

**請求參數**（form-urlencoded）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 帳號 |
| `password` | ✅ | string | 密碼 |
| `client_id` | ✅ | int | 客戶 ID |
| `client_secret` | ✅ | string | 客戶密鑰 |
| `grant_type` | ✅ | string | 固定 `"password"` |

**成功響應**

```json
{
    "access_token": "ghtLc3LvPxXr7x9I3252V19XXY09uCy853utub2rmbSirXS",
    "token_type": "Bearer",
    "expires_in": "864000"
}
```

Header 格式：`Authorization: Bearer {access_token}`

### 5.2 Game Launch — `POST /game/launchurl`

產生遊戲啟動 URL。

**請求參數**（form-urlencoded，Header 帶 Bearer Token）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `client_id` | ✅ | int | 客戶 ID |
| `client_player_id` | ✅ | string | 我方玩家 ID（max 45 字元） |
| `username` | 選填 | string | 玩家帳號（max 45 字元） |
| `email` | 選填 | string | 玩家 email |
| `display_name` | 選填 | string | 玩家顯示名 |
| `game_code` | ✅ | string | 遊戲代碼 |
| `game_provider` | ✅ | string | 遊戲供應商名稱 |
| `country_code` | ✅ | string | ISO 3166 Alpha-2 |
| `exitUrl` | 選填 | string | 離開遊戲跳轉 URL |
| `token` | ✅ | string | 我方產生的 player token（CHAR(32)） |
| `lang` | ✅ | string | ISO 639-1 語言代碼 |
| `ip_address` | 選填 | string | 玩家 IP |
| `demo` | 選填 | string | `"true"` 為試玩模式，預設 `"false"` |
| `device` | 選填 | string | `"desktop"` 或 `"mobile"`，預設 `"desktop"` |

**成功響應**

```json
{
    "message": "SUCCESS",
    "game_code": "BonusMania",
    "url": "https://example.invalid/play",
    "game_launch": true
}
```

**失敗響應**

```json
{
    "message": "OTHER_ERROR",
    "game_code": "BonusMania",
    "url": "https://example.invalid/OTHER_ERROR",
    "game_launch": false
}
```

**成功判定**：`game_launch === true` 且 `url` 非空。

### 5.3 Game List — `GET /game/list?client_id={client_id}`

取得可用遊戲清單（Header 帶 Bearer Token）。

**響應範例**

```json
[
    {
        "provider_id": "sp143",
        "provider_name": "KAGaming",
        "icon": "https://xxx.xxx/KAGaming.png",
        "games_list": [
            {
                "game_id": 1,
                "game_name": "Bonus Mania",
                "game_code": "BonusMania",
                "game_provider": "KAGaming",
                "game_type": "Slots",
                "game_icon": "https://xxx.xxx/xxx.jpg",
                "rtp": null,
                "release_date": "0000-00-00"
            }
        ]
    }
]
```

### 5.4 Popular Games — `POST /game/popular?client_id={client_id}`

取得近 30 天 Top 25 熱門遊戲（Header 帶 Bearer Token）。

---

## 6. 回調端點（Controller 端）

Tiger Games 主動呼叫以下端點，由我方實作。所有回調為 `POST`、`Content-Type: application/json`。

### 6.1 Player Detail — `POST {callback_url}/player`

遊戲啟動時呼叫，驗證玩家身份與查詢餘額。

**請求參數**

| 參數 | 類型 | 說明 |
|---|---|---|
| `access_token` | string | 靜態 access token |
| `hashkey` | string | `md5(api_key + access_token)` |
| `type` | string | `"playerdetailsrequest"` |
| `datesent` | string | 請求時間 |
| `gameid` | string | 遊戲代碼（選填） |
| `clientid` | int | 客戶 ID |
| `playerdetailsrequest` | object | 玩家資訊物件 |

**playerdetailsrequest 子欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `client_player_id` | string | 我方玩家 ID |
| `token` | string | Player token |
| `gamelaunch` | boolean | 是否為遊戲啟動 |
| `player_username` | string | 玩家帳號 |
| `refreshtoken` | boolean | `true` 時需回傳新 token |

**成功響應**

```json
{
    "playerdetailsresponse": {
        "status": {
            "code": 200,
            "status": "OK",
            "message": "The request was successfully completed."
        },
        "accountid": "1",
        "accountname": "player1",
        "username": "player1",
        "email": "",
        "balance": 27.00,
        "currencycode": "MYR",
        "country_code": "MY",
        "firstname": "",
        "lastname": "",
        "gender": "",
        "refreshtoken": "NEW_TOKEN_IF_REQUESTED_OR_FALSE"
    }
}
```

**錯誤碼**

| code | status | 說明 |
|---|---|---|
| 200 | SUCCESS | 玩家存在 |
| 402 | PLAYER_NOT_FOUND | 玩家不存在 |

### 6.2 Fund Transfer — `POST {callback_url}/fund`

資金異動（投注/派彩/退款）。同一 `roundId` 會有多個 `transactionId`。

**請求參數**

| 參數 | 類型 | 說明 |
|---|---|---|
| `access_token` | string | 靜態 access token |
| `hashkey` | string | `md5(api_key + access_token)` |
| `type` | string | `"fundtransfer"` |
| `datesent` | string | 請求時間 |
| `gamedetails` | object | 遊戲資訊 |
| `fundtransferrequest` | object | 交易資訊 |

**gamedetails 子欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gameid` | string | 遊戲代碼 |
| `gamename` | string | 遊戲名稱 |
| `provider_name` | string | 遊戲供應商 |

**fundtransferrequest.playerinfo 子欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `username` | string | 玩家帳號 |
| `client_player_id` | string | 我方玩家 ID |
| `token` | string | Player token |

**fundtransferrequest.fundinfo 子欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `gamesessionid` | string | 遊戲 session ID（選填） |
| `transactiontype` | string | `"debit"`（扣款/投注）或 `"credit"`（入款/派彩） |
| `transactionId` | string | **每筆交易唯一 ID**（冪等鍵） |
| `roundId` | string | **回合 ID**（同 round 多筆交易共用） |
| `rollback` | boolean | `true` = 退款/correction；`false` = 正常交易 |
| `currencycode` | string | 幣別 |
| `amount` | float | 交易金額 |
| `endround` | boolean | `true` = 回合結束 |

**交易類型判定**

| transactiontype | rollback | 業務語意 |
|---|---|---|
| `debit` | `false` | 投注（扣款） |
| `credit` | `false` | 派彩（入款） |
| `credit` | `true` | 退款/correction（入款，退回之前扣的錢） |

**成功響應**

```json
{
    "fundtransferresponse": {
        "status": {
            "code": 200,
            "status": "SUCCESS",
            "message": "Transaction successful."
        },
        "balance": 2038.10,
        "currencycode": "MYR"
    }
}
```

**錯誤響應**（HTTP 200，內部 code 402）

```json
{
    "fundtransferresponse": {
        "status": {
            "code": 402,
            "status": "INSUFFICIENT_BALANCE",
            "message": "INSUFFICIENT_BALANCE"
        },
        "balance": 15.21,
        "currencycode": "MYR"
    }
}
```

### 6.3 Inquiry — `POST {callback_url}/inquiry`

查詢交易狀態，對帳用途。

**請求參數**

| 參數 | 類型 | 說明 |
|---|---|---|
| `access_token` | string | 靜態 access token |
| `hashkey` | string | 驗簽 |
| `player_username` | string | 玩家帳號（選填） |
| `client_player_id` | string | 我方玩家 ID |
| `roundId` | string | 回合 ID |
| `transactionId` | string | 交易 ID |

**響應格式**

```json
{
    "code": 200,
    "status": "TRANSACTION_SUCCESS",
    "data": {
        "total_win": 200
    }
}
```

> **注意**：若 `transactionId` 找不到但 `roundId` 存在，仍需回傳該 round 的 total_win（無派彩則 total_win=0）。

**Inquiry 專用錯誤碼**

| code | status | 說明 |
|---|---|---|
| 200 | TRANSACTION_SUCCESS | 交易存在（成功/已收到） |
| 403 | TRANSACTION_PROGRESSING | round 存在但尚無對應 credit（不建議使用） |
| 404 | TRANSACTION_NOT_FOUND | 交易或 round 不存在 |

---

## 7. 錯誤碼

### 7.1 Game Launch 錯誤碼

| status | 說明 |
|---|---|
| SUCCESS | 成功 |
| CLIENT_NOT_FOUND | 帳號不存在 |
| CLIENT_DISABLED | 帳號停用 |
| GAME_NOT_FOUND | 遊戲不存在 |
| GAME_UNDER_MAINTENANCE | 遊戲維護中 |
| PROVIDER_NOT_FOUND | 供應商不存在 |
| PROVIDER_UNDER_MAINTENANCE | 供應商維護中 |
| PLAYER_DISABLED | 玩家被封鎖 |
| TOKEN_ALREADY_EXIST | Token 重複使用 |
| PLAYER_NOT_FOUND | 玩家不存在 |
| GAME_NOT_SUBSCRIBED | 遊戲未訂閱 |
| MISSING_PARAMETER | 缺少必要參數 |
| CURRENCY_NOT_FOUND | 幣別不存在 |
| INTERNAL_ERROR | 內部錯誤 |

### 7.2 Fund Transfer 錯誤碼

| code | status | 說明 |
|---|---|---|
| 200 | SUCCESS | 成功 |
| 402 | INSUFFICIENT_BALANCE | 餘額不足 |
| 402 | GAME_NOT_FOUND | 遊戲不存在 |
| 402 | ROUND_NOT_FOUND | 回合不存在 |
| 402 | TRANSACTION_NOT_FOUND | 交易不存在 |
| 402 | DUPLICATE_TRANSACTION | 重複交易 |
| 402 | OTHER_ERROR | 其他錯誤 |
| 402 | INTERNAL_ERROR | 內部錯誤 |

---

## 8. 重試機制

Tiger Games 的重試行為：

- **Debit（投注）**：請求逾時時，TG 會以原 `transactionId` 發送 rollback（`credit + rollback=true`）退回投注金額
- **Credit（派彩）**：最多重試 5 次，條件為：無回應 / 非 200 HTTP 碼 / 無效回應格式
- **Credit 重試失敗**：超過最大重試次數後轉為手動結算（manual settlement）

---

## 9. 重要注意事項

1. **延遲要求**：所有回調回應須 ≤ 300ms
2. **Token 格式**：嚴格遵守 `^[0-9,A-F]{32}$`，不含特殊字元
3. **金額精度**：兩位小數
4. **同 roundId 多 transactionId**：一個回合會有多筆 debit（投注）和 credit（派彩），需以 `roundId` 彙總 BetRecord，以 `transactionId` 做冪等
5. **rollback=true 的 credit**：是退款，不是正常派彩，需區分處理
6. **endround=true**：標示回合結束，此時可建立 TotalBetRecord
