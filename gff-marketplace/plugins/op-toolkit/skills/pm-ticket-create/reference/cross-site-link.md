# Cross-site Sister Tickets Flow

> 適用條件：`questions.yaml Q1_station = MULTI`。
> Leah 慣例：跨站功能（例：joker baccarat 同時上 TPower + Yes2win + Wintoto）**每站獨立建單**，
> 用 `relates_to` issue link 互連。**不**共用一張單。

證據：`target/tickets#3081` (TP) `relates_to` `#3082` (TPower 配置) / `#3083` (Yes2win 配置) / `#3829` (Wintoto 配置)。

---

## 流程（skill 必須依序執行）

### Phase MultiPre：收集姐妹單站台清單
從 `questions.yaml` 的 `Q1_station` → `stage_b_sites`（特定站台的複選那一段）取得 `sites = [...]`；選了 2 站以上才算多站姐妹單。

### Phase MultiLoop：先收集各站資料，問一次 y，再逐站建單
分成兩個迴圈，中間問一次 y。第一段只收集、不寫入；合併預覽問過一次 y 之後，第二段才開始寫進 GitLab：

```
# 第一段：逐站收集，不寫入 GitLab
for i, site in enumerate(sites):
    1. 跑 questions.yaml Q2-Q6 (僅第一次完整跑，後續 site 自動沿用 Q2-Q6 答案)
    2. 載 description-schema/<schema>.md
    3. 填欄位（站台相關欄位 - 商戶 / API URL / IP 等 - 必須 per-site 重新詢問）

# 所有站收集完之後
4. 印一份多站合併預覽（格式見下方「預覽（多站姐妹單版）」），使用者回 y 才進第二段

# 第二段：逐站寫入
for i, site in enumerate(sites):
    5. 建 parent (Step 2 of api-cheatsheet：create_work_item)
    6. 建子任務 + 掛階層 (Step 3 / 3.1)
    7. 將 parent_iid 加入 created_parents 累積清單
```

**關鍵**：
- Q2 分類 / Q3 優先級 / Q4 子任務模式 / Q5 排期 / Q6 里程碑對所有姐妹單**一致**，只問一次（排期推算結果亦一致套用）。
- TG 群連結通常**所有姐妹單共用同一個**（廠商 x 我方統一群）。
- 商戶 ID / API Key / Callback URL / IP / Account Prefix **每站獨立**，必須 per-site 重新收集。

### Phase MultiLink：建立 relates_to 互連

完成所有 parent 建立後，對 `created_parents = [iid_1, iid_2, ..., iid_n]` 兩兩互連。
`PARENT_PROJECT_ID = 365`。

對每對 `(i, j)` where `i < j`，呼叫 `mcp__plugin_gitlab-mcp_gitlab__create_issue_link`：
- `project_id` = `<PARENT_PROJECT_ID>`
- `issue_iid` = `created_parents[i]`
- `target_project_id` = `<PARENT_PROJECT_ID>`
- `target_issue_iid` = `created_parents[j]`
- `link_type` = `relates_to`

> issue link 是雙向：A → relates_to → B 建立後，從 B 看也會看到 A。
> 所以 N 個 parent 需要 `N*(N-1)/2` 次呼叫即可（不要重複）。

### Phase MultiVerify：驗證 link
對每個 parent 呼叫 `mcp__plugin_gitlab-mcp_gitlab__list_issue_links`（`project_id`, `issue_iid`），確認回傳的 sister parent 數量 = N-1。

---

## 預覽（多站姐妹單版）

區塊名稱與說明照下面的中文寫，不要改回英文；排期區塊照單站預覽的格式寫，各站一致。

```
========== 預覽（多站姐妹單，共 3 站）==========
共用設定：
  分類      ：遊戲商（單錢包(直對)）
  優先級    ：0.2 優先
  子任務    ：設定遊戲商（ - 開發、 - 測試）
  排期      ：照單站預覽的排期區塊寫，各站一致
  TG 群     ：[JB x TPOWER API](https://example.invalid/+xxx)（各站共用）

每站要建的主單：
  [1] [GFF][TP]joker baccarat 單錢包(直對)接入
        商戶：GFFTEST_MA ／ API：https://merchant-api.jokerbaccarat.live
  [2] [GFF][YES2WIN]joker baccarat 單錢包(直對)接入
        商戶：JBYES2WIN ／ API：https://jbyes2win.tiger9.live
  [3] [GFF][Wintoto]joker baccarat 單錢包(直對)接入
        商戶：JBWINTOTO ／ API：https://example.invalid/...

每張主單的子任務（共 6 張）：
  [1.1]  - 開發 → rb        [1.2]  - 測試 → Benwu
  [2.1]  - 開發 → rb        [2.2]  - 測試 → Benwu
  [3.1]  - 開發 → rb        [3.2]  - 測試 → Benwu

要建立的關聯（3 組）：
  第 1 站主單 ↔ 第 2 站主單
  第 1 站主單 ↔ 第 3 站主單
  第 2 站主單 ↔ 第 3 站主單

送出後依序執行：
  1. 第 1 站：建主單 → 建 2 張子任務 → 掛到主單底下
  2. 第 2 站：建主單 → 建 2 張子任務 → 掛到主單底下
  3. 第 3 站：建主單 → 建 2 張子任務 → 掛到主單底下
  4. 三張主單兩兩互相連結
  5. 逐站讀回檢查標題、標籤、負責人、父子關係、起訖日
==============================================
```
