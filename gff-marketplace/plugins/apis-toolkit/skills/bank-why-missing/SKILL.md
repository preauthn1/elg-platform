---
name: bank-why-missing
description: 為何前端看不到 X 銀行 / agent 看不到某銀行 / withdrawal/banks 少了某銀行 / 客服回報銀行清單缺項 / 提款銀行不見了。當需要診斷會員 App 為何看不到某銀行時自動載入。🚫 診斷後若是「matcher no-match / low-confidence、要補 alias/keyword 到 Data/banks/{X}.php」走 bank-config-supplement；要清理 DB banks row 對齊 standard 走 bank-mismatch-fix。
---

# 銀行可見性診斷 SOP

當使用者說「為何前端看不到 X 銀行」「agent Y 看不到 Z 銀行」「withdrawal/banks 少了某銀行」等時觸發。

## 環境

本 skill 是純讀診斷。查指定白標時，`docker exec` 依 `whitelabel-container-exec` 帶 `-e DB_CONNECTION=<白標>`；純讀 SQL 可改走 `whitelabel-db-query`。

## 流程

### 1. 確認回報資訊

向使用者確認：
- bank_id 或 bank name
- agent_id
- 幣別
- （可選）cashflow_id

### 2. 跑診斷

```bash
# 模式 A：單一銀行 + agent
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --bank={N} --agent={M}"

# 模式 B：agent + 幣別，列出所有不可見銀行
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --agent={M} --currency={X}"

# 模式 C：agent + cashflow，列出該 cf 所有 feed bank 可見性
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --agent={M} --cashflow={N}"
```

### 3. 解讀失敗 step → 路由到對應修復工具

| 失敗 step | 失敗原因 | 修復工具 |
|---|---|---|
| 1 | cash_flow_banks 缺 | 跑 `bank:audit-feed`（feed 缺）或檢查 provider 設定 |
| 2 | AgentCashFlow 不存在 | 跑 `agent:add-agent-cash-flow` |
| 3 | AgentCashFlow status=0 | 啟用該金流商戶（`agent_cash_flows` 列；手動 SQL 或後台）|
| 4 | available_banks 漏 bank | 重跑 `agent:add-agent-cash-flow` |
| 5 | available_banks entry status=0 | 後台調整 |
| 6 | banks 表缺對應 agent_group_id row | 重跑 `agent:add-agent-cash-flow` |
| 7 | banks.status=0 | 手動啟用 |
| 8 | OrderType 停用 | 後台啟用 |

> 🔴 **先分清楚問的是哪一件事，否則會往錯的方向排查**：
>
> 會員 App 的提款銀行清單（`POST /frontend/api/withdrawal/banks` → `Frontend\WithdrawalController::getBanks()`）只查
> `Bank::where('agent_group_id', $cashFlowGroupId)->where('status', 1)`，**完全不經過 `agent_cash_flows.info.available_banks`**。
> ⇒「會員看不到某銀行」永遠是 `banks` 表該 `(agent_group_id, code)` 的列不存在或 `status=0`，先下這條就能定位：
> `SELECT * FROM banks WHERE agent_group_id = ? AND code = ? AND status = 1`。
>
> 上表 step 4／5 的 `available_banks` 是**另一個問題**——「這個金流商的代付支不支援該銀行」，
> 那條路徑是後台選代付渠道（`Agent\WithdrawalController::channels()`）才會走到。
>
> ⚠️ `banks` 表可能同時存在 `agent_group_id=0`（全域樣板）與該群組的多筆同 code 列，前端只撈該群組的那筆。

> `agent:add-agent-cash-flow` 是唯一建立入口。代收金流若不同 channel 配有各自的手續費 cost，命令會建議或自動採拆分模式，建立「每個 channel 一筆 AgentCashFlow」，因為目前一筆 AgentCashFlow 只能保存一個 cost；代付固定為單筆。完整判斷順序見 `payment-integration` skill §14。

### 4. 套用修復後重跑驗證

```bash
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --bank={N} --agent={M}"
```

確認 `visible=true`。

### 5. Commit（如有 config / DB 變動）

這一步是修正既有資料，類型用 `fix`；標題與 body 的寫法照 `git-commit` skill。

```
fix: 補 agent {M} 在 cf {N} 上 {bank_name} 可見性問題
```

## 不要做

- ❌ 直接改 DB 而不跑 diagnose（會漏掉鏈上其他斷點）
- ❌ 跳過 step 4 驗證（修了不確認等於沒修）
- ❌ 對 step 1 失敗的 case 跑 `agent:add-agent-cash-flow`（feed 都沒有，加金流商戶也補不出來）

## 與其他 skill 的搭配

- step 1 失敗（feed 缺）→ 觸發 `bank-config-supplement` 補 config 後重跑 sync
- step 4/6 失敗（金流商戶 / banks 表缺）→ 跑 `agent:add-agent-cash-flow`，必要時觸發 `bank-mismatch-fix` 清理 DB 殘留
