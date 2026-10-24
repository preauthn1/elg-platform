---
type: Rule
id: rules/vendor-rgsw
title: RGSW 無縫錢包回調
description: RG 的無縫錢包版（RGSW）發回調給我方時，查玩家、查餘額、下注、派彩、取消投注與事後調整類回調各要怎麼表現，以及調整類回調怎麼擋重送。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, RGSW, RG, 重送, 冪等, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:59+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/RgswController.php
    title: RGSW 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2160
    title: RGSW 調整類回調補上重送守衛，部分退款不再一律關單
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3100
    title: RGSW 調整類回調重送
entities:
  - { api: "GET external/api/sw/rg/user/check/{username}" }
  - { api: "GET external/api/sw/rg/user/balance/{username}" }
  - { api: "POST external/api/sw/rg/game/bet" }
  - { api: "POST external/api/sw/rg/game/payout" }
  - { api: "POST external/api/sw/rg/game/endRound" }
  - { api: "POST external/api/sw/rg/game/betPayout" }
  - { api: "POST external/api/sw/rg/game/refund" }
  - { api: "POST external/api/sw/rg/game/debit" }
  - { api: "POST external/api/sw/rg/game/credit" }
  - { api: "POST external/api/sw/rg/game/sumBet" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\RgswController" }
  - { table: rgsw_bet_records }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

RGSW 是遊戲商 RG 的無縫錢包版。會員查餘額、下注、取得派彩，或 RG 取消投注、事後補款、扣款、追加投注時，RG 會發回調給我方。我方依回調增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），再把餘額回給 RG。

同一局在我方只有一張注單。下注之後的派彩、取消投注與各種事後調整都直接改這張注單，並把每次調整的請求記在注單上。

RGSW 的回調還沒改用無縫錢包的共用前置檢查流程，IP 白名單、授權碼、玩家帳號解析由各端點自己檢查，所以行為與錯誤碼寫在本份。授權碼（wtoken）是遊戲商戶設定裡的授權字串，RG 每次回調都放在請求標頭（header）裡。下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)；RGSW 實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

本份目前收錄：前置檢查、查玩家、查餘額、下注、派彩、取消投注、事後扣款、補款、追加投注，以及調整類回調的重送判定。

## 怎麼觸發

沒有操作畫面。會員在 RG 的遊戲內操作時，由 RG 發回調觸發。QA 可以在 dev 站進入 RGSW 的遊戲下注來觸發。六個站點中目前只有 TPower 有 RGSW 流量（見 MR !2160 描述）。

## 規則

### 回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為 0、訊息「Success」，並回乘過匯率的餘額；失敗時回對應狀態碼，訊息是狀態碼名稱。 | code:RgswController::successResponse、code:RgswController::errorResponse |
| R-2 | 查玩家、查餘額、下注、派彩、多筆派彩、下注派彩合一這幾種以玩家帳號定位的回調，遇到以下任一情況回狀態碼 42293：正式環境來源 IP 不在白名單、玩家帳號不存在或尚未綁定會員、帳號所屬遊戲商戶不存在或已停用、請求標頭的授權碼為空或與遊戲商戶設定不符、玩家帳號已停用。 | code:RgswController::bet、code:RgswController::resolveByUsername、code:RgswController::verifyWtoken |
| R-3 | 取消投注、扣款、補款、追加投注這幾種以局號定位的回調，局號找不到符合狀態的注單，或 IP、授權碼檢查不過時，回狀態碼 42296；玩家帳號已停用時回 42293。 | code:RgswController::refund、code:RgswController::debit、code:RgswController::resolveByBetId |
| R-4 | RG 送來的時間是 UTC-4，我方換成 UTC+0 後寫入注單。 | code:RgswController::parseVendorTime |

### 查玩家與查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 查玩家時，通過前置檢查就回存在。 | code:RgswController::checkPlayer |
| R-6 | 查餘額時回會員真金加禮碼的合計乘以匯率。會員不是正常狀態，或處於鎖分模式且不允許玩這家遊戲商時，回 42293。 | code:RgswController::balance |

### 下注與派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 收到下注回調時，依匯率換算的投注總額從會員錢包扣除（禮碼先扣、真金補足），有效投注取回調帶的實際投注額（沒帶時等於投注總額），建立一張未結算注單與 TBR。 | code:RgswController::bet |
| R-8 | 下注與下注派彩合一時，會員不具下注資格回 42293；會員真金加禮碼不足以支付投注總額回 42295。兩種都不建注單、不扣款。下注資格檢查遊戲時只看遊戲是否標為可投注。 | code:RgswController::bet、code:RgswController::betPayout |
| R-9 | 同一個局號的下注或下注派彩合一重複送達時，回 42297，不重複扣款。 | code:RgswController::bet、code:RgswController::betPayout |
| R-10 | 收到派彩回調時，依局號找到未結算的注單，派彩額加入會員錢包，有效投注改取回調帶的實際投注額，注單改為已派彩並更新 TBR。 | code:RgswController::payout |
| R-11 | 收到多筆派彩回調時，依局號找到未結算的注單，每一筆派彩逐筆累加進注單與會員錢包，注單改為已派彩並更新 TBR。 | code:RgswController::endRound |
| R-12 | 派彩與多筆派彩只處理未結算的注單。同一局已派彩後再收到，不會重複入帳。 | code:RgswController::payout、code:RgswController::endRound、mr:!2160 |
| R-13 | 收到下注派彩合一回調時，一次扣投注總額、加派彩，建立一張已派彩的注單與 TBR。餘額檢查以投注總額為準，不以扣完再加派彩的淨額判斷。 | code:RgswController::betPayout |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 取消投注可以發生在未結算或已派彩的局。已取消完的局不再接受取消投注。 | code:RgswController::refund |
| R-15 | 未結算的局收到取消投注時，投注額減去取消金額，取消金額退回會員錢包（禮碼先退）。投注還有剩時注單維持未結算，有效投注同步減少、最低為 0；投注減到 0 時，有效投注歸零、注單改為已取消。 | code:RgswController::refund、test:RefundOnPendingRoundTest::test_partial_refund_keeps_round_open_and_next_partial_refund_still_works、test:RefundOnPendingRoundTest::test_partial_refund_clamps_valid_bet_at_zero、test:RefundOnPendingRoundTest::test_refund_of_whole_remaining_bet_marks_round_refunded、mr:!2160 |
| R-16 | 未結算的局收到取消投注，取消金額超過剩餘投注額時，回 42298，錢包與注單都不動。 | code:RgswController::refund、test:RefundOnPendingRoundTest::test_refund_exceeding_remaining_bet_is_rejected、mr:!2160 |
| R-17 | 取消金額為 0 或負數時回 42298，錢包與注單都不動。 | code:RgswController::refund、test:RefundOnPendingRoundTest::test_refund_with_negative_amount_is_rejected、mr:!2160 |
| R-18 | 已派彩的局收到取消投注時，派彩與輸贏加上取消金額，取消金額加入會員錢包，注單狀態維持已派彩。 | code:RgswController::refund、test:CorrectionIdempotencyTest::test_refund_on_settled_round_identical_retry_is_ignored |
| R-19 | 換算匯率後只差不到 0.0001 的金額視為相等。全額取消時，即使匯率除不盡，注單也會改為已取消，不會留下殘量卡在未結算。 | code:RgswController::refund、test:RateConversionTest::test_full_refund_is_not_rejected_when_the_rate_does_not_divide_evenly |

### 事後扣款、補款與追加投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 扣款、補款、追加投注只處理已派彩的局（含下注派彩合一），結果直接改在原注單上，派彩時間不變。 | code:RgswController::debit、code:RgswController::credit、code:RgswController::sumBet |
| R-21 | 收到扣款回調時，扣款額（RG 送負值）加到注單的派彩與輸贏，並從會員錢包扣除。扣完餘額會變負時回 42295，不動錢包。 | code:RgswController::debit |
| R-22 | 收到補款回調時，補款額加到注單的派彩與輸贏，並加入會員錢包。 | code:RgswController::credit、test:CorrectionIdempotencyTest::test_credit_applies_once_and_identical_retry_is_ignored |
| R-23 | 收到追加投注回調時，追加額加到注單的投注與有效投注、從輸贏扣除，並從會員錢包扣款。會員真金加禮碼不足時回 42295，不動錢包。 | code:RgswController::sumBet、test:CorrectionIdempotencyTest::test_sum_bet_identical_retry_is_ignored |

### 調整類回調的重送判定

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-24 | 取消投注、扣款、補款、追加投注在 300 秒內，對同一局、同一種調整、同一金額再次送達時，視為 RG 重送：回成功與當前餘額（乘過匯率），錢包與注單都不動，並寫一筆警告 log。 | code:RgswController::isDuplicateCorrection、code:RgswController::duplicateCorrectionResponse、test:CorrectionIdempotencyTest::test_credit_applies_once_and_identical_retry_is_ignored、test:CorrectionIdempotencyTest::test_debit_identical_retry_is_ignored、test:CorrectionIdempotencyTest::test_sum_bet_identical_retry_is_ignored、test:CorrectionIdempotencyTest::test_refund_on_settled_round_identical_retry_is_ignored、test:RefundOnPendingRoundTest::test_identical_partial_refund_retry_is_ignored、test:RateConversionTest::test_duplicate_response_returns_the_balance_in_vendor_currency、mr:!2160 |
| R-25 | 重送判定排在餘額檢查之前。第一次扣款後餘額已不足以再扣一次時，重送的扣款與追加投注仍回成功，不回餘額不足。 | code:RgswController::debit、code:RgswController::sumBet、test:CorrectionIdempotencyTest::test_debit_retry_is_ignored_even_when_balance_cannot_cover_it_again、test:CorrectionIdempotencyTest::test_sum_bet_retry_is_ignored_even_when_balance_cannot_cover_it_again |
| R-26 | 重送判定只比對局號、交易編號與金額。金額不同、調整種類不同，或超過 300 秒時，都當成新的一筆照常處理；請求多帶其他欄位、或金額寫法不同但數值相同，仍判為重送。 | code:RgswController::isSameAdjustment、test:CorrectionIdempotencyTest::test_credit_with_different_amount_is_still_applied、test:CorrectionIdempotencyTest::test_credit_is_not_swallowed_by_a_same_amount_refund_on_the_same_round、test:CorrectionIdempotencyTest::test_credit_outside_duplicate_window_is_applied_again、test:CorrectionIdempotencyTest::test_retry_carrying_an_extra_field_is_still_recognised、test:CorrectionIdempotencyTest::test_retry_with_a_differently_formatted_amount_is_still_recognised |

## 還沒確認的事

- 300 秒的重送時間窗沒有 RG 文件依據，也無法用歷史資料校準，待向 RG 確認重送策略（見 MR !2160 描述）。
- 部分取消投注後有效投注的算法 RG 沒有提供，目前與投注同步遞減，待 RG 回覆校準（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 派彩與多筆派彩找不到未結算注單時（包括重送），錯誤沒有在端點內處理，回應交給全域錯誤處理，不是 RG 的狀態碼格式。待 RD 確認 RG 收到後的行為（2026-10-22 回填時盤點程式碼發現，尚無工單）。
