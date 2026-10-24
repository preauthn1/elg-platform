# Domain → Skill 路由表（Mode A 用）

> 開發收單後依 domain 跳轉到對應既有 skill / SOP，避免重複跨技能查找。
> 路由不是「唯一選擇」而是「建議起點」；複合 domain 可同時推多條。

---

## 1. 主路由表

| domain | 子情境 | 建議下一步 |
|---|---|---|
| `payment` | 充值未到帳 / 提款卡單 | 查 `deposit_orders` / `withdrawal_orders`；用 `whitelabel-db-query` 對該白標下唯讀 SQL |
| `payment` | 三方代付通道異常 | `payment-integration` skill（檢查 provider 設定 / cash_flow ↔ agent_cash_flow 配置） |
| `payment` | 投注帳變 / 補帳 | `list-sbr-corrections`（查調帳差額：已關帳時段追加的差額列）/ `reconcile-bet-records`（對齊驗證，只讀不寫；確認要補帳後交棒 `repair-bet-records` 做補帳作業） |
| `payment` | 大額通知沒發 | `large-amount-notify-debug` |
| `bank` | 前端看不到某銀行 | `bank-why-missing` |
| `bank` | 三方代付 IDR 銀行配置 | `bank-config-supplement`（補 alias / keyword） |
| `bank` | banks 表 mismatch / 重複 | `bank-mismatch-fix` |
| `bank` | 代收金流商 channel 拆分 | 打命令 `/apis-toolkit:split-cash-flow-channels <金流商>`（這支只能打命令叫用，建議下一步直接寫這條命令，不寫成一句話） |
| `bonus` | 活動重複領 / 條件異常 | 查 `activity_events` / `member_activity_logs`；先理解活動規則再 trace |
| `bonus` | 紅包 / 流水未達標 | 查 `bonus_orders` / `member_bonus_records` / `member_flow_records` |
| `vendor` | 遊戲打不開 / 錯誤彈窗 | `game-integration` skill 對應遊戲商 vendors/{name}.md |
| `vendor` | 遊戲商商戶額度不足（玩家有餘額卻跳資金不足、遊戲商回覆「商家余额不足」） | 營運向遊戲商補額度，RD 不必改程式；用 `whitelabel-db-query` 依小時統計該遊戲商的注單數，比對補額度前後，確認已恢復 |
| `vendor` | 注單異常 / 派彩錯 | `reconcile-bet-records`（三表對齊）+ 對應 vendor 對接卡 |
| `vendor` | 遊戲商新接入需求 | `pm-ticket-create`（這是 GFF 需求，不是 OP 線上問題） |
| `login` | 註冊驗證碼錯誤（9992，舊版為 9015） | 見 `register-9015-debug.md`（多半 validate_tokens 過期） |
| `login` | 登入失敗 | 查 GCP log（`gcp-log-troubleshoot`）member_id / device_id |
| `ui` | 後台顯示問題 | 切到對應白標環境檢視；確認 frontend 與 API 是否一致 |
| `data` | 報表 / 統計不對 | 查 SBR / TBR / BR 是否同步（`reconcile-bet-records`） |
| `other` | 通用 bug | `dev-toolkit:bug-diagnosis`（先建會變紅的重現命令再列假設，避免直接跳結論） |

---

## 2. 跨 domain 必走的 skill

幾乎所有 op_tickets domain 都會用到：

| 起始動作 | 對應 skill |
|---|---|
| 查對應白標的資料（唯讀 SQL） | `whitelabel-db-query` |
| 在對應白標跑 artisan 命令 | `whitelabel-container-exec` |
| 撈 GCP log 看請求 / 三方回應 | `gcp-log-troubleshoot` |
| 與三方對接（金流 / 遊戲商）細節 | `payment-integration` / `game-integration` |
| 注單補帳（BR、TBR、SBR 對不上要補正） | `repair-bet-records`（起手要先有 `reconcile-bet-records` 的診斷結論；寫入留審計紀錄，每個寫入命令前逐一確認） |
| 其他資料要寫新的修正命令 | `laravel-best-practices` + DB 鎖 `lockForUpdate()` |

---

## 3. Assignee 推薦規則（Mode B 用）

依 domain 預設指派的開發（可被 user 覆蓋）：

| domain | 預設 assignee | 後備 |
|---|---|---|
| `payment` 三方代付 | `Tim` | `zoe` |
| `payment` 帳變 / 對帳 | `rb` | `Tim` |
| `bank` 配置 | `rb` | `Tim` |
| `bonus` 活動 | `daniel` | `william` |
| `vendor` 配置 / 遊戲商接入 | `leo` | `mars` |
| `login` 註冊 | `mars` | `rb` |
| `ui` 前端 | `ryan` | 無 |
| `data` 報表 | `rb` | `Tim` |
| 不確定 | `leah` (PM 分派) | 無 |

> 依當前團隊調整；Mode B 建單前若不確定 domain，預設 `leah`，由 PM 二次分派。

---

## 3.5 邊界情境：偏新需求的單

若解析發現工單性質**偏新需求 / 商務對接**而非線上問題（觸發詞：「需要對接」「需要安排」「希望接入」「能否新增」「規劃接入」），在摘要區補一行提示：

```
⚠️ 此單性質偏新需求（partner integration / 對接 / 新功能），可能應走 pm-ticket-create (GFF) 流程而非 OP 線上問題。
   若確認屬實，建議：1) 評估是否在 op_tickets 結案 2) 由 PM 在 target/tickets 開立對應 GFF 工單
```

實例：#2008「PT x DTK/APEXG/ONEMIX 活動對接需求」屬此類。

---

## 4. 多 domain 處理原則

工單同時涉及多 domain（如 `bonus + payment`）：

1. 摘要區的 `domain` 欄位列所有：`bonus + payment`
2. 建議下一步**依嚴重度排序**：先處理影響資金的（payment），再處理活動規則的（bonus）
3. 列出每個 domain 對應 skill，讓開發自選

例：
```
domain: bonus + payment

🔍 建議下一步：
  1. 查 tpower 的資料走 whitelabel-db-query（唯讀）
  2. [payment] 查 deposit_orders（player / amount / time）→ 確認充值是否真的到帳
  3. [bonus] 查 activity_events 確認活動觸發條件
  4. 看 GCP log（gcp-log-troubleshoot）trace 完整請求
```
