# Wbet（vendor_id=102）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 回調方向 | 代理 → WBET 主動呼叫，**無回調** |
| 驗證方式 | MD5 公式 A（玩家 API）/ 公式 B（報表 API） |
| 成功判斷 | `status === 1` |
| 時區 | UTC+08:00 |
| 資料保留 | **150 天**，標記後不可再取 |

---

## 簽名公式選用陷阱

選錯公式會導致所有 API 請求簽名失敗。兩種公式的差異在於有無 `account_id`：

| 公式 | 組成 | 適用 API |
|---|---|---|
| A | `md5(operator_id + ukey + account_id + ukey + secret_key)` | 玩家相關：`createmember` / `getbalance` / `newdeposit` / `newwithdrawal` / `launchsports` / `setbetlimit` |
| B | `md5(operator_id + ukey + ukey + secret_key)` | 非玩家 / 報表：`checktranstatus` / `getbetlist` / `getparlaybetlist` / `getparlaydetails` / `getresult` / `getbonusresult` 及對應 `markfetch*` |

> 報表與對帳 API 一律用公式 B（不含 `account_id`）；不可沿用公式 A 的 `generateSignature()`。

---

## checktranstatus 雙欄位陷阱

`checktranstatus` 回應含**兩個語意不同**的欄位，兩者都必須檢查：

| 欄位 | 意義 |
|---|---|
| `status` | API 查詢本身是否成功（`1` = 查詢成功，查不到也算） |
| `success` | 該 `transaction_id` 的轉帳是否真正成功（`1` = 成功，`0` = 失敗） |

常見錯誤：只看 `status === 1` 就認定轉帳成功，實際上 `success` 可能為 `0`（轉帳失敗）。

---

## 轉帳逾時未走 checktranstatus

目前 `handleTransfer()` 只依回應 `status === '1'` 回 SUCCESS / FAILED，**沒有處理連線逾時 / 不確定狀態 → checktranstatus 對帳的路徑**。

這是轉帳錢包的資損風險點：逾時不查單可能導致雙向扣款或錢包不一致。

建議補上：逾時例外捕捉 → 以 `transaction_id` 呼叫 `checktranstatus`（公式 B）→ 依 `success` 欄位決定最終狀態。

---

## 對帳拉取-標記閉環

| 規則 | 值 |
|---|---|
| 單次最多 | 300 筆 |
| 頻率限制 | 1 分鐘最多 2 次，每次間隔 30 秒 |
| 標記時機 | 確認資料已成功入庫後才呼叫 `markfetch*` |
| 標記後 | 不再重複返回，不可再取 |

> 務必先確認入庫成功再標記；標記後若入庫失敗，資料永久遺失（150 天保留期內也無法重拉）。

---

## 結算時間切日

WBET 結算區間：**前日 12:00 PM → 當日 12:00 PM** 算一天，**不是自然日**。

對帳排程按自然日查詢會漏前半天或重複計算。需依「前日 12:00 PM 到當日 12:00 PM」設定查詢區間。

---

## getresult REOPEN 處理

`result_status` 狀態序可能為 `END → REOPEN → END`，每次都需處理：

| 狀態 | 處理 |
|---|---|
| `END`（新） | 以 `result_id` 查本地；無則新增，已有則更新整筆（`winlose` / `payout` 可能變動）；確認入庫後呼叫 `markfetchresult` |
| `REOPEN` | 以 `result_id` 從本地刪除（或標記作廢）；等待下一筆相同 `result_id` 的 `END` 重新拉取 |

**不可在 REOPEN 時 skip 或只標記不處理**，否則會保留錯誤的結算結果。

---

## 實作落差（待確認）

以下項目為實作與三方文檔存在潛在差異之處，已標記供 RD / 客服確認，**不代表已確認為 bug**。

1. **setbetlimit 放行 status 3 / -110**：Driver `sendRequest()` 對 `setBetLimit` 額外放行 status `3`（沒有變更 / 已調整）與 `-110`（120 秒內只能打一次）視為可接受。文件 v2.1 僅載 `1 = 成功`，未明列 `3` 與 `-110`。建議向 WBET 客服索取完整 setbetlimit 回應碼表確認。
   位置：`app/Vendors/Wbet/Driver.php`（`sendRequest` 內 `in_array(..., ['1','3','-110'])`）。

2. **setbetlimit 多送 `cs_*` 欄位**：`prepareBetLimitData()` 額外送 `cs_min_bet` / `cs_max_bet` / `cs_max_per_match`（CS 電競）。文件 setbetlimit 參數列與範例只到 `others_*`，未列 `cs_*`。需確認 WBET 是否接受該額外欄位（忽略 or 報錯）。

3. **`getbalance` 取小數位**：文件稱餘額以 4 位小數返回；Driver 以 `Common::numFloor($balance, 2)` 取 2 位。屬刻意降精度（無條件捨去到 2 位），非錯誤，但若上游金額帶第 3、4 位小數會被捨去，記錄一下以備對帳差異追查。

4. **Driver 內 `sendRequest` 失敗碼比對標為 TODO**：程式碼註解 `// TODO 缺少文檔比對邏輯`：目前只用 `status !== '1'` 一律當失敗。可參照 api.md 附錄錯誤碼補上分類處理。

5. **轉帳逾時未走 checktranstatus**：`handleTransfer()` 目前無逾時 → 查單機制（詳見上方「轉帳逾時未走 checktranstatus」）。

6. ~~對帳 / 報表 API 未實作~~：已實作 `SupportsBetRecordFetch`（`getbetlist` / `getparlaybetlist` / `getresult` / `getbonusresult` 四支查詢 + 對應 `markfetch*`，公式 B 簽名），可用 `fetch_bet_record --direct --vendor=102` 手動補拉。以下為實作時已知的風險點：

   - **標記時機早於入庫確認**：文件要求「務必先確認資料已成功入庫後才標記」，但共用契約 `SupportsBetRecordFetch::fetchBetRecords()` 是在 `BetRecordFetchService` 寫入 DB **之前**呼叫，Driver 無法得知後續寫入是否成功。目前做法（經需求方確認接受）是 fetch 完立即標記；若標記後寫入 DB 失敗，該批注單會對三方永久遺失（150 天保留期也救不回）。
   - **getresult / getbonusresult 欄位映射沿用舊系統假設**：`working_date` 作為 `payout_time`、`bet_on` 作為缺 `bet_time` 時的備援欄位，這兩者在本文件（`api.md`）的欄位表中並非「主要欄位」清單所列項目，沿用自舊系統 RD-Studio 實作，尚未對照 WBET 現行 sandbox 回應重新驗證。
   - 位置：`app/Vendors/Wbet/Driver.php`（`fetchBetRecords` / `formatBetRecord` / `formatBaseTotalBetRecord` 及私有方法 `postReportApi` / `markFetched`）。

   > `betRecordAccountColumn()` 沿用框架預設（對應 `WbetAccount.vendor_username`）與 `game_code` 固定值 `'TP01'` 皆已確認正確，非風險項：舊系統 `database/procedures/SP_transfer_bet_record_wbet.sql` 轉 TBR 時就是拿 `wbet_bet_records.vendor_username` 對 `wbet_accounts.vendor_username`（非 `username`）、拿 `game_code` 對 `games.game_type`，且同樣以 `status = 1` 篩選轉入列，與本 Driver 判斷一致。

---

## 整合落地建議

| WBET 概念 | APIs 專案對應 |
|---|---|
| `operator_id` | `vendor_infos.info.CLIENT_ID` |
| `secret_key` | `vendor_infos.info.CLIENT_SECRET` |
| Base URL | `vendor_infos.info.AUTH_URL`（endpoint 以 `AUTH_URL . /api/xxx` 組成） |
| `account_id` | `App\Vendors\Models\WbetAccount.username` |
| Driver | `app/Vendors/Wbet/Driver.php`（繼承 `AbstractTransferWallet`，實作 `transferToVendor` / `transferToLocal` / `getGameUrl` / `getBalance` / `SupportsBetRecordFetch`） |
| Vendor 註冊 | `config/vendor.php`：`VendorIdEnum::WBET->value (102) => Vendors\Wbet\Driver::class`（已註冊） |
| 帳務模型 | `WbetAccount`、`WbetBetRecord`（`app/Vendors/Models/`） |
| 拉取注單 | `SupportsBetRecordFetch`（`getbetlist` / `getparlaybetlist` / `getresult` / `getbonusresult` + `markfetch*`，公式 B）；已知風險見上方「實作落差」第 6 項 |
| 限紅開關 | `vendor_infos.info.SET_BET_LIMIT`（=1 時 launch 前呼叫 setbetlimit）、`vendor_infos.info.BET_LIMIT.*`（各運動別上下限與 default_min/max/max_per_match） |
| 語言對應 | Driver `mapLanguageCode()`：langId 2/3→`cn`、5→`id`、6→`vi`、7→`th`、其餘→`en`（WBET 另支援 `kr`，目前未對應） |
| 裝置型別 | header `X-Device-Type`：`Desktop`→type `1`、其餘→type `2` |
| Callback / routes | **無**（轉帳錢包，WBET 不回呼） |
| 對帳保留期 | WBET 保留 **150 天**且標記後不可再取，對帳工具須在期限內完成且確保入庫後才 markfetch |
