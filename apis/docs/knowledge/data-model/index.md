# 資料模型

<!-- knowledge:generated start -->
## 會員

* [data-model/member_groups 會員群組](member_groups.md) - member_groups 記錄代理設定的會員群組：充值、取款、轉帳限額與平台手續費階梯，決定一群會員能做什麼、要收多少手續費。
* [data-model/members 會員](members.md) - members 是會員主檔，記錄帳號、密碼、聯絡資料、餘額與驗證狀態，登入、充值、取款與錢包操作都讀寫它。

## 金流

* [data-model/agent_cash_flows 金流商戶](agent_cash_flows.md) - agent_cash_flows 記錄金流商戶的憑證、入帳比例與成本費率，由金流商戶管理命令寫入，代收建單與金流商手續費計算讀取。
* [data-model/cash_flow_banks 代付銀行碼對照](cash_flow_banks.md) - cash_flow_banks 記錄金流商、幣別與我方取款銀行之間的代付銀行碼對照，由 cash-flow:sync 等命令維護，代付送件時讀取。
* [data-model/currencies 幣別主檔](currencies.md) - currencies 是平台認得的標準幣別主檔，六個站點共用同一套編號，充值、取款與換算金額時讀取。
* [data-model/deposit_manual_reviews 充值人工確認紀錄](deposit_manual_reviews.md) - deposit_manual_reviews 記錄需要人工確認的充值訂單，目前由 CCPayment 風險交易建單時寫入，排程依退款結果解鎖或轉為失敗。
* [data-model/drift_baselines 漂移比對基準](drift_baselines.md) - drift_baselines 存放各監測對象上次比對時的已知內容，供下次比對是否有變動；目前只有 FPay 代付銀行清單在用。
* [data-model/standard_banks 標準銀行](standard_banks.md) - standard_banks 是平台認得的標準銀行清單，由各幣別設定檔同步產生，供取款銀行與金流商的銀行名稱比對基準。
* [data-model/withdrawal_orders 取款訂單](withdrawal_orders.md) - withdrawal_orders 是會員與代理的取款訂單，記錄金額、手續費、代付狀態與金流商回應，取款建單、審核與代付流程讀寫它。

## 遊戲與遊戲商

* [data-model/ace333sw_bet_records Ace333 注單](ace333sw_bet_records.md) - ace333sw_bet_records 是無縫錢包遊戲商 Ace333 回報進來、未經轉換的注單（BR），由 Ace333SWController 的回調寫入。
* [data-model/acewin_bet_records Acewin 注單](acewin_bet_records.md) - acewin_bet_records 是無縫錢包遊戲商 Acewin 回報進來、未經轉換的注單（BR），由 AcewinController 的回調寫入。
* [data-model/afbsw_bet_records AFBSW 注單](afbsw_bet_records.md) - afbsw_bet_records 是無縫錢包遊戲商 AFBSW 回報進來、未經轉換的注單（BR），由 AFBSwController 的回調寫入。
* [data-model/agent_games 代理遊戲開放清單](agent_games.md) - agent_games 記錄每個代理各自開放了哪些遊戲給底下會員，由遊戲清單同步流程寫入、代理後台讀取。
* [data-model/bet_record_recovery_logs 注單人工補帳撤回事件記錄](bet_record_recovery_logs.md) - bet_record_recovery_logs 記錄維運用命令對無縫錢包注單做人工補帳（退款、投注量歸零）或撤回補帳的每一次操作，供日後查核。
* [data-model/game_names 遊戲多語系名稱](game_names.md) - game_names 記錄每款遊戲在各語系下顯示的名稱，由遊戲清單同步流程寫入、前台與後台依語系讀取。
* [data-model/games 遊戲主檔](games.md) - games 是遊戲商旗下每一款遊戲的主檔，記錄所屬遊戲商、遊戲類型與有效投注轉換比例，由遊戲清單同步流程寫入。
* [data-model/onebxsw_bet_records OnebxSW 注單](onebxsw_bet_records.md) - onebxsw_bet_records 是無縫錢包遊戲商 OnebxSW 回報進來、未經轉換的注單（BR），由 OnebxSWController 的回調寫入。
* [data-model/pgssw_bet_records target 注單](pgssw_bet_records.md) - pgssw_bet_records 是無縫錢包遊戲商 target（含 BWG、TIGER9）回報進來、未經轉換的注單（BR），由 target 回調流程寫入。
* [data-model/pgssw_cancel_logs target 取消投注紀錄](pgssw_cancel_logs.md) - pgssw_cancel_logs 記錄 target（含 BWG、TIGER9）取消投注回調的處理狀態，由 target 回調流程讀寫。
* [data-model/pgssw_event_records target 活動派彩紀錄](pgssw_event_records.md) - pgssw_event_records 記錄 target（含 BWG、TIGER9）活動派彩回調發放的獎勵明細，由 target 回調流程寫入。
* [data-model/rgsw_bet_records RGSW 注單](rgsw_bet_records.md) - rgsw_bet_records 存放 RGSW（RG 無縫錢包版）的注單，由 RGSW 回調寫入與更新。
* [data-model/summary_bet_records 注單彙總（SBR）](summary_bet_records.md) - summary_bet_records 是依每人每小時每款遊戲聚合後的投注結算數字，報表與代理分潤讀它。
* [data-model/total_bet_records 統一注單（TBR）](total_bet_records.md) - total_bet_records 是把各家遊戲商注單轉成同一組欄位後的注單，供跨遊戲商查詢與 SBR 彙總使用。
* [data-model/tthsw_deal_records EliteHub 回調交易紀錄](tthsw_deal_records.md) - tthsw_deal_records 記錄 EliteHub（TTHSW）每筆已處理回調的異動前後餘額，用來判斷回調重送。
* [data-model/vendor_info_games 遊戲商戶的遊戲設定](vendor_info_games.md) - vendor_info_games 記錄每個遊戲商戶底下有哪些遊戲、各自的投注與活動參與設定。
* [data-model/vendor_infos 遊戲商戶](vendor_infos.md) - vendor_infos 是遊戲商戶主檔，記錄每個遊戲商戶的連接憑證、幣別與帳號池設定。
* [data-model/vendors 遊戲商](vendors.md) - vendors 是遊戲商主檔，記錄每家遊戲商的拆帳比例、有效投注轉換比例與無縫錢包設定。

## 系統

* [data-model/queue_job_runs Queue job 執行紀錄](queue_job_runs.md) - queue_job_runs 記錄各背景 job 在每個工作單位的執行結果，供各自的排程與回看補洞判斷是否已完成。
<!-- knowledge:generated end -->
