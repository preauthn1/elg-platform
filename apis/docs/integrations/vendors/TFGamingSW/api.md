# TFGamingSW（雷火電競，vid 114）— 廠商原始 API 規格

> 原始來源：`main_wallet_chinese_v8.pdf`（標題頁標示「主钱包接口文档 — 应用和说明（版本 7.0）／雷火电竞」）。
> 抓取日期：2026-06-03。來源路徑：`/Users/rb/Workspace/遊戲商/114.TFGamingSW/main_wallet_chinese_v8.pdf`。
> ⚠️ PDF 內含大量無法解析字元（字型／編碼缺失，呈方框 `▯`）：下注金額、超時秒數、HTTP 狀態碼、回應碼數值多處無法直接讀出。本檔回調欄位與流程語意以**專案實作**（`app/Vendors/TFGamingSW/`）為主要重建依據。
> 本檔為忠實的第三方 API 規格重述。我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

TFGaming（雷火電競，**vid 114**）為電競 / 體育類遊戲商，採 **Main Wallet（主錢包＝單一錢包 / Seamless）** 模式：玩家餘額由本平台保管，TFGaming 在下注、結算、重新結算、回滾時透過回調即時扣 / 加款。

---

## 通用約定

### 兩個方向的 API

| 方向 | 用途 | 認證 | 對應實作 |
|---|---|---|---|
| **出站**（平台 → TFGaming） | 進入遊戲：member-login 取 launch URL、partner-account-logout | `Authorization: <PRIVATE_TOKEN>` header | `Driver.php` |
| **入站 / 回調**（TFGaming → 平台） | 查餘額、金額調動（下注 / 結算 / 重新結算 / 回滾） | IP 白名單 | `TFGamingSWController.php` |

### 請求格式

- **HTTP Method**：出站 login / logout 為 `POST`；回調 `wallet`（查餘額）為 `GET`，`transfer` / `rollback` 為 `POST`。
- **Content-Type**：`application/json`。

### 驗證方式（取代簽章）

本整合**無**雜湊或對稱加密簽章機制。

- **出站方向**：HTTP header 帶 `Authorization: <PRIVATE_TOKEN>`，body 帶 `operator_id`。
- **入站 / 回調方向**：以 **IP 白名單**保護（`Common::verifyIPWhitelistByVendorId(114)`）。未通過回應：

```jsonc
{ "Status": 6, "Description": "IP <ip> not allow", "ResponseDateTime": "Y-m-d H:i:s" }
```

- PDF 標題另提及 `PUBLIC_TOKEN`，但出站／入站程式碼僅實際使用 `PRIVATE_TOKEN`；`PUBLIC_TOKEN` 用途待確認。

### Base URL

PDF **未提供正式 Base URL**；實際以 `vendor_infos.info.API_URL`（出站 API base）與 `GAME_URL` 為準，由 TFGaming 客服提供。

---

## 出站 API（平台 → TFGaming）

| 用途 | 端點 | 認證 | 回應要求 |
|---|---|---|---|
| 合作夥伴帳號登出（先登出舊 session） | `POST {API_URL}/api/v2/partner-account-logout/` | `Authorization: PRIVATE_TOKEN`；body `{ member, operator_id }` | 回應需含 `member`，否則拋 `ApiException` |
| 會員登入取 launch URL | `POST {API_URL}/api/v2/member-login/` | `Authorization: PRIVATE_TOKEN`；body `{ member_code }` | 回應需含 `launch_url`，否則拋 `ApiException` |

進遊戲流程：`getGameUrl()` → 先 `partner-account-logout` 登出舊 session → `member-login` 取 `launch_url`（pc / mobile 共用）。

---

## 入站回調 API（TFGaming → 平台）

| # | 用途 | Method | 路由（prefix `tfgaming`） |
|---|---|---|---|
| 1 | 會員錢包查詢（取餘額） | `GET` | `tfgaming/wallet` |
| 2 | 金額調動（下注 / 結算 / 重新結算） | `POST` | `tfgaming/transfer` |
| 3 | 回滾（撤單退注） | `POST` | `tfgaming/rollback` |

> 回調 body **不帶簽章**，明文 JSON；安全靠 IP 白名單。完整路由見 `routes/external/sw.php`。

### 1. 會員錢包查詢（GET `tfgaming/wallet`）

Request：`loginName`（String，必填，會員帳號）。
Success：`{ "code": 0, "balance": "100.0000" }`。
Fail（HTTP 400）：`{ "code": <errCode>, "msg": "<msg>" }`。

### 2. 金額調動（POST `tfgaming/transfer`）

下注、結算、重新結算共用同一格式，由 `placeBet` + `description` 區分。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `loginName` | String | ✅ | 會員帳號 |
| `amount` | Numeric | ✅ | 金額（下注為負、結算贏為正、重新結算為負）；進場先 `numDiv(amount, rate)` 換算本地幣 |
| `placeBet` | Boolean | ✅ | `true`=下注；`false`=結算 / 重新結算 |
| `ticketNum` | String | ✅ | 本次事件單號；結算後格式為 `<原注單>_<時間戳>` |
| `description` | String | ✅ | `Place bet` / `Settlement` / `Loss` / `Unsettlement` |
| `ticketDetail` | Object | ✅ | 注單物件（見下「ticketDetail」節） |
| `ticketDetail.id` | String | ✅ | 原始注單號（結算 / 沖銷定位下注紀錄） |
| `ticketDetail.date_created` | String | ✅ | 建立時間 |
| `ticketDetail.settlement_datetime` | String\|null | 否 | 結算時間（空則回退用 `date_created`） |

分派（由 `placeBet` + `description`）：

| `placeBet` | `description` | 行為 |
|---|---|---|
| `true` | `Place bet` | 下注扣款 |
| `false` | `Settlement` / `Loss` | 結算派彩（贏 / 輸） |
| `false` | `Unsettlement` | 重新結算（沖銷先前結算） |

> 輸的結算（`description: "Loss"`）預設不發送，需向 TFGaming 客服要求開啟。

請求範例（下注 / 結算）：

```jsonc
// 下注
{ "loginName": "ABC", "amount": -20.00, "placeBet": true, "ticketNum": "XYZ",
  "description": "Place bet", "ticketDetail": { "id": "...", "date_created": "...", "settlement_datetime": null } }

// 結算
{ "loginName": "ABC", "amount": 38.50, "placeBet": false, "ticketNum": "XYZ_20190620182933",
  "description": "Settlement", "ticketDetail": { "id": "...", "date_created": "...", "settlement_datetime": "..." } }
```

Success：`{ "code": 0, "msg": "success" }`；Fail（HTTP 400）：`{ "code": <errCode>, "msg": "<msg>" }`。

### 3. 回滾（POST `tfgaming/rollback`）

PDF：「回滾只有在下注的情況才有」——僅允許回滾**未結算**的下注。Request 與 transfer 同結構（`description` 範例 `"Place bet rollback"`、`placeBet: true`）。

---

## ErrorCodeEnum（回應碼，`app/Vendors/TFGamingSW/Enums/ErrorCodeEnum.php`）

| code | case | 說明 |
|---|---|---|
| 0 | `SUCCESS` | 成功 |
| 1 | `UNDEFINED` | 未定義錯誤 |
| 2 | `WITHDRAWAL_NOT_ALLOWED` | 上次交易後 3 秒內不允許提款（被借用為「找不到注單」等） |
| 3 | `USER_NOT_FOUND` | 會員不存在 |
| 4 | `INVALID_AMOUNT` | 金額無效 |
| 5 | `INSUFFICIENT_BALANCE` | 餘額不足 |
| 6 | `INVALID_OPERATOR` | operator id 無效 / 廠商關閉 / IP 未授權 |
| 7 | `INVALID_RANGE` | 超出允許範圍 |
| 8 | `CURRENCY_MISMATCH` | 幣別不符 |
| 9 | `INVALID_AUTH` | 認證憑證無效 |
| 10 | `ACCOUNT_FROZEN` | 帳號凍結 |
| 11 | `MISSING_FIELDS` | 缺少必填欄位 |
| 12 | `AUTH_NOT_PROVIDED` | 未提供認證憑證 |
| 13 | `INVALID_TABLE_STATE` | 桌台狀態無效 |
| 14 | `WALLET_ERROR` | 錢包錯誤 |
| 15 | `INVALID_MEMBER_STATE` | 會員狀態無效（帳號停用 / 流水未達） |

> ⚠️ 此碼集為內部 / 通用碼集；PDF 字型缺失無法佐證 TFGaming 端期望的成功 / 錯誤碼數值，是否與 TFGaming 規範一致待確認。

---

## ticketDetail（注單物件）

TFGaming Bet Transaction 注單物件。重點欄位：

| 欄位 | 說明 |
|---|---|
| `id` | 注單號（character varying(64)），作為定位下注紀錄的鍵 |
| `date_created` | 注單建立時間 |
| `settlement_datetime` | 結算時間（可空；空則回退 `date_created`） |
| `odds` / `malay_odds` / `euro_odds` | 賠率（馬來盤 / 歐洲盤） |

> 完整 `results[0]` 結構見 PDF 內 Postman 文件：`https://example.invalid/view/4350692/S1Zz6pfi`（GET Bet Transaction，對帳拉單用途）。

---

## 超時與重試（PDF 描述，數值因字型缺失無法讀出）

- **下注**：TFGaming「只會等待 N 秒」，逾時不重試而斷線，改以**回滾**流程補回會員金額。
- **回滾失敗 / 結算 / 重新結算逾時**：排進系統，每 N 分鐘請求一次、持續 N 小時。
- **重複回滾**：代理已回滾過又收到重複請求，「請回我們狀態碼」即可讓 TFGaming 將該請求移出排程。
- **重新結算**：PDF 提示「請允許餘額扣除低於（值無法讀出）」——即沖銷派彩時容許餘額為負。

---

## 下注 / 結算完整流程（PDF 唯一可讀範例）

| # | 動作 | placeBet | amount | ticketNum |
|---|---|---|---|---|
| 1 | 下注 | true | -20.00 | `EFG` |
| 2 | 回滾 | true | -20.00 | `EFG` |
| 3 | 下注 | true | -20.00 | `ABC` |
| 4 | 結算 | false | 38.50 | `ABC_20200422135112` |
| 5 | 重新結算 | false | -38.50 | `ABC_20200422141320` |
| 6 | 結算 | false | 38.50 | `ABC_20200422141503` |

同一原始注單 `ABC` 結算後 `ticketNum` 變為 `ABC_<時間戳>`；重新結算（Unsettle）以負額沖銷，再次結算以新時間戳 `ticketNum` 重新派彩。以 `ticketDetail.id`（原始注單號）串連這些事件。

---

## Sources

- `main_wallet_chinese_v8.pdf`（雷火電競「主錢包接口文檔」v7.0），`/Users/rb/Workspace/遊戲商/114.TFGamingSW/`，2026-06-03 讀取（PDF 多處字型缺失，以實作交叉重建）。
- 交叉驗證：`app/Vendors/TFGamingSW/Driver.php`、`TFGamingSWService.php`、`Libs/{Bet,Settle,Unsettle,Rollback}.php`、`Enums/ErrorCodeEnum.php`、`Datas/TransferInfoData.php`；`app/Http/Controllers/External/SingleWallets/TFGamingSWController.php`、`app/Http/Requests/External/TFGamingSW/TransferRequest.php`、`routes/external/sw.php`。
