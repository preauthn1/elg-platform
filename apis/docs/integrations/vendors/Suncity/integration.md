# SunCity（vendor_id=292）— 對接實測

> 只記「文件與程式碼查不到」的實測結論。廠商規格見同目錄 `api.md`（與 `../Play8oy/api.md` 同一份），通用接入規範見 `game-integration` skill。

## 1. `-50003` 的真實語意：EndDate 不可 ≥ 三方伺服器的現在時間

文件只寫「查詢日期為今日需限制在現在時間之前」。2026-10-01 dev 實測 `fetch_bet_record --vendor=292 --direct --dry-run --minutes=60`：前 5 片（10 分鐘窗）皆 Code 0，第 6 片（窗口尾端 = 命令起跑的現在）三方回 HTTP 400：

```json
{"Code":-50003,"Message":"The date of the query record is today, and it needs to be limited to the range before the current time"}
```

即 EndDate ≥ 三方伺服器現在時間就拒，含秒級時鐘誤差。排程預設窗口 `[now−1h, now]` 的最後一片永遠貼著現在，不處理就每 5 分鐘必失敗一片、Job 進 failed_jobs。

**處理**：`Suncity\Driver::fetchBetRecords()` 先把窗口尾端夾到 `now(UTC) − END_SAFETY_LAG_SECONDS`（120 秒），夾完無剩餘窗口直接回空、不打三方。被截掉的尾段由下一輪排程的 1 小時回看窗覆蓋；三方資料本身約有 20 分鐘延遲，不需要更大的餘量。

## 2. staging TLS 憑證過期

`*.example.invalid` 的憑證自 2025-09-29 過期，驗證會 cURL 60 失敗。dev 商戶 info 設 `VERIFY_SSL=false`（`Play8oy\Driver::http()` 統一套用）；**正式商戶禁止設 false**，缺省即驗證。

## 3. 原始字串簽名已實打驗證

2026-10-01 以 Agent 憑證（merchantName `hero9stg`）打 staging，以下皆通過驗簽：

| 端點 | 結果 |
|---|---|
| `player/exists` | 回 `-90208`（玩家不存在，代表驗簽已過） |
| `player/create` | 建成 `hero9stg_00000001` |
| `game/log/record` | Code 0（含空格與冒號的 `StartDate`／`EndDate`） |

`http_build_query` 版本未實測；依文件 2.2 範例為原始字串，日期參數經 URL 編碼（空格→`+`、冒號→`%3A`）會不符。

## 4. `vendors.need_login` 必設 1，否則前台點遊戲卡在讀取畫面

App 帳密登入型遊戲商（GreatWall、Mega888、Pussy888、Play8oy、918）在 `vendors` 表都是 `need_login=1`、`show_on_app=0`，但 `vendor:add_vendor_info`／`SyncVendorSeeder` 的預設是 `need_login=0`、`show_on_app=1`。2026-10-06 dev 實測：SunCity 以預設值建檔後，前台點任一款遊戲會走網頁遊戲流程開新視窗等 `pc`／`mobile` 網址，而 `getGameUrl()` 只回 `app_infos`，畫面停在讀取。`database/data/vendors.php` 的 SUNCITY 條目已補 `need_login=1`、`show_on_app=0`，但 `SyncVendorSeeder` 只新增不更新既有列，**在本修正之前就已建過 vendor 292 的站（dev 已手動改）上線時要另外把 `vendors` 這兩個欄位改掉**，後台廠商管理的編輯對話框沒有這兩個欄位。

## 5. 業務錯誤碼走 HTTP 400

iGaming 平台（Play8oy／SunCity 共用 `Play8oy\Driver`）只有成功回 HTTP 200，**業務錯誤碼一律以 HTTP 400 回**。2026-10 本機實打 staging 的紀錄：

| 端點 | 回應 body | HTTP |
|---|---|---|
| `transaction/create` | `{"Code":0,...}` | 200 |
| `transaction/create` | `{"Code":-90607,"Message":"Admin blance is not enough deposit."}` | 400 |
| `transaction/create` | `{"Code":-90606,...}`（玩家餘額不足） | 400 |
| `transaction/check` | `{"Code":-90608,"Message":"Not yet processed."}` | 400 |
| `transaction/check` | `{"Code":-90001,"Message":"fail"}` | 400 |
| `player/exists` | `{"Code":-90209}`、`{"Code":-90208}` | 400 |
| `game/log/record` | `{"Code":-50003,...}`（見 §1） | 400 |

**修正前的後果**：Driver 每個呼叫都 `->post(...)->throw()`，400 直接拋 `RequestException`，`MemberTransferService` 接到例外就把轉帳標成 8888（UNKNOWN_TRANSFER_FAILED）、不退款。`TransactionResponseCodeEnum::transferResult()` 的「明確拒絕碼 → FAILED → 退款」分支因此**永遠到不了**。dev 實例：會員 64291 轉入 1005 被三方以 `-90607`（商戶額度不足）明確拒絕，本該 FAILED 退款，卻卡成 8888（transaction_log 224274994）。Play8oy 線上同樣受影響。

**修正方式**（`Play8oy\Driver`，SunCity 繼承）：

- 移除所有 `->throw()`，出站一律經 `postJson()`（帶 `merchantName`／`Sign`，不 throw），回應交 `decode()`：只有 **5xx 或非 JSON**（含 WAF 的 HTML 403）回 `null` 當不可知；4xx 帶 JSON 一律交業務碼判斷。
- `transfer()`／`checkTransferStatus()`：`null` → `TIMEOUT`；**只有 HTTP 200／400 帶 Code 才分類，其他 4xx（gateway／WAF）一律 `TIMEOUT`**（`carriesBusinessCode()`），即使 body 帶拒絕碼；`Code` 缺或非數字 → 拋 `ApiException`（format error）；其餘交 `TransactionResponseCodeEnum` 分類。**5xx 的 body 即使帶拒絕碼也是 TIMEOUT**，不自動退款。
- `getBalance()`／`resetPassword()`／`getGameUrl()`（appurl）／`getGameList()`：`null` → 拋 `ApiException`（訊息含 HTTP 狀態碼）；400 帶非 0 碼走既有「拋 `ApiException` 帶 Code」或回 `FAILED` 分支。`createVendorAccount()`：`null` 或非 0 碼回 `false`，連線層例外照舊 catch 回 `false`。
- `Suncity\Driver::fetchBetRecordSegment()`：第 1 頁 400 `-50001` 查無資料 → 回空；**翻頁中途（第 2 頁起）回 `-50001` 代表三方資料不一致，拋 `ApiException`**，不把已拉到的部分當完整結果；5xx／非 JSON／其他非 0 碼 → 拋 `ApiException` 讓時間片失敗重跑，**絕不回空**。
- 連線層錯誤（`ConnectionException`，逾時、DNS、TLS）照舊往上拋，轉帳由 `MemberTransferService` 當結果不明處理。

**禁止在 iGaming Driver 加回 `->throw()`**；HTTP 狀態 × 業務碼的組合由 `tests/Feature/Vendors/Play8oy/DriverTest.php` 與 `tests/Feature/Vendors/Suncity/DriverTest.php` 釘住。
