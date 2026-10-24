# KissH5（KissH5SW，vendor_id=233）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`（測試案例見 `test-cases.md`）。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，KissH5 回調扣加款 |
| 雙向 | 出站 `GetGameList` / `GameLogin`（取 game URL）；入站 8 種回調（GetBalance/Bet/GameResult/Rollback/CashBonus/Jackpot/BetNSettle/CancelBetNSettle） |
| 認證 | `OperatorId` + `Signature`（MD5）+ `RequestDateTime`（**UTC** ISO `Y-m-d\TH:i:s\Z`） |
| 成功判定 | 回應 `Status == 200`；錯誤放 `Description` |
| 回應時間 | 回調須 **≤ 3 秒** |
| 冪等鍵 | `BetId` / `ResultId` / `TranId`（重複回 `900409 Duplicate Transaction`） |

## 陷阱（高風險）

### 1. Signature 拼接順序逐接口不同

`makeSignature()` = `md5(implode('', $parts))`，**各接口 parts 順序不同**，少一段或順序錯即驗簽失敗：

| 接口 | MD5 組成 |
|---|---|
| GameLogin | `GameLogin + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| GetBalance | `GetBalance + RD + OperatorId + SecretKey + PlayerId` |
| Bet / Rollback | `{Fn} + BetId + RD + OperatorId + SecretKey + PlayerId` |
| GameResult / BetNSettle / CancelBetNSettle | `{Fn} + ResultId + RD + OperatorId + SecretKey + PlayerId` |
| CashBonus / Jackpot | `{Fn} + TranId + RD + OperatorId + SecretKey + PlayerId` |

> Bet/Rollback 用 **BetId**、GameResult 系用 **ResultId**、Bonus/Jackpot 用 **TranId** 作簽名與冪等鍵。

### 2. 對帳時區 UTC vs SGT（資損風險）

回調 `RequestDateTime` 為 **UTC**，但 KissH5 **後台 / CSV / GetDaySummary 以 SGT 日界**彙總。對帳換算必須統一時區，否則跨日界注單會對不齊（見 [[reference_kissh5_timezone]]）。

### 3. Rollback ↔ GameResult 互斥

同一 `BetId`，Rollback 與 GameResult **不會同時發生**（遊戲正常→GameResult 不可取消/void；未完成→Bet 失敗時直接 Rollback）。對**已結算**訂單收到 Rollback 屬異常，須回 `900415 Bet Transaction Not Found` 拒絕。

### 4. ID 是 17 位大整數，超過 double 安全範圍（資損風險）

`BetId` / `ResultId` / `RoundId` 皆為 17 位數字，已超過 IEEE 754 double 的安全整數上限
2^53 ≈ 9.007e15（16 位）。在該量級下 double 的可表示間隔是 **8**，差距 ≤7 的相鄰 ID 會被折成同一個值。

**已發生的事故**（`f9e835c72`）：`bet_id` 欄位是 `varchar`，但 PHP integer 傳進 `where()` 時
MySQL 會把 varchar 轉 float 比對，導致相鄰注單被誤判為同一筆，**可能錯誤地結算或回滾其他玩家的注單**。
修法是在 5 個回調（bet / settle / rollback / betNSettle / cancelBetNSettle）取值後統一 `(string)` cast，
強制走字串比對。

落地要求：

- 三個 ID 從 `$params` 取出後**一律 `(string)` cast**，禁止讓 integer 進入 `where()`
- DB schema 維持字串（`bet_id` varchar(50)、`round_id` / `reference_no` varchar(30)），不可改為整數型別
- 自行寫查詢／腳本比對這些 ID 時同樣以字串處理

**⚠️ 排查陷阱（GCP log）**：log 的 `payload.context.params.BetId` 是**數值型、已失真**
（實測偏差 −2 ～ +5）；`payload.context.Content`（原始 request body 字串）才精確。
拿 `params` 顯示的值去 SEARCH 會撈不到對應 entry——**一律以 `Content` 內的值為準**。

### 5. 孤兒注單：Bet 成功但 GameResult 永不到達

§3 說 Rollback 與 GameResult 互斥（二選一），但實務存在**第三種狀態：兩者都沒有**。
Bet 回調成功（已扣款、寫入 `status=0` 記錄）後，KissH5 端既未送 GameResult 也未送 Rollback，
該筆就永遠停在未結算——**玩家的錢已被扣走且不會自動恢復**，因為重試由廠商端發起（見下節），
廠商不發就沒有下文。

這違反下節「GameResult 失敗 3 次後每小時重試、不可取消/void、須最終成功」的契約，
成因在廠商端（多見於其服務短暫異常的時間窗），非本平台可預防，但**必須主動偵測**。

**偵測**（純讀、零筆自動退出，穩態下應恆為零）：

```
php artisan kissh5sw:export-orphans --hours=24
```

判定條件為 `type='N'` ＋ `status=0` ＋ `bet_time` 超過閾值。持續產生代表廠商端有服務異常，
應向 KissH5 反映該時間窗，而非只做善後。

**處置**：`kissh5sw:repair-orphans` 依**是否存在對應的「汙染記錄」**自動分流（`findPollutionFor()`），
兩條路徑的審計 `source` 不同：

| 判定 | 成因 | 修復路徑 | `BetRecordRecoveryLog.source` |
|---|---|---|---|
| 找不到汙染記錄 | GameResult 從未送達 | `processSingle` | `pure_orphan` |
| 存在 `status=1` 記錄，其 `info[1].BetId` ＝本孤兒 `bet_id`、且 `info[0].BetId != info[1].BetId` | 精度事故的配對汙染（見 §4） | `processPair` | `pair (paired_with_bet_record_id=…)` |

> ⚠️ **判定依據不是孤兒自己的 `info` 長度**——孤兒必然只有 1 個元素（就是沒收到 settle 才成為孤兒），
> 汙染記在**另一筆已結算的記錄**上。拿孤兒的 `JSON_LENGTH(info)` 判型會把兩種成因誤歸為同一類。

兩條路徑**都必須備妥 K5 直出的 21 欄位逐筆 CSV**（`TranID` / `BetTranID` 為 17 位完整數字）——
命令要用三方真值還原注單並直接改玩家錢包餘額。**xlsx 或從 xlsx 轉出的 CSV 不可用**（末位精度遺失，見 §4）；
CSV 缺該 BetId 時只會記 `skip_csv_missing_orphan` 跳過，等於空跑。

## 重試與冪等

| 事件 | 廠商重試 | 本平台處理 |
|---|---|---|
| Bet 失敗 | **不重試**，直接觸發 Rollback | — |
| Rollback 失敗 | 3 次後每小時重試 | 重複回「退款成功」/「交易不存在」終止 |
| GameResult 失敗 | 3 次後每小時重試，**不可取消/void** | 須最終成功 |
| Bonus / Jackpot 失敗 | 同 GameResult | — |

- 冪等：相同 `BetId`/`ResultId`/`TranId` 已處理 → 回 `900409` 終止重試。

## 整合落地建議

| KissH5 概念 | APIs 專案對應 |
|---|---|
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::KISSH5SW->value (233) => Vendors\KissH5SW\Driver::class` |
| Driver（出站 / 進遊戲） | `app/Vendors/KissH5SW/Driver.php`（`AbstractSingleWallet`，`getGameUrl` → `POST {API_URL}/GameLogin`，寫 `account.auth_token`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/KissH5Controller.php` |
| Route | `routes/external/sw.php` |
| Account / BetRecord Model | `App\Vendors\Models\KissH5SWAccount`（`username` + `SUFFIX`）/ `KissH5SWBetRecord` |
| 狀態 Enum | `app/Vendors/KissH5SW/OperatorStatusEnum.php` |
| `vendor_infos.info` | `API_URL` / `OPERATOR_ID` / `SECRET_KEY`（簽名）/ `CURRENCY` / `SUFFIX`（帳號後綴） |
| 帳號規則 | `username = {本地 username}{SUFFIX}`；進遊戲時 `auth_token = [REDACTED] `-`） |
| 語言 | ISO 639-1：`en-us`/`zh-cn`/`zh-tw`/`th-th`/`my-mm`/`id-id`，預設 `en-us` |

## gaps / 待 KissH5 / RD 確認（非確認 bug）

1. **正式 API_URL / SECRET_KEY / OPERATOR_ID**：由 KissH5 客服開戶提供。
2. **對帳保留期**：api.md / 文檔未明列，須向客服確認後訂排程上限。
3. **GameType 對照**：`convertGameType()` 將 KissH5 type（1 slot / 2,5 arcade / 3 poker / 6 fishing）映射本平台 GameTypeEnum，其餘歸 EVENT_AND_OTHERS，待確認完整對照。
