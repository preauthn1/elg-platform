# Play8oy（vendor_id=30）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Play8oy 端保管 |
| 平台品牌 | iGaming（同平台另有 Clubsuncity、GW99、Kenzo 等品牌） |
| 文件版本 | v10.1（2022-05） |
| HTTP Method | 所有 API 一律 `POST` |
| 驗證方式 | IP 白名單 + Header 簽章（`merchantName` + `Sign`，SHA256 raw bytes → Base64） |
| 成功判定 | `Code == '0'`（注意：實作以**字串** `'0'` 比較） |
| 進入遊戲 | App deep-link：`lobbyplay8oy://lobbyplay8oy?account={username}&password=[REDACTED] |
| 轉帳方向 | 存款=正數 `Amount`（`transferToVendor`），提款=負數 `Amount`（`transferToLocal`） |

## 高風險陷阱

### 1. `Code` 比較用字串，不用整數

實作一律以 `Code` 是否等於字串 `'0'` 判斷成功。官方 JSON 範例 `Code` 值實際為整數，但 PHP `json_decode` 預設轉型後嚴格比較 `=== '0'` 會失敗。確認實作中 `Code` 比較是否已做型別轉換（或使用 `==` 寬鬆比較）。

### 2. 簽章字串必須以原始值串接，不可 URL-encode（2026-09 已修正）

官方範例中簽章字串為原始未編碼形式。舊實作以 `ksort()` + `http_build_query()` 組字串，會對參數值做 percent-encode（空白→`+`、冒號→`%3A`）。

- 帳號、金額、交易號為純英數字時兩者一致，轉帳／查單不受影響。
- `game/log/record` 的 `StartDate`／`EndDate` 含空白與冒號，舊實作會與驗章不符。

`sign()` 已改為 `ksort()` 後以 `key=value&…&PrivateKey={key}` 原始字串串接（target/tickets#4434，SunCity 拉單需要）；純英數參數新舊輸出等值由 `tests/Feature/Vendors/Play8oy/DriverTest.php` 釘住。**禁止改回 `http_build_query`。**

2026-10-01 已核對各站 vendor_infos（VWorld 3 筆、TPower 1 筆）之 account_prefix／API_AGENT／API_MERCHANT_NAME 與全部 play8oy_accounts.username 僅含英數與 `_`，新舊簽名對線上參數等值。

### 3. `API_KEY`（PrivateKey）寫入 log 必須遮罩

`API_KEY` 作為簽章金鑰，寫入 log / 通知前必須以 `Common::maskCredentials()` 遮罩。

### 4. 玩家建立（已實作）

`createAccount()` 已實作（遷自 RD-Studio `create_play8oy_account`）：本地先以 `member_id = -999999999` 佔位建列，呼叫 `player/create` 成功後才改成 `0` 釋出到帳號池；三方失敗則保留佔位、不進帳號池。

### 5. `transaction/check` 的 `-90609` 歸類為 FAILED

`-90609 Transaction does not exist.` 在實作中歸 `FAILED`。若交易剛送出 Play8oy 端尚未落庫時也回 `-90609`，TIMEOUT 後補查可能誤判為失敗（導致不必要的錯誤流程）。

> 建議向客服確認 `-90609` 是否可能出現在交易剛送出的時間窗（見 [api.md §10](api.md#10-examine-transaction-results--transactioncheck)）。

### 6. 業務錯誤碼走 HTTP 400，禁止 `->throw()`（2026-10 已修正）

iGaming 只有成功回 HTTP 200，`-90607`／`-90606`／`-90608`／`-90001`／`-90208` 等業務碼都是 HTTP 400。舊實作每個呼叫都 `->throw()`，明確拒絕被攔成 `RequestException`，轉帳一律卡 8888（UNKNOWN_TRANSFER_FAILED）不退款，`FAILED` 分支永遠到不了（dev 實例：transaction_log 224274994，`-90607` 被當 8888）。現改經 `postJson()`＋`decode()`：只有 HTTP 200／400 帶 `Code` 才交業務碼分類，5xx／非 JSON／其他 4xx（gateway／WAF）一律結果不明（轉帳 `TIMEOUT`）。實測對照表與各方法處置見 [`../Suncity/integration.md` §5](../Suncity/integration.md#5-業務錯誤碼走-http-400)。

## 轉帳流程與冪等性

```
進入遊戲（玩家須已存在於 Play8oy 端）：
  transaction/create  Amount=+X   本地餘額 → Play8oy（存款）
  └─ 回 ApiTransId，記錄備查

取得 App 啟動連結：
  GET_DOWNLOAD_URL=true  → 呼叫 appurl 取動態下載連結
  GET_DOWNLOAD_URL=false → 用靜態 IOS_DOWNLOAD_URL / ANDROID_DOWNLOAD_URL
  本地組裝 deep-link：lobbyplay8oy://lobbyplay8oy?account={username}&password=[REDACTED]

玩家遊玩（餘額在 Play8oy 端）

離開時：
  transaction/create  Amount=-X   Play8oy → 本地（提款）

逾時補查：
  transaction/check   以 ExternalTransactionId 補查最終狀態
```

業務碼不論 HTTP 200 或 400 都依下表分類（見高風險陷阱 6）。分類原則：**不確定就不自動退款；只有三方明確拒絕才 `FAILED`。** 轉帳回 `FAILED` 時 `MemberTransferService` 會立即退款，三方若其實已入帳就雙重入帳；結果不明一律 `TIMEOUT`，交給補查與人工確認。碼表與分類在 `app/Vendors/Play8oy/Enums/TransactionResponseCodeEnum.php`（SunCity 繼承同一套）。

`transaction/create`（轉帳）：

| 轉帳回應 | VendorTransferResultEnum |
|---|---|
| HTTP 5xx、200／400 以外的 4xx、無 JSON 回應（含 WAF HTML） | `TIMEOUT` |
| `0` | `SUCCESS` |
| 明確拒絕：`-90208` 玩家不存在、`-90212` 已凍結、`-90600`～`-90607`（金額格式錯誤／為零／超過或低於存提款限制／玩家餘額不足／管理員餘額不足）、`-90615`／`-90616` 交易 Id 空白／過長、`-90617` 遊戲中不能提款 | `FAILED` |
| 結果不明：`-90002` 未知錯誤、`-90003` 例外錯誤、`-90500`／`-90501` 三方內部呼叫 GTAPI 失敗、`-90610` 我方交易號已存在、其他未列在文件的碼 | `TIMEOUT` |

`transaction/check`（補查）：

| 補查回應 | VendorTransferResultEnum |
|---|---|
| HTTP 5xx、200／400 以外的 4xx、無 JSON 回應（含 WAF HTML） | `TIMEOUT` |
| `0` | `SUCCESS` |
| `-90001` 交易失敗、`-90609` 交易不存在 | `FAILED` |
| `-90608` 尚未有結果、`-90003` 例外錯誤、其他未列在文件的碼 | `TIMEOUT` |

- `ExternalTransactionId` = `getTransactionNo()` 產生（UUIDv7 去 `-` 截前 20 碼），對應官方 varchar(20) 上限。
- `-90610 External transaction ID already exists.` 只在同一 `ExternalTransactionId` 重送時出現，代表前一次請求可能已成功入帳，故歸 `TIMEOUT` 走補查，不可當失敗退款。

## 整合落地建議

| Play8oy 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Play8oy/Driver.php`（繼承 `AbstractTransferWallet`） |
| Account Model | `app/Vendors/Models/Play8oyAccount.php` |
| BetRecord Model | `app/Vendors/Models/Play8oyBetRecord.php` |
| Enum | `App\Enums\Vendor\VendorIdEnum::PLAY8OY = 30` |
| `vendor_infos.info.API_URL` | API base URL（結尾含 `/`）。VWorld 正式 `https://example.invalid/` |
| `vendor_infos.info.API_MERCHANT_NAME` | 送入 Header `merchantName`（VWorld = `vworldmy`） |
| `vendor_infos.info.API_AGENT` | 建立玩家時的 `Agent`（Kiosk 名稱，前置碼 `vwm_`） |
| `vendor_infos.info.API_KEY` | PrivateKey，用於簽章計算（敏感，log 必須遮罩） |
| `vendor_infos.info.GET_DOWNLOAD_URL` | bool，`true` 時呼叫 `appurl` 動態取 App 連結 |
| `vendor_infos.info.IOS_DOWNLOAD_URL` | 靜態 iOS 下載連結（`GET_DOWNLOAD_URL=false` 時用） |
| `vendor_infos.info.ANDROID_DOWNLOAD_URL` | 靜態 Android 下載連結 |
| `vendor_infos.info.VERIFY_SSL` | bool，缺省 `true`（驗證三方 TLS 憑證）；只允許 staging／測試商戶設 `false`（iGaming staging `*.example.invalid` 憑證自 2025-09-29 過期），正式商戶禁止。所有出站請求經 `Driver::http()` 統一套用 |
| Callback / Controller | 無（Transfer Wallet 無即時回調） |
| 路由 | 無 external 回調路由（`sw.php`、`tw.php` 均無 Play8oy） |
| 注單同步 | 主動拉取：`game/log/record`（≤10 分鐘區間）/ `game/log/all`；彙總 `report/playerstatreport` |
| 對帳保留期 | 文件未載（gap）；`game/log/record` 限制 ≤600 秒區間，建議每 1 分鐘排程 |

## gaps / 待 Play8oy 確認

- **`-90609` 的時間窗行為**：交易剛送出時是否也回 `-90609`（影響 TIMEOUT 補查是否誤判為 FAILED）。
- **對帳資料保留期**：文件未明載，需向客服確認 `game/log/record` / `game/log/all` 資料保留天數。
- **`Code` 型別**：JSON 回傳 `Code` 實際為整數或字串（影響 `=== '0'` 的嚴格比較）。
- **Rate Limits**（Appendix 6）：官方標示「Not activated」，是否已啟用待確認。
