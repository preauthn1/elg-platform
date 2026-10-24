# agent:add-agent-cash-flow 使用說明

**功能**：為指定代理建立金流商戶配置（`AgentCashFlow`），讓代理可以使用該金流商。

**相關文件**：`app/Console/Commands/CashFlow/AddAgentCashFlow.php`

> 本命令已整併原 `agent:add-agent-cash-flow-by-channel`（舊版單筆命令退場、by-channel 版更名回本名），
> 並內建原 `fix:split-agent-cash-flow-by-channel` 的單筆→拆分就地遷移（該補丁已退場，見 `--migrate-single`）。
> 代收（type=1）支援兩種建立形式，由操作者確認：
>
> - **拆分模式**（一商戶對一通道）：每個 deposit channel 建立一筆 AgentCashFlow、各自 cost——唯一能對各通道配置不同 cost 的形式
> - **單筆模式**（一商戶對多通道）：一筆 AgentCashFlow 掛全部啟用渠道、單一 cost（等同舊版行為）
>
> 決定順序：`--mode` 選項 > 互動詢問（預設建議依 provider config 是否帶 channel cost）> 非互動依 config cost 自動分流。
> 代付（type=3）固定單筆，不受影響。

## 影響資料表

| 資料表 | 操作 | 條件 |
|--------|------|------|
| `agent_cash_flows` | INSERT / UPDATE | 每次執行都會處理 |
| `agent_cash_flow_order_types` | INSERT / UPDATE | 代收金流商 |
| `banks` | INSERT（如不存在） | 代付金流商 |

## 參數說明

| 參數 | 必填 | 說明 | 範例 |
|------|:----:|------|------|
| `--agent` | ❌ | 代理 ID；省略則進入互動模式 | `--agent=4249` |
| `--cashflow` | ❌ | 金流商 ID；省略則進入互動模式 | `--cashflow=99` |
| `--currency` | ❌ | 幣別 ID 或代碼；省略則進入互動模式 | `--currency=MYR` 或 `--currency=1` |
| `--status` | ❌ | AgentCashFlow 狀態，預設 1（啟用） | `--status=0` |
| `--mode` | ❌ | 代收建立形式：`split`=拆分模式（每通道一筆、各自 cost）、`single`=單筆模式；未指定時互動詢問、非互動依 config cost 自動分流 | `--mode=split` |
| `--migrate-single` | ❌ | 拆分模式偵測到既有單筆記錄（含設錯成一對多的）時就地遷移為拆分形式；互動模式未帶會詢問、非互動未帶則中止 | `--migrate-single` |
| `--dry-run` | ❌ | 乾跑模式：執行後回滾，不寫入資料庫 | `--dry-run` |
| `--force` | ❌ | 跳過環境確認提示 | `--force` |

**支援的幣別代碼**：

| 代碼 | ID | 名稱 |
|------|----|------|
| MYR | 1 | 馬來西亞令吉 |
| SGD | 2 | 新加坡元 |
| VND | 3 | 越南盾 |
| IDR | 6 | 印尼盾 |
| THB | 9 | 泰銖 |
| USDT | 10 | 泰達幣 |
| HKD | 16 | 港幣 |

## 使用範例

```bash
# 互動式執行（依序詢問代理 ID、金流商 ID、幣別）
php artisan agent:add-agent-cash-flow

# 非互動式執行（使用幣別代碼）
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=MYR

# 非互動式執行（使用幣別 ID）
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=1

# 乾跑模式（預覽，不寫入資料庫）
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=MYR --dry-run

# 指定拆分模式（每通道一筆 AgentCashFlow、各自 cost），免互動詢問
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=MYR --mode=split

# 既有單筆記錄（設錯或舊資料）就地遷移為拆分形式＋補建缺漏渠道（先 dry-run 預覽遷移計畫）
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=MYR --mode=split --migrate-single --dry-run

# 跳過環境確認直接執行
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=99 --currency=MYR --force
```

## 執行流程

```
1. 驗證代理是否存在
   ↓
2. 驗證金流商是否存在
   ↓
3. 驗證幣別是否存在
   ↓
4. 代收：確認建立形式（--mode / 互動詢問 / 非互動依 config cost 自動分流）
   ↓
5. 建立 AgentCashFlow（拆分模式：每通道一筆；單筆模式：一筆）
   ↓
6. 代收：建立 OrderType 關聯（拆分模式只掛該通道 key、不濾 order_types.status）
   代付：設定 available_banks（僅存碼對映，限額由 banks 表負責）並同步銀行
   ↓
7. 顯示執行結果
```

## 輸出範例

```
代理金流配置命令開始執行...

代理資訊: TestAgent (ID: 4249)
金流群組 ID: 123

金流商資訊: EpicPay (ID: 99)
金流類型: 代收

幣別資訊: 馬來西亞令吉 (MYR, ID: 1)

開始處理...

處理幣別: 馬來西亞令吉 (MYR)
  匯率: 1, 最小金額: 10, 最大金額: 50000
  ✓ 已建立 AgentCashFlow (ID: 185)
  處理 2 個支付渠道...
    + EpicPay (QR) (min: 10, max: 50000)
    + EpicPay (FPX) (min: 10, max: 50000)
      + 已建立 5 個銀行選項

✓ 所有變更已成功寫入資料庫

執行統計:
┌─────────────────────┬──────┐
│ 項目                │ 數量 │
├─────────────────────┼──────┤
│ AgentCashFlow (新增)│ 1    │
│ AgentCashFlow (更新)│ 0    │
│ OrderType 關聯      │ 7    │
│ 銀行新增            │ 0    │
└─────────────────────┴──────┘
```

## 注意事項

- 命令執行後會**自動更新銀行 mapping**（`available_banks`），無需額外操作
- 若代理未設定 `cash_flow_group_id`，命令會報錯；需先在 `agents` 表設定後再執行
- 多個代理需要相同配置時，建議改用 [`cash-flow:auto-sync`](cash-flow-auto-sync.md) 一次完成
- **單筆→拆分就地遷移**：拆分模式偵測到既有單筆記錄（name = `({CUR}) {金流商名}`，不論舊版命令
  或 `--mode=single` 建立）時，經確認（互動詢問或 `--migrate-single`）就地遷移——rename 重用該列為
  channel[0]、其餘 channel 新建並**繼承 rate／info（憑證）／status**、依 channel.key **搬移既有 ACFOT
  （保留手動限額與設定）**、刪除孤兒 ACFOT，遷移後僅補建缺漏配置（不覆寫既有）；未確認或非互動
  未帶 flag 則中止。單筆記錄與 per-channel 記錄並存（混合狀態）時會擋下，需人工清理
- provider config 無目標幣別的 deposit channel 藍圖時無從拆分，一律以單筆模式建立
