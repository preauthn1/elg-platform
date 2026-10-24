---
type: Rule
id: rules/vendor-onebxsw
title: OnebxSW 無縫錢包回調
description: OneBX 體育的無縫錢包版（OnebxSW）發回調給我方時，查餘額、下注、派彩、取消派彩與取消投注各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, OnebxSW, OneBX, 體育, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:59+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php
    title: OnebxSW 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2278
    title: OnebxSW 改繼承 BaseController，下注以 verifyBettability 驗資格
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4375
    title: 下注資格改走 verifyBettability
entities:
  - { api: "POST external/api/sw/onebx/api/balance" }
  - { api: "POST external/api/sw/onebx/api/bet" }
  - { api: "POST external/api/sw/onebx/api/step/bet" }
  - { api: "POST external/api/sw/onebx/api/settle" }
  - { api: "POST external/api/sw/onebx/api/step/settle" }
  - { api: "POST external/api/sw/onebx/api/undo" }
  - { api: "POST external/api/sw/onebx/api/step/undo" }
  - { api: "POST external/api/sw/onebx/api/cancel" }
  - { api: "POST external/api/sw/onebx/api/step/cancel" }
  - { api: "POST external/api/sw/onebx/api/rollback" }
  - { api: "POST external/api/sw/onebx/api/step/rollback" }
  - { api: "POST external/api/sw/onebx/api/step/bonus" }
  - { api: "POST external/api/sw/onebx/api/step/refund-bonus" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\OnebxSWController" }
  - { table: onebxsw_bet_records }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

OnebxSW 是體育遊戲商 OneBX 的無縫錢包版。會員查餘額、下注、取得派彩，或 OneBX 取消派彩、取消投注時，OneBX 會發回調給我方。我方依回調增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），再把餘額回給 OneBX。

OneBX 有一般與 step 兩組端點，處理方式相同，只差金額欄位的名稱。派彩、取消派彩、取消投注以批次送來，一次最多 30 筆，每筆各自處理。整個體育大廳在我方視為同一款遊戲。

OnebxSW 的回調還沒改用無縫錢包的共用前置檢查流程，IP 白名單、簽章、玩家帳號解析由自己的入口檢查，所以行為與錯誤碼寫在本份。下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)；OnebxSW 實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

本份目前收錄：前置檢查、查餘額、下注、派彩、取消派彩、取消投注，以及兩個還沒實作的活動獎金端點。

## 怎麼觸發

沒有操作畫面。會員在 OneBX 體育大廳下注、賽事結算或改判時，由 OneBX 發回調觸發。QA 可以在 dev 站進入 OnebxSW 的大廳下注來觸發。

截至 2026-09，正式站的 OnebxSW 回調仍由舊系統 API_External 處理，APIs 還沒接手（見 MR !2278 描述）。

## 規則

### 回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 成功時回 HTTP 200、狀態「success」；查餘額、下注與取消投注（rollback）另回乘過匯率的餘額。 | code:OnebxSWController::successResponse |
| R-2 | 失敗時回應的狀態與 HTTP 狀態碼固定對照：系統錯誤回 E_00／500、不可下注回 B_00／401、帳號不存在回 C_01／401、簽章或 IP 不符回 M_00／401、餘額不足或金額不合理回 C_00／400。 | code:OnebxSWController::errorLogAndResponse |
| R-3 | 派彩回調（一般與 step）的系統錯誤不回 E_00，改回「RETRY」，HTTP 狀態碼仍是 500。OneBX 的規格是派彩收到非 200 且狀態為 E_00 時，整批派彩永久失敗、不再重送。 | code:OnebxSWController::errorLogAndResponse、test:OnebxSWCallbackTest::test_settle_unknown_bet_is_retryable_not_permanent_failure、mr:!2278 |
| R-4 | 正式環境來源 IP 不在白名單時回 M_00；簽章與遊戲商戶的金鑰算出的值不符，或遊戲商戶沒有設定金鑰時，也回 M_00。都不動錢包。 | code:OnebxSWController::callAction、code:OnebxSWController::isSignatureValid、test:OnebxSWCallbackTest::test_invalid_signature |
| R-5 | 玩家帳號不存在或尚未綁定會員時回 C_01；帳號指到的會員不存在時回 B_00；遊戲商戶不存在或屬於別家遊戲商時回 E_00。 | code:OnebxSWController::callAction、test:OnebxSWCallbackTest::test_unknown_account |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 查餘額時回會員真金加禮碼的合計乘以匯率。 | code:OnebxSWController::balance、test:OnebxSWCallbackTest::test_balance |
| R-7 | 查餘額時，玩家帳號已停用、會員不是正常狀態，或會員處於鎖分模式且不允許玩這家遊戲商，回 B_00。 | code:OnebxSWController::balance、mr:!2278 |

### 下注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 收到下注回調時，扣款額依匯率換算後從會員錢包扣除（禮碼先扣、真金補足），有效投注另取回調帶的投注額，建立一筆未結算注單。下注當下不建 TBR，派彩後才建。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_bet_deducts_wallet_and_creates_pending_record |
| R-9 | 負賠率盤口的扣款額小於投注額時，注單投注記扣款額、有效投注記投注額。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_bet_negative_price_deducts_less_than_stake |
| R-10 | 同一個注單編號的下注重複送達時（包括幾乎同時送達），回成功與當前餘額，不重複扣款、不重複建單。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_duplicate_bet_is_idempotent |
| R-11 | 會員真金加禮碼不足以支付扣款額時，回 C_00，不建注單、不扣款。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_bet_rejected_when_balance_not_enough |
| R-12 | 玩家帳號已停用、遊戲商戶已停用，或會員不具下注資格時，回 B_00，不建注單、不扣款。資格在鎖住會員錢包之後才檢查，讀到的是最新狀態。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_bet_rejected_when_member_locked、test:OnebxSWCallbackTest::test_bettability_is_verified_after_member_lock、mr:!2278 |
| R-13 | 下注資格檢查遊戲時，只看大廳在遊戲商戶下是否標為可投注，不看遊戲在後台是否關閉。標為不可投注時回 B_00。 | code:OnebxSWController::placeBet、test:OnebxSWCallbackTest::test_bet_rejected_when_game_not_bettable、mr:!2278 |
| R-14 | 會員鎖定只擋下注。派彩、取消派彩、取消投注照常處理。 | code:OnebxSWController::settleBet、test:OnebxSWCallbackTest::test_settle_still_pays_when_member_locked、mr:!2278 |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 收到派彩回調時，每筆派彩額依匯率換算後加入會員錢包，注單改為已結算，輸贏加上派彩額，並建立 TBR。 | code:OnebxSWController::settleBet、test:OnebxSWCallbackTest::test_settle_pays_out_and_creates_total_bet_record |
| R-16 | 注單已不是未結算狀態時，再收到派彩不動錢包、回成功。同一個呼叫編號是重送，直接略過；呼叫編號不同代表沒經過取消派彩就重新派彩，同樣不動錢包，只寫一筆警告 log 供人工補付。 | code:OnebxSWController::settleBet、code:OnebxSWController::warnIfUnknownSettlement、test:OnebxSWCallbackTest::test_settle_is_idempotent、test:OnebxSWCallbackTest::test_settle_idempotency_keys_on_call_transaction_id_in_infos |
| R-17 | 派彩額為負數時回 C_00，不動錢包。負數派彩應走取消派彩。 | code:OnebxSWController::settleBet、test:OnebxSWCallbackTest::test_settle_rejects_negative_amount |
| R-18 | 派彩時找不到注單，或一批超過 30 筆時，回 RETRY／500，讓 OneBX 重送。 | code:OnebxSWController::settleBet、code:OnebxSWController::eachItem、test:OnebxSWCallbackTest::test_settle_unknown_bet_is_retryable_not_permanent_failure、test:OnebxSWCallbackTest::test_too_many_items、mr:!2278 |
| R-19 | 批次中某一筆失敗時，回失敗；排在它前面、已處理完的筆數不回滾。 | code:OnebxSWController::eachItem |

### 取消派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 收到取消派彩回調時，收回的金額取注單上已記錄的派彩，不看回調帶的金額。注單退回未結算，輸贏改回負的投注額，並刪除該注單的 TBR。之後再派彩只會派一次。 | code:OnebxSWController::undoSettle、test:OnebxSWCallbackTest::test_undo_reverts_settlement、test:OnebxSWCallbackTest::test_undo_then_resettle_pays_exactly_once、mr:!2278 |
| R-21 | 取消派彩時，會員真金不足以收回派彩，回 C_00，錢包與注單都不動，並發一則 Telegram 告警（系統發到維運的 Telegram 警告群組的訊息）。 | code:OnebxSWController::undoSettle、code:OnebxSWController::notifyReverseBalanceNotEnough、test:OnebxSWCallbackTest::test_undo_rejected_when_wallet_cannot_cover |
| R-22 | 取消派彩時找不到注單回 E_00；注單不是已結算狀態時不動錢包、回成功。 | code:OnebxSWController::undoSettle |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 收到取消投注回調（rollback 或 cancel）時，把取消金額退回會員錢包（禮碼先退、真金補足），注單改為已取消、輸贏歸零。注單上的投注欄保留原下注額供對帳。 | code:OnebxSWController::refundBet、test:OnebxSWCallbackTest::test_rollback_refunds_bet、test:OnebxSWCallbackTest::test_cancel_batch_refunds_each_item、mr:!2278 |
| R-24 | 取消金額超過注單的投注額時回 C_00，不動錢包。 | code:OnebxSWController::refundBet、mr:!2278 |
| R-25 | 注單已結算時，取消投注連同派彩一起收回，並刪除該注單的 TBR。會員真金加取消金額不足以收回派彩時，回 C_00、不動錢包，並發一則 Telegram 告警。 | code:OnebxSWController::refundBet、test:OnebxSWCallbackTest::test_cancel_on_settled_bet_reverses_payout_and_refunds_stake |
| R-26 | 找不到注單或注單已取消過時，視為已處理，回成功（rollback 另回當前餘額），不動錢包。 | code:OnebxSWController::refundBet、test:OnebxSWCallbackTest::test_rollback_unknown_bet_is_noop |
| R-27 | 取消金額為 0 卻對到我方存在的注單時，回 E_00 讓 OneBX 重送，注單維持原狀態、錢包不動。 | code:OnebxSWController::refundBet、test:OnebxSWCallbackTest::test_rollback_zero_amount_on_existing_bet_is_rejected、mr:!2278 |
| R-28 | 取消投注（cancel）一批超過 30 筆時回 E_00。請求沒有最外層時間時，以每筆自帶的時間為準，照常處理。 | code:OnebxSWController::eachItem、test:OnebxSWCallbackTest::test_too_many_items、test:OnebxSWCallbackTest::test_cancel_without_top_level_timestamp_is_accepted |

### 還沒實作的端點

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-29 | 活動獎金發放與回收兩個 step 端點還沒實作。收到時把請求內容寫進 log，回 E_00／500，讓 OneBX 持續重送，不回成功。 | code:OnebxSWController::logUnimplementedEndpoint、test:OnebxSWCallbackTest::test_unimplemented_promotion_endpoints_log_payload_and_refuse、mr:!2278 |

## 還沒確認的事

- 取消派彩改以注單已記錄的派彩為收回額，不看回調帶的金額。這個語意變更線上沒有樣本，待 OneBX 確認（見 MR !2278 描述）。
- 沒經過取消派彩就重新派彩（呼叫編號不同）時，OneBX 文件寫「must be applied as a new payout」但沒定義是補差額還是補全額，目前不動錢包、只寫 log。待 PM 與 OneBX 確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
