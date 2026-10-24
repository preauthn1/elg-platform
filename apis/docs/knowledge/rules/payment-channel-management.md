---
type: Rule
id: rules/payment-channel-management
title: 支付通道管理
description: 用命令建立金流商戶、上下架與設定成本費率時，憑證怎麼來、開關寫在哪一層、後台怎麼顯示、改費率影響哪些單。
domain: 金流
subdomain: 渠道與貨幣
tags: [金流, 支付渠道管理, 金流商戶, 商戶, 渠道, 支付通道, 通道, 代收, 代付, 上下架, 上架, 下架, 停用, 啟用, 後台顯示, 隱藏, backend display, channel status, 憑證, 密鑰, 私鑰, token, credentials, 成本費率, 費率, cost, 金流商手續費, 支付商手續費, payment cost, 分潤, 佔成, agent cash flow, ACF, TopPay, AkPay, PayMate, CCPayment, 代理]
entities:
  - command: cash-flow:set-cost
  - command: agent:add-agent-cash-flow
  - command: cash-flow:channel-status
  - class: 'App\Services\AgentCashFlowListService'
  - class: 'App\Services\OrderTypeService'
  - class: 'App\Models\AgentCashFlow'
  - class: 'App\Models\WithdrawalOrder'
  - table: agent_cash_flows
  - table: withdrawal_orders
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/SetAgentCashFlowCostCommand.php
    title: 成本費率命令 cash-flow:set-cost（預演、預檢、備份、鎖定重驗後只改成本費率）
    at: 2026-10-20
  - kind: code
    ref: /app/Console/Commands/CashFlow/AddAgentCashFlow.php
    title: 建立金流商戶命令 agent:add-agent-cash-flow（憑證預填與補缺、上下架參數依金流類型落層）
  - kind: code
    ref: /app/Console/Commands/CashFlow/ChannelStatus.php
    title: 上下架命令 cash-flow:channel-status（查樹狀、改上下架與後台顯示）
  - kind: code
    ref: /app/Services/AgentCashFlowListService.php
    title: 代理後台金流列表，代收讀支付通道層、代付讀金流商戶層
  - kind: code
    ref: /app/Services/OrderTypeService.php
    title: 會員充值頁的支付通道清單，各層都要啟用、快取一分鐘
  - kind: code
    ref: /app/Models/AgentCashFlow.php
    title: 金流商手續費公式（paymentCostFor）
  - kind: code
    ref: /app/Models/WithdrawalOrder.php
    title: 代付成功時以出款金流商戶的成本費率算金流商手續費（paymentCostVia）
  - kind: code
    ref: /database/procedures/SP_calc_profit_share_payment_cost.sql
    title: 分潤的支付成本加總訂單上已寫入的金流商手續費
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/providers/TopPay.php
    title: TopPay 配置檔，商戶私鑰留空
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/providers/AkPay.php
    title: AkPay 配置檔，商戶 token 留空
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/providers/PayMate.php
    title: PayMate 配置檔，簽名密鑰留空
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2335
    title: 新增 cash-flow:set-cost 批次設定金流商戶的成本費率
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2308
    title: 金流商戶上下架改寫在營運實際使用的開關，上下架命令新增後台顯示參數
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2425
    title: 清除 PayMate 配置檔寫死的簽名密鑰
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2390
    title: 清除程式碼裡寫死的金流商憑證值，測試命令改從商戶設定讀取
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4238
    title: "[GFF][TPower]CCPayment-USDT代收代付接入"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4240
    title: "[GFF][Yes2win]CCPayment-USDT代收代付配置"
related:
  - rules/deposit-order-fees
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

金流商戶是金流商發給平台的一組收付款身分，底下掛著會員在充值頁看得到的支付通道。金流商戶由 RD 或上線人員用命令建立，之後在命令列或代理後台上下架、設定成本費率。成本費率是平台付給金流商的手續費占交易金額的比例，決定訂單上的金流商手續費。

上下架有兩個開關：

- 上下架狀態：決定能不能用。
- 後台顯示：決定代理後台列表看不看得到這一列。

營運關代收時，關的是支付通道；關代付時，關的是金流商戶。關之前，營運會先把同一層設成隱藏。

本份目前收錄的範圍：

- MR !2390、!2425 改變的新建金流商戶時的憑證預填。
- MR !2308 改變的上下架開關寫在哪一層，以及後台顯示參數。
- MR !2335 新增的成本費率批次設定。
- 理解以上幾項必要的既有行為：會員充值頁怎麼篩選支付通道、金流商手續費何時寫入。

「支付渠道管理」頁的其餘條目，隨後續 MR 補入。

## 怎麼觸發

- 建立金流商戶：命令列 `agent:add-agent-cash-flow`（`--status` 指定上下架）。
- 上下架與後台顯示：命令列 `cash-flow:channel-status`（`--acf` 指定金流商戶、`--acfot` 指定支付通道，`--set-status`、`--backend-display`）。
- 成本費率：命令列 `cash-flow:set-cost`（`--pair=<金流商戶 id>:<費率>`、`--reason`、`--execute`）。
- 結果在代理後台 › 系統管理 › 支付渠道管理看得到。新建金流商戶後補填憑證也在這裡（補填的畫面位置待確認）。

## 規則

### 建立金流商戶時的憑證

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 用建立命令新建金流商戶時，憑證依該金流商的配置檔預填。金流商戶號、API 網址這類非私密的值照帶，私密憑證（簽名密鑰、私鑰、token）一律是空白。TopPay 的金流商戶私鑰、AkPay 的金流商戶 token、PayMate 的簽名密鑰都屬於這類，新建後要由上線人員在代理後台補填。 | code:AddAgentCashFlow、code:TopPay.php、code:AkPay.php、code:PayMate.php |
| R-2 | 對已經存在的金流商戶重跑建立命令時，憑證只補上原本沒有的欄位，已填好的值一律保留，不會被配置檔的空白蓋掉。 | code:AddAgentCashFlow |

### 上下架開關寫在哪一層

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-3 | 建立命令的上下架參數（預設啟用）依金流類型寫在不同層。代收寫在支付通道（含它底下的銀行選項），代收金流商戶本身固定為啟用；代付寫在金流商戶。 | code:AddAgentCashFlow |
| R-4 | 建立命令指定停用時，同一層的後台顯示一併設為隱藏；指定啟用時，設為顯示。代收金流商戶本身的後台顯示不受這個參數影響。 | code:AddAgentCashFlow |
| R-5 | 重跑建立命令時，有兩種情況沿用營運既有的設定、不套用上下架參數：單筆轉拆分之後的續跑，以及依通道識別碼找到既有金流商戶。這兩種情況下，既有支付通道與金流商戶的上下架狀態、後台顯示都維持營運設定的值。 | code:AddAgentCashFlow |
| R-6 | 不屬於 R-5 的重跑，既有支付通道（代付則是金流商戶）的上下架狀態與後台顯示，會被改寫成這次參數的值。營運手動隱藏的支付通道，用啟用重跑後會重新顯示。 | code:AddAgentCashFlow |

### 上下架命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 上下架命令只帶金流商、不帶改值參數時，以樹狀表列出該金流商底下每個金流商戶與支付通道的上下架狀態與後台顯示，不做任何變更。 | code:ChannelStatus |
| R-8 | 上下架命令一次改一個金流商戶或一個支付通道。上下架狀態與後台顯示可以單獨帶或一起帶：只改上下架時，後台顯示不動；只改後台顯示時，上下架不動。 | code:ChannelStatus |
| R-9 | 上下架與後台顯示只接受 0 或 1，其他值拒絕、不寫入。指定的金流商戶或支付通道不存在，或不屬於命令指定的金流商時，也拒絕、不寫入。 | code:ChannelStatus |
| R-10 | 上下架命令只寫實際有變的欄位。目標值與現值都相同時，直接回報無需變更。要寫入時，先列出每個欄位的前後值，經操作者確認才寫（可加參數略過確認）。 | code:ChannelStatus |

### 會員與後台看到的結果

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 會員充值頁只列出本身、所屬的金流商戶與金流商三層全部啟用中的支付通道，所以停用代收時，只停用支付通道就足以讓會員選不到。這份清單最多快取一分鐘，上下架變更後，最晚約一分鐘反映到會員端。 | code:OrderTypeService |
| R-12 | 支付渠道管理列表上，代收那一列的上下架狀態與後台顯示讀支付通道那一層，代付那一列讀金流商戶那一層，與 R-3 寫入的層一致。 | code:AgentCashFlowListService |

### 成本費率命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 成本費率命令可一次指定多組「金流商戶與費率」，代收與代付金流商戶都適用，理由必填。預設只預覽：列出每個金流商戶的現值與目標值，不寫入任何資料。加上執行參數才寫入。 | code:SetAgentCashFlowCostCommand |
| R-14 | 費率必須是 0 到 1 之間、最多 4 位小數的十進位數（0.005 代表 0.5%）。大於 0.1 視為疑似填錯小數點，要另加放行參數才收。 | code:SetAgentCashFlowCostCommand |
| R-15 | 只要任何一組不合格（格式不符、超出範圍、金流商戶不存在或已刪除、同一金流商戶重複指定），整批都不寫入。 | code:SetAgentCashFlowCostCommand |
| R-16 | 現值已等於目標值的金流商戶列為略過，不寫入也不備份，所以同一批可以重跑。 | code:SetAgentCashFlowCostCommand |
| R-17 | 寫入前先備份會被改到的金流商戶。備份失敗或備份列數不符就中止，整批不寫入。寫入只改成本費率，金流商戶的其他設定不變。完成後印出還原用的命令。 | code:SetAgentCashFlowCostCommand |
| R-18 | 命令第一行印出這次寫入的連線與資料庫名。寫入正式庫前，另要操作者確認（可加參數略過）。 | code:SetAgentCashFlowCostCommand |

### 改成本費率影響哪些單

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 改了成本費率之後，只有之後才寫入金流商手續費的訂單用新費率，已寫入的訂單不重算（見 [rules/deposit-order-fees#R-11](deposit-order-fees.md)）。代付的取款訂單在代付成功時寫入金流商手續費，金額是實際出款那個金流商戶當下的成本費率，乘以「取款金額乘以入帳比例」，截到小數第 4 位、不四捨五入。 | code:WithdrawalOrder::paymentCostVia |
| R-20 | 分潤（佔成）的金流商手續費，是加總訂單上已寫入的金流商手續費，不以當下的成本費率重算。所以改成本費率不會改變已入帳訂單的分潤金流商手續費，重算歷史日期也一樣。 | code:SP_calc_profit_share_payment_cost.sql |

## Examples

金流商戶 450 目前成本費率 0，金流商戶 446 目前 0.005：

| 情境 | 結果 | 依據 |
|---|---|---|
| 指定 450 設 0.005，不加執行參數 | 列出 450 由 0 改 0.005，不寫入 | R-13 |
| 指定 450 設 0.005、446 設 0.005，加執行參數 | 備份 450 後只改 450，446 列為略過 | R-16、R-17 |
| 指定 450 設 0.25 | 擋下，要另加放行參數 | R-14 |
| 指定 450 設 0.005，另一組的金流商戶不存在 | 整批不寫，450 仍為 0 | R-15 |
| 450 改成 0.005 前已上分的充值訂單 | 金流商手續費維持原值 | R-19 |
| 450 改成 0.005 後才上分、入帳點數 1,000 的充值訂單 | 金流商手續費 5 | R-19、[rules/deposit-order-fees#R-5](deposit-order-fees.md) |

用建立命令新建一個代收金流商戶，上下架參數帶停用：

| 層 | 上下架 | 後台顯示 | 依據 |
|---|---|---|---|
| 代收金流商戶 | 啟用 | 不變（顯示） | R-3、R-4 |
| 支付通道與其銀行選項 | 停用 | 隱藏 | R-3、R-4 |
| 會員充值頁 | 看不到這條支付通道 | 不適用 | R-11 |
