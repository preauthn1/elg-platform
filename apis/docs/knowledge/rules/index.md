# 規則

<!-- knowledge:generated start -->
## 會員

* [rules/member-group-fee-tiers 會員群組的平台手續費階梯](member-group-fee-tiers.md) - 代理在後台編輯會員群組時，平台手續費階梯可以怎麼設、什麼設定會被擋下。

## 金流

* [rules/agent-self-deposit 代理端開分訂單](agent-self-deposit.md) - 代理在代理後台經金流商替自己開分時，哪些支付通道受理、被擋時看到什麼、金流商要求的額外欄位怎麼帶、建單後的結果。
* [rules/currency-and-wallet-currency 幣別與錢包幣別](currency-and-wallet-currency.md) - 平台認得哪些標準幣別、哪些能當錢包幣別、會員錢包何時建立並記下錢包幣別，以及商戶幣別回填、稽核命令與 TrustPay 缺幣別時的擋單。
* [rules/deposit-order-fees 充值訂單的兩種手續費](deposit-order-fees.md) - 會員經金流商充值或自助上分建單時，訂單上記哪兩種手續費、何時記、金額怎麼算。
* [rules/payment-callback-common-checks 金流回調的共用檢查](payment-callback-common-checks.md) - 金流商發給我方的代收與代付回調，在動訂單之前要先通過哪些檢查（來源 IP、金流商比對、簽名、反查），被擋下時怎麼表現、什麼情況發告警、往返怎麼留紀錄。
* [rules/payment-channel-management 支付通道管理](payment-channel-management.md) - 用命令建立金流商戶、上下架與設定成本費率時，憑證怎麼來、開關寫在哪一層、後台怎麼顯示、改費率影響哪些單。
* [rules/payment-cost-summary 金流商手續費的寫入與彙總](payment-cost-summary.md) - 訂單上的金流商手續費何時寫定，系統怎麼每小時彙總給代理數據看板，以及重算與回補命令怎麼補數字。
* [rules/payout-bank-code-mapping 代付銀行碼對照維護](payout-bank-code-mapping.md) - 代付銀行碼對照表怎麼由同步命令更新、什麼情況會被覆寫或保留、停用的對照列怎麼改回啟用，以及 FPay 代付銀行清單的每日稽核查什麼、告警長什麼樣。
* [rules/payout-submit-and-status 代付送件與狀態流轉](payout-submit-and-status.md) - 客服在洗分訂單審核按「設定代付」後，系統送件前擋什麼、金流商回什麼會變成什麼狀態、回調怎麼處理、哪些情況告警。
* [rules/provider-a3pay A3Pay 代收與代付](provider-a3pay.md) - 新加坡幣金流商 A3Pay 的代收（網銀 OB、掃碼 BQR）與代付（銀行轉帳）怎麼建單、怎麼判讀金流商回應與回調、什麼情況交人工。
* [rules/provider-b3wallet B3Wallet 代收與代付](provider-b3wallet.md) - B3Wallet 開給會員哪幾條充值的支付通道、代付支援哪 23 家銀行，以及金流商拒絕或回調結果不同時系統怎麼表現。
* [rules/provider-ccpayment CCPayment USDT 代收與代付](provider-ccpayment.md) - 會員用 CCPayment 的固定鏈上地址充值 USDT、選 USDT 取款銀行取款時，系統怎麼取地址、怎麼憑回調入帳與定案代付、風險交易怎麼鎖住與解鎖。
* [rules/standard-bank-list 標準銀行清單維護](standard-bank-list.md) - 標準銀行清單從各幣別設定檔同步到各站，取款銀行與金流商的銀行名稱怎麼比對到標準銀行，已合併或改名的銀行怎麼併入，以及怎麼改取款銀行的顯示名稱。
* [rules/withdrawal-member-fee 會員取款的會員手續費與建單檢查](withdrawal-member-fee.md) - 會員在會員 App 送出取款時，系統怎麼算會員手續費、怎麼數當日第幾筆、什麼情況擋下。

## 遊戲與遊戲商

* [rules/bet-record-pull 拉取遊戲商注單](bet-record-pull.md) - 我方主動向遊戲商拉注單時，排程、命令列與 Olympus「拉取注單」頁各怎麼觸發，注單與 TBR 怎麼寫入，乾跑比對什麼，以及 Oppo、Wbet、ClotPlaySW、PP 各自的特殊規則。
* [rules/game-list-sync 同步遊戲商遊戲清單](game-list-sync.md) - 向遊戲商抓遊戲清單、把新遊戲寫進我方時，哪些遊戲商會被處理、新遊戲寫成什麼樣、遊戲商下架的遊戲怎麼提示，以及各家遊戲商的收錄規則。
* [rules/single-wallet-callback-common-checks 單一錢包回調的共用檢查](single-wallet-callback-common-checks.md) - 已改用共用前置檢查的無縫錢包回調要先通過哪些檢查，以及各家遊戲商在這些檢查擋下時回什麼錯誤碼。
* [rules/single-wallet-unsettled-refund 退回無縫錢包未結算注單的投注額](single-wallet-unsettled-refund.md) - 無縫錢包遊戲商判定投注失敗、永遠不會派彩的已扣款注單，維運怎麼用命令把投注額退回玩家，以及已取消投注的注單怎麼把殘留的投注量歸零。
* [rules/summary-bet-record 注單彙總（SBR）的排程、補洞、稽核與人工補跑](summary-bet-record.md) - SBR 每小時怎麼產生、漏跑時怎麼自動補、已關帳的時段怎麼調帳、每日稽核與隔日整天重跑怎麼運作，以及維運怎麼人工補跑。
* [rules/vendor-account-pool 預建遊戲商帳號池](vendor-account-pool.md) - 系統怎麼預先向遊戲商建好玩家帳號放進帳號池、會員第一次進遊戲時怎麼綁定、補不進去時怎麼告警，以及 Olympus 上怎麼手動補建與查庫存。
* [rules/vendor-acewin Acewin 與 Ace333SW 無縫錢包回調](vendor-acewin.md) - Acewin（AcewinSW）與 Ace333SW 兩家無縫錢包遊戲商發回調給我方時，憑證檢查、查餘額、下注、派彩、彩金與取消投注各要怎麼表現。
* [rules/vendor-afbsw AFBSW 無縫錢包回調](vendor-afbsw.md) - AFB Gaming 的無縫錢包版（AFBSW）發回調給我方時，憑證檢查、查餘額、下注、派彩與取消投注各要怎麼表現。
* [rules/vendor-aigamingsw AiGamingSW 無縫錢包回調](vendor-aigamingsw.md) - AiGamingSW 發回調給我方時，查餘額、投注扣款、結算、獎勵遊戲（bonus game）派彩、取消投注、純派彩各要怎麼表現。
* [rules/vendor-and-channel-management 遊戲商管理與接入通道](vendor-and-channel-management.md) - 代理後台「廠商管理」頁顯示哪些遊戲商資訊、回調路徑誰能看誰能改，以及新增遊戲商與聚合商子遊戲商時系統怎麼表現。
* [rules/vendor-astar Astar 無縫錢包回調](vendor-astar.md) - Astar 發回調給我方時，驗證玩家、查餘額、上桌扣款、離桌入帳、取消投注、查細單與查未完成局各要怎麼表現。
* [rules/vendor-epicwin EpicWin 無縫錢包回調](vendor-epicwin.md) - EpicWin 發回調給我方時，查餘額、投注、派彩、取消投注、現金獎金與彩金各要怎麼表現。
* [rules/vendor-fumasw FumaSW 無縫錢包回調](vendor-fumasw.md) - FumaSW 發回調給我方時，查餘額、投注、派彩各要怎麼表現，以及錯誤時回什麼。
* [rules/vendor-motivationsw MotivationSW 單一錢包回調](vendor-motivationsw.md) - MotivationSW（對外品牌 Gameboy）發回調給我方時，查玩家、查餘額、下注、轉出、退款、結算、轉回、事後扣款與補款各要怎麼表現。
* [rules/vendor-onebxsw OnebxSW 無縫錢包回調](vendor-onebxsw.md) - OneBX 體育的無縫錢包版（OnebxSW）發回調給我方時，查餘額、下注、派彩、取消派彩與取消投注各要怎麼表現。
* [rules/vendor-target target（含 BWG、TIGER9）無縫錢包回調](vendor-target.md) - 聚合商 target 的無縫錢包（PGSSW，含經它接入的 BWG 與 TIGER9）發回調給我方時，查餘額、下注、派彩、改派彩、取消投注與活動派彩各要怎麼表現。
* [rules/vendor-ptsw PTSW 無縫錢包回調](vendor-ptsw.md) - PlayTech 的無縫錢包版（PTSW）發回調給我方時，登入驗證、查餘額、投注、派彩、取消投注、關局與獎金入帳各要怎麼表現。
* [rules/vendor-rcb988 RCB988 無縫錢包回調](vendor-rcb988.md) - RCB988 發回調給我方時，查餘額、投注、調整投注、結算、取消與還原取消各要怎麼表現。
* [rules/vendor-rgsw RGSW 無縫錢包回調](vendor-rgsw.md) - RG 的無縫錢包版（RGSW）發回調給我方時，查玩家、查餘額、下注、派彩、取消投注與事後調整類回調各要怎麼表現，以及調整類回調怎麼擋重送。
* [rules/vendor-sky1388 Sky1388 轉帳確認回調](vendor-sky1388.md) - 玩家對 Sky1388 轉入或轉出時，Sky1388 回頭向我方確認這筆轉帳是否真的由我方發起，我方怎麼核對、回什麼。
* [rules/vendor-spadegamingsw SpadegamingSW 單一錢包回調](vendor-spadegamingsw.md) - Spadegaming 的無縫錢包版發回調給我方時，查餘額、下注、取消下注、派彩、紅利各要怎麼表現。
* [rules/vendor-top55 Top55SW 無縫錢包回調](vendor-top55.md) - 聚合商 Top55SW 發回調給我方時，查餘額、投注、派彩、取消投注與批次交易各要怎麼表現。
* [rules/vendor-tthsw EliteHub（TTHSW）無縫錢包回調](vendor-tthsw.md) - 聚合商 EliteHub（程式識別名 TTHSW）發回調給我方時，登入驗證、查餘額、扣款、派彩、取消投注、回合結束、活動派彩與錢包加扣款各要怎麼表現。
* [rules/vendor-uuslotsw UUSlotSW 無縫錢包回調](vendor-uuslotsw.md) - UUSlotSW 發回調給我方時，查餘額、投注、派彩、取消投注、活動獎金、彩金各要怎麼表現。
* [rules/vendor-wf WFSW 無縫錢包回調](vendor-wf.md) - WFSW 發回調給我方時，查餘額、投注、派彩、取消投注、活動獎金、彩金各要怎麼表現。
* [rules/vendor-wudisw WudiSW 無縫錢包回調](vendor-wudisw.md) - WudiSW 發回調給我方時，查餘額、投注、派彩、取消投注、獎勵各要怎麼表現，重複請求回什麼。

## 報表

* [rules/report-sr02n SR02N 開洗分明細表](report-sr02n.md) - 代理後台的 SR02N 開洗分明細表列哪些開分、洗分與充值紀錄，合計、手續費、實際金額怎麼算，篩選、金流商三層樹與選單節點怎麼運作。
* [rules/report-sr12-payout SR12 下注記錄的總數與派彩計算](report-sr12-payout.md) - 代理後台 SR12 下注記錄查哪些注單，總數區的押注、有效押注、派彩、輸贏怎麼算。
<!-- knowledge:generated end -->
