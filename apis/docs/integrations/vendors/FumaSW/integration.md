# FumaSW（vendor_id=171）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless Wallet） |
| vendor_id | `171`（FumaSW）、`214`（FUMASW_FREE，免盤費子商戶） |
| 回調方向 | FumaGames → 本平台（callback URL：`POST /fuma`） |
| 驗證方式 | AES-128-CBC + Base64 URL Safe（雙向加解密） |
| 成功判定 | `status == "0000"` |
| Action 分派 | 1=取餘額（4 秒輪詢）、2=下注扣款、3=結算派彩 |
| 本平台主動呼叫 | action 4=踢人、5=取遊戲 URL、6=每日報表、7=遊戲列表、8=inGame查詢、9=踢所有下線、10=查詢遊戲結果 URL、15=建立子代理、16=遊戲詳細 JSON |
| 時區 | 文件未揭露，待確認 |
| 憑證儲存 | `vendor_infos.info`：`DC`、`KEY`、`IV`、`API_URL`、`PARENT` |
| 對帳保留期 | 每日報表（action 6）限 3 個月；詳細注單（action 16）限 30 天 |

---

## 高風險陷阱

### windowMode 與 balance 參數落差（action 5）

文件要求 action 5（取遊戲 URL）必須帶 `windowMode: '2'` 與 `balance`（玩家當前餘額），但實作存在兩處落差：

1. **`windowMode`**：`Driver.php:79` 目前傳 `'0'`，文件說「只需要發 `2`」，且 `lobbyURL` 只在 `windowMode=2` 時有效。若 FumaGames 端驗證此欄位，可能導致 lobby 跳轉失敗。
2. **`balance` 未帶入**：文件明確列 `balance` 為必填欄位，實作中 `data` 陣列未帶此值。若 FumaGames 用此欄位初始化遊戲內顯示餘額，玩家可能看到 0 或異常金額。

處置：上線前須與 FumaGames 客服確認這兩個欄位的實際驗證行為，視結果修正 `Driver.php`。

### PKCS7 Unpadding 與官方 trim() 的差異

加密流程使用 **Space Padding**（以空格填充至 16 byte 對齊）+ `OPENSSL_NO_PADDING`。解密時：

- **官方文件範例**（§19.2）：`trim($decrypted)` 清除尾部空格
- **實作**（`FumaSWService::pkcs7_unpad()`）：讀取最後一個 byte 的 ASCII 值作為 pad 長度移除

若 FumaGames 的 callback payload 確實以空格填充，且空格 ASCII(32) 恰好等於 pad byte 值（32 = 2 個空格 block），兩者行為可能不同，導致解密後 JSON 尾部殘留空格而 `json_decode()` 失敗。建議觀察線上解密失敗 log，若有異常改回 `trim()`。

---

## BetRecord 狀態機

FumaSW 為單一錢包，BetRecord 由 callback action 驅動：

```
玩家登入遊戲
      │
      ▼
action=1（Get Balance）─── FumaGames 每 4 秒輪詢一次
      │
玩家下注
      │
      ▼
action=2（Bet）─── 扣除 bet 金額，建立 fumasw_bet_records（BET type）
      │              返回 status="0000" + 剩餘 balance
      │
FumaGames 計算結果
      │
      ▼
action=3（Settle）─── 發放 win 金額，建立 fumasw_bet_records（SETTLE type）
                       或更新 TBR（free spin 多次結算時）
                       返回 status="0000" + 最新 balance
```

**免費旋轉（Free Spin）**：

- `bet=0` 的 action 2 表示免費旋轉下注（不扣款）
- 每次免費旋轉會有獨立一組 action 2 + action 3
- 多組共用同一個 `freewin_roundId`
- action 3 的 `lastfree=1` 時才是最後一次免費旋轉
- `TotalBetRecordService::createOrUpdate()`：BetRecord `status=0` 更新、`status=1` 新建

**`fumasw_bet_records.status`（只對 BET 列有意義）**：

| status | 語意 | 誰寫 |
|---|---|---|
| `0` | 未派彩（action 2 已扣款、等 action 3） | `Libs/Bet.php` |
| `1` | 已派彩 | `Libs/Settle.php` |
| `2` | 已退款——三方判定投注失敗、永不派彩，我方主動把下注金額退回錢包（對齊 `BetRecordStatusEnum::REFUND`） | `php artisan bet-record-recovery:refund-unsettled --vendor=fumasw` |

**三方 timeout 造成的「已扣款、永不派彩」注單**：FumaSW 協定沒有取消 action，若我方 action 2 回應超過三方 timeout，三方會判定投注失敗且不再送任何請求（2026-09-05 TPower DDoS 事故實證：43 筆停在 `type=1／status=0`）。處置走 `bet-record-recovery:refund-unsettled`（預設 dry-run，`--execute` 必填 `--reason`）：退回 `bet − rolling_chips`、帳變流水 type 28（`SEAMLESS_WALLET_CANCEL`）、status 改 2、`infos` 追加 `_refund`、寫 `bet_record_recovery_logs`（type=refund）；不動 TBR／SBR（這類注單本來就沒進去）。含禮碼的注單命令會拒絕，需人工另案。

---

## 冪等性

| 場景 | 處理方式 | 回傳碼 |
|---|---|---|
| 重複 `transferId`（action 2 重送） | MySQL unique key（`fumasw_bet_records.bet_id`）觸發 error 1062 | `9011` |
| 重複 `transferId`（action 3 重送） | 同上 unique key 防護 | `9011` |
| 同 roundId 並發多筆 action 2 | `GET_LOCK('fumasw:{md5(roundId:uid)}', 5)` 命名鎖序列化，取不到鎖 | `9011` |
| action 3 先於 action 2 到達 | `Settle::lockAndGetPlaceBetRecord()` 找不到 BET 記錄 | `SYSTEM_ERROR` |
| 已退款（status=2）的 BET 列又收到 action 3 | `Settle::lockAndGetPlaceBetRecord()` 拒絕結算並記 warning log；不建派彩列、不動錢包、不改 status | `SYSTEM_ERROR`（9999） |

---

## 整合落地建議

| FumaGames 概念 | APIs 專案對應 |
|---|---|
| `dc` | `vendor_infos.info.DC` |
| `KEY` | `vendor_infos.info.KEY` |
| `IV` | `vendor_infos.info.IV` |
| `API_URL`（Base URL） | `vendor_infos.info.API_URL`（由客服提供） |
| `parent`（代理帳號） | `vendor_infos.info.PARENT` |
| `uid`（玩家帳號） | `fumasw_accounts.username` |
| Driver | `app/Vendors/FumaSW/Driver.php`（繼承 `AbstractSingleWallet`） |
| Callback Service | `app/Vendors/FumaSW/FumaSWService.php` |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/FumaSWController.php` |
| 回調路由 | `routes/external/sw.php` → `POST /fuma` |
| 帳號 Model | `app/Vendors/Models/FumaSwAccount.php` |
| BetRecord Model | `app/Vendors/Models/FumaSwBetRecord.php` |
| IP 白名單驗證 | `Common::verifyIPWhitelistByVendorId(VendorIdEnum::FUMASW->value)` |
| SBR 重建 | 需建立 `App\Services\SummaryBetRecords\FumaSW`，`$timeField = 'payout_time'` |

---

## Gaps / 待 FumaGames 確認

| # | 問題 | 狀態 |
|---|---|---|
| 1 | **正式 Base URL**：文件以 `{API_Address}` 佔位，須向客服索取 | 未知 |
| 2 | **測試環境 URL / IP 白名單**：連線說明表均為「待填」 | 未知 |
| 3 | **callback 來源 IP**：正式環境 IP 未揭露，需設定白名單 | 未知 |
| 4 | **action 16 範例 typo**：範例 JSON 寫 `"action": 12`，但章節定義是 action=16，應為文件筆誤 | 推測筆誤 |
| 5 | **`windowMode` 實際值**：文件要求 `'2'`，實作送 `'0'`，需確認 FumaGames 是否驗證 | 待確認 |
| 6 | **`balance` 是否必填（action 5）**：文件列為必填，實作未傳，需確認影響 | 待確認 |
| 7 | **PKCS7 vs 空格 padding**：callback 解密時 pad 方式需對齊，觀察線上 log | 待觀察 |
| 8 | **`ts` 容差範圍**：error 9005 為時間戳驗證失敗，允許偏差未說明 | 未知 |
| 9 | **`freewin_roundId` / `freetotalwin`**：需向 FumaGames 申請才會傳送 | 未申請 |
| 10 | **FUMASW_FREE（vendor_id=214）**：免盤費商戶的業務邏輯與主商戶差異 | 內部邏輯 |
