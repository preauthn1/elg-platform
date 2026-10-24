# 方案 A｜資料清洗止血

> **層級**：銀行收斂五級方案的第 1 級（A ⊂ B ⊂ C ⊂ D，E 以 C 為最低前提）。分級總覽與決策狀態見 [RB/TODO.md §3.1.3](../../RB/TODO.md)。
> **工程量**：8–10 人天（A2 有下修空間，見工作項說明）｜**曆時**：約 2 週
> **定位**：不動任何業務邏輯，把現有髒數據清乾淨＋上防復發約束。

---

## 1. 要解決的問題

跨白標 `banks` 數據混亂——同一實體銀行多碼多列、同碼跨白標異義、代付對映靠店內碼字串比對失敗：

- **觸發個案**：DEV `withdrawal_orders.id=155`（`agent_group_id=3`、MYR、Hong Leong Bank、`bank_code=MYR0035`）無法走 PayMate 代付。直接死因：`banks` 表對 Hong Leong 有三筆重複列、code 各不相同（`HongLeongBank` / `MYR0035` / `TPMY02`），會員綁到 `MYR0035`，PayMate 的 `available_banks` 只認 `HongLeongBank`。
- **跨白標對比**（agent_group 3 / MYR / 啟用）：VWorld 22 筆 22 distinct code 零冗餘（乾淨基準）；DEV 66 筆 55 distinct code、11 筆純重複——證明是 DEV 數據污染。
- **同碼異義鐵證**：`MYR0035` 在 DEV = Hong Leong、TPower = Bank of China、VWorld 無此 code。

## 2. 工作項

| # | 工作項 | 性質 | 人天 |
|---|---|---|---|
| A1 | `standard_banks` migration＋`cash-flow:seed-standard-banks`（config 冪等 upsert）＋`bank:sync-mappings`（回填 `banks.standard_bank_id`）建 mapping——兩支均已在 master。舊 `cash-flow:backfill-standard-banks`（banks GROUP BY 直接 INSERT 的舊方向）**已於 2026-07-28 移除** | 現成微調 | 1–2 |
| A2 | **bank_id 外鍵遷移覆蓋面 audit＋補缺口**：`bank:dedup-active` 現行實作（`BankActiveDedupService`）**已會遷移** `member_bank_accounts` 與 `withdrawal_orders` 的引用，但未涵蓋所有引用表（`bank_accounts`、`bank_order_types`、`bank_names` 等需逐表確認）。工項為逐表 audit 現有覆蓋面＋補齊缺漏表 | audit＋擴充 | 1–3 |
| A3 | 同碼重複：`bank:dedup-active`＋`bank:guard-active-banks`（master 現成，分組鍵 `(agent_group_id, type, currency_id, code)`）；**跨碼重複**（order 155 的 Hong Leong 三筆 code 各不同，現行 dedup 合不掉）：經 standard_bank_id 映射的合併命令（dedup 小幅擴充或個案補丁） | runbook＋擴充 | 3 |
| A4 | 驗收＋回滾演練（`bank:dedup-rollback` 現成，依 `bank_dedup_logs` 反向） | 驗收 | 1–2 |

## 3. 現有命令能力（2026-07-06 實測勘誤後）

| 能力 | 命令 | 實際行為 |
|---|---|---|
| seed→standard_banks | `cash-flow:seed-standard-banks` | 從 git-tracked config `Data/banks/StandardBankCodes.php` 冪等 upsert（宣告式 seed，支援 `--dry-run`）；搭配 `bank:sync-mappings` 回填 `banks.standard_bank_id`。舊 `cash-flow:backfill-standard-banks`（banks GROUP BY 直接 INSERT、無 upsert）**已於 2026-07-28 移除** |
| active 去重 | `bank:dedup-active` | dry-run 預設；分組鍵 `(agent_group_id, type, currency_id, code)`——只能合併同碼純複製，跨碼重複合不掉；**已遷移** `member_bank_accounts` / `withdrawal_orders` 引用，其他引用表未涵蓋 |
| 去重防復發 | `bank:guard-active-banks` | generated column partial unique |
| 去重回滾 | `bank:dedup-rollback` | 依 `bank_dedup_logs` 反向 |
| name/合併修正 | `bank:fix-mismatch` | 涵蓋 banks＋bank_names＋6 引用表，但以 name 對映 |

## 4. 安全紅線

> **外鍵遷移覆蓋面補齊（A2）之前，`bank:dedup-active` 不可單獨上線。**
> 現行實作已處理 `member_bank_accounts` / `withdrawal_orders`，但只要有任一引用表不在遷移範圍，去重就會留下指向 `status=0` 銀行的懸空引用。A2 的逐表 audit 是所有更高級方案（B/C/D）共同的前置。

**A2 待補覆蓋的引用表（2026-07-06 讀碼確認 `BankActiveDedupService` 實際只 re-point `member_bank_accounts` 與 `withdrawal_orders`）**：

| 引用表 | 現況 |
|---|---|
| `bank_order_types` | ❌ dedup-active 未覆蓋 |
| `order_images` | ❌ 未覆蓋 |
| `realname_validation_requests` | ❌ 未覆蓋 |
| `agent_bank_accounts` / `cash_flow_banks` | 由 `StandardizeBankCodes` / `BankRepairService` 另行處理，dedup-active 本身不碰 |
| `agent_banks` | ⚠️ **死表**——migration 建了但全 codebase 零讀寫、無 Model（見 [方案 D](./方案D-完整四層收斂.md) §2 警示）；dedup 上線前需先決定此表去留，否則未來一旦有人寫入就懸空 |

## 5. 優缺點與適用

- **優點**：最快見效、風險最低；order 155 個案修復（會員綁定改指保留列後，code 對得上）。
- **缺點**：代付對映機制不變（`available_banks` JSON 字串比對）——guard 只防「同碼純重複」，防不了「建錯碼／provider 改碼／跨碼再冒」，同類問題可能換個形式再發生。
- **適用**：資源極度吃緊、只需先止住 DEV 亂象時。要根治請至少批 [方案 B](./方案B-代付查表化.md)。
