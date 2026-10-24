# 決策

<!-- knowledge:generated start -->
## 金流

* [decisions/0002-redundancy-via-extra-payment-channels 備援與分流用多開支付通道](0002-redundancy-via-extra-payment-channels.md) - 多開支付通道做備援與分流，不在同一條通道下掛多個金流商戶。
* [decisions/0005-payment-cost-frozen-on-order 金流商手續費在訂單上凍結](0005-payment-cost-frozen-on-order.md) - 金流商手續費在交易成功時寫進充值訂單與取款訂單並凍結，彙總與分潤直接加總這個值，不在彙總時以成本費率現算。
* [decisions/0006-payment-cost-summary-php-single-writer 金流商手續費彙總由 PHP 排程單一寫入](0006-payment-cost-summary-php-single-writer.md) - 代理數據看板的金流商手續費彙總由 PaymentCostSummaryService 每小時排程單獨寫入，原本的 MySQL EVENT 與預存程序停用，不做雙軌。
* [decisions/0007-payment-cost-rate-based-ccpayment-actual 金流商手續費依成本費率計算，CCPayment 例外記實際值](0007-payment-cost-rate-based-ccpayment-actual.md) - 金流商手續費預設以「點數 × 成本費率」計算，不採三方回調回報的手續費；CCPayment 的充值訂單與取款訂單改記向金流商回查到的實際值。
* [decisions/0008-payout-bank-code-strict-empty-check 代付銀行碼判空一律逐字比對](0008-payout-bank-code-strict-empty-check.md) - 判斷代付銀行碼或銀行名稱有沒有設定時，一律逐字比對空字串，不用 PHP 的 empty()，因為字串 "0" 是合法的代付銀行碼。
* [decisions/0009-payout-bank-code-guard-per-provider 查不到代付銀行碼時由各金流商自己擋](0009-payout-bank-code-guard-per-provider.md) - 查不到代付銀行碼時，擋單的檢查放在各金流商自己的代付類別，不放在共用轉碼方法或派單前的共用檢查。
* [decisions/0010-manual-override-protects-provider-code-only 人工覆寫標記只保護代付銀行碼](0010-manual-override-protects-provider-code-only.md) - cash_flow_banks 被人工修過的列帶上覆寫標記，同步命令看到標記只跳過代付銀行碼一欄，銀行碼與重新啟用照常同步。
* [decisions/0011-fpay-remap-group-classification FPay 銀行代號換碼採整組判定](0011-fpay-remap-group-classification.md) - FPay 印尼盾代付銀行代號的換碼命令，以整組代號判定是舊代號還是新代號，不逐列推斷；新舊混雜時一律中止轉人工。
* [decisions/0012-bank-data-fix-via-audited-command 銀行資料的正式庫修正走專用命令](0012-bank-data-fix-via-audited-command.md) - 正式庫的銀行資料修正寫成可預演、有預檢、有審計的專用 artisan 命令，不直接下 SQL，也不只在後台改。
* [decisions/0013-fpay-bank-list-audit-against-baseline FPay 代付銀行清單稽核比對上次快照](0013-fpay-bank-list-audit-against-baseline.md) - FPay 代付銀行清單的每日稽核，比對的是 FPay 上次回的清單快照而不是我方銀行名稱；有差異時不更新快照，直到人工接受。
* [decisions/0014-payout-reject-only-when-provably-unaccepted 代付只在能證明金流商未受理時判明確拒絕](0014-payout-reject-only-when-provably-unaccepted.md) - 代付送件收到的同步回應，只有能證明金流商沒有受理時才判明確拒絕、讓取款訂單可以重新發起；其餘一律當結果未知，避免重複出款。
* [decisions/0016-callback-source-whitelist-fail-closed 回調來源 IP 白名單空白即全部拒絕](0016-callback-source-whitelist-fail-closed.md) - 金流回調的來源 IP 白名單採 fail-closed，白名單為空等於全部拒絕；白名單只存在金流商層級的 cash_flows.whitelist，來源 IP 一律取信任代理鏈判定的值。
* [decisions/0017-callback-order-match-by-cash-flow 回調找單比對訂單所屬金流商](0017-callback-order-match-by-cash-flow.md) - 回調找到的充值訂單或代付紀錄，經它的金流商戶取出金流商（cash_flow_id），與回調網址上金流商戶的金流商比對；不比對金流商戶本身。
* [decisions/0018-no-ip-rate-limit-on-callback-routes 回調路由不做 IP 限流](0018-no-ip-rate-limit-on-callback-routes.md) - 金流回調路由 deposit/notify 與 withdrawal/notify 不掛依來源 IP 的限流；先整個移除，不靠調門檻。
* [decisions/0019-easypay-callback-signature-if-present EasyPay 回調帶了簽名才驗](0019-easypay-callback-signature-if-present.md) - EasyPay 的回調只在帶了 mdfive_val 時驗簽，帶了就一定要驗得過；沒帶一律放行，不強制驗簽。
* [decisions/0020-callback-trusts-requery-result 回調的狀態與金額以向金流商回查為準](0020-callback-trusts-requery-result.md) - AP8PAY 與 CCPayment 收到回調後向金流商回查，充值的狀態與金額以回查結果為準，回調本身只當觸發訊號。
* [decisions/0021-exchange-log-stores-raw-request 三方往返紀錄存回調原文](0021-exchange-log-stores-raw-request.md) - order_request_logs 存回調請求的原文而不是驗證過濾後的欄位；後台查詢端點只回純量欄位，往返原文不經任何 API 暴露。
* [decisions/0022-retired-banks-merged-not-deleted 已合併或改名的銀行停用併入現行條目，不刪除](0022-retired-banks-merged-not-deleted.md) - 標準銀行清單裡已合併或改名的銀行，舊條目留在分檔並標記併入現在那家；寫進 standard_banks 時設為停用、名稱與別名併入現行條目，金流商的代付銀行碼不動。
* [decisions/0024-apis-scope-guard-at-agent-entry APIs 承接範疇的檢查只放代理端入口](0024-apis-scope-guard-at-agent-entry.md) - 「這條支付通道是否由 APIs 承接」的檢查只放在代理端建單與代付送件兩個入口，不放進 PaymentService::getProvider()，以免擋到會員端與三方回調。
* [decisions/0025-withdrawal-bank-ownership-uniform-error 取款銀行歸屬不符與不存在回同一個錯誤](0025-withdrawal-bank-ownership-uniform-error.md) - 會員取款建單與綁定銀行帳號時，選到看不到的銀行一律回既有錯誤碼 1315；「編號不存在」與「存在但不屬於你」回同一個訊息，不新增專屬錯誤碼。
* [decisions/0026-payout-cost-bucketed-by-payment-time 代付的金流商手續費彙總依 payment_time 分桶](0026-payout-cost-bucketed-by-payment-time.md) - 代付的金流商手續費彙總，三方成功與人工出款一律依取款訂單的 payment_time 分到小時，不再對三方成功另走代付紀錄的 callback_time。
* [decisions/0027-backend-display-optional-flag 後台顯示開關用選用參數，不隨狀態連動](0027-backend-display-optional-flag.md) - cash-flow:channel-status 的後台顯示（backend_display）用選用參數 --backend-display 設定，不帶就不動；不讓 --set-status 自動連動。
* [decisions/0028-a3pay-standalone-implementation A3Pay 獨立實作，不與 GlobePay 共用](0028-a3pay-standalone-implementation.md) - A3Pay 的代收與代付完整獨立實作，不與同閘道的 GlobePay 共用類別或 trait；薄繼承只用在協定完全相同的商戶。
* [decisions/0030-group-fee-tiers-take-over-bank-fee 群組手續費階梯接管後不回退銀行手續費](0030-group-fee-tiers-take-over-bank-fee.md) - 會員群組設了平台手續費階梯，就完全接管會員取款的手續費：未達第一階時手續費為 0、不回退銀行手續費；「手續費大於等於取款金額就擋」只守群組路徑，銀行路徑維持現行。
* [decisions/0031-trustpay-currency-code-from-enum TrustPay 幣別代碼取 CurrencyEnum 名稱，缺失即擋單](0031-trustpay-currency-code-from-enum.md) - TrustPay 送三方的幣別代碼仍取 CurrencyEnum 的 case 名，不改讀幣別主檔；幣別 id 缺失或不認得時擋單並記 log，不回退預設值。
* [decisions/0032-payout-status-23-removed-not-reused 代付狀態 23 直接移除且禁止重用](0032-payout-status-23-removed-not-reused.md) - 已廢除的代付狀態 23（三方查證中）從 WithdrawalOrderStatusEnum 直接刪除，原位置註明禁止重用；回調與人工定案命令遇到 23 一律拒絕。
* [decisions/0033-ccpayment-risky-refund-requery-original-deposit CCPayment 風險退款同步逐筆回查原存款](0033-ccpayment-risky-refund-requery-original-deposit.md) - CCPayment 風險交易的退款同步排程，付款地址與服務費都逐筆向金流商重新回查原存款取得，不讀充值訂單的 remark。

## 遊戲與遊戲商

* [decisions/0001-win-lose-uses-player-perspective 輸贏以玩家視角記錄](0001-win-lose-uses-player-perspective.md) - 注單 win_lose 的正負號以玩家視角記錄，需要平台視角的數字時在讀取端取負，不改寫入端。
* [decisions/0003-vendor-channel-and-callback-declared-by-driver 接入通道與回調路徑由 Driver 宣告](0003-vendor-channel-and-callback-declared-by-driver.md) - 遊戲商的接入通道與回調路徑預設由 Driver 自己宣告，不另存對照表。
* [decisions/0034-playtech-game-list-sheet-url-from-operator PlayTech 遊戲清單的活頁簿連結由觸發者帶入](0034-playtech-game-list-sheet-url-from-operator.md) - PlayTech 遊戲清單讀官方 SharePoint 活頁簿，連結不寫進 config 或 env，由觸發者每次帶入，程式自動改寫成下載直鏈；只手動同步，不掛排程。
* [decisions/0035-single-wallet-bet-record-fetch-dry-run-only 無縫錢包遊戲商的拉注單只放行乾跑](0035-single-wallet-bet-record-fetch-dry-run-only.md) - 無縫錢包遊戲商可以拉三方注單來對帳，但只能乾跑、不寫入注單，也不進自動排程。
* [decisions/0036-bet-record-recovery-asserts-primary-before-execute 注單補帳命令執行前自己確認寫入連線落在主庫](0036-bet-record-recovery-asserts-primary-before-execute.md) - 無縫錢包未結算退款與退款注單投注量歸零兩支補帳命令，正式執行前先查寫入連線的 read_only，為 1 就中止，不依賴資料庫擋。
* [decisions/0037-callback-missing-credential-rejected-per-controller 缺遊戲商戶憑證的回調在各家 controller 拒收，不改共用底座](0037-callback-missing-credential-rejected-per-controller.md) - AcewinSW 與 Ace333SW 的回調沒帶遊戲商戶憑證一律拒收；這道檢查寫在兩家各自的 controller，無縫錢包共用底座對空遊戲商戶識別碼的語意不動。
* [decisions/0038-ptsw-refund-duplicate-gate-per-bet PTSW 取消投注閘以「同一筆下注」為粒度](0038-ptsw-refund-duplicate-gate-per-bet.md) - PTSW 的取消投注（REFUND）回調在錢包上鎖前先檢查：原下注要確實扣過款、同一筆下注不能取消第二次；粒度是單筆下注而不是整局，只有補帳命令補的整局 REFUND 事件例外。
* [decisions/0039-ptsw-lock-timeout-error-code-by-action PTSW 撞同局鎖逾時依動作回不同錯誤碼](0039-ptsw-lock-timeout-error-code-by-action.md) - PTSW 回調取不到同局鎖時，派彩（gameRoundResult）回可重試的 INTERNAL_ERROR，下注回不可重試的 ERR_TRANSACTION_DECLINED，未列舉的動作預設回可重試碼。
* [decisions/0040-rgsw-adjustment-replay-guard RGSW 調整類回調以同局、同類、同金額、300 秒內判定重送](0040-rgsw-adjustment-replay-guard.md) - RG 的 refund、credit、debit、sumBet 沒有交易序號，同一局同一種調整同一金額在 300 秒內再來一次就當重送，只比對關鍵欄位，守衛排在餘額檢查之前。
* [decisions/0041-tthsw-cancel-idempotency-by-original-transaction EliteHub（TTHSW）取消交易以原交易為冪等鍵，擋下時沿用既有錯誤碼](0041-tthsw-cancel-idempotency-by-original-transaction.md) - EliteHub（TTHSW）的取消交易（CANCEL_TRANSACTION，Type 9）以被取消的原交易判斷是否已取消過，比對不分大小寫；已取消過回 502、整局已取消回 403，不改用 503。
* [decisions/0042-vendor-account-creation-scope-declared-by-driver 自動補建玩家帳號的範圍由對接程式宣告](0042-vendor-account-creation-scope-declared-by-driver.md) - 哪些遊戲商納入每 5 分鐘的帳號池自動補建，由對接程式是否實作排程介面決定，不再用 vendor id 分界線加白名單。
* [decisions/0043-vendor-account-placeholder-before-vendor-create 建玩家帳號先寫佔位，遊戲商建成才放進帳號池](0043-vendor-account-placeholder-before-vendor-create.md) - 向遊戲商建號時，我方先寫一筆佔位帳號，遊戲商確認建成才改成可綁定；遊戲商失敗時帳號留在佔位狀態。
* [decisions/0044-single-wallet-bet-time-index-skips-large-tables 無縫錢包注單表補 bet_time 索引時排除三張大表](0044-single-wallet-bet-time-index-skips-large-tables.md) - 補 bet_time 索引的 migration 刻意排除 fumasw、uuslotsw、wudisw 三張大表，這三家改用既有索引查未結算注單。
* [decisions/0045-single-wallet-bettability-check-after-member-lock 無縫錢包下注資格檢查放在鎖會員錢包之後](0045-single-wallet-bettability-check-after-member-lock.md) - 無縫錢包遊戲商的下注資格改在交易內、鎖住會員錢包之後以 verifyBettability() 檢查，不再在交易外以 BetHelper::isMemberAbleToBet() 檢查。
* [decisions/0046-onebx-settle-retry-and-resettle-handling OneBX 派彩失敗回可重試碼，換交易號的重新派彩不動錢只告警](0046-onebx-settle-retry-and-resettle-handling.md) - OneBX 派彩（settle）遇到系統錯誤時回 HTTP 500 與錯誤碼 RETRY，讓三方重送；同一個 call_transaction_id 的重送靜默放行，不同 call_transaction_id 的重新派彩不動錢、只記 warning。
* [decisions/0047-onebx-unimplemented-money-endpoints-return-non-200 OneBX 尚未實作的動錢端點先掛路由記 log，一律回非 200](0047-onebx-unimplemented-money-endpoints-return-non-200.md) - OneBX 的 step/bonus 與 step/refund-bonus 規格不明，先掛路由把三方請求完整記下，回系統錯誤讓三方持續重送，不回 404 也不回 200。
* [decisions/0048-summary-backfill-by-completion-marker 注單彙總以完成標記判斷要不要補跑，手動補跑的標記不算數](0048-summary-backfill-by-completion-marker.md) - 整點注單彙總改由佇列執行並寫完成標記，每輪回看近 6 小時補跑缺標記的格子；手動補跑留下的標記不讓回看跳過。
* [decisions/0049-metagaming-game-list-filter-by-code-format MetaGamingSW 遊戲清單以代碼格式過濾](0049-metagaming-game-list-filter-by-code-format.md) - MetaGamingSW 同步遊戲清單時，唯一的收錄條件是遊戲代碼為六位數字，不維護逐款白名單，也不依產品線排除。
* [decisions/0050-astar-rollin-settles-by-amount ASTAR rollin 以 amount 入帳](0050-astar-rollin-settles-by-amount.md) - ASTAR 的 rollin 入帳一律以 amount（應還回錢包的金額）為唯一依據，棋牌桌以 tablename 前綴判斷，公式對不上只記警告。
* [decisions/0051-game-list-sync-trims-half-width-space 遊戲清單同步比對時只去掉遊戲代碼前後的半形空白](0051-game-list-sync-trims-half-width-space.md) - 遊戲清單同步在遊戲商端與資料庫端都以去掉前後半形空白的遊戲代碼比對，不去 tab 與換行，也不改寫資料庫裡的值。
* [decisions/0052-summary-full-day-rerun-serial-in-scheduler 前一整天的注單彙總重跑在排程進程內逐商序列執行](0052-summary-full-day-rerun-serial-in-scheduler.md) - 每日重跑前一整天注單彙總，在排程進程內一家一家依序跑，不再每家派一個佇列工作併發，排程時刻在 UTC 日界之後。
* [decisions/0054-igaming-transfer-failed-only-on-explicit-reject Play8oy 與 SunCity 轉帳只在三方明確拒絕時判失敗](0054-igaming-transfer-failed-only-on-explicit-reject.md) - iGaming 平台（Play8oy、SunCity）的轉帳回應，只有列舉的明確拒絕碼、且由 HTTP 200 或 400 帶回時才判失敗並做轉帳失敗退回，其餘一律當結果不明。
* [decisions/0055-igaming-verify-ssl-per-merchant iGaming 對接程式的 TLS 憑證驗證預設開啟，由遊戲商戶配置逐筆關閉](0055-igaming-verify-ssl-per-merchant.md) - Play8oy 與 SunCity 對三方的請求預設驗證 TLS 憑證，只有遊戲商戶配置明確寫 VERIFY_SSL=false 才關閉，不依環境整體關閉。
* [decisions/0056-olympus-operation-date-lower-bound-by-role 拉取注單與重跑注單彙總的日期下限依角色區分](0056-olympus-operation-date-lower-bound-by-role.md) - Olympus 拉取注單與重跑注單彙總兩頁，運維只能回溯 30 天、管理員 60 天，選 4 天前以上的日期要先確認。
* [decisions/0057-suncity-shares-play8oy-driver SunCity 繼承 Play8oy 對接程式，簽名修正放在父類](0057-suncity-shares-play8oy-driver.md) - SunCity 與 Play8oy 同平台同版本，SunCity 繼承 Play8oy 的對接程式；簽名改用原始字串串接，修在父類讓兩家一起生效。
* [decisions/0058-vendor-account-pool-stuck-detected-by-tail-placeholders 帳號池補建卡住以表尾連續佔位帳號判斷](0058-vendor-account-pool-stuck-detected-by-tail-placeholders.md) - 每小時監測帳號池補建是否卡住，判準是每個遊戲商戶帳號表尾端連續幾筆是未完成的佔位帳號，不解析遊戲商錯誤碼，也不看累積量。
* [decisions/0059-vendor-account-replenish-outside-transaction 帳號池補建不包交易，以時間預算分輪續補](0059-vendor-account-replenish-outside-transaction.md) - 補建玩家帳號不把整家遊戲商包在一個資料庫交易裡，每輪只用工作時限的八成，剩下的缺口下一輪從主庫重算再補。
* [decisions/0060-make-vendor-stub-declares-callback-path 無縫錢包腳手架預先宣告回調路徑，由守門測試擋下沒改的值](0060-make-vendor-stub-declares-callback-path.md) - 用 `php artisan make:vendor` 產生的無縫錢包對接程式直接實作回調路徑契約並放佔位值，再由測試檢查每個宣告都符合格式、對得到已註冊的路由。
* [decisions/0061-vendor-callback-path-admin-role-only 廠商管理的回調路徑只給角色為 admin 的帳號看與改](0061-vendor-callback-path-admin-role-only.md) - 代理後台廠商管理的回調路徑，以帳號角色是否為 admin 判斷能不能看與改，不沿用平台管理員的判定。
* [decisions/0062-vendor-callback-url-uses-external-api-domain 廠商管理的回調路徑以對外 API 網域組成](0062-vendor-callback-url-uses-external-api-domain.md) - 廠商管理顯示的遊戲商回調路徑，以 custom.api_external_url 為網域、接上回調路徑後綴，不用 app.url。
* [decisions/0063-pp-live-casino-status-f-as-cancelled PP 拉注單只把真人局的 status F 當取消單](0063-pp-live-casino-status-f-as-cancelled.md) - PragmaticPlay 拉回的注單中，只有真人局（dataType LC）且 status 為 F 的才當取消單歸零，RNG 的 F 局照一般注單入帳。

## 報表

* [decisions/0029-sr02n-fee-on-all-withdraw-rows SR02N 洗分列一律計入手續費](0029-sr02n-fee-on-all-withdraw-rows.md) - SR02N 開洗分明細表的洗分列一律計入手續費與實際金額，對不到取款訂單的列手續費記 0，讓實際金額合計等於總洗分減手續費合計。
* [decisions/0053-sr12-total-payout-uses-bet-plus-win-lose SR12 總數派彩以押注加輸贏計算](0053-sr12-total-payout-uses-bet-plus-win-lose.md) - SR12 的總數派彩改用押注加輸贏，不用有效押注加輸贏，與 SR14 的派獎口徑一致。

## 系統

* [decisions/0004-ssot-lives-in-git-knowledge-bundle SSOT 正本放 git 的知識庫](0004-ssot-lives-in-git-knowledge-bundle.md) - 系統行為的 SSOT 正本放進 git 的 docs/knowledge/，格式採 Open Knowledge Format，Outline 只留客服用的操作手冊。
* [decisions/0015-bot-notify-sends-after-rollback 告警在交易回滾後照樣送出並標註](0015-bot-notify-sends-after-rollback.md) - BotNotify 的告警在交易 commit 或 rollBack 之後都送出，rollBack 那次在訊息開頭標註寫入未生效；只有「沒發生就不該通知」的業務通知在 rollBack 時丟掉。
* [decisions/0023-large-table-add-column-must-assert 大表加欄位的 migration 必須確認欄位建成](0023-large-table-add-column-must-assert.md) - 大表加欄位的 migration 不能假設 SchemaMonitor 一定執行；要嘛手寫 DDL 明寫 ALGORITHM=INSTANT，要嘛在 SchemaMonitor 之後斷言欄位真的建成，沒建成就中止。
<!-- knowledge:generated end -->
