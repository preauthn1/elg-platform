# 金流相關表 denormalization 設計原則

> **目標**：為金流相關 schema 的 denormalization 決策建立統一框架，避免重複「設計了但寫入端沒做完」的歷史問題。
>
> **建立日期**：2026-05-22
>
> **狀態**：草稿（待 PO / 相關 owner 決議）
>
> **層級**：上位指導文件（金流商架構重構・軌道⑤，文件地圖見 [./README.md](./README.md)、狀態與決策見 [RB/TODO.md §3.1](../RB/TODO.md)）。本文件**不指定**具體要 denormalize 哪些欄位，只定義「何時該做、何時不該做、做了之後要配什麼」。具體欄位提案由獨立 spec 處理（範例：[費率與手續費雙層收斂計畫.md](./費率與手續費雙層收斂計畫.md)、[orders.cash_flow_id-欄位現況盤點.md](./orders.cash_flow_id-欄位現況盤點.md)）。

---

## 目錄

1. [立意](#1-立意)
2. [Denormalization 適用條件](#2-denormalization-適用條件)
3. [Denormalization 不該被選的情境](#3-denormalization-不該被選的情境)
4. [必備配套：四項紀律](#4-必備配套四項紀律)
5. [決策流程](#5-決策流程)
6. [現有 dead column / 半成品清單](#6-現有-dead-column--半成品清單)
7. [反 pattern](#7-反-pattern)
8. [新提案 checklist 範本](#8-新提案-checklist-範本)
9. [待決議事項](#9-待決議事項)

---

## 1. 立意

### 1-1 為何需要這份文件

本專案的金流相關表（`orders`、`agent_cash_flows`、`agent_cash_flow_order_types`、`cash_flows` 等）出現多處**設計與實作脫節**的欄位：

| 欄位 | 設計意圖 | 實際狀態 |
|---|---|---|
| `orders.cash_flow_id` | denormalize 自 `agent_cash_flows.cash_flow_id` 省 JOIN | 寫入端從未實作，全表 100% NULL |
| `agent_cash_flows.tax` / `tax_percentage` | 金流商級的手續費設定 | 實務上幾乎沒在用，TPower 僅 2 筆有設定且都未生效 |

這些不是「過度正規化」造成的問題，是 **denormalization 沒紀律完成**留下的負債——比單純的 JOIN 慢還難 debug，因為欄位看似存在、查詢無語法錯，但結果靜默錯誤。

### 1-2 為何 denormalization 在本專案的場景上方向是對的

PHP 8.4 + Laravel + 12M-row 主表的場景下：

- 99% request latency 在 DB IO，跨多表 JOIN 是常態
- Eloquent 的 hydration / lazy loading 對每筆 row 都有 reflection 成本
- MySQL optimizer 對跨 5+ 表的查詢路徑選擇不一定最優（已實證：跨 ACF + CF + 多 EXISTS 子查詢的命令耗時 8 分鐘）
- 常用查詢欄位 denormalize 到主表能直接減少這些成本

**所以方向是對的，但執行要有紀律。**

### 1-3 這份文件不主張什麼

- **不主張**「全面 denormalize」或「廢棄 3NF」——3NF 是事務一致性的 baseline，不是學術裝飾
- **不主張**所有慢查詢都靠 denormalize 解決——很多時候是 index 缺漏、N+1、lazy loading 配置不當
- **不主張**所有欄位都加副本——維護成本曲線會反過來壓垮收益

---

## 2. Denormalization 適用條件

當以下條件**多數成立**時，denormalization 是合理選項：

| 條件 | 說明 |
|---|---|
| ✅ **讀遠多於寫** | 該欄位被查詢的頻率遠高於被更新的頻率（典型：訂單表的 `cash_flow_id`、`currency_code`、`provider_name`） |
| ✅ **JOIN 成本顯著** | 涉及大表 JOIN 或多層關聯，且該 JOIN 出現在高頻路徑上（API endpoint、報表查詢） |
| ✅ **source 欄位幾乎不變** | source 表的該欄位是「設定一次就鎖定」性質（如 `agent_cash_flows.cash_flow_id` 一旦建立通常不改） |
| ✅ **已有索引策略也救不了** | 已嘗試 / 評估 source 表加 index，仍無法滿足性能需求 |
| ✅ **代替方案不適用** | 快取 / 物化視圖 / read replica 都不適合該場景 |

**「多數成立」的判斷**：建議至少 4 條為真，才進入 denormalization 提案流程。

---

## 3. Denormalization 不該被選的情境

下列情境**應優先用替代方案**，不是 denormalization：

| 情境 | 替代方案 |
|---|---|
| 報表 / 統計類大範圍 JOIN | **物化視圖** 或 **read replica 上跑專用 view** |
| 高頻讀取共用設定資料（白名單、字典） | **快取**（`Cache::safeRemember`） |
| 跨期統計（日 / 月匯總） | **預計算彙總表**（參考既有 `summary_bet_records` 模式） |
| 顯示用欄位（username、name） | **Eloquent eager loading + select 指定欄位**，避免拉整個 row |
| source 欄位會頻繁變動 | **保持 normalize**，避免每次變動都要同步多表 |
| 涉及外部 API 取得的即時資料 | **不寫入 DB**，由 service 即時呼叫 |

**判斷準則**：如果替代方案能在不引入「資料漂移風險」的前提下解決問題，**優先選替代方案**。

---

## 4. 必備配套：四項紀律

凡新增 denormalize 欄位，**必須**同時建立以下四項配套，缺一不可：

### 4-1 明確的 source of truth

- 文件化「哪張表 / 哪個欄位是寫的權威」
- denormalize 副本永遠是「派生資料」，不可被當作獨立資料源編輯
- 同一份資料絕對禁止「兩個地方都可以寫」

### 4-2 同步機制

寫入點必須收斂到單一路徑，並在該路徑保證副本同步。可接受的同步機制：

| 機制 | 適用情境 | 注意事項 |
|---|---|---|
| **Service 層強制 propagate** | 應用程式內部寫入 | 必須禁止任何 `DB::table()->insert/update` 繞過 service |
| **Eloquent observer / event** | 大量繼承同一 model 的場景 | 需確保 raw insert 不會繞過 observer |
| **DB trigger** | 多種來源寫入難以收斂 | 重 DB 而非重應用，debug 成本較高，慎用 |
| **批次校正命令** | 偶發 drift 的容錯 | 是補丁而非主機制，必須搭配上述其中一項 |

### 4-3 Drift audit 命令

每個 denormalize 欄位**必須**有對應的 audit 命令，可隨時驗證副本與 source-of-truth 是否一致：

```bash
# 範例命令格式
php artisan denormalize:audit-{table}-{field}
# 輸出：source 值 vs denormalize 值 不一致的 row 列表 + 統計
```

audit 命令應：
- 預設只讀，不自動修正
- 提供 `--fix` 旗標執行修正
- 列在 README / docs 索引中，易被找到

### 4-4 Single write path enforcement

代碼 review / lint 必須能阻止：
- 對 denormalize 欄位的 raw insert / update（繞過 service 或 observer）
- 新增第二個寫入 service 而沒先同步原 service 邏輯

可選方式：
- PHPStan / Larastan 自訂 rule 偵測直接 `Order::create([..., 'cash_flow_id' => X])` 的呼叫
- Code review checklist 強制 reviewer 確認

---

## 5. 決策流程

提案新增 denormalize 欄位時，須依以下流程：

### Phase 0：提案

- 寫一份獨立 spec（放 `docs/未完成計劃/金流商架構重構/` 下）
- 內容包含：
  - 動機（哪個查詢慢、慢多少、現況數據）
  - 替代方案評估（快取 / view / index 為何不適用）
  - source of truth 是哪個欄位
  - 預計同步機制
  - audit 命令設計

### Phase 1：審核

- PO / 相關 owner review
- 確認替代方案不夠用
- 確認同步機制可落地

### Phase 2：實作

- 建立 migration（新增欄位 + 必要 index）
- 修改 service 加上同步邏輯
- 寫 backfill 命令處理歷史資料
- 寫 audit 命令
- 寫 test cover 同步邏輯（含「raw insert 應被拒」的測試）

### Phase 3：部署 + 驗證

- 上線後跑 audit 一輪確認 0 drift
- 觀察期至少 1 週，期間每日跑 audit

### Phase 4：列入維運

- audit 命令排程定期執行（可整合進現有 cron）
- spec 文件搬到 `docs/` 主目錄或對應子目錄當作正式設計記錄

---

## 6. 現有 dead column / 半成品清單

> Baseline，待持續 audit 補充。每項建議獨立提案處理（A 補完寫入端 / B 移除欄位 / C 維持）。

> **2026-07-06 多 agent 讀碼 audit 補充**：下表新增五組確認過寫入/讀取端的候選。分類原則——「dead」需寫入端缺席**且**讀取端無實質使用；有 runtime 讀取的（如 `tax`）屬「雙層冗餘」不是 dead。

| 欄位 | 設計意圖 | 現況（附證據） | 建議 | 處理 spec |
|---|---|---|---|---|
| `orders.cash_flow_id` | denormalize 自 `agent_cash_flows.cash_flow_id` | 全表 100% NULL，讀取端 silent bug（`DepositController.php:331`） | A 補寫入／B 移除 | [orders.cash_flow_id-欄位現況盤點.md](./orders.cash_flow_id-欄位現況盤點.md) |
| `agent_cash_flows.tax` / `tax_percentage` | 金流商級手續費 | **非 dead**：有 runtime 讀取（`OrderService.php:77-78`、`AgentCashFlowListService.php:121-122`），屬 ACF↔ACFOT **雙層冗餘** | 收斂到 ACFOT | [費率與手續費雙層收斂計畫.md](./費率與手續費雙層收斂計畫.md) |
| `orders.third_party_id` | 支付商訂單號 | **dead（高確信）**：寫入端零、DB 抽查 distinct=1 全空字串；功能已由 `third_party_order_no` 承擔（勿混淆） | **B 移除** | 本表 |
| `cash_flow_info_id`（`orders` / `agent_cash_flows` 兩表） | `cash_flow_infos.id` denormalize | **dead（中確信）**：setup 命令寫死 `0`、runtime 零讀；ACF model docblock 標「目前棄用中」 | **B 兩表同批移除**，並評估 `cash_flow_infos` 表本身退役。（ACFOT 該欄已於 2026-07-20 repurpose 為 `cash_flow_id`＝denormalize `agent_cash_flows.cash_flow_id`，脫離本移除清單；該案 2026-07-24 合入 master `936336cdb`，設計文件已依落實即刪除原則移除） | 本表 |
| `cash_flows.rate` / `cash_flows.percentage` | 金流商級費率 | 疑 dead：僅 `SyncCashFlowsCommand` 寫、零直接讀（`$cashFlow->rate` grep 無）；**需 DB 驗證** distinct 是否全預設值 | 待 DB 驗證後 A/B | 本表 |
| `banks.third_party_code` / `banks.remark` | 三方碼／備註 | 疑 dead：`third_party_code` 僅一個 Report 命令讀、不在代付路徑（語意與 `cash_flow_banks.provider_bank_code` 重疊）；`remark` 未見金流使用 | 待 [銀行收斂](./銀行收斂/) 切 cash_flow_banks 後評估 | 本表 |
| `agent_cash_flows.cost` | 平台成本 | **非 dead**：仍有讀取（`AgentCashFlowListService.php:126`），migration 已標「待份桶 & SR99 切換後移除」；接班欄 `ACFOT.cost/cost_type` 已建但**尚無讀取路徑** | C 暫保留（勿搶跑） | [費率與手續費雙層收斂計畫.md](./費率與手續費雙層收斂計畫.md) |
| `agent_banks`（整張表） | 白標開放層 L2 | **死表**：migration 建了但零 app 讀寫、無 Model | 銀行軌決定啟用或 DROP | [方案 D](./銀行收斂/方案D-完整四層收斂.md) §2 |

### 6-1 建議：通用 dead column audit 命令

未來可建立一個通用命令：

```bash
php artisan schema:audit-dead-columns {table}
# 輸出該表每個欄位的：
#   - 總筆數
#   - NULL 比例
#   - 唯一值數量
#   - 是否實質為 dead column（NULL > 99% 或唯一值 = 1）
```

跑一輪可快速找出更多類似 `orders.cash_flow_id` 的半成品欄位。

---

## 7. 反 pattern

下列做法**禁止**，是過去踩過 / 即將踩到的坑：

### 7-1 加欄位但不加寫入端

migration 只寫 `ALTER TABLE ... ADD COLUMN`，未同時修改 service 寫入邏輯 → 永遠 NULL 的 dead column（範例：`orders.cash_flow_id`）。

### 7-2 雙 source of truth

同一份資料散在兩張表的同名欄位，未文件化哪邊權威，代碼裡兩條路徑各讀一邊 → silent 邏輯分歧（範例：`ACF.tax` vs `ACFOT.tax` 的雙層問題）。

### 7-3 寫入點散落

denormalize 欄位被 N 個 controller / service / job 各自寫一次，互不知道對方存在 → 維護一處就漏一處 → drift。

### 7-4 加欄位但不加 audit

denormalize 欄位上線後沒有定期校驗機制 → 漂移發生時沒有警報，往往要等客服 / 報表異常才發現。

### 7-5 借 denormalize 之名行偷懶之實

把「該做正規 ETL / 物化視圖」的場景塞進 denormalize 欄位 → 該欄位變成所有報表的 dumping ground → schema 失控。

---

## 8. 新提案 checklist 範本

提案新 denormalize 欄位時，在 spec 中附帶以下 checklist：

```markdown
## Denormalization 提案 checklist

- [ ] 提供現況數據：該查詢實際耗時 / QPS / 涉及表大小
- [ ] 評估過至少 2 個替代方案（cache / view / index）並說明為何不夠用
- [ ] 明確指出 source of truth 表 + 欄位
- [ ] 列出所有現有寫入點，提出收斂方案
- [ ] 設計同步機制（service / observer / trigger 擇一）
- [ ] 設計 audit 命令並列出輸出格式
- [ ] 設計 backfill 命令處理歷史資料
- [ ] 評估上線後對既有 query 的影響（read path / 報表）
- [ ] 訂出觀察期長度與監控指標
- [ ] 寫 test cover 同步邏輯 + raw insert 應被拒
```

提案 review 時，**任一項 checklist 未勾**都應退回補齊，避免重複歷史錯誤。

---

## 9. 待決議事項

- [ ] 本設計原則是否需提升為**全專案規範**（不限金流相關表）？建議：先在金流相關表試行 1 個 quarter，穩定後考慮推廣
- [ ] 是否需新人 onboarding 文件加入這份規範？
- [ ] 是否需在 PR template 加入「若新增 denormalize 欄位，請貼 spec link + checklist」的 reminder？
- [ ] §6.1 通用 dead column audit 命令是否值得獨立開發？建議優先級：中（一次性投入，後續發現更多半成品時都受益）
- [ ] 是否需建立「denormalize 欄位註冊表」（清單形式列出所有 denormalize 欄位 + source + audit 命令路徑），降低新人探索成本？

---

## 附錄：相關 spec 索引

- [費率與手續費雙層收斂計畫.md](./費率與手續費雙層收斂計畫.md) — 第一份具體欄位提案（tax＋cost 合編）
- [orders.cash_flow_id-欄位現況盤點.md](./orders.cash_flow_id-欄位現況盤點.md) — 第二份具體欄位提案
- [金流商架構重構總綱](./README.md) — 五軌總覽（cost 欄位 denormalize 案例現屬軌道②）
- `agent_cash_flows.cash_flow_type`（2026-08-03 已落地）— 便利欄，四項紀律對照：source of truth＝`cash_flows.type`（§4-1）；同步＝`AgentCashFlowObserver` creating 一律覆寫＋updating 於 `cash_flow_id` 變更時重繼承（§4-2／§4-4，衍生欄不接受外部指定值）；drift audit 兼收斂＝`cash-flow:sync-acf-type`（§4-3，排程 daily `--execute`，冪等、不觸 `updated_at`）；已知例外寫入者＝舊系統 API_Agent 後台建 ACF（`AgentOnlineDepositController`）不帶此欄，由排程收斂＋另案補 legacy 寫入

## 附錄：發現過程

2026-05-22 處理代收訂單 tax 異常排查時，連續發現：
1. `agent_cash_flows.tax` / `tax_percentage` 與 `agent_cash_flow_order_types.tax` / `tax_percentage` 雙層冗餘且代碼路徑「擇一」處理
2. `orders.cash_flow_id` 全表 100% NULL，寫入端從未實作但讀取端有依賴
3. 兩個問題共同 pattern 是「設計意圖與實作脫節」，根因為缺乏 denormalization 紀律

由此延伸出本文件，作為未來金流相關 schema 變更的決策框架。
