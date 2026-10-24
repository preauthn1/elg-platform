---
type: Rule
id: rules/vendor-tthsw
title: EliteHub（TTHSW）無縫錢包回調
description: 聚合商 EliteHub（程式識別名 TTHSW）發回調給我方時，登入驗證、查餘額、扣款、派彩、取消投注、回合結束、活動派彩與錢包加扣款各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 聚合商, 無縫錢包, 回調, callback, EliteHub, TTHSW, PEGASUS, PRGM, IBC, 會員, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:39:28+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/TTHSWController.php
    title: EliteHub 回調 controller
    at: 2026-10-22
  - kind: code
    ref: /app/Vendors/TTHSW/Helpers/SignatureHelper.php
    title: EliteHub 簽章計算
  - kind: code
    ref: /app/Vendors/TTHSW/Helpers/ResponseDeadline.php
    title: 回應期限計算
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2154
    title: 動錢回調取到錢包鎖後檢查回應期限；回合結束補去重
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2158
    title: 取消投注冪等改以原交易為鍵，補已退款局的守衛
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2172
    title: TBR 寫入失敗改為往外拋
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2226
    title: 錢包加扣款回調的 Reason 改為選填
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2399
    title: session token 縮短為 64 字元
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2400
    title: 支援 1.5 版 Widget；回調驗簽改用原始內容
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2423
    title: 登入驗證回應的城市與國家改從遊戲商戶配置取
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2439
    title: 一局多注時每筆要入帳的派彩都加到錢包
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2444
    title: 回合結束不入帳時不覆蓋注單派彩
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4310
    title: 動錢回調的回應期限與死鎖處理
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3099
    title: 取消投注重複退款
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4272
    title: 錢包加扣款回調 Reason 選填
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4374
    title: PEGASUS 無法投注、登入驗證回應城市與國家
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4328
    title: 支援 1.5 版 Widget
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4544
    title: 一局多注派彩入帳與回合結束不入帳
entities:
  - api: "POST external/api/sw/tthsw/auth"
  - api: "POST external/api/sw/tthsw/balance"
  - api: "POST external/api/sw/tthsw/complete"
  - api: "POST external/api/sw/tthsw/deduct"
  - api: "POST external/api/sw/tthsw/promowin"
  - api: "POST external/api/sw/tthsw/refreshtoken"
  - api: "POST external/api/sw/tthsw/refund"
  - api: "POST external/api/sw/tthsw/wallet"
  - api: "POST external/api/sw/tthsw/win"
  - class: 'App\Http\Controllers\External\SingleWallets\TTHSWController'
  - class: 'App\Vendors\TTHSW\Helpers\SignatureHelper'
  - class: 'App\Vendors\TTHSW\Helpers\ResponseDeadline'
  - table: tthsw_deal_records
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

EliteHub 是聚合商（把多家遊戲商包成同一套 API 轉給我方的中間商），程式識別名是 TTHSW，以無縫錢包接入。PEGASUS、PRGM、IBC 這類集成的遊戲商都經由它發回調。

玩家在遊戲內登入、下注、拿派彩、被取消投注、回合結束、拿活動獎金時，EliteHub 會發回調給我方。我方依回調增減會員錢包，寫入 BR（EliteHub 回報、未經轉換的注單）與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給 EliteHub。每筆處理過的回調另記一筆交易紀錄，用來判斷重送。

本份目前收錄 EliteHub 九個回調入口的行為。透過 EliteHub 取遊戲網址、取 Widget（快捷投注小工具）網址屬於進遊戲的流程，不在本份。

本份提到兩種玩家層級的憑證：

- 連線憑證（AuthToken）：玩家進遊戲時拿到、EliteHub 在登入驗證回調時帶回來給我方比對的字串，用來找出是哪個玩家帳號。
- 工作階段憑證（session token）：登入驗證通過後由我方產生、回給 EliteHub 的字串，存在玩家帳號上；EliteHub 可以發「更新工作階段憑證」回調換一組新的。

## 怎麼觸發

沒有操作畫面。玩家在 EliteHub 底下的遊戲內操作時，由 EliteHub 發回調觸發。QA 可以在 dev 站進入經 EliteHub 接入的遊戲下注來觸發。

## 規則

無縫錢包回調共用的檢查寫在 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。EliteHub 的回調自己做前置檢查，沒有走那份文件的共用流程，所以 IP 白名單、簽章、帳號解析、停用與下注資格在 EliteHub 的表現寫在本份的「共通」分群；與共用檢查不同的地方寫在各條敘述裡。

回調用「交易類型」區分同一個入口裡的不同動作。本份用到的交易類型如下：

| 交易類型 | 意思 | 走哪個入口 |
|---|---|---|
| 1 | 一般投注 | 扣款 |
| 2 | 派彩 | 派彩 |
| 3 | 重新派彩（取代既有派彩） | 派彩 |
| 4 | 取消整局投注 | 取消 |
| 5 | 回合結束 | 回合結束 |
| 7 | 禮物投注 | 扣款 |
| 8 | 派彩調整 | 取消 |
| 9 | 取消單筆投注 | 取消 |
| 10 | 預留額度 | 扣款 |
| 11 | 釋放預留額度 | 取消 |
| 12 | 從預留額度下注 | 扣款 |
| 14 | 活動派彩 | 活動派彩 |
| 15 | 錢包扣款 | 錢包加扣款 |
| 16 | 錢包補款 | 錢包加扣款 |

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為 0、訊息為「Success」；失敗時填對應錯誤碼。前置檢查、欄位驗證、幣別不符、非預期錯誤大多回 907，實際原因只看訊息，例如「Invalid signature」「Timestamp expired」「Deduct failed」。 | code:TTHSWController::callAction、code:TTHSWController::errorResponse |
| R-2 | 回調沒帶簽章或時間戳記時，回 907「Missing signature or timestamp」。 | code:TTHSWController::callAction |
| R-3 | 我方依回調內容找玩家帳號：登入驗證用遊戲商送來的連線憑證找，其他回調用玩家帳號找。其他回調沒帶玩家帳號時回 201「Missing PlayerCode」；登入驗證沒帶連線憑證時回 907「Missing AuthToken」。找不到玩家帳號時回 907「System error」。 | code:TTHSWController::callAction、code:TTHSWController::getPlayerByUsername、code:TTHSWController::getPlayerByAuthToken |
| R-4 | 玩家帳號、遊戲商戶、會員三者任一不是啟用狀態時，所有回調都被擋下，回 907「System error」，包括查餘額、派彩、取消投注與回合結束。這點與共用檢查「停用只擋下注」不同。 | code:TTHSWController::validateAccount |
| R-5 | 在正式環境，來源 IP 不在 EliteHub 的白名單時，回 907「IP not in whitelist」。白名單為空時不檢查。 | code:TTHSWController::callAction |
| R-6 | 簽章以遊戲商送來的原始請求內容計算，不用去掉頭尾空白之後的內容。欄位值帶頭尾空白的回調（例如 IBC 的下注）也能通過驗簽。簽章不符時回 907「Invalid signature」。 | code:TTHSWController::callAction、code:SignatureHelper::verifySignature、mr:!2400 |
| R-7 | 回調的時間戳記與我方時間相差超過 300 秒時，回 907「Timestamp expired」。 | code:TTHSWController::callAction、code:SignatureHelper::verifyTimestamp |
| R-8 | 會動錢包的回調帶的幣別與遊戲商戶的幣別不同時，擋下並回 907，不動錢包。登入驗證、查餘額、更新工作階段憑證不檢查幣別。 | code:TTHSWController::verifyCurrency |
| R-9 | 回給遊戲商的餘額乘上匯率後，依遊戲商戶配置的小數位數輸出；沒有配置時取 2 位。 | code:TTHSWController::convertBalanceByRate |

### 回應期限與重送

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 扣款、派彩、取消、回合結束、活動派彩、錢包加扣款這六種回調，在取得會員錢包鎖之後，會檢查距離我方收到請求已過多久。超過回應期限時，整筆處理回滾、不動錢包，回 -1「Response deadline exceeded」。回應期限預設 5000 毫秒，遊戲商戶配置可以覆寫。 | code:TTHSWController::lockWallets、code:ResponseDeadline::assert、mr:!2154 |
| R-11 | 同一局、同一交易序號、同一交易類型的回調重送時，回 502，附第一次處理時的起始餘額與結束餘額，不再動錢包。六種會動錢包的回調都適用，回合結束也是。 | code:TTHSWController::findExistingTransaction、code:TTHSWController::transactionDuplicatedResponse、test:WinCreditToWalletTest::test_duplicate_win_is_not_credited_twice、mr:!2154 |
| R-12 | 寫入 TBR 失敗（例如資料庫死鎖）時，整筆處理會重試；重試後仍失敗就整筆回滾，回 907。這時注單、錢包異動與交易紀錄都不會留下，遊戲商重送時會被當成新的回調處理，不會被判成重複。 | code:TTHSWController::createTotalBetRecord、mr:!2172 |

### 登入驗證、查餘額與更新工作階段憑證

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到登入驗證回調時，產生一組新的工作階段憑證（64 個字元的亂數）存到玩家帳號，並回玩家帳號、會員帳號、工作階段憑證、遊戲商戶幣別、會員餘額與來源 IP。64 個字元是為了不超過 PEGASUS 的 90 字元上限。 | code:TTHSWController::auth、code:TTHSWController::generateSessionToken、mr:!2399 |
| R-14 | 登入驗證回應的城市與國家取遊戲商戶配置的值；沒有配置時回空字串。 | code:TTHSWController::auth、mr:!2423 |
| R-15 | 收到更新工作階段憑證回調時，產生一組新的工作階段憑證取代舊的，並回新的工作階段憑證與會員餘額。 | code:TTHSWController::refreshToken |
| R-16 | 收到查餘額回調時，回玩家帳號目前的工作階段憑證與會員餘額。 | code:TTHSWController::balance |

### 扣款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-17 | 扣款前先檢查會員的下注資格，不具資格時回 907「Account could not bet yet」，不建注單、不扣款。 | code:TTHSWController::deduct |
| R-18 | 一般投注與禮物投注：會員餘額不足以支付投注額時，回 301 並附當前餘額，不扣款。足夠時，該局第一筆投注建一張進行中的注單；同一局之後的投注累加到同一張注單的投注額與有效投注。 | code:TTHSWController::processBet |
| R-19 | 同一局已經有預留額度時，再送一般投注或禮物投注，回 602，不扣款。 | code:TTHSWController::processBet |
| R-20 | 預留額度：會員餘額不足時回 301。足夠時從錢包扣除預留額，記為該局的投注；同一局再預留時累加。 | code:TTHSWController::processReserve |
| R-21 | 從預留額度下注：不動錢包，只記錄這筆用掉了多少預留額。該局找不到預留時回 601。剩餘預留額不足、且差額超過遊戲商送來的容差時，回 605。 | code:TTHSWController::processReserveBet |
| R-22 | 該局已經是已結算狀態時，再送扣款、派彩、取消整局、取消單筆、派彩調整或回合結束，回 -1「Game round already settled」並附當前餘額，不動錢包。 | code:TTHSWController::checkBetRecordNotSettled |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 收到派彩回調但該局找不到注單時，回 501。 | code:TTHSWController::processWin |
| R-24 | 派彩是否加到會員錢包只看回調的入帳旗標，不看回合結束旗標。入帳時，派彩（交易類型 2）累加到注單的派彩，同一局多注各送一筆派彩時每一筆都會入帳；重新派彩（交易類型 3）則取代注單既有的派彩，錢包依前後差額調整。 | code:TTHSWController::processWin、code:TTHSWController::addPayoutToBetRecord、test:WinCreditToWalletTest::test_multi_bet_round_credits_each_win_and_accumulates、test:WinCreditToWalletTest::test_single_win_credits_and_settles、mr:!2439 |
| R-25 | 入帳的派彩有標記回合結束時，注單改為已結算；沒標記時注單維持原狀態，等之後的派彩或回合結束回調。 | code:TTHSWController::processWin、test:WinCreditToWalletTest::test_multi_bet_round_credits_each_win_and_accumulates、test:WinCreditToWalletTest::test_single_win_credits_and_settles |
| R-26 | 不入帳的派彩不動會員錢包。有標記回合結束時，注單派彩設為這筆的金額並改為已結算，寫入 TBR；沒標記時只記交易紀錄，注單不變。 | code:TTHSWController::processWin、test:WinCreditToWalletTest::test_uncredited_win_completing_round_does_not_touch_wallet、test:WinCreditToWalletTest::test_uncredited_wins_then_complete_credits_total_once |

### 取消投注與派彩調整

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-27 | 取消整局投注：該局找不到注單時回 501。找到時，注單的投注、有效投注、派彩、輸贏全部歸零，改為已退款狀態；會員錢包依前後差額調整，等於退回投注額、收回已入帳的派彩。 | code:TTHSWController::processCancelRound、test:RefundedRoundGuardTest::test_cancel_round_retry_with_new_transaction_id_is_rejected |
| R-28 | 取消單筆投注：沒帶原交易序號、該局找不到注單、或同局找不到原交易時，回 501。找到時，從注單的投注額與有效投注扣掉原交易的金額，退回會員錢包，並寫入派彩時間。同一局有多筆投注時，每一筆原交易都可以各取消一次。 | code:TTHSWController::processCancelTransaction、test:CancelTransactionIdempotencyTest::test_cancel_transaction_allows_each_original_transaction_once、mr:!2172 |
| R-29 | 同一筆原交易只能被取消一次。遊戲商換一個新的交易序號重送同一筆取消，或原交易序號只差大小寫，都回 502，不再退回投注額；回應的交易序號帶這次送來的值，起始與結束餘額取第一次取消時的紀錄。 | code:TTHSWController::findCancelForOriginalTransaction、code:TTHSWController::originalTransactionCanceledResponse、test:CancelTransactionIdempotencyTest::test_cancel_transaction_rejects_retry_with_new_transaction_id、test:CancelTransactionIdempotencyTest::test_cancel_transaction_rejects_case_variant_of_original_transaction_id、mr:!2158 |
| R-30 | 派彩調整：該局找不到注單時回 401。找到時，調整金額（可正可負）加到注單的派彩，會員錢包依差額調整。 | code:TTHSWController::processAdjust |
| R-31 | 釋放預留額度：該局找不到預留時回 601。剩餘預留額大於 0 時退回會員錢包，注單的投注額改為實際用掉的預留額；剩餘預留額為負且超過容差時回 605。 | code:TTHSWController::processReleaseReserve |
| R-32 | 該局已經是已退款狀態時，再送派彩、取消整局、取消單筆、派彩調整、釋放預留額度或回合結束，回 403 並附當前餘額，不動錢包、不改注單。 | code:TTHSWController::checkBetRecordNotRefunded、test:RefundedRoundGuardTest::test_adjust_is_rejected_on_refunded_round、test:RefundedRoundGuardTest::test_release_reserve_is_rejected_on_refunded_round、test:RefundedRoundGuardTest::test_cancel_round_retry_with_new_transaction_id_is_rejected、test:RefundedRoundGuardTest::test_complete_is_rejected_on_refunded_round、test:CancelTransactionIdempotencyTest::test_cancel_transaction_rejected_after_cancel_round、mr:!2158 |

### 回合結束

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-33 | 收到回合結束回調但該局找不到注單時，回 501。 | code:TTHSWController::complete |
| R-34 | 回合結束帶入帳旗標時，注單派彩設為回調的總派彩（取代先前的派彩），會員錢包依差額調整，注單改為已結算並寫入 TBR。 | code:TTHSWController::complete、test:WinCreditToWalletTest::test_uncredited_wins_then_complete_credits_total_once |
| R-35 | 回合結束不帶入帳旗標時，不動會員錢包，也不用總派彩覆蓋注單派彩；注單維持先前已入帳的派彩累計，只改為已結算、寫入派彩時間與 TBR。 | code:TTHSWController::complete、test:WinCreditToWalletTest::test_credited_win_then_uncredited_complete_keeps_payout、mr:!2444 |

### 活動派彩與錢包加扣款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-36 | 收到活動派彩回調時，每筆建一張投注為 0、派彩為該筆金額的已結算注單，金額加到會員錢包，並寫入 TBR。 | code:TTHSWController::promoWin、code:TTHSWController::createPromoWinRecord |
| R-37 | 錢包加扣款回調只接受錢包扣款（交易類型 15）與錢包補款（交易類型 16），其他類型回 907。原因欄位選填，沒帶、帶空值都照常處理。 | code:TTHSWController::wallet、mr:!2226 |
| R-38 | 錢包加扣款每筆建一張已結算注單：扣款記為投注、從會員錢包扣除；補款記為派彩、加到會員錢包。兩者都寫入 TBR。回調沒帶局號時，以交易序號當局號。 | code:TTHSWController::wallet、code:TTHSWController::createWalletRecord |

## 還沒確認的事

- 派彩調整、活動派彩的扣款方向（金額為負），程式不先檢查會員餘額是否足夠；原本的餘額不足檢查被註解掉，註解寫「遊戲商反饋扣到 0 返回成功」。餘額不足時實際會怎麼表現，未查證（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 錢包扣款（交易類型 15）不先檢查會員餘額是否足夠，餘額不足時實際會怎麼表現，未查證（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 取消單筆投注的「同一筆原交易只能取消一次」判定沒有加鎖，併發重送時是否仍能擋下，程式註解寫「未驗證，需人工確認」（`target/op_tickets#3099`）。
- 派彩調整與釋放預留額度在已退款的局一律回 403，是 MR !2158 釘住的決定；EliteHub 是否會在整局取消投注後送合法的派彩調整，還沒向 EliteHub 確認。
