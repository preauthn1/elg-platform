# 殘留 unsettled 紀錄處置 SOP

> **何時讀這份**：Phase E Step 10 收尾盤點；或月結帳前清理 status=未結算 殘留時。

對齊完一輪 Phase A~D 後，幾乎都會剩 status=未結算 紀錄 — 這是會計上的「待結算負債」，必須依下列分類處置，**不可放任永久卡住**。

---

## 1. 異常分類（單錢包通用）

| 異常類型 | 識別條件 | 處理方向 |
|---|---|---|
| **(A) status 卡未結算，TBR 已存在** | `status=未結算 + TBR 存在` | 翻 status → 已結算（不需新建 TBR） |
| **(B) status 卡未結算，TBR 缺失** | `status=未結算 + TBR 不存在` | 補寫 TBR + 翻 status |
| **(C) Multi-bet stuck**（vendor 支援 multi-bet 才會發生） | 同 `(bet_id, username)` 多筆，至少 1 已結算 | Consolidation：1 round = 1 TBR，金額合併 |
| **(D) 缺原始表資料**（DataFeed 漏抓 / callback 漏送） | BR 有但 vendor 三方資料表沒有 | 重抓原始資料（vendor 卡定義的取資料命令） |
| **(E) payout_time 不一致** | vendor.endDate ≠ BR.payout_time | 同步 payout_time 並調整 SBR 桶 |

> **(A) 與 (C) 不可混淆**：(A) 假設 1 BR=1 TBR；(C) 假設多 BR=1 TBR。混用會留下重複 TBR。處理 multi-bet stuck **必須**先於單筆 stuck。

---

## 2. stuck 未結算根因模板

實務上多筆 status=未結算 卡住的根本原因幾乎都是：

```
原始資料漏抓（DataFeed 漏 timepoint / callback 漏送）
  └─ 該段紀錄在 vendor 原始表缺結算欄位（甚至整筆缺）
       └─ 表示 vendor 端 result/endRound callback 沒成功送達我方
            └─ BR 卡 status=未結算
                 ├─ TBR 已被 endRound 寫入 → 案例 (A)
                 └─ TBR 也漏寫       → 案例 (B)
```

**處理順序**：先補抓原始資料 → sync-payout-time（若 vendor 提供）→ 再跑 fix-records；**不要直接跑 fix-records 重試**。

---

## 3. 未結算紀錄會計語意

「未結算」(pending settlement) 在會計上等同於：玩家錢已扣，但結果尚未敲定。

| status 語意 | 是否入帳？ |
|---|---|
| 投注中（極少見） | ❌ 不入帳 |
| **未結算（懸而未決）** | ❌ **不應入月帳** |
| 退款 | ❌ 不入帳（金額為 0） |
| 已結算（最終態） | ✅ 入帳 |

**月帳只計算「已結算」**（時間欄位用 `$brTimeField`，不是 `bet_time`）：

```sql
-- ★ 時間欄位用 vendor 的 $brTimeField(多數 = payout_time、見 SKILL.md Step 0)
--   用 bet_time 切月帳會排除「上月投注本月結算」、納入「本月投注下月結算」、月份錯置
SELECT COUNT(*) AS settled_count, SUM(bet) AS settled_bet, SUM(win_lose) AS settled_winlose
FROM xxx_bet_records
WHERE {br_time_field} >= ? AND {br_time_field} < ?
  AND status = {SETTLED};  -- 依 vendor enum 替換
```

> 對齊原則：已結算 / 月帳 / 對齊驗證**一律用 `$brTimeField`**；只有「未結算殘留」`bet_time` + `payout_time IS NULL` 是例外（pending row 通常無 payout_time，見 §6.4 / SKILL.md §7.3）。

---

## 4. 兩階段重拉

vendor 原始資料會隨時間補進新資料（玩家斷線恢復、人工結算），所以殘留處理分兩階段：

### 4.1 首次對齊完當下

立刻匯出殘留清單（bet_id、username、bet_time、game_code），存檔備用。

### 4.2 數天後重拉

有「中途斷線 / 進行中」紀錄的日期（vendor 通常數小時 ~ 數天內補上）：

1. 跑 vendor 卡定義的取資料命令重拉
2. sync-payout-time（若 vendor 提供）
3. fix-records
4. 驗證殘留清單是否減少

---

## 5. 永久殘留送 vendor 客服

無法自動處理的兩種狀況需送 vendor 客服：

| 類別 | 提供資料 | 預期處置 |
|---|---|---|
| **原始資料完全沒收到** | bet_id、username、game_code、bet、bet_time | 詢問漏單 / 補資料 |
| **原始資料有但結算時間始終 NULL** | bet_id、username、bet、startDate、bet_time | 要求人工強制結算或退款 |

---

## 6. 業務處置選項表

vendor 回覆後依結論執行。**所有處置都要更新 BR.status，避免永久卡未結算**。

**路徑選擇核心原則**：
- **✅ 通用 `recovery_logs` Type C** 只支援「**既有 BR 翻 status + INSERT 新 TBR + 不動 wallet**」這一種狀態轉換
- **❌ 專屬 patch** 處理所有其他情境：動 wallet、修既有 TBR、純 status-only（無 TBR）、INSERT 新 BR

| 處置 | 玩家錢包 | 對 BR | 對 TBR | 寫入路徑 |
|---|---|---|---|---|
| **退款 REFUND**（任何 TBR 狀態） | **需退錢**（玩家投注時錢已扣） | status=退款、bet=0、win_lose=0 | 視 TBR 狀態而定 | ❌ 專屬 patch（必動 wallet） |
| **強制結算（輸）**（TBR 缺失） | **不動**（玩家投注時已扣、輸了不退也不派） | status=已結算、payout_time=now() 或 vendor 提供時間 | 用現有 BR 數值建立 TBR | ✅ 通用 recovery_logs Type C |
| **強制結算（輸）**（TBR 已存在） | 不動 | 翻 status=已結算（status-only） | 不動 | ❌ 專屬 patch（無對應新建 TBR、通用 revert 不適用） |
| **強制結算（贏）**（任何 TBR 狀態） | **需派 win** | status=已結算、更新 win/win_lose | 視 TBR 狀態而定 | ❌ 專屬 patch（必動 wallet） |
| **重拉 + 自動消化** | 由 vendor flow 處理 | sync-payout-time → fix-records | 自動建立 | ➖ 走 vendor 自身命令、不經通用 recovery_logs |
| **超時無回覆** | 業務決策（建議優先退款） | 同退款 | 同退款 | 同退款 → ❌ 專屬 patch |

> **❌ 專屬 patch 的共同需求**（依情境組合選用）：
> - **動 wallet 類**（退款 / 強制結算贏）：明確計算 `wallet_delta`、用 `WalletService::getMemberLockedWallets()` + `TransactionLogCreateService::create()` 建立可對稱 revert 的交易紀錄；revert 命令必須能反向扣回（見 [recovery_logs.md §2.4](recovery_logs.md#24-wallet-派彩決策vendor-specific-patch-用不可放通用-sop)）
> - **修既有 TBR 類**：自帶 `backup_tbr` JSON 快照、獨立 revert 命令還原既有 TBR（禁用通用 revert，會誤刪）
> - **純 status-only**（既有 TBR + 翻 BR status）：不需建新 TBR、通用 revert 設計不適用
>
> 範例參考：
> - `app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php`（Type A 補建 + wallet 處理範例）
> - **⚠️ 反例型態**：對既有 TBR 做 UPDATE 卻寫進通用 `bet_record_recovery_logs`、並把該 TBR id 放進 `after._meta.total_bet_record_id`。之後跑通用 revert 會刪掉這筆合法 TBR。新 patch 不可照這種寫法（見 `recovery_logs.md` §2.0）。
>
> 執行前必先：
> 1. `SELECT * FROM total_bet_records WHERE bet_record_id=? AND vendor_id=?` 判斷 TBR 是否存在
> 2. 解析該 vendor 的 settled status enum（不同 vendor 數字不同；見 `recovery_logs.md` §2.2 警告）
> 3. 確認該 vendor 是 SW / TW 以決定 wallet 處理路徑與 TransactionEnum 類型

---

## 7. 結帳時的處理原則

每月結帳時，未結算紀錄處置順序：

1. **匯出未結算清單**
2. **依業務處置選項表分類處置**（多半是退款）
3. **執行處置後**，所有 status=未結算 紀錄都會轉為退款 / 已結算
4. **重新對帳驗證**：`SELECT count(*) FROM xxx_bet_records WHERE status=未結算 AND bet_time IN range` 應為 0

未處置的紀錄**不應計入月帳營收**，僅作為「待結算負債」管理。
