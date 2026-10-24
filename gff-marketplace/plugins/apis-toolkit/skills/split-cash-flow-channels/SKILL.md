---
name: split-cash-flow-channels
description: 拆分代收金流商的 deposit channel 為多筆 AgentCashFlow（每個 channel 一筆，使用各自 cost）。只能打 `/apis-toolkit:split-cash-flow-channels <金流商>` 叫用。技能會詢問要處理哪個金流商，自動偵測哪些（agent_group_id, currency_id）組合有 legacy 記錄需要遷移，逐一以 agent:add-agent-cash-flow 拆分模式遷移並驗證。
disable-model-invocation: true
argument-hint: "[金流商名稱或 ID]"
---

你是「拆分代收金流商 channel」執行助理。當使用者觸發本技能，依下列步驟逐項完成。
任一步偵測到不需處理，就提示原因並結束，不要繼續執行命令。

---

## ⚠️ 過渡階段背景（向使用者說明時可引用）

不同 deposit channel 手續費 cost 不同，但目前 `cost` 只存在 `agent_cash_flows.cost`，因此只能用
「每個 channel 一筆 AgentCashFlow」承載各自 cost。這是**過渡階段設計**，未來 cost 欄位會下放到
`agent_cash_flow_order_types`，屆時可回歸「一個 cash_flow + 一個 currency = 一筆 AgentCashFlow」
的單純結構，本技能與相關命令也將退場。

---

## 環境

本 skill 的命令會寫入指定白標的 DB。開工前先確認白標，所有 `docker exec` 依 `whitelabel-container-exec` 帶 `-e DB_CONNECTION=<白標>`；生產白標要寫入時另依該 skill 的「生產白標預設『連得上但寫不進去』（dbc 從庫）」一節注入主庫 port。純讀的 SQL 查詢可改走 `whitelabel-db-query`。

---

## Step 1：解析金流商

優先使用使用者帶入的 `$ARGUMENTS`；若無，用 `AskUserQuestion` 詢問金流商名稱或 ID。

候選查詢（限 type=1 代收）：

```sql
SELECT id, name, third_party_code, type
FROM cash_flows
WHERE type = 1
  AND (name LIKE '%關鍵字%' OR third_party_code LIKE '%D\\_關鍵字%')
ORDER BY id;
```

若多筆相符，列出讓使用者選一筆。

如果使用者選的 cash_flow `type !== 1`，告知「此技能只處理代收金流」並結束。

---

## Step 2：檢查 Provider channel 藍圖與 cost

從 `cash_flow.third_party_code` 解析 provider 名稱（去除 `D_` 前綴）：

```
provider_name = preg_replace('/^[DW]_/', '', third_party_code)
config_path   = app/Console/Commands/CashFlow/Data/providers/{ProviderName}.php
```

讀取該檔，只取目標幣別的 `deposit.channels`，確認 channel 藍圖存在，並檢查是否至少一個 channel **存在 `cost` key**。`cost=0` 仍算已配置；判斷依據是 key 是否存在，不是數值是否大於 0。

- 找不到 config 檔 → 告知使用者 provider 配置不存在，**結束**
- config 沒有目標幣別的 `deposit.channels` → 告知使用者命令無從拆分、會固定使用單筆模式，**結束**
- 目標幣別 channels 全部沒有 `cost` key → 告知使用者
  > Provider 未配置 channel cost，命令在未指定 `--mode` 時會建議（互動）或自動選擇（非互動）單筆模式。若確定仍要拆分，可明確使用 `--mode=split`，但各 channel 未配置的 cost 會以 0 建立；不要自行替 provider 補 cost。

  讓使用者確認要繼續明確拆分或取消；取消就結束。

這項判斷的原因是不同代收通道可能有不同手續費，而 `cost` 目前位於 `agent_cash_flows`：要保留各 channel 的不同 cost，就必須拆成「每個 channel 一筆 AgentCashFlow」。本 skill 是使用者已明確要求拆分的流程，因此確認後會以 `--mode=split` 執行；一般建立流程則交由命令互動確認或依 config 自動判斷。

---

## Step 3：偵測需要遷移的 legacy 記錄

對該 cash_flow 的全部 (agent_group_id, currency_id) 組合，找 name 完全等於 `({CUR}) {cashflow.name}` 的記錄：

```sql
SELECT acf.id, acf.agent_group_id, acf.currency_id, c.code AS currency_code,
       acf.name, acf.cost, acf.status
FROM agent_cash_flows acf
JOIN currencies c ON c.id = acf.currency_id
WHERE acf.cash_flow_id = {CASH_FLOW_ID}
  AND acf.name = CONCAT('(', c.code, ') ', '{CASHFLOW_NAME}')
ORDER BY acf.agent_group_id, acf.currency_id;
```

`{CASHFLOW_NAME}` 用 Step 1 取得的 `cash_flows.name` 值（請以參數綁定，不要直接字串拼接以防特殊字元）。

- 沒有任何 legacy → 告知「未偵測到需遷移的 legacy 記錄」，結束
- 有 legacy → 進入 Step 4

---

## Step 4：列出計畫並讓使用者確認

對每個 legacy 記錄，找一個屬於該 `agent_group_id` 的 agent ID（patch 命令需要 `--agent`）：

```sql
SELECT id, username FROM agents WHERE cash_flow_group_id = {GROUP_ID} ORDER BY id LIMIT 1;
```

整理成表格給使用者看，並用 `AskUserQuestion` 詢問是否全部執行：

```
| agent_group_id | currency | legacy id | legacy cost | 代理 (id/username) |
```

選項：
- 全部依序執行
- 取消

如果該 cash_flow_group_id 找不到任何 agent → 標記為「無法執行」並從清單中排除，繼續處理其他組合。

---

## Step 5：對每筆 legacy 跑 dry-run 預覽

針對每筆 legacy 組合：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && \
  php artisan agent:add-agent-cash-flow \
  --agent={AGENT_ID} \
  --cashflow={CASH_FLOW_ID} \
  --currency={CURRENCY_CODE} \
  --mode=split --migrate-single \
  --dry-run --force --no-interaction"
```

把每筆 dry-run 的「📋 遷移計畫」結果整理摘要給使用者，例如：

```
agent_group=3 / IDR：
  EWALLET (cost 0.011)  重用 legacy 7 ACFOT
  QRCODE  (cost 0.012)  新建        2 ACFOT
  BANK    (cost 0.006)  新建       12 ACFOT
  孤兒 ACFOT 1 筆刪除
```

如果任一筆 dry-run 失敗（例如混合狀態錯誤），停止整批，列出失敗原因讓使用者處理。

---

## Step 6：使用者確認後正式執行

用 `AskUserQuestion` 再次確認：「以上 N 筆 legacy 記錄即將正式遷移，是否執行？」

確認後對每筆執行（去掉 `--dry-run`）：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && \
  php artisan agent:add-agent-cash-flow \
  --agent={AGENT_ID} \
  --cashflow={CASH_FLOW_ID} \
  --currency={CURRENCY_CODE} \
  --mode=split --migrate-single \
  --force --no-interaction"
```

逐筆執行；任一筆失敗立即停止，回報失敗的組合，不要繼續處理後續。

---

## Step 7：驗證遷移結果

對每個成功遷移的組合，跑驗證查詢：

```sql
SELECT acf.id, acf.agent_group_id, acf.currency_id, acf.name, acf.cost,
       (SELECT COUNT(*) FROM agent_cash_flow_order_types WHERE agent_cash_flow_id = acf.id) AS acfot_count
FROM agent_cash_flows acf
WHERE acf.cash_flow_id = {CASH_FLOW_ID}
  AND acf.agent_group_id = {GROUP_ID}
  AND acf.currency_id = {CURRENCY_ID}
ORDER BY acf.id;
```

預期看到 N 筆記錄（N = provider deposit.channels 數），每筆 name 對應 channel.name、cost 對應 channel.cost、ACFOT 數合理（parent OT + 子層）。

把驗證結果整理摘要給使用者。

---

## 邊界情境快速處理

| 情境 | 處理方式 |
|---|---|
| `cash_flow.type !== 1` | 告知技能只支援代收，結束 |
| Provider config 檔不存在 | 提示路徑後結束，不要建立檔案 |
| 目標幣別沒有 channel 藍圖 | 告知命令無從拆分並結束 |
| Provider 未配置 channel cost | 說明預設為單筆；使用者明確確認後才以 `--mode=split` 繼續，不要修改 provider 檔 |
| 沒有任何 legacy | 告知並結束 |
| 該 cash_flow_group 找不到 agent | 該組合標記跳過，繼續處理其他組合 |
| dry-run 出現「混合狀態」錯誤 | 列出衝突的 channel 名單，請使用者手動清理後再跑技能 |
| 正式執行中任一筆失敗 | 立即停止，回報已成功 / 失敗 / 未處理的清單 |

---

## 不要做的事

- 不要主動修改 `app/Console/Commands/CashFlow/Data/providers/{Name}.php`（cost 必須由使用者決定）
- 不要在遷移命令失敗時嘗試手動 SQL 修補；交給使用者
- 不要跳過 dry-run 直接正式執行，除非使用者明確要求
- 不要對 type=3（代付）金流商觸發此技能
