---
type: Rule
id: rules/vendor-rcb988
title: RCB988 無縫錢包回調
description: RCB988 發回調給我方時，查餘額、投注、調整投注、結算、取消與還原取消各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, RCB988, Rcb988, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Rcb988Controller.php, title: RCB988 回調 controller, at: 2026-10-22 }
  - { kind: code, ref: /app/Helpers/BetHelper.php, title: 下注資格檢查 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2277, title: callAction 改用共用帳號組裝，下注以 verifyBettability 驗資格 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4375, title: 無縫錢包下注資格改走共用檢查 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/3329, title: 取消投注先於投注送達 }
entities:
  - { api: "POST external/api/sw/rcb988" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\Rcb988Controller" }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

RCB988 是以無縫錢包接入的遊戲商。RCB988 只有一個回調入口，以請求內的動作名稱區分查餘額、投注、調整投注、結算、重新結算、取消投注、取消結算、作廢投注、作廢結算、還原作廢投注、還原作廢結算。除了查餘額，一次回調可以帶多筆交易。

我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

本份目前收錄：前置檢查、回應格式、批次處理方式，以及各動作的處理。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 RCB988 的遊戲下注來觸發。

## 規則

下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。RCB988 只用了共用的帳號組裝，IP 白名單、簽章、停用與下注資格由 RCB988 自己檢查；實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

### 共通：回應格式、前置檢查與批次處理

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為「0000」，並帶玩家帳號與目前餘額；餘額乘以匯率後無條件捨去到小數 3 位。 | code:Rcb988Controller::response、test:Rcb988SWGetBalanceTest::test_get_balance_ok |
| R-2 | 在正式環境，來源 IP 不在 RCB988 遊戲商層級的 IP 白名單時，回錯誤碼「9999」，訊息「IP not in whitelist」。白名單為空時不檢查。 | code:Rcb988Controller::callAction |
| R-3 | 回調沒帶玩家帳號時，回錯誤碼「1000」。查餘額以外的回調，所有交易必須是同一個玩家帳號，不一致時同樣回錯誤碼「1000」。 | code:Rcb988Controller::getUsername |
| R-4 | 玩家帳號在我方不存在、是尚未綁定會員的池帳號、或對應的會員不存在時，回錯誤碼「1002」，訊息「User not found」。 | code:Rcb988Controller::callAction、test:Rcb988SWGetBalanceTest::test_get_balance_user_not_found、mr:!2277 |
| R-5 | 玩家帳號所屬的遊戲商戶不存在、不是 RCB988 的遊戲商戶、或已停用時，所有回調都回錯誤碼「9999」，訊息「Can not find corresponding vendor info」。 | code:Rcb988Controller::callAction、test:Rcb988SWBettabilityTest::test_disabled_vendor_info_keeps_legacy_response、mr:!2277 |
| R-6 | 回調帶的金鑰與遊戲商戶設定的金鑰不同時，回錯誤碼「1027」，訊息「Sign error」。 | code:Rcb988Controller::checkSign、test:Rcb988SWGetBalanceTest::test_get_balance_invalid_key |
| R-7 | 動作名稱不在支援清單內時，回錯誤碼「1036」，訊息「Invalid action」。 | code:Rcb988Controller::entrance、test:Rcb988SWGetBalanceTest::test_get_balance_invalid_action |
| R-8 | 一次回調帶多筆交易時，逐筆依序處理，整批在同一個資料庫交易內。任一筆失敗，整批都不生效，回那一筆的錯誤碼；全部成功時，回最後一筆處理完的餘額。 | code:Rcb988Controller::callAction、code:Rcb988Controller::entrance |
| R-9 | 處理中發生非預期錯誤時，回錯誤碼「9999」。 | code:Rcb988Controller::callAction |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。玩家帳號停用時回錯誤碼「1002」，不回餘額。 | code:Rcb988Controller::getBalance、test:Rcb988SWGetBalanceTest::test_get_balance_ok |

### 投注與調整投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 收到投注時，投注額除以匯率換成平台金額，從會員錢包扣除（禮碼先扣、真金補足），產生一筆投注狀態的注單。投注當下不建 TBR，結算時才建。 | code:Rcb988Controller::bet、test:Rcb988SWBetTest::test_bet_ok |
| R-12 | 投注與調整投注時玩家帳號停用，回錯誤碼「1002」，不扣款。 | code:Rcb988Controller::bet |
| R-13 | 投注與調整投注在鎖住會員之後才檢查下注資格；會員不具下注資格（例如會員被系統鎖定）時回錯誤碼「1013」，不建注單、不扣款。 | code:Rcb988Controller::bet、test:Rcb988SWBetTest::test_bet_member_locked、test:Rcb988SWBettabilityTest::test_bettability_is_verified_after_member_lock、mr:!2277 |
| R-14 | 下注資格中的「遊戲能不能下注」，只看遊戲商戶的遊戲是否標為可下注；不看遊戲本身的狀態、遊戲商戶遊戲的狀態與代理的遊戲設定。遊戲沒有標為可下注時回錯誤碼「1013」，不建注單、不扣款。 | code:BetHelper::assertMemberAbleToBet、test:Rcb988SWBettabilityTest::test_bet_is_rejected_when_game_is_not_bettable、mr:!2277 |
| R-15 | 投注額為負數時回錯誤碼「1044」；會員真金加禮碼不足以支付投注額時回錯誤碼「1018」。兩種情況都不建注單、不扣款。 | code:Rcb988Controller::bet、test:Rcb988SWBetTest::test_bet_insufficient_balance |
| R-16 | 同一個交易編號的投注已存在時，回錯誤碼「1016」，不再扣款。 | code:Rcb988Controller::bet、test:Rcb988SWBetTest::test_bet_duplicate_platform_tx_id |
| R-17 | 收到調整投注時，依交易編號找到原注單，以新的投注額覆寫，會員錢包依新舊差額補扣或退回。找不到原注單時回錯誤碼「1017」。 | code:Rcb988Controller::bet |

### 結算

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 收到結算時，依交易編號找到投注狀態的原注單，派彩額除以匯率後加入會員錢包，注單改為已結算，並建立該局 TBR。 | code:Rcb988Controller::settle、test:Rcb988SWSettleTest::test_settle_ok_win、test:Rcb988SWSettleTest::test_settle_ok_loss |
| R-19 | 派彩額為負數時回錯誤碼「1010」；找不到原注單時回錯誤碼「1017」；原注單不是投注狀態（例如已結算）時回錯誤碼「1025」。三種情況都不動錢包。 | code:Rcb988Controller::settle、test:Rcb988SWSettleTest::test_settle_bet_not_found、test:Rcb988SWSettleTest::test_settle_already_settled |
| R-20 | 收到重新結算時，原注單必須是已結算狀態，否則回錯誤碼「1025」。 | code:Rcb988Controller::settle |
| R-21 | 會員被系統鎖定時，結算照常派彩，不因鎖定而中斷。 | code:Rcb988Controller::settle、test:Rcb988SWBettabilityTest::test_settle_still_pays_when_member_is_locked |

### 取消與作廢

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-22 | 收到取消投注或作廢投注時，原注單必須是投注狀態，否則回錯誤碼「1025」。投注額退回會員錢包，注單分別改為已取消或已作廢投注，注單上保留原本的投注額與輸贏。 | code:Rcb988Controller::cancel、test:Rcb988SWCancelTest::test_cancel_bet_ok、test:Rcb988SWCancelTest::test_void_bet_ok、test:Rcb988SWCancelTest::test_cancel_bet_wrong_status |
| R-23 | 收到取消結算或作廢結算時，原注單必須是已結算狀態，否則回錯誤碼「1025」。投注額退回、已加的派彩收回，會員錢包回到投注前；注單分別改為已取消或已作廢結算，並把該局 TBR 更新為投注額 0。 | code:Rcb988Controller::cancel、test:Rcb988SWCancelTest::test_unsettle_ok、test:Rcb988SWCancelTest::test_void_settle_ok |
| R-24 | 取消投注先於投注送達、找不到原注單時，建一筆金額為 0 的已取消注單並回成功，不動錢包。之後同一個交易編號的投注才送達時，回錯誤碼「1016」，不扣款。 | code:Rcb988Controller::cancel、code:Rcb988Controller::bet、test:Rcb988SWCancelTest::test_cancel_bet_not_found_creates_zero_record |
| R-25 | 作廢投注、取消結算、作廢結算找不到原注單時，回錯誤碼「1017」，不建注單。 | code:Rcb988Controller::cancel、test:Rcb988SWCancelTest::test_void_bet_not_found |
| R-26 | 會員被系統鎖定時，取消投注照常退回投注額，不因鎖定而卡住本金。 | code:Rcb988Controller::cancel、test:Rcb988SWBettabilityTest::test_cancel_still_refunds_when_member_is_locked |

### 還原作廢

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-27 | 收到還原作廢投注時，原注單必須是已作廢投注狀態，否則回錯誤碼「1025」。重新扣回原投注額，注單改回投注狀態。 | code:Rcb988Controller::unCancel、test:Rcb988SWUnCancelTest::test_unvoid_bet_ok、test:Rcb988SWUnCancelTest::test_unvoid_bet_wrong_status |
| R-28 | 收到還原作廢結算時，原注單必須是已作廢結算狀態，否則回錯誤碼「1025」。重新套用原投注額與派彩，注單改回已結算，並更新該局 TBR。 | code:Rcb988Controller::unCancel、test:Rcb988SWUnCancelTest::test_unvoid_settle_ok |
| R-29 | 還原作廢投注或還原作廢結算找不到原注單時，回錯誤碼「1017」。 | code:Rcb988Controller::unCancel、test:Rcb988SWUnCancelTest::test_unvoid_bet_not_found |

### 停用的玩家帳號

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-30 | 玩家帳號停用時，結算、取消、作廢、還原作廢回調照樣更新注單與 TBR 並回成功，但不異動會員的一般錢包。 | code:Rcb988Controller::settle、code:BaseController::changeBalance |

## 還沒確認的事

- 重新結算時，程式把這次帶來的派彩額加在原注單已派彩額之上，而不是取代原派彩額。三方送的是差額還是新的總額，待 RD 依三方文件確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 下注資格的「遊戲能不能下注」只看遊戲商戶的遊戲是否標為可下注（R-14）。營運是否長期維護這個設定，待營運確認；不維護的話，日後加開遊戲時可能放行不該開放的遊戲（target/tickets#4375）。
