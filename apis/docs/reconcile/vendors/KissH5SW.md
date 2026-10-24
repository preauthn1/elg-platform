# KissH5SW（vendor_id=233）

> **卡型：專屬表卡（dedicated）** — BR 一表一 vendor、有 status / valid_bet、可直接 SUM。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；本檔聚焦 KissH5 差異。
> ⚠️ 2026-05 處理過精度 bug 受害資料，已修復；incident 善後手冊見本檔下方「§2026-05 incident：精度 bug 善後」章節。

## 一行概述

KissH5 Seamless Wallet（單錢包）；slot；2026-04 接入；2026-05 完成精度 bug 善後。

## 取得三方資料（Step 0）

### 可用 API

| API | 用途 | 特性 |
|-----|------|------|
| `GetDaySummary` | 整日總額（按 currency 分組）| 即便傳 UTC `Date`，內部仍轉 SGT 計算 |
| `GetTransactionDetails` | 單筆 ResultId/TranId 報表 URL | 只回 HTML，不適合自動化 |
| `GetUnfinishRound` | 取玩家未完成回合 | 對精度 bug 受害注單**沒用**（K5 視為已完成） |
| `GetRoundDetails` | 單回合詳情 | 需要 RoundId |

### 不可用（會 Status: 900500 Internal Server Error）

- ❌ `PullLog` / `PullLogByTimestamp` / `FlagLog` / `FlagLogBonus`
- 文件第 9 頁明確標示「exclusively intended for fund transfer wallet integration」

### 後台匯出（人工）

- **玩家彙總 CSV**（Layer 2 玩家對帳用）：欄位至少含 `Player Name, Currency, No Of Games, Valid Bet, Bet Amount, Return Amount, Game Income`
- **逐筆注單 CSV（21 欄位直出版本）**（Layer 3 逐筆對齊用）：含 `TranID, BetTranID, TranDateTime, PId, PlayerId, GameId, ..., TranType, ..., GameServerRefId, RoundType`
  - ⚠️ **xlsx 或從 xlsx 轉的 CSV 會把 17 位 BetId 末 1~3 位四捨五入**，無法精確配對；務必取直出 CSV

### 命令

| 階段 | 命令 |
|---|---|
| Layer 1 健檢 | tinker 直接打 `GetDaySummary`（範本見下方） |
| Layer 2 玩家層對帳 | `kissh5sw:compare-players {csv} {date}`（預設 `--tz=Asia/Singapore`）|

## 時區

| 對象 | 時區 |
|---|---|
| Callback `TranDateTime` | **UTC** |
| `kissh5sw_bet_records.bet_time` / `payout_time` | **UTC**（與 callback 一致） |
| K5 後台介面 / CSV 匯出 / `GetDaySummary` | **SGT (UTC+8) 日界** |
| `config/summary_bet_record.php` time_field | `payout_time` |

**對帳換算公式**：

```
KissH5「2026-05-01」整日 = UTC [2026-04-30 16:00, 2026-05-01 16:00)
```

> 直接拿 UTC 整日對帳 → 個別玩家會出現 8 小時錯位的「假差異」（A/C 段歸類錯誤），但日總和會因互相抵銷看起來大致對齊。**個別玩家對帳必須做時區換算**。

## 欄位 mapping（K5 CSV → BR）

| K5 CSV | BR 欄位 | 備註 |
|--------|---------|------|
| `Round Id` | `bet_id` | slot 場景 RoundId == BetId |
| `Tran ID` | `reference_no` | 即 ResultId（settle 後寫入） |
| `Bet Amount` | `bet` | |
| `Return Amount` | `win` | |
| `Tran Date` | `payout_time` | **SGT 時區，需轉 UTC** |
| Player Balance | — | 對帳輔助 |

## bet_id 處理（重要）

- **編碼**：17~18 位整數
- **大整數陷阱**：`where('bet_id', $intBetId)` 因 PHP int ≥ 2^53 會走 float 命中相鄰記錄（差距 1~5）
- **必須全程 string**：`where('bet_id', (string) $betId)`
- **修復狀態**：`KissH5Controller.php` 7 個查詢點全部已 `(string)` cast（commit `f9e835c72`、`bonus()` `jackpot()` 補強）。**新請求安全**，但歷史汙染資料見下方「§2026-05 incident」章節

> 「整數沒有小數點就不會有精度問題」是錯的：超過 `2^53` 的大整數（17~18 位）被隱式轉 float 後尾數失真，造成誤命中。所有 `bet_id` / `reference_no` / `TranID` / `BetTranID` 全程以 **string** 處理，禁止 int/float 參與比對。

## status / 結算流程

| 值 | 語意 |
|---|---|
| 0 | 未結算（孤兒可能性） |
| 1 | 已結算 |

**multi-bet：不支援**。

> Type 欄位 `'N'` 表正式注單（non-cancel）；其他 `'C'` (cancel)、`'B'` (bonus)、`'J'` (jackpot)。對帳預設 `type='N' AND status=1`。

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| 孤兒偵測（純讀，**穩態應 0 筆**） | `kissh5sw:export-orphans [--hours=N]` |
| Layer 2 玩家對帳 | `kissh5sw:compare-players {csv} {date}` |
| 撤回先前補帳（跨 vendor 通用） | `bet-record-recovery:revert --log-ids=...` |
| Step 4 補帳（通用） | `summary_bet_record --vendor=233 --date={Y-m-d}` |

> **2026-07-28 改分類**：`kissh5sw:export-orphans`（偵測）與 `kissh5sw:repair-orphans`（修復）
> 兩支都已移入 `app/Console/Commands/Vendor/KissH5SW/Maintenance/`，`Patches/PrecisionBug2026May/`
> 目錄已清空移除。原因：孤兒主因是**廠商端未發 GameResult**、會持續產生（26 筆中佔 20 筆），
> 並非單一事故的殘留。機制見 [`integrations/vendors/KissH5/integration.md`](../../integrations/vendors/KissH5/integration.md) §5；
> 2026-05 精度事故的始末與 SQL 仍保留於下方「§2026-05 incident」章節。

## Layer 1 健檢 tinker 範本

確認當日是否有對帳缺口（5 分鐘內可完成）：

```bash
docker exec -it php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker"
```

```php
use App\Models\VendorInfo;
use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Support\Facades\Http;

$v = VendorInfo::where('vendor_id', VendorIdEnum::KISSH5SW->value)->first();
$info = $v->info;
$date = '2026-05-01'; // SGT 日

// 1. 打 GetDaySummary
$now = gmdate('Y-m-d\TH:i:s\Z');
$sig = md5('GetDaySummary' . $now . $info['OPERATOR_ID'] . $info['SECRET_KEY']);
$resp = Http::post(rtrim($info['API_URL'], '/') . '/GetDaySummary', [
    'OperatorId'      => $info['OPERATOR_ID'],
    'RequestDateTime' => $now,
    'Signature'       => $sig,
    'Date'            => $date . 'T00:00:00Z',
])->json();

// 2. 我方 SUM（SGT 範圍換 UTC）
$start = '2026-04-30 16:00:00';
$end   = '2026-05-01 16:00:00';
$ours = KissH5SWBetRecord::where('type', 'N')
    ->where('status', 1)
    ->where('payout_time', '>=', $start)
    ->where('payout_time', '<', $end)
    ->selectRaw('COUNT(*) cnt, SUM(bet) bet, SUM(win) payout, SUM(win_lose) wl')
    ->first();

print_r(['k5' => $resp['Trans'][0] ?? null, 'ours' => $ours->toArray()]);
```

判斷：
- 差距 < 1 MYR → 對帳通過，結案
- 差距 > 1 MYR → 進 Layer 2（`kissh5sw:compare-players`）

## 對帳三層模型

```
┌─────────────────────────────────────┐
│ Layer 1: GetDaySummary tinker        │ → 差距 < 1 MYR? 結案
└────────┬────────────────────────────┘
         ▼
┌─────────────────────────────────────┐
│ Layer 2: 玩家彙總 CSV vs 我方 GROUP │ → 0 位差異? 結案（差距純為時區誤判）
│   工具：kissh5sw:compare-players    │
└────────┬────────────────────────────┘
         ▼
┌─────────────────────────────────────┐
│ Layer 3: 逐筆 CSV vs 我方 records   │ → 4 類分桶（遺失/孤兒/汙染/正常）
│   ⚠️ 若發現孤兒+汙染配對，走 §2026-05 incident │
└─────────────────────────────────────┘
```

## 終驗 SQL（單日）

```sql
-- 期間 SGT 日對應 UTC 範圍
SET @utc_start = '2026-04-30 16:00:00';
SET @utc_end   = '2026-05-01 16:00:00';

SELECT 'kissh5sw_bet_records (type=N, status=1)' AS source,
  COUNT(*) AS cnt, SUM(bet) AS sum_bet, SUM(win) AS sum_win, SUM(win_lose) AS sum_wl
FROM kissh5sw_bet_records
WHERE type='N' AND status=1
  AND payout_time >= @utc_start AND payout_time < @utc_end
UNION ALL
SELECT 'total_bet_records (vendor=233)', COUNT(*),
  SUM(t.bet), SUM(t.bet + t.win_lose), SUM(t.win_lose)
FROM total_bet_records t
JOIN kissh5sw_bet_records b ON b.id = t.bet_record_id
WHERE t.vendor_id = 233
  AND t.payout_time >= @utc_start AND t.payout_time < @utc_end;
```

> ⚠️ **無 SBR 層**：vendor 233 未註冊於 `config/summary_bet_record.php`，通用 `summary_bet_record` 不寫其 SBR；現役白標（VWorld / Yes2Win）查無 KissH5 業務、`summary_bet_records` 亦無 233 資料（2026-06-23 實測）。KissH5 對帳止於 **BR = TBR**；若日後確認有 SBR 來源，再補此層與對應終驗段。

## 已知 incident / 注意事項

- **精度 bug（2026-05）**：歷史孤兒 / 汙染配對受害資料 → 詳見下方「§2026-05 incident：精度 bug 善後」章節
- **PullLog 系列 API 不可用**：詳見上方「不可用」一節
- **時區個別玩家對帳必須換算**：拿 UTC 整日對帳會出 8 小時錯位假差異

## KissH5 專屬陷阱

| 陷阱 | 症狀 | 解法 |
|------|------|------|
| 用 UTC 整日對帳 | 個別玩家差距大、總和卻接近對齊 | 改用 SGT 整日換算 UTC 區間 |
| 把 BetId 當 int 查 DB | 大數字（≥ 9e15）誤命中相鄰記錄 | `where('bet_id', (string) $betId)` |
| 試 PullLog 想拉注單 | 永遠 900500 | KissH5SW 是 SW，這 API 對它無效 |
| `whereBetween('time', [start, end])` | 包含 end 那一秒，跨日多算 | 改用 `>= start AND < end` |

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Callback 入口（bet/settle/rollback/bonus/jackpot/betNSettle/cancelBetNSettle） | `app/Http/Controllers/External/SingleWallets/KissH5Controller.php` |
| Vendor 註冊 | `app/Vendors/KissH5SW/Driver.php` |
| 注單 Model（含時區提醒） | `app/Vendors/Models/KissH5SWBetRecord.php` |
| 注單表結構 | `database/migrations/2026_04_16_000005_create_kissh5sw_bet_records_table.php` |
| Layer 2 玩家層對帳命令 | `app/Console/Commands/Vendor/KissH5SW/ComparePlayers.php` |
| 廠商規格書 | `docs/integrations/vendors/KissH5/api.md`（原 PDF 已整理成這份） |

---

# 2026-05 incident：精度 bug 善後

> 本節為歷史 incident 善後紀錄。bug 已於 commit `f9e835c72` + 後續補強（`bonus()` `jackpot()`）修復；新請求安全。
> 通用對帳 SOP 見 `reconcile-bet-records` skill；KissH5 通用對接資訊見本檔上半段。

## ⚠️ 開工前必看：兩種症狀的配對關係

執行任何補帳前，**先用下方「全表掃汙染 SQL」確認該批待修注單是否有汙染配對**。

| 場景 | 影響 | 修復工具 |
|------|------|---------|
| 純孤兒（無配對汙染）| 玩家自己 bet 沒結算到，settle 命中已 status=1 別人記錄、被 idempotent 回掉 | `kissh5sw:repair-orphans`（pure 分支）|
| 孤兒 + 汙染配對 | 跨玩家：A 的 settle 跑去結算 B 的 status=0 record，A 是孤兒、B 被汙染（多扣錢、win 被錯給）| `kissh5sw:repair-orphans`（pair 分支，自動偵測）|

實測（2026-05-06）：99 筆孤兒中 34 筆有配對汙染，65 筆是純孤兒。`repair-orphans` 一次處理完。

## 1. Bug 行為與修復狀態

### 1.1 Bug 行為

`where('bet_id', $betId)` 當 `$betId` 是 PHP int 且 ≥ 9e15（2^53 邊界）時，PDO/MySQL 會走 float 比對導致**命中相鄰記錄**（差距 1~3）：

```
查詢 betId=70000003777885624 (int) → 命中 bet_id=70000003777885621 ❌
查詢 betId="70000003777885624" (string) → 正確回傳「不存在」 ✅
```

### 1.2 修復狀態

`KissH5Controller.php` 7 個查詢點全部已 `(string)` cast（commit `f9e835c72`、本次補強 `bonus()` `jackpot()`）。**新請求安全**，但歷史汙染資料還在。

### 1.3 受害症狀（三類）

| 類別 | 症狀 | 偵測方式 |
|------|------|---------|
| **遺失** | K5 有 BetId、我方完全無記錄 | 需 K5 逐筆 CSV 比對 |
| **孤兒**（status=0）| 我方有但 settle 沒到（被 bug 引去更新別人）| `kissh5sw:export-orphans` |
| **被汙染**（status=1）| bet_id 對齊但 bet/win 等欄位被別人的 settle 覆寫 | 我方 `info` JSON 自我偵測（bet payload BetId ≠ settle payload BetId）|

### 1.4 「孤兒 + 汙染」配對關係（重要）

實測（2026-05-06）發現：**99 筆孤兒中、34 筆有對應的汙染配對，且 100% 跨玩家**。

```
某 settle 進來 (BetId=A)：
  query where('bet_id', $A) (int) → 因精度命中 record_B (B = A ± 1~5)
  ┌─────────────────────────────────────────────────┐
  │ 若 record_B 已 status=1 (idempotent return) │   → 1 孤兒（A），無汙染
  │ 若 record_B 還是 status=0 (走 update 路徑)  │   → 1 孤兒（A）+ 1 汙染（B）
  └─────────────────────────────────────────────────┘
```

**汙染特徵**：`info[0].BetId` (bet 階段) 與 `info[1].BetId` (settle 階段) **不一致**。

汙染的 record 看起來是「正常結算的」（status=1），但內容（bet/win/reference_no/payout_time）全是「另一個玩家的 settle 資料」。**單看 record 看不出問題**，必須對 info JSON 內外比對。

### 1.5 修復必須「配對處理」

絕對不能單獨補孤兒不修汙染。原因：

| 角色 | 玩家 / 注單 | 誰錯收/誤扣 |
|------|------------|------------|
| 孤兒 record_A（player A）| status=0、玩家 A 投注被扣 | A 應得的 win 沒拿到 |
| 汙染 record_B（player B）| status=1、被 A 的 settle 覆寫 | B 多扣了「A 的 bet 金額」、win 是「A 應得的 win」（錯給 B 了）|

修復必須**同 transaction 處理**：
1. 還原 record_B 為 player B 自己的真實資料（從 player B 的 K5 CSV 取）
2. 補上 record_A 為 player A 自己的真實 settle（從 player A 的 K5 CSV 取）
3. 兩位玩家的錢包同時調整

## 2. 修復前提

1. **K5 CSV 必須是直出、精度完整的 21 欄位版本**
   含 `TranID, BetTranID, TranDateTime, PId, PlayerId, GameId, ..., TranType, ..., GameServerRefId, RoundType`。
   xlsx 或從 xlsx 轉的 CSV 會把 17 位 BetId 末 1~3 位四捨五入，無法精確配對 → 該對會被跳過。
2. **目標 DB 必須已套用 migration** `2026_05_06_000001_create_bet_record_recovery_logs_table`
   命令會查 `bet_record_recovery_logs` 做冪等檢查，沒這張表會直接 query exception。
3. **CSV 必須涵蓋孤兒玩家 + 汙染玩家**（配對通常跨玩家）

## 3. 偵測 SQL

### 3.1 全表掃汙染（不需 K5 CSV）

汙染可以**靠我方 info JSON 直接偵測**：

```sql
SELECT id, bet_id,
    JSON_UNQUOTE(JSON_EXTRACT(info, '$[0].BetId')) AS bet_payload_betid,
    JSON_UNQUOTE(JSON_EXTRACT(info, '$[1].BetId')) AS settle_payload_betid
FROM kissh5sw_bet_records
WHERE type = 'N' AND status = 1
  AND JSON_LENGTH(info) >= 2
  AND JSON_UNQUOTE(JSON_EXTRACT(info, '$[0].BetId')) !=
      JSON_UNQUOTE(JSON_EXTRACT(info, '$[1].BetId'));
```

每筆汙染的 `settle_payload_betid` 通常會匹配某筆**孤兒**的 `bet_id`，形成「孤兒 + 汙染」配對。

### 3.2 全表撈孤兒

```bash
php artisan kissh5sw:export-orphans
```

匯出 `type='N'` `status=0` 的孤兒清單給 K5 客服參考。

## 4. Layer 3 逐筆對齊（依 K5 CSV）

依 `Round Id == bet_id` 對齊，產出 4 類分桶：

| 類別 | 條件 | 修復方式 |
|------|------|---------|
| **遺失** | K5 有 BetId、我方完全沒 | 不在自動修復範圍；需向 K5 客服確認手動補建 |
| **孤兒** | 我方 status=0 | 必須與其配對汙染**一起修**（見 §5）|
| **汙染** | status=1，info 兩段 BetId 不一致 | 必須與其配對孤兒**一起修**（見 §5）|
| **正常** | bet_id 對齊、info 兩段 BetId 一致、金額對齊 | 過濾 |

K5 CSV 關鍵欄位對應：

| K5 CSV | 我方 `kissh5sw_bet_records` |
|--------|------------------------------|
| Round Id | `bet_id` |
| Tran ID | `reference_no` |
| Bet Amount | `bet` |
| Return Amount | `win` |
| Tran Date | `payout_time`（**SGT，需轉 UTC**）|

## 5. 修復命令：`kissh5sw:repair-orphans`

統一一個命令處理「純孤兒」與「孤兒+汙染配對」兩種情境。入口為孤兒（status=0），命令會自動偵測有無配對汙染並走對應分支。

```bash
# Dry-run（強烈建議先跑，會列出每筆走「pair」或「pure」分支、預期錢包變動）
php artisan kissh5sw:repair-orphans _local/kissh5sw/full_period.csv

# 真執行（必填 --reason）
php artisan kissh5sw:repair-orphans _local/kissh5sw/full_period.csv \
  --execute --reason="客服票號 #1234，2026-05 精度 bug 修復"
```

### 命令邏輯

```
iterate 所有 type=N、status=0 的孤兒 O
  └─ 在 CSV 找 O.bet_id 對應 row（找不到 → skip_csv_missing_orphan）
  └─ SQL 查有無配對汙染 P（status=1，info[0].BetId != info[1].BetId, info[1].BetId == O.bet_id）
       ├─ 有 P → 「配對」分支：
       │     CSV 找 P.bet_id row（找不到 → skip_csv_missing_pollution）
       │     同 transaction：
       │       1. 還原 record_P：bet/valid_bet/win/win_lose/reference_no/payout_time ← P 的 CSV 真實資料
       │       2. 結算 record_O：status 0→1，欄位 ← O 的 CSV 真實資料
       │       3. 玩家 P 錢包：+(P 真實 win − 當前 win)（扣回錯派彩、加回應得 win）
       │       4. 玩家 O 錢包：+ O 真實 win
       │       5. 寫 2 筆 BetRecordRecoveryLog（pair_role = pollution / paired_orphan，互指 paired_bet_record_id）
       │       6. info JSON 加 _repaired 標記
       └─ 無 P → 「純孤兒」分支：
             單 transaction：
               1. 結算 record_O：status 0→1
               2. 玩家 O 錢包：+ O 真實 win
               3. 寫 1 筆 BetRecordRecoveryLog（pair_role = pure_orphan，paired_bet_record_id = null）
```

### 安全機制

- 預設 dry-run；`--execute` 才寫入；execute 必填 `--reason`
- 鎖內二次驗證：`status=0`、`info[0].BetId != info[1].BetId`（pair 分支）、未被 `BetRecordRecoveryLog::isCurrentlyRecovered`
- 任一端 CSV 缺就跳過該筆／該對
- 每筆／對獨立 `DB::transaction`，單筆失敗不影響後續
- info JSON 加 `_repaired` 標記，記錄執行者、原因、CSV 來源資料

## 6. 撤回（事後發現補錯 → revert）

跨 vendor 通用命令，詳細通用機制見 `reconcile-bet-records` skill §3.3。本節僅補 KissH5 場景注意事項。

```bash
# Dry-run 看計劃 + 呆帳預演（強烈建議先跑）
php artisan bet-record-recovery:revert --log-ids=123,124,125

# 真執行（必填 --reason）
php artisan bet-record-recovery:revert \
  --log-ids=123,124,125 \
  --execute --reason="客服票號 #5678，玩家投訴重複收款"

# 從 CSV 讀 log id（取第一欄）
php artisan bet-record-recovery:revert --log-id-csv=_local/revert_ids.csv

# 加呆帳上限保險閥（呆帳 > 100 直接 abort）
php artisan bet-record-recovery:revert --log-ids=... --execute --reason=... --max-bad-debt=100
```

每筆撤回會：
1. 驗證 log type=recover 且未被 revert 過（`isReverted()`）
2. 鎖定 record + drift check（current state 必須 = recover 的 after 快照；不一致時除非 `--force` 否則 refuse）
3. 鎖定錢包，**允許扣到負數**（呆帳場景：玩家已領走錯派彩）
4. 用 recover 的 `before` 快照還原 record（status=1→0、清空 win 等）
5. 反向 TransactionLog（`SEAMLESS_WALLET_BET_SETTLE`，金額負）
6. 刪除 recover 階段建立的 `total_bet_records` 列（用 `after._meta.total_bet_record_id`）
7. 在 `bet_record_recovery_logs` 新增 `type=revert` 事件，`parent_log_id` 指向被撤回的 recover

**輸出兩份 CSV** 到 `storage/logs/`：
- `bet_record_revert_{mode}_{ts}.csv` — 逐筆明細
- `bet_record_revert_player_summary_{mode}_{ts}.csv` — 玩家彙總（含 start/end balance、是否負數、呆帳金額）

Dry-run 階段就會印玩家彙總表 + 預期呆帳合計，**execute 前可先看數字決定要不要繼續**。

## 7. 已棄用命令對照

| 指令 | 原因 |
|------|------|
| ~~`kissh5sw:fetch-game-rounds`~~ | 用了 PullLogByTimestamp，對 SW 整合無效；已刪除 |
| ~~`kissh5sw:compare-records`~~ | 依賴 `kissh5sw_game_rounds`，該表永遠拉不到資料；已刪除 |
| ~~`kissh5sw:reconcile-orphans-by-csv`~~ | 命名拗口，已改名為 `kissh5sw:repair-orphans` |
| ~~`kissh5sw:recover-orphans`~~ | 與 `fix-pollution-pairs` 合併為 `kissh5sw:repair-orphans` |
| ~~`kissh5sw:fix-pollution-pairs`~~ | 與 `recover-orphans` 合併為 `kissh5sw:repair-orphans` |
| ~~`kissh5sw:rollback-orphans`~~ | 改採「補帳 + 撤回」雙向設計，不再單純 rollback |

## 8. incident 關鍵檔案

| 用途 | 路徑 |
|---|---|
| **Maintenance** — 孤兒匯出（純讀偵測） | `app/Console/Commands/Vendor/KissH5SW/Maintenance/ExportOrphans.php` |
| **Maintenance** — 統一修復（pure_orphan／pair 自動分流，⚠️ 改玩家錢包） | `app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php` |
| 修復測試（7 情境：dry-run / pair / pure / mixed / missing / idempotent / require-reason） | `tests/Feature/Console/Commands/Vendor/KissH5SW/RepairOrphansTest.php` |

跨 vendor 撤回（也可用於本 incident）：

| 用途 | 路徑 |
|---|---|
| 撤回審計表 migration | `database/migrations/2026_05_06_000001_create_bet_record_recovery_logs_table.php` |
| 審計 Model | `app/Models/BetRecordRecoveryLog.php` |
| recovery type enum | `app/Enums/BetRecordRecoveryTypeEnum.php` |
| 撤回命令（跨 vendor） | `app/Console/Commands/BetRecordRecovery/Revert.php` |
| Revert 測試（7 情境） | `tests/Feature/Console/Commands/BetRecordRecovery/RevertTest.php` |

## 9. 善後完成後的歸檔建議

當所有受害資料處理完畢、且確認後續無新增孤兒/汙染：

1. 跑全表掃汙染 SQL（§3.1）確認 0 筆
2. 跑 `kissh5sw:export-orphans` 確認 0 筆
3. ~~將 Patches/PrecisionBug2026May/ 下命令歸檔~~ —— **已於 2026-07-28 處置**：兩支命令改列
   Maintenance、該目錄清空移除。它們**不隨本 incident 歸檔**（孤兒仍會產生，見本檔開頭的改分類註記）
4. 本 incident 章節可考慮從 vendor 卡片移除（事件已結案）；但 §3.1 的汙染偵測 SQL 建議保留——
   `repair-orphans` 的 pair 分支仍依賴同一組判定條件
