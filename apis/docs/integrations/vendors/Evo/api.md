# Titan(EVO) 聚合 API 規格

> **來源註記**：TitanHub 官方 apidoc 位於 `https://bo.nexusapp.club/apidoc`（需登入，Appendix 含 GameProviderCode 完整對照）。本檔內容整理自該 apidoc 的既有轉抄與線上整合實際觀測的請求/回應格式；取得官方全文後應以官方為準回頭校正。

## 基本資訊

- **Base URL**：`vendor_infos.info.API_URL`（正式站 `https://example.invalid`）
- **傳輸格式**：HTTP POST + JSON（`Content-Type: application/json`），無簽章、無密鑰
- **共用參數**：所有請求皆帶 `AgentId`（`vendor_infos.info.AGENT_ID`）
- **聚合結構**：Titan 旗下所有子遊戲商共用同一組 API，以 `GameProviderCode` 區分實際遊戲商；各子商的代碼存於 `vendor_infos.info.PROVIDER`

## 回應通用格式

```json
{
  "code": 1,
  "message": "...",
  "data": []
}
```

`code = 1` 為成功，其餘為錯誤（見下方錯誤碼表）。

## Game/GetList（取得遊戲清單）

`POST {API_URL}/Game/GetList`

### 請求

| 參數 | 型別 | 說明 |
|---|---|---|
| `AgentId` | string | 代理識別碼（`info.AGENT_ID`） |
| `GameProviderCode` | string | 遊戲商代碼（`info.PROVIDER`，對照 Appendix） |

### 回應 `data[]`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `code` | string | 遊戲代碼（平台以此為 `games.game_type` 唯一鍵） |
| `name` | object | 多語遊戲名稱，key 為語系代碼（`EN` / `ZH` / `CN` / `TH`） |
| `type` | string | 遊戲類型代碼（見下表） |
| `gameIcon` | string | 遊戲圖示 URL（可能缺欄位） |

### 遊戲類型代碼

| type | 語意 |
|---|---|
| 1 | OTHER |
| 2 | SLOT |
| 3 | CASINO |
| 4 | SPORTBOOK |
| 5 | KENO |
| 6 | LOTTERY |
| 7 | FISHING |
| 8 | ESPORT |

### 語系代碼

`name` 物件的 key：`EN`（英文）、`ZH`（繁中）、`CN`（簡中）、`TH`（泰文）。

## 其他既接端點（本專案 Driver 使用中）

| 端點 | 用途 |
|---|---|
| `POST /User/Register` | 建立玩家帳號（`AgentId` / `LoginId` / `Password`） |
| `POST /User/EditPassword` | 重設密碼（`OldPassword` / `NewPassword` / `Reset`） |
| `POST /User/EditMax` | 設定限紅（`MaxBetLimit` / `BetLimit.EVO.limitId`） |
| `POST /User/UpdateBalance` | 轉帳（`Reference` / `Amount`，負數為轉出） |
| `POST /User/GetBalance` | 查詢餘額 |
| `POST /Game/OpenGame` | 開啟遊戲大廳（直進大廳型子商） |
| `POST /Game/OpenGameByCode` | 以遊戲代碼開啟指定遊戲（`GameCode` / `Language` / `IsMobile`） |

## 錯誤碼

| code | 語意 |
|---|---|
| 0 | FATAL_ERROR |
| 1 | SUCCESS |
| 2 | FAILED |
| 3 | CONTACT_AGENT |
| 4 | SESSION_TIMEOUT |
| 5 | INVALID_AGENT |
| 6 | INVALID_USER |
| 7 | INVALID_GAME_PROVIDER |
| 8 | INVALID_LOGINID |
| 9 | INVALID_PASSWORD |
| 10 | INVALID_AMOUNT |
| 11 | INVALID_CURRENCY |
| 12 | INVALID_ROLE |
| 13 | INVALID_ORDER |
| 14 | INVALID_TYPE |
| 15 | USER_EXISTED |
| 16 | RECORD_EXISTED |
| 17 | RECORD_NOT_FOUND |
| 18 | EXCEED_RECORD |
| 19 | GAME_UNDER_MAINTENANCE |
| 20 | GAME_PROVIDER_ERROR |
| 21 | ACTION_NOT_ALLOWED |
| 22 | EXCEED_BET_LIMIT |
| 23 | INSUFFICIENT_BALANCE |
| 24 | ROW_EXCEED_LIMIT |
| 25 | USER_FREEZED |
| 26 | BET_NOT_CONTAIN |
| 27 | INVALID_REFERENCE |
| 28 | INVALID_URL |
| 29 | REQUEST_TIMEOUT |
| 30 | NO_DEMO_PROVIDED |
| 31 | DATA_PULLING |
| 32 | SEAMLESS_URL_RESPONSE_ERROR |
| 33 | MOBILE_NOT_SUPPORT |
| 34 | DESKTOP_NOT_SUPPORT |
| 35 | SEAMLESS_NOT_SUPPORT |

## Appendix：GameProviderCode 對照（節錄）

完整對照見官方 apidoc Appendix。常用子商：

| GameProviderCode | 遊戲商 | 平台 vendor_id |
|---|---|---|
| 5 | 93Connect | 58 |
| 12 | Evolution Gaming | 57 |
| 13 | Live22 | 59 |
| 47 | UU Slots | 88 |
| 77 | Rich Game Slots | 94 |
