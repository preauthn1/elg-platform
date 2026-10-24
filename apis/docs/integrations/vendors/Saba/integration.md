# Saba（vendor_id=44）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet（轉帳錢包）；玩家餘額由 Saba 端保管 |
| 廠商 | 沙巴體育（IBCbet） |
| 請求格式 | `POST` + `application/x-www-form-urlencoded`；回應為 JSON |
| 驗證方式 | `vendor_id` 字串 + IP 白名單（閘道層）；**無簽章演算法** |
| 成功判定 | `error_code=0` |
| 時間格式 | ISO-8601 |
| 產品線 | `wallet_id=1`=體育；`wallet_id=5`=AG；`wallet_id=13`=Exchange |
| 注單同步 | **版本號輪詢拉單**（GetBetDetail），無 Merchant callback |

## 高風險陷阱

### 1. 無簽章演算法（與其他廠商不同）

Saba 官方文檔**未定義任何 signature / HMAC 機制**。請求參數以明文 form 欄位直接送出，驗證僅靠：① `vendor_id` 字串比對；② 閘道層 IP 白名單。需確保 `vendor_id` 不外洩，避免仿冒請求。

### 2. FundTransfer TIMEOUT 後必查 CheckFundTransfer（至少等 5 分鐘）

| FundTransfer 條件 | Driver 判定 |
|---|---|
| `error_code=0` | `SUCCESS` |
| `error_code=1` 且 `status=1` | `FAILED` |
| `error_code=1` 且 `status=2` | `TIMEOUT` → 5 分鐘後呼叫 CheckFundTransfer |
| 回應為空（無 JSON） | `TIMEOUT` |
| 其他 | `FAILED` |

文檔明示：**存款方向**遇 TIMEOUT 須先不動作，等確認成功後才入帳；**提款方向**遇 TIMEOUT 須先不加本地餘額，確認後才加。若跳過 CheckFundTransfer 直接判 FAILED 重做轉帳，可能造成重複扣款。

### 3. CheckFundTransfer 五態解讀

| CheckFundTransfer 條件 | Driver 判定 |
|---|---|
| `error_code=0` 且 `status=0` | `SUCCESS` |
| `error_code=0` 且 `status=1` | `FAILED` |
| `error_code=1` 且 `status=2` | `TIMEOUT`（再次輪詢） |
| `error_code=2` | `FAILED`（無此轉帳，須重新做） |
| `error_code=3` | `TIMEOUT`（依 `message` 秒數後重查） |
| 回應為空 | `TIMEOUT` |
| 其他 | `FAILED` |

> `error_code=3` 的等待單位：api.md §7 原文為「分鐘數」，但本專案 api.md 整合時原始文檔 §7 說「秒數」，兩者矛盾——見 gaps §1。

### 4. GetWidgetUrl 不在官方文檔

`Driver::getWidgetUrl()` 呼叫 `/api/GetWidgetUrl`，但 `api_ch_20230613.pdf`（343 頁）全文無 `widget` 字樣。研判為對接閘道（aggregator）擴充端點，規格需向對接方 / RD 確認（見 gaps §2）。

### 5. 版本號跳號為正常現象

`GetBetDetail` 的 `version_key` 遞增但**不一定連號**（all pool 排列，跳號正常）。每次最多回 500 筆，偶爾不到 500 也屬正常。同一張注單狀態變更（waiting→running→lose）`trans_id` 不變但 `version_key` 會變，需輪詢補抓。

## 轉帳錢包流程與狀態機

```
CreateMember（首次一次）
       │
       ▼
FundTransfer direction=1（transferToVendor，存款）
       │
       ▼
GetSabaUrl（取得遊戲登入連結） → 玩家進入遊戲下注
       │
       ▼
FundTransfer direction=0（transferToLocal，提款）
       │
       ▼
  狀態不明 → CheckFundTransfer（5 分鐘後）
```

**注意**：建立會員後須先成功 `FundTransfer` 才能下注（Saba 文檔明示）。

## 注單對帳（版本號輪詢）

- 首次從 `version_key=0` 開始拉，每次帶入上次回傳的 `last_version_key`。
- 建議頻率 **6~10 秒一次**，最多 500 筆/次。
- 對帳時間欄位以 `winlost_datetime`（決勝時間）歸帳，**不是** `transaction_time`。
- 回溯指定日期：`GetVersionkeyByDate` 取得 `version_key`，**減 1** 後帶入 `GetBetDetail`。
- 保留期限：官方文檔未明示，建議向客服確認後訂對帳排程。
- RNG 類注單（Saba Casino / PP / SG / PG Soft / Joker 等）透過 `GetBetDetail` 拿到的是每位玩家 / 每遊戲類型 / 每天的**彙總單**；細單需另用 `GetRNGBetDetail`。

## 整合落地建議

| Saba 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Saba/Driver.php`（`AbstractTransferWallet`，實作 `transferToVendor` / `transferToLocal` / `checkTransferStatus`） |
| BetRecord Model | `app/Vendors/Models/SabaBetRecord`（`saba_bet_records` 表） |
| 注單同步路徑 | `GetBetDetail` 版本號輪詢 → 寫入 `saba_bet_records`；**無 SW 回調 Controller / 無 sw.php 路由** |

### vendor_infos.info 欄位

| 欄位 | 說明 |
|---|---|
| `API_URL` | 對外網域（對接閘道網域，`{domain name}`，由對接方提供） |
| `VENDOR_ID` | 厂商标识符（每支 API 必帶） |
| `OPERATOR_ID` | 厂商 ID（自定義；轉帳 ID = `OPERATOR_ID . '_' . billNo`） |
| `WALLET_ID` | 產品代碼（體育 = `1`，AG = `5`，Exchange = `13`） |
| `CURRENCY` | 幣別代碼（見附件「幣別表」） |
| `ODDSTYPE` | 盤口 / 賠率類型（預設 `'1'` 歐洲盤） |
| `WIDGET_ID` | 小工具 ID（預設 `'00000000'`，僅 GetWidgetUrl 用） |

### 帳號注意事項

| 欄位 | 說明 |
|---|---|
| `saba_accounts.username` | 原始帳號 |
| `saba_accounts.username_modified` | 含前綴帳號（`vendor_member_id`，送給 Saba 的帳號） |

- **大小寫不敏感**：`vendor_member_id` 不分大小寫（`ABC` 與 `abc` 視為同一帳號）。
- **環境隔離**：測試帳號建議加後綴 `_test`，避免與正式環境帳號衝突。
- **前綴字符限制**：`vendor_member_id` / `username` 僅支援 ASCII 33–126，且有前綴合法性驗證（Error 8）。

### `amount` 精度

`FundTransfer` 金額以 `Common::numFloor($credit, 2)` 無條件捨去到小數 2 位送出（文檔定義 `decimal(10,2)`）。

## gaps / 待確認

1. **`CheckFundTransfer` error_code=3 等待單位矛盾**：原始文檔原文寫「秒數」、本整合版寫「分鐘數」，與官方文檔原文需核對，上線前確認以哪個單位等待後重查。
2. **`GetWidgetUrl` 規格**：未見於 `api_ch_20230613.pdf`，屬閘道擴充端點。參數（`widgetId` / `odds_type` / `domain` / `language`）為程式碼推得，需向對接方索取官方規格。
3. **注單保留期限**：官方文檔正文未明示最大可回溯天數，建議向 Saba 客服確認後訂對帳排程上限。
4. **`amount` 精度：捨去 vs 四捨五入**：本專案用捨去，確認與 Saba 端 `mintransfer` 校驗的相容性（捨去後若低於最小轉帳金額會回 Error 4）。
5. **盤口來源**：`OType` 由 CreateMember 時的 `oddstype` 決定（會員設定），與文檔「取得連結後自行帶入 URL」的描述有差異，待與 RD 確認兩種機制的實際效果。
