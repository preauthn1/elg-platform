# cash-flow:auto-sync 使用說明

**功能**：自動化金流接入流程，一次完成以下兩件事：
1. 呼叫 `cash-flow:sync` 同步金流商配置
2. 依環境配置，為所有代理逐一呼叫 `agent:add-agent-cash-flow`

## 執行流程

```
1. 讀取 Data/site_environments.php，自動識別當前資料庫環境
   ↓
2. 顯示環境名稱，要求確認（正式環境預設 No，開發環境預設 Yes）
   ↓
3. 依 --production / --test 旗標篩選代理清單
   ↓
4. 執行 cash-flow:sync（同步金流商）
   ↓
5. 逐一為每個代理執行 agent:add-agent-cash-flow
   ↓
6. 顯示統計（總計 / 成功 / 失敗 / 略過）
```

> ⚠️ **在 TPower 以 `--cashflow=102`（PayPro 代付）、`--currency=IDR` 執行本命令後**，要照 [cash-flow:sync 使用說明](cash-flow-sync.md) 的「對 TPower 同步 PayPro 代付後，要補跑回填並抽驗」一節，補跑回填並抽驗。原因是第 4 步的同步靠銀行名稱重建銀行對應，名稱對不上的對應會被停用，第 5 步也會跟著把它們從金流商戶設定移除，本命令不會自動補回。

## 代理初始狀態

| 代理類型 | 初始狀態 | 說明 |
|----------|----------|------|
| 正式代理（production） | 停用（status=0） | 需手動審核後啟用 |
| 測試代理（test） | 啟用（status=1） | 立即可用 |

## 參數說明

| 參數 | 說明 | 範例 |
|------|------|------|
| `--cashflow` | 要同步的金流商 ID，多個用逗號分隔（必填） | `--cashflow=79,80` |
| `--currency` | 幣別代碼或 ID（必填） | `--currency=VND` |
| `--production` | 只處理正式代理（status=0） | `--production` |
| `--test` | 只處理測試代理（status=1） | `--test` |
| `--dry-run` | 乾跑模式：執行後回滾，不寫入資料庫 | `--dry-run` |

> 未指定 `--production` 或 `--test` 時，同時處理所有正式代理與測試代理。

## 使用範例

```bash
# 同步指定金流商，並建立所有代理配置（含正式與測試）
php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND

# 只建立正式代理配置
php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --production

# 只建立測試代理配置
php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --test

# 預覽操作（不寫入資料庫）
php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --dry-run
```

## 與 cash-flow:sync 的差異

| 項目 | `cash-flow:sync` | `cash-flow:auto-sync` |
|------|-----------------|----------------------|
| 同步金流商 | ✓ | ✓（內部呼叫） |
| 建立代理配置 | ✗ | ✓（自動批次） |
| 環境偵測 | ✗ | ✓ |
| 環境確認提示 | ✗ | ✓ |
| 適用場景 | 單獨同步金流商資料 | 完整接入新金流商 |

## 幣別代碼對照表

| 代碼 | ID | 名稱 |
|------|----|------|
| MYR | 1 | 馬來西亞令吉 |
| SGD | 2 | 新加坡元 |
| VND | 3 | 越南盾 |
| IDR | 6 | 印尼盾 |
| THB | 9 | 泰銖 |
| USDT | 10 | 泰達幣 |
| HKD | 16 | 港幣 |
