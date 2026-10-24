<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

$showOutput = function ($output) {
    echo $output;
};

// 活動系統排程
Schedule::command('check:event_v2_schedule')->everyMinute()->onOneServer()->thenWithOutput($showOutput);
// 紅包（target/tickets#4234）也走上面這條：類型列 schedule 填 0 16 * * *，到點由 app:init-event-v2 轉給 AngpowPeriodResetService 依各檔週期清
Schedule::command('app:recycle-event-v2-schedule')->dailyAt('17:00')->onOneServer()->thenWithOutput($showOutput);

// 每日活動排程
Schedule::command('app:summary-mission-datas')->everyFiveMinutes()->onOneServer()->thenWithOutput($showOutput);

// 每天封存前一天的資料到分表
Schedule::command('archive:command_logs --daily')->dailyAt('05:00')->onOneServer()->thenWithOutput($showOutput);
Schedule::command('archive:activity_logs --daily')->dailyAt('06:00')->onOneServer()->thenWithOutput($showOutput);

// queue_job_runs 執行紀錄保留一個月（最大量來源 fetch_bet_record，不清會撞 SchemaMonitor 50 萬列 ALTER 門檻）
Schedule::command('clean:queue-job-runs')->dailyAt('05:30')->onOneServer()->thenWithOutput($showOutput);
Schedule::command('clean:order-request-logs')->dailyAt('05:40')->onOneServer()->thenWithOutput($showOutput);

// 到期備份表清除（<原表名>_bak_<時間戳>，TableBackupService 的產物；保留 15 天）
// 只認嚴格命名＋戳早於保留期，不碰任何不合模式的表。
Schedule::command('db:prune-table-backups')->dailyAt('05:20')->onOneServer()->thenWithOutput($showOutput);

// 多幣別錢包觀察期：比對 members.balance 與影子錢包餘額，差異即告警（決議⑨，
// 連續無差異是進入切換寫入的前提）。時段避開 hourlyAt(30) 的注單彙總與各日結排程。
Schedule::command('wallet:verify-balance')->dailyAt('04:10')->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 金流四層鏈孤立 acfot 巡檢（唯讀報告，發現才 BotNotify）
// 安全網定位：AgentCashFlowObserver 只覆蓋 Eloquent 路徑，raw SQL 直改 DB 靠此接住。
// 刻意不自動下架——下架走 fix:cashflow-orphans（人工確認），還原走 fix:acf-cascade-restore。
Schedule::command('check:cashflow-orphans --notify')->dailyAt('07:00')->onOneServer()->thenWithOutput($showOutput);

// ACF cash_flow_type 便利欄收斂（權威=cash_flows.type；冪等、無差異零寫入）
// 安全網定位：AgentCashFlowObserver 只覆蓋 Eloquent 路徑，舊系統 API_Agent 建 ACF／raw SQL 靠此收斂。
Schedule::command('cash-flow:sync-acf-type --execute')->dailyAt('07:20')->onOneServer()->thenWithOutput($showOutput);

// 銀行表拆分第一期：banks（權威）→ agent_banks／cfb.standard_bank_id 冪等全量投影（附歧義組與缺口報告）
// banks 寫入口多為 DB::table() 批次 update、Observer 蓋不到，故走排程全量重建（千級列數）。
Schedule::command('bank:sync-projection')->dailyAt('07:40')->onOneServer()->thenWithOutput($showOutput);

// banks active 唯一約束存在性稽核（唯讀）：bank:dedup-rollback 卸鎖不復原、建立半途失敗的
// 半套態都無其他偵測手段，缺席即 BotNotify 告警（復原＝清理後重跑 bank:guard-active-banks）。
Schedule::command('bank:verify-guard')->dailyAt('07:50')->onOneServer()->thenWithOutput($showOutput);

// FPay 代付銀行清單稽核（op_tickets#3229）：向 FPay 查各代付設定的現行銀行清單，與上次的內容比對。
// FPay 的銀行代號按「商戶帳號×幣別」各發一套且會在不通知的情況下整批換掉；代號被換成別家銀行時
// 照舊送出不會被退回，錢會匯到別家銀行（op_tickets#3174），只有比對前後兩份看得出來。
// 不動業務資料，只在內容與上次不同時往 drift_baselines 寫一列（沒變的日子等同唯讀）；
// 有差異即 BotNotify 且一律回 SUCCESS，避免排程每天紅。08:00 避開 07:00／07:20／07:40 三支既有金流排程。
Schedule::command('cash-flow:audit-fpay-bank-list --notify')->dailyAt('08:00')->onOneServer()->thenWithOutput($showOutput);

// 代付對照表銀行碼稽核（唯讀）：cash_flow_banks.bank_code 與 banks.code 逐字不同即為脫節，代付查表會落空。
// 各站都有一批已登記的舊脫節，所以比照上一支：脫節清單與基準不同才 BotNotify 並存新基準，沒變的日子零寫入。
// 排在 sync-projection（07:40）之後、避開 08:00 的 FPay 稽核。
Schedule::command('bank:audit-cfb-code --notify')->dailyAt('08:10')->onOneServer()->thenWithOutput($showOutput);

// 積分到期失效（target/tickets#4233）：批次 expired_at 進位到 10 分鐘刻度，每 10 分鐘掃一批；:02 起跳避開整點 DB 尖峰。
// withoutOverlapping 單位是分鐘。MemberScoreService::deduct() 另有扣點前即時到期，本排程是全站掃尾非唯一防線。
// ⚠️ 表達式必須 ≤ 20 字：ScheduleProvider 會把它寫進 command_logs.cronjob varchar(20)，超長會在 TaskStarting 就炸、命令永遠不跑
// （dev 2026-09-17 實例：'2,12,22,32,42,52 * * * *' 24 字 → 三小時零執行）。'2-59/10' 等價於 2,12,…,52。
Schedule::command('score:expire')->cron('2-59/10 * * * *')->onOneServer()->withoutOverlapping(10)->thenWithOutput($showOutput);

// 積分投影列校驗（target/tickets#4233）：比對批次剩餘加總 vs member_wallets 積分列，差異告警不自動修（--fix 人工執行）。
// 排在 wallet:verify-balance（04:10）之後，同屬離峰唯讀巡檢。
Schedule::command('score:verify-balance')->dailyAt('04:20')->onOneServer()->withoutOverlapping(60)->thenWithOutput($showOutput);

// 長期未登入代理限制登入（tickets#1988；天數依組別設定 5003，0 = 不啟用）
// 取代舊 MySQL EVENT + SP_Check_Agent_Not_Login（僅測試環境部署過）；解除限制走後台人工操作，鎖定經 transaction 重驗、冪等可重跑。
Schedule::command('agent:lock-inactive')->dailyAt('06:40')->onOneServer()->thenWithOutput($showOutput);

// 檢查遊戲帳號是否足夠（命令只派發 per-vendor job；同商併發由 job 的 ShouldBeUnique 唯一鎖擋）
Schedule::command('create_vendor_account')->everyFiveMinutes()->onOneServer()->thenWithOutput($showOutput);

// 監測補不進去的帳號池：補建對三方連續失敗時只會留 Log、水位靜靜不動，沒有這支就無人察覺
// withoutOverlapping：要逐商戶查表尾與水位，VWorld（多子樹、ns_accounts 117 萬列）實測 54 秒，
// 遠短於 hourly 間隔但仍防呆，避免 DB 變慢時兩輪疊在一起打主庫
Schedule::command('vendor:monitor-account-pool')->hourly()->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 檢查轉帳狀態並退款, (內部有個陣列指定哪些遊戲商需要檢查, 不會全跑)
Schedule::command('check_vendor_transfer_status')->everyMinute()->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 掃描停留於代付請求中(21)超時訂單並告警（target/tickets#4137 E 項）
// withoutOverlapping 參數單位是「分鐘」（Laravel 13 官方 API），勿誤填秒數
Schedule::command('withdrawal:alert-stuck-submitted')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->thenWithOutput($showOutput);

// CCPayment 回調補送：存款漏單與代付卡 22 超過 2 小時的單請金流商重送 webhook，入帳與定案仍走回調；
// 金流商限流門檻未知，頻率先保守
Schedule::command('ccpayment:resend-missing-webhooks')->everyThirtyMinutes()->onOneServer()->withoutOverlapping(25)->thenWithOutput($showOutput);

// CCPayment 風險交易退款同步：營運在金流商後台退款後金流商不通知我方，查退款紀錄更新充值訂單（target/tickets#4463）
Schedule::command('ccpayment:sync-risky-refunds')->everyTenMinutes()->onOneServer()->withoutOverlapping(9)->thenWithOutput($showOutput);

// 更新遊戲（:12 離峰執行：整點是小時邊界匯總與各排程疊加的 DB 尖峰，
// 實測比對段耗時可差 700 倍；:30 / :45 另有重匯總排程，含同 driver 錯開派發最長約 7 分鐘不會壓到）
Schedule::command('update_game_list')->hourlyAt(12)->onOneServer()->thenWithOutput($showOutput);

// 每小時 30 分派發前一小時 SBR 彙總 job（target/tickets#4257：排程只作觸發器，
// 彙總由 Horizon long-running 佇列的 SummarizeBetRecordHour 執行、內建回看 6 小時補洞——
// 節點汰換等原因漏掉一輪觸發時，由下一整點的 job 自動補齊缺漏 bucket）
Schedule::command('summary_bet_record:dispatch')->hourlyAt(30)->onOneServer()->thenWithOutput($showOutput);

// 每日稽核 SBR 完成標記缺口（回看視窗只有 6 小時，整條佇列靜默中斷超過視窗的缺格
// 只有這裡會叫；不依賴 Redis/Horizon，缺口即 BotNotify ＋ 失敗留 command_logs；
// 預設視窗 48 小時＝每段被掃兩次，稽核自己漏跑一輪仍由下一輪兜底）
Schedule::command('summary_bet_record:audit')->dailyAt('02:40')->onOneServer()->thenWithOutput($showOutput);

// 每日 UTC 00:25（UTC+8 08:25）以對帳時區重跑 next_day_full_rerun 遊戲商的前一整天彙總
// （選在 UTC 日界之後：+0 與 +8 時區的「昨天」都是剛結束的同一天，+0 的資料才完整；
// target/tickets#4357；在本排程進程內逐商序列執行（轉帳錢包先同步拉注單、含更新既有列，再彙總；
// 單一錢包只彙總）——結算的 team／滾碼鎖不含 vendor，併發必互撞，理由見該命令 docblock。
// withoutOverlapping：拉帳久跑時擋住隔天那輪，避免同一支自己疊上來）
Schedule::command('summary_bet_record:rerun-full-day')->dailyAt('00:25')->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 每小時 45 分校正近 2 個整點的存提小時匯總（全量重算覆蓋，修正即時累加的飄移）
Schedule::command('member:correct-hourly-statistics --hours=2')->hourlyAt(45)->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 每小時 20 分重建前 3 個整點小時的支付成本彙總 event 3／8（target/tickets#4369：取代 MySQL EVENT ＋
// SP_summary_event_record_by_payment／_W；:20 避開整點 SBR 尖峰與 :12／:30／:45 的其他排程）。
// 部署後須停用各站 Event_Summary_event_reocrd_by_payment（六站）與 _W（Yes2Win），兩個寫入者不可並存。
Schedule::command('summary:payment-cost --hours=3')->hourlyAt(20)->onOneServer()->withoutOverlapping(30)->thenWithOutput($showOutput);

// GGR 推廣代理結算（target/tickets#4121）：每日 GMT+8 15:00（UTC 07:00）觸發，逐啟用方案派發
// GgrSettleJob 到 long-running 佇列 catch-up 未結算期間（QueueJobRun 防重、
// 期末早於今天業務日才結——不需關帳判斷）；漏觸發由下一日自動補齊
Schedule::command('ggr:settle')->dailyAt('07:00')->onOneServer()->withoutOverlapping(60)->thenWithOutput($showOutput);

// GGR 推廣代理發放（target/tickets#4121 批次七）：每日 GMT+8 16:00（UTC 08:00，結算 15:00 後留緩衝）
// 派發 GgrPayoutJob 把 ggr_settlements 未發放列入帳到代理餘額；結算未及完成的期
// 由隔日發放 catch-up，冪等靠結算列 payout_status 狀態守門
Schedule::command('ggr:payout')->dailyAt('08:00')->onOneServer()->withoutOverlapping(60)->thenWithOutput($showOutput);

// 每小時 25 分重建前 3 個整點小時的 VIP 獎勵彙總 event 9（target/tickets#4420：取代 MySQL EVENT ＋
// SP_summary_event_record_by_vip；:25 夾在 :20 支付成本與 :30 SBR 派發之間）。
// 原 SP 把會員彙總與代理彙總分兩段裸跑，失敗時 EXIT HANDLER 吞掉例外又不回滾，而視窗每小時只前進
// 1 小時，失敗那班若已是最後一班涵蓋該格的，那一格就永久空掉：TPower 近 30 天漏 22 個小時、
// 合計 4,655.0968，newsr11 的 Vip 與代理數據看板的 VIP 成本因此長期對不上。
// 部署後須停用各站 Event_Summary_event_reocrd_by_vip，兩個寫入者不可並存。
Schedule::command('summary:event-record-vip --hours=3')->hourlyAt(25)->onOneServer()->withoutOverlapping(30)->thenWithOutput($showOutput);

// 每小時 35 分重建前 3 個整點小時的簡訊成本彙總 event 4（target/tickets#4424：取代 MySQL EVENT ＋
// SP_summary_event_record_by_sms；:35 避開 :12／:20／:25／:30／:45 的其他排程）。
// 成本改於發送當下凍結在 sms_send_records.cost，本排程直接加總、不再 join sms_gateway_infos 反推單價——
// 原 SP 的反推少了國別維度，同一家簡訊商在同一通道組有多國費率時兩列都命中，一則被算成兩國單價之和。
// 兩張彙總表的寫入亦包進同一交易，失敗整筆回滾（原 SP 分兩段裸跑，TPower 因此漏 130 個小時）。
// 部署後須停用各站 Event_Summary_event_reocrd_by_sms，兩個寫入者不可並存。
Schedule::command('summary:event-record-sms --hours=3')->hourlyAt(35)->onOneServer()->withoutOverlapping(30)->thenWithOutput($showOutput);

// 轉帳錢包遊戲商拉注單（自動發現實作 ShouldScheduleBetRecordFetch 的遊戲商，逐商派發 Job）
// 預設窗口為前 1 小時，與 5 分鐘排程間隔重疊；寫入以唯一鍵去重，重疊安全
Schedule::command('fetch_bet_record')->everyFiveMinutes()->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);

// 遊戲商自動掛維護/取消維護
Schedule::command('vendor_detect')->everyFiveMinutes()->onOneServer()->withoutOverlapping(3600)->thenWithOutput($showOutput);
