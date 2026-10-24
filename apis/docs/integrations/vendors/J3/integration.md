# J3（vendor_id=122）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `122`（`VendorIdEnum::J3`） |
| 遊戲類型 | 彩票（Lottery） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| Driver | `app/Vendors/J3/Driver.php`（繼承 `AbstractTransferWallet`） |
| 認證方式 | `agent` + `agentpassword` POST 參數，**無加密簽名** |
| Content-Type | `application/x-www-form-urlencoded` |
| Base URL | `http://example.invalid/api/j3/`（文件範例；正式以客服提供為準） |
| 成功判斷 | `status === "success"` |
| 遊戲 URL 組裝 | `{GAME_URL}?token=[REDACTED] |
| 語言代碼 | EN→0、ZH_TW/ZH_CN→1、MS_MY→2、其他→0 |
| 對帳保留期 | **文件未說明**，待客服確認 |
| config key | `vendor_infos.info`：`API_URL`、`AGENT_LOGIN`、`AGENT_PASSWORD`、`GAME_URL` |

## 高風險陷阱

### 1. getbalance 餘額欄位雙重 fallback

- 文件未說明 `getbalance.php` 的回應餘額欄位名稱。
- 實作採用 `$json['balance'] ?? $json['data']` 雙重 fallback：優先讀 `balance`，不存在則讀 `data`。
- **風險**：若上游不回傳這兩個欄位，餘額取值為 `null`，會導致轉帳金額計算錯誤或 Exception。
- 接入前必須以實際請求確認欄位名，或向 J3 客服索取完整文件。

### 2. Additional API 帳號欄位 vs Agent API

- `j3_accounts` 資料表含 `password`、`uniqueid`、`secucode` 三個欄位。
- 這三個欄位是供 **Additional API**（signin/bet/delete/reprint/report）使用，不是 Agent API 的建立帳號參數。
- **Agent API 建立帳號（createuser.php）只需 `login`**，`password`/`uniqueid`/`secucode` 不在此流程。
- 若誤將 Additional API 欄位用於 Agent API 流程會收到認證錯誤。

### 3. topup / withdraw 回應 data 欄位不明

- 文件未說明 `topup.php` 和 `withdraw.php` 轉帳成功後 `data` 欄位的具體內容。
- 實作以 `isset($json['data'])` 作為成功判斷。
- 若 J3 改版後 `data` 欄位在失敗時也存在，此判斷邏輯會失效；`status === "success"` 才是更可靠的判斷。

## 錢包流程（Transfer Wallet）

```
進入遊戲：
  玩家帳號是否存在？
    否 → POST createuser.php（建立帳號）
    是 ↓
  POST topup.php（轉入籌碼）
    → POST gettoken.php（取得一次性 Base64 Token）
    → 組裝 {GAME_URL}?token=[REDACTED]
    → 導向玩家

離開 / 提回：
  POST withdraw.php（轉出籌碼）
```

**流程備注**：
- J3 無「訂單 ID」機制，轉帳不支援二次查單確認。
- `topup` / `withdraw` 成功後直接信任回應，無額外 double-check 機制。
- 遊戲 URL 前綴來自 `vendor_infos.info.GAME_URL`；`lang` 依語言對照表轉換（見速查表）。

## 冪等性

- J3 Agent API **無冪等保護機制**（無訂單 ID，無查單 endpoint）。
- `topup` / `withdraw` 重試可能導致重複轉帳；網路逾時時不能直接 retry，需先用 `getbalance` 確認餘額變化後再決定。
- 建議在本地維護轉帳狀態，避免不確定狀態下重試。

## 整合落地建議

| J3 概念 | APIs 專案對應 |
|---|---|
| `agent` | `vendor_infos.info.AGENT_LOGIN` |
| `agentpassword` | `vendor_infos.info.AGENT_PASSWORD` |
| Base API URL | `vendor_infos.info.API_URL`（Driver 自動 `rtrim('/')` ） |
| 大廳 URL 前綴 | `vendor_infos.info.GAME_URL`（組裝：`{GAME_URL}?token=[REDACTED] |
| 玩家帳號 | `j3_accounts.username`（`J3Account` model） |
| 注單同步 | `j3_bet_records`（見下方欄位說明） |
| Driver 路徑 | `app/Vendors/J3/Driver.php` |
| Driver 註冊 | `config/vendor.php`：`VendorIdEnum::J3->value => Vendors\J3\Driver::class` |
| 回調 Controller | **無**（Transfer Wallet 不接收 J3 推送） |

### j3_accounts 欄位說明

| 欄位 | 說明 | 用途 |
|---|---|---|
| `vendor_info_id` | FK → `vendor_infos.id` | 多白標隔離 |
| `username` | J3 玩家帳號（唯一） | Agent API 共用 |
| `member_id` | 本地會員 ID | 對應關係 |
| `admin` | 預設 1 | J3 帳號類型 |
| `password` | Additional API 密碼 | **非 Agent API 欄位** |
| `uniqueid` | Additional API 唯一碼 | **非 Agent API 欄位** |
| `secucode` | Additional API 安全碼 | **非 Agent API 欄位** |

### j3_bet_records 欄位說明

| 欄位 | 說明 |
|---|---|
| `bet_id` | 注單 ID，對應 J3 `BetID`（唯一） |
| `username` | 玩家帳號，FK → `j3_accounts.username` |
| `game_code` | 遊戲編號 |
| `bet` | 下注金額（4 位小數） |
| `valid_bet` | 有效下注金額（4 位小數） |
| `win_lose` | 輸贏金額（4 位小數） |
| `bet_time` | 下注時間 |
| `payout_time` | 派彩/取消時間 |
| `infos` | 詳細資訊（原始注單 JSON） |
| `result_infos` | 結果資訊（JSON） |

`vendor_infos.info` 必要欄位：

```jsonc
{
  "API_URL":        "http://example.invalid/api/j3",
  "AGENT_LOGIN":    "<代理帳號，客服提供>",
  "AGENT_PASSWORD": "<代理密碼，客服提供>",
  "GAME_URL":       "<大廳 URL 前綴，客服提供>"
}
```

## Gaps / 待 J3 確認

| # | 項目 | 說明 |
|---|---|---|
| 1 | **getbalance 餘額欄位名** | 文件未列 `balance` 欄位名；實作以 `balance` → `data` 雙重 fallback 處理，待客服確認實際欄位名。 |
| 2 | **對帳報表保留期** | 文件未說明；需向 J3 客服確認報表保留天數，以設定對帳拉取頻率。 |
| 3 | **正式 Base URL** | 文件列 `http://example.invalid/api/j3/`，未明確區分 Dev/Prod；正式接入 URL 以客服提供為準。 |
| 4 | **topup / withdraw `data` 欄位內容** | 文件未說明轉帳成功後 `data` 的具體值；實作以 `isset` 判斷，實際內容不明。 |
| 5 | **IP 白名單** | 文件未提及是否需要向 J3 申報出口 IP。 |
| 6 | **Additional API 整合可行性** | signin/bet/delete/reprint/report 目前未整合；若需接入，需先向 J3 客服確認 `uniqueid`/`secucode` 產生機制與申請流程。 |
