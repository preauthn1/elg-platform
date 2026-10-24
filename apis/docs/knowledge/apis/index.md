# API 與命令

<!-- knowledge:generated start -->
## 金流

* [apis/agent/DepositController 代理自助開分](agent/DepositController.md) - 代理後台給代理使用，查詢支付通道與三方往返紀錄，並送出開分申請向金流商付款。
* [apis/console/agent-add-agent-cash-flow 建立金流商戶](console/agent-add-agent-cash-flow.md) - RD 或上線人員接新金流商或替代理新增金流商戶與支付通道時使用的命令，可依拆分或單筆模式建立代收設定。
* [apis/console/bank-reactivate-cfb-rows 重新啟用停用的代付銀行碼對照列](console/bank-reactivate-cfb-rows.md) - RD 在代付銀行碼同步命令停用了人工修正過的對照列之後，用它把指定列改回啟用，並視需要一併校正代付銀行碼。
* [apis/console/bank-set-name 改取款銀行的顯示名稱](console/bank-set-name.md) - RD 要統一或修正會員 App 看到的取款銀行名稱時用它批次改名，寫入前先出預檢報表避免同名衝突。
* [apis/console/bank-sync-mappings 把取款銀行對到標準銀行](console/bank-sync-mappings.md) - RD 在標準銀行清單更新後，用它把各站取款銀行依名稱比對回標準銀行，或重驗既有的對應關係。
* [apis/console/cash-flow-audit-fpay-bank-list FPay 代付銀行清單每日稽核](console/cash-flow-audit-fpay-bank-list.md) - 系統排程每天比對 FPay 代付銀行清單與上次存下的基準，代號被換成別家銀行或消失時用 BotNotify 告警。
* [apis/console/cash-flow-channel-status 查詢與調整支付通道上下架狀態](console/cash-flow-channel-status.md) - RD 或上線人員要查某個金流商底下所有金流商戶與支付通道的上下架狀態，或要調整其中一項的上下架與後台顯示時使用。
* [apis/console/cash-flow-remap-tpower-fpay-idr-bankcodes 把 TPower FPay 印尼盾代付銀行碼換成現行清單](console/cash-flow-remap-tpower-fpay-idr-bankcodes.md) - 一次性工具，RD 用它把 TPower 站 FPay 印尼盾代付的銀行代號改成 FPay 現行清單上的新代號，或反向以代付設定校正對照表。
* [apis/console/cash-flow-seed-standard-banks 手動同步標準銀行清單](console/cash-flow-seed-standard-banks.md) - RD 在部署前，用它把各幣別標準銀行設定檔的內容手動同步到站台的標準銀行表。
* [apis/console/cash-flow-set-cost 設定金流商戶的成本費率](console/cash-flow-set-cost.md) - RD 或上線人員用命令批次設定金流商戶的成本費率，決定訂單上要記多少金流商手續費。
* [apis/console/cash-flow-sync 匯入金流商的支付通道與代付銀行碼設定](console/cash-flow-sync.md) - RD 在接入或調整金流商設定時執行，依共用設定檔同步金流商戶底下的支付通道、取款銀行與代付銀行碼對照。
* [apis/console/cash-flow-third-party-query 查詢金流商戶的通道限額與代付銀行清單](console/cash-flow-third-party-query.md) - RD 或維運人員用命令向金流商查某個金流商戶的限額與支援銀行，或查單筆代收訂單狀態，核對與我方設定是否一致。
* [apis/console/ccpayment-resend-missing-webhooks 補送 CCPayment 遺漏的回調](console/ccpayment-resend-missing-webhooks.md) - 排程每 30 分鐘執行，找出 CCPayment 已入帳或代付處理中過久卻沒有對應回調的訂單，請金流商重送。
* [apis/console/ccpayment-sync-risky-refunds 同步 CCPayment 風險交易的退款結果](console/ccpayment-sync-risky-refunds.md) - 排程每 10 分鐘執行，查詢 CCPayment 風險交易的退款結果，據以更新或解鎖對應充值訂單。
* [apis/console/clean-order-request-logs 清理逾期的三方往返紀錄](console/clean-order-request-logs.md) - 排程每天執行，刪除超過 90 天的金流回調往返紀錄。
* [apis/console/currency-audit-orphan-parent 稽核會員幣別解析鏈的缺口](console/currency-audit-orphan-parent.md) - RD 唯讀稽核命令，逐一檢查會員的幣別解析鏈斷在哪一步，並為缺上層代理的會員蒐集歸屬線索。
* [apis/console/currency-audit-vendor-info 稽核遊戲商戶幣別能否對到標準幣別](console/currency-audit-vendor-info.md) - RD 唯讀稽核命令，列出遊戲商戶的幣別字串對不到標準幣別、會讓幣別編號回填落空的商戶。
* [apis/console/currency-backfill-vendor-info 回填遊戲商戶與活動商戶的幣別編號](console/currency-backfill-vendor-info.md) - RD 或維運在幣別主檔治理作業時執行，依幣別 alias 契約回填遊戲商戶與活動商戶還沒有幣別編號的資料。
* [apis/console/order-backfill-payment-cost 回補訂單的金流商手續費](console/order-backfill-payment-cost.md) - RD 或維運在金流商手續費彙總與訂單對不上時執行，回補充值訂單與取款訂單上缺漏的金流商手續費。
* [apis/console/order-recalc-payment-cost-summary 重算金流商手續費彙總](console/order-recalc-payment-cost-summary.md) - RD 或維運在金流商手續費彙總算錯、或剛回補完訂單缺漏的手續費後執行，重建指定區間的充值與取款小時彙總。
* [apis/console/summary-payment-cost 每小時彙總金流商手續費](console/summary-payment-cost.md) - Laravel 排程每小時自動執行，把最近幾個整點小時訂單上已寫定的金流商手續費重新彙總，供代理數據看板讀取。
* [apis/console/wallet-verify-balance 比對會員主檔餘額與錢包表餘額](console/wallet-verify-balance.md) - Laravel 排程每日自動執行，比對會員主檔餘額與錢包表裡的一般錢包餘額，兩邊不一致時告警。
* [apis/console/withdrawal-alert-stuck-submitted 告警停留在代付提交中的取款訂單](console/withdrawal-alert-stuck-submitted.md) - Laravel 排程每 5 分鐘自動執行，查核停留在代付提交中超過門檻時間的取款訂單，能自動查單定案的先定案，查不清楚的才告警交人工。
* [apis/console/withdrawal-resolve-payout 代付停留單人工定案](console/withdrawal-resolve-payout.md) - RD 或維運在金流商後台人工核對出取款訂單的實際出款結果後執行，把停留的代付訂單定案為可重新發起或代付成功。
* [apis/external/DepositNotifyController 代收回調](external/DepositNotifyController.md) - 金流商在代收交易有結果後打回我方的回調入口，依結果判定充值訂單成功或失敗。
* [apis/external/WithdrawalNotifyController 代付回調](external/WithdrawalNotifyController.md) - 金流商在代付交易有結果後打回我方的回調入口，依結果判定取款訂單的代付請求成功或失敗。
* [apis/frontend/AutoDepositController 會員自助上分](frontend/AutoDepositController.md) - 會員 App 給會員使用，把款項轉入平台公布的銀行帳戶後，建立待審核充值訂單的入口。
* [apis/frontend/DepositController 會員充值](frontend/DepositController.md) - 會員 App 給會員使用，查詢支付通道、建立充值訂單，並取得 CCPayment 固定收款地址的入口。
* [apis/frontend/MemberTransferController 會員遊戲商餘額轉帳](frontend/MemberTransferController.md) - 會員 App 給會員使用，把餘額轉入遊戲商或轉回一般錢包，並查詢遊戲商餘額與轉帳狀態的入口。
* [apis/frontend/WalletController 會員錢包紀錄與互轉](frontend/WalletController.md) - 會員 App 給會員使用，查詢一般錢包與遊戲錢包的帳變紀錄，並在兩者之間互轉餘額的入口。
* [apis/frontend/WithdrawalController 會員取款](frontend/WithdrawalController.md) - 會員 App 給會員使用，查詢取款限制、綁定取款銀行帳號並送出取款申請的入口。

## 遊戲與遊戲商

* [apis/agent/VendorManagementController 遊戲商管理](agent/VendorManagementController.md) - 代理後台查詢遊戲商清單、調整無縫錢包遊戲商回調路徑的入口。
* [apis/console/bet-record-recovery-refund-unsettled 退回三方判定投注失敗、永不派彩的單一錢包未結算注單](console/bet-record-recovery-refund-unsettled.md) - 維運怎麼用命令把無縫錢包遊戲商判定投注失敗、永不派彩的未結算注單投注額退回玩家。
* [apis/console/bet-record-recovery-zero-refunded-turnover 把已退款注單殘留的投注量歸零](console/bet-record-recovery-zero-refunded-turnover.md) - 維運怎麼用命令把已取消投注、但注單與 TBR 上殘留的投注量歸零。
* [apis/console/create_vendor_account 預產遊戲帳號供用戶綁定](console/create_vendor_account.md) - 系統怎麼預先向遊戲商建好玩家帳號放進帳號池，以及怎麼手動補建。
* [apis/console/fetch_bet_record 拉取遊戲商注單並轉入 TBR](console/fetch_bet_record.md) - 我方主動向遊戲商拉注單時，排程、命令列與 Olympus 頁各怎麼觸發，注單怎麼寫入。
* [apis/console/make-vendor 建立遊戲商的檔案](console/make-vendor.md) - 新接一家遊戲商時，產生 Model、Driver 骨架檔案並註冊進 config/vendor.php 的腳手架命令。
* [apis/console/summary_bet_record 遊戲記錄每個人每小時每款遊戲結算-新版](console/summary_bet_record.md) - 依指定日期、小時與遊戲商人工補跑 SBR 的命令說明。
* [apis/console/summary_bet_record-audit 稽核 SBR 完成標記缺口](console/summary_bet_record-audit.md) - 稽核 SBR 有沒有漏跑或異常的命令說明。
* [apis/console/summary_bet_record-dispatch 派發前一小時 SBR 彙總工作](console/summary_bet_record-dispatch.md) - 整點排程派發上一小時 SBR 彙總工作的命令說明。
* [apis/console/summary_bet_record-rerun-full-day 隔日整天重跑 SBR](console/summary_bet_record-rerun-full-day.md) - 對設定為整天重跑的遊戲商重新彙總前一整天 SBR 的命令說明。
* [apis/console/update_game_list 更新遊戲商的遊戲清單](console/update_game_list.md) - 向遊戲商拉取最新遊戲清單、同步進遊戲主檔的命令說明。
* [apis/console/vendor-monitor-account-pool 監測補不進去的帳號池並發 Telegram 告警](console/vendor-monitor-account-pool.md) - 監測遊戲商玩家帳號池補不進去、發送告警的命令說明。
* [apis/external/AFBSwController AFBSW 無縫錢包回調](external/AFBSwController.md) - 遊戲商 AFB Gaming 的無縫錢包版（AFBSW）回調入口，處理查餘額、下注、派彩與取消投注。
* [apis/external/Ace333SWController Ace333SW 無縫錢包回調](external/Ace333SWController.md) - 遊戲商 Ace333SW 的回調入口，處理驗證身分、查餘額、下注、派彩、彩金與取消投注。
* [apis/external/AcewinController Acewin 無縫錢包回調](external/AcewinController.md) - 遊戲商 Acewin（程式識別名 AcewinSW）的無縫錢包回調入口，處理驗證身分、下注（下注與派彩合一）與取消投注。
* [apis/external/AiGamingSWController AiGamingSW 無縫錢包回調](external/AiGamingSWController.md) - 遊戲商 AiGamingSW 的無縫錢包回調入口，處理查餘額、投注扣款、結算、bonus game 派彩、取消投注與純派彩。
* [apis/external/AstarController Astar 無縫錢包回調](external/AstarController.md) - 提供真人與棋牌遊戲的遊戲商 Astar 回調入口，處理驗證玩家、查餘額、登出、上桌扣款、離桌入帳、取消投注、查細單與查未完成局。
* [apis/external/BWGController BWG 無縫錢包回調](external/BWGController.md) - 經聚合商 target 接入的遊戲商 BWG 回調入口，與 target 共用同一套處理，涵蓋查餘額、下注、派彩、改派彩、取消投注與活動派彩。
* [apis/external/EpicWinController EpicWin 無縫錢包回調](external/EpicWinController.md) - 遊戲商 EpicWin 的無縫錢包回調入口，處理查餘額、投注、派彩、取消投注、現金獎金與彩金。
* [apis/external/FumaSWController FumaSW 無縫錢包回調](external/FumaSWController.md) - 遊戲商 FumaSW（含一般與免盤費兩種遊戲商戶）的回調入口，處理查餘額、下注與派彩。
* [apis/external/MotivationSWController MotivationSW 無縫錢包回調](external/MotivationSWController.md) - 遊戲商 MotivationSW（對外品牌 Gameboy）的無縫錢包回調入口，處理查詢、下注、退款、結算與事後扣補款。
* [apis/external/OnebxSWController OnebxSW 無縫錢包回調](external/OnebxSWController.md) - 體育遊戲商 OneBX 的無縫錢包版（OnebxSW）回調入口，處理查餘額、下注、派彩、取消派彩與取消下注。
* [apis/external/PegasusController target 無縫錢包回調](external/PegasusController.md) - 聚合商 target（Pegasus，又稱 PGSSW）的回調入口，處理查餘額、下注、派彩、改派彩、取消下注與活動派彩；BWG 與 TIGER9 經由 target 接入，共用同一套規則。
* [apis/external/PlayTechController PTSW 無縫錢包回調](external/PlayTechController.md) - 遊戲商 PlayTech 的無縫錢包版（PTSW，多為真人遊戲）回調入口，處理登入、查餘額、下注、派彩、取消下注、關局與紅利入帳。
* [apis/external/Rcb988Controller RCB988 無縫錢包回調](external/Rcb988Controller.md) - 遊戲商 RCB988 的回調入口，處理查餘額、下注、調整下注、結算、取消與還原取消。
* [apis/external/RgswController RGSW 無縫錢包回調](external/RgswController.md) - 遊戲商 RG 的無縫錢包版（RGSW）回調入口，處理查玩家、查餘額、下注、派彩、退款與事後調整類回調。
* [apis/external/Sky1388Controller Sky1388 確認轉帳回調](external/Sky1388Controller.md) - 轉帳錢包遊戲商 Sky1388 反向確認轉帳的回調入口，核對一筆轉入或轉出是否確實由我方發起。
* [apis/external/SpadegamingSWController SpadegamingSW 無縫錢包回調](external/SpadegamingSWController.md) - 遊戲商 SpadegamingSW（Spadegaming 的無縫錢包版）的回調入口，處理查餘額、下注、取消下注、派彩與紅利。
* [apis/external/TTHSWController EliteHub（TTHSW）無縫錢包回調](external/TTHSWController.md) - 聚合商 EliteHub（程式識別名 TTHSW）的無縫錢包回調入口，處理登入驗證、查餘額、扣款、派彩、取消投注、回合結束、活動派彩與錢包加扣款。
* [apis/external/Top55Controller Top55SW 無縫錢包回調](external/Top55Controller.md) - 聚合商 Top55SW 的無縫錢包回調入口，處理查餘額、單筆交易與批次交易。
* [apis/external/UUSlotSWController UUSlotSW 無縫錢包回調](external/UUSlotSWController.md) - 遊戲商 UUSlotSW 的無縫錢包回調入口，處理查餘額、投注、派彩、取消投注、活動獎金與彩金。
* [apis/external/WFController WFSW 無縫錢包回調](external/WFController.md) - 遊戲商 WFSW 的無縫錢包回調入口，處理查餘額、投注、派彩、取消投注、活動獎金與彩金。
* [apis/external/WudiSWController WudiSW 無縫錢包回調](external/WudiSWController.md) - 遊戲商 WudiSW 的無縫錢包回調入口，處理查餘額與投注、派彩、取消投注、獎勵四種轉帳類型。

## 報表

* [apis/agent/ReportController SR12 下注記錄報表](agent/ReportController.md) - 代理後台查詢 SR12（下注記錄）報表的入口，回傳押注、有效投注、派彩、輸贏總數與分頁注單明細。
* [apis/agent/Sr02nController SR02N 開洗分明細表](agent/Sr02nController.md) - 代理後台給代理與客服使用，查詢一段期間內的開分、洗分與充值明細、合計，以及來源篩選用的金流商三層樹。

## 系統

* [apis/console/app-upgrade 升級腳本](console/app-upgrade.md) - 版本升級或環境初始化時，把遊戲商、金流商戶等多項主檔資料同步進資料庫的命令。
<!-- knowledge:generated end -->
