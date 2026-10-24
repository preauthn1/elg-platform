---
type: Rule
id: rules/vendor-acewin
title: Acewin 與 Ace333SW 無縫錢包回調
description: Acewin（AcewinSW）與 Ace333SW 兩家無縫錢包遊戲商發回調給我方時，憑證檢查、查餘額、下注、派彩、彩金與取消投注各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, Acewin, AcewinSW, Ace333SW, Ace333, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:59+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AcewinController.php
    title: Acewin 回調 controller
    at: 2026-10-22
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/Ace333SWController.php
    title: Ace333SW 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2148
    title: Acewin／Ace333SW 回調未帶商戶憑證一律拒收
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2267
    title: AcewinSW 下注改走 verifyBettability，檢查移到鎖會員之後
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2281
    title: AcewinSW bet_id 以字串綁定
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2137
    title: 回調憑證遮罩與驗證、Acewin 撤單歸零投注量
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3085
    title: Acewin／Ace333SW 空憑證拒收
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4375
    title: 下注資格改走 verifyBettability
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3078
    title: Acewin 取消投注歸零投注量
entities:
  - { api: "POST external/api/sw/acewin/auth" }
  - { api: "POST external/api/sw/acewin/bet" }
  - { api: "POST external/api/sw/acewin/cancelBet" }
  - { api: "POST external/api/sw/ace333sw/authenticate" }
  - { api: "GET external/api/sw/ace333sw/getbalance" }
  - { api: "POST external/api/sw/ace333sw/bet" }
  - { api: "POST external/api/sw/ace333sw/betresult" }
  - { api: "POST external/api/sw/ace333sw/jackpotwin" }
  - { api: "POST external/api/sw/ace333sw/refund" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\AcewinController" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\Ace333SWController" }
  - { table: acewin_bet_records }
  - { table: ace333sw_bet_records }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

Acewin（程式裡叫 AcewinSW）與 Ace333SW 是兩家以無縫錢包接入的遊戲商。會員在遊戲內下注、取得派彩或彩金、遊戲商取消投注時，遊戲商會發回調給我方。我方依回調增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），再把餘額回給遊戲商。

兩家的回調都還沒改用無縫錢包的共用前置檢查流程，IP 白名單、憑證、玩家帳號解析由各自的入口檢查，所以行為與錯誤碼寫在本份。下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)；這兩家實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

本份提到兩種憑證：

- 連線憑證（token）：玩家登入遊戲時拿到、遊戲商回調時帶回來給我方比對的字串，用來找出是哪個玩家帳號。
- 授權碼（token）：遊戲商戶設定裡的授權字串。Ace333SW 每次回調都把它放在請求標頭（header）裡，我方拿來比對是哪個遊戲商戶。

本份目前收錄：兩家的憑證檢查、查餘額、下注、取消投注，以及 Ace333SW 的派彩、彩金與驗證身分。

## 怎麼觸發

沒有操作畫面。會員在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 Acewin 或 Ace333SW 的遊戲下注來觸發。

截至 2026-09，兩家正式站的回調仍由舊系統 API_External 處理，APIs 這條路徑在正式站沒有流量（見 MR !2148、!2267 描述）。

## 規則

### Acewin：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時錯誤碼為 0，並回玩家帳號、商戶幣別與乘過匯率的餘額；失敗時只回錯誤碼與訊息。 | code:AcewinController::response、code:AcewinController::errorResponse |
| R-2 | 回調未帶 HTTP Basic Auth 憑證、帶的不是 Basic 格式，或憑證與遊戲商戶設定不符時，一律回錯誤碼 5、訊息「Invalid client」，不動錢包、不寫注單。 | code:AcewinController::callAction、test:AcewinClientCredentialTest::test_callback_without_basic_auth_is_rejected、test:AcewinClientCredentialTest::test_callback_with_wrong_basic_auth_is_rejected、test:AcewinClientCredentialTest::test_callback_with_non_basic_authorization_is_rejected、mr:!2148 |
| R-3 | 遊戲商戶已停用時，所有回調都回錯誤碼 5、訊息「Invalid client」，包括取消投注。 | code:AcewinController::callAction |
| R-4 | 正式環境來源 IP 不在白名單時，回錯誤碼 5、訊息「IP not in whitelist」。 | code:AcewinController::callAction |
| R-5 | 回調帶玩家帳號時，帳號不存在或尚未綁定會員，回錯誤碼 5、訊息「User not found」。只帶連線憑證時，連線憑證查不到玩家帳號，回錯誤碼 4。 | code:AcewinController::callAction |
| R-6 | 處理中發生非預期錯誤時，回錯誤碼 5、訊息「Internal server error」，真實錯誤只寫 log。 | code:AcewinController::callAction |

### Acewin：驗證與下注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 驗證回調時，玩家帳號已停用就回錯誤碼 5、訊息「User not found」；否則回會員真金加禮碼的合計乘以匯率。 | code:AcewinController::auth |
| R-8 | Acewin 的下注回調同時帶投注額與派彩額，一筆回調就完成下注與派彩。我方扣投注額（禮碼先扣、真金補足）、加派彩，注單直接記為已結算，並建立 TBR。 | code:AcewinController::bet、test:AcewinBetTest::test_bet_settles_in_one_call |
| R-9 | 投注額為 0 的免費旋轉照常收單：有效投注記 0，派彩照常入帳。 | code:AcewinController::bet、test:AcewinBetTest::test_zero_stake_free_spin_is_paid_out |
| R-10 | 會員真金加禮碼不足以支付投注額時，回錯誤碼 2，不建注單、不扣款。 | code:AcewinController::bet、test:AcewinBetTest::test_bet_is_rejected_when_balance_is_insufficient |
| R-11 | 同一局號的下注重複送達時，回錯誤碼 1，不重複扣款與派彩，注單只有一筆。 | code:AcewinController::bet、test:AcewinBetTest::test_duplicate_round_is_rejected |
| R-12 | 會員不具下注資格時回錯誤碼 5，不建注單、不扣款；玩家帳號已停用、會員停用或被鎖定都算不具下注資格。遊戲商戶停用時在前置檢查就被擋下（R-3）。資格在鎖住會員錢包之後才檢查，讀到的是最新的會員與遊戲商戶狀態。 | code:AcewinController::bet、test:AcewinBettabilityTest::test_bet_is_rejected_when_member_is_locked、test:AcewinBettabilityTest::test_bettability_is_verified_after_member_lock、mr:!2267 |
| R-13 | 下注資格檢查遊戲時，只看該遊戲在遊戲商戶下是否標為可投注。遊戲在後台關閉、但仍標為可投注時照常收單；標為不可投注時回錯誤碼 5。 | code:AcewinController::bet、test:AcewinBettabilityTest::test_bet_passes_when_game_disabled_but_still_bettable、test:AcewinBettabilityTest::test_bet_is_rejected_when_game_is_not_bettable、mr:!2267 |

### Acewin：取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 收到取消投注回調時，注單改為已取消，投注、有效投注、輸贏一律歸零，TBR 同步歸零。錢包依取消前後的差額異動：原本扣的真金退回一般錢包、禮碼退回禮碼錢包；同一筆下注回調已入帳的派彩一併收回。 | code:AcewinController::cancelBet、test:AcewinCancelBetTest::test_cancel_bet_zeroes_turnover_and_refunds_wallet、test:AcewinCancelBetTest::test_cancel_bet_returns_rolling_chip_portion_to_rolling_chip_wallet、mr:!2137 |
| R-15 | 取消投注回調帶的金額換算後與原投注額不符時，回錯誤碼 3、訊息「Cancel amount mismatch」，錢包與注單都不動。 | code:AcewinController::cancelBet、test:AcewinCancelBetTest::test_cancel_bet_is_rejected_when_amount_differs_from_placed_bet、mr:!2137 |
| R-16 | 局號找不到注單時回錯誤碼 2；注單已取消過時回錯誤碼 1。兩種情況都不動錢包。 | code:AcewinController::cancelBet、test:AcewinCancelBetTest::test_cancel_bet_returns_not_found_when_bet_does_not_exist、test:AcewinCancelBetTest::test_cancel_bet_is_rejected_when_already_rolled_back |
| R-17 | 會員被鎖定時，取消投注仍照常退回投注額，不檢查下注資格。 | code:AcewinController::cancelBet、test:AcewinBettabilityTest::test_cancel_bet_still_refunds_when_member_is_locked |

### Ace333SW：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 回調的請求標頭未帶授權碼、授權碼為空字串，或授權碼與遊戲商戶設定不符時，一律回 HTTP 400、訊息「Invalid token」，不動錢包。遊戲商戶已停用時，所有回調都同樣回 HTTP 400、訊息「Invalid token」。 | code:Ace333SWController::callAction、test:Ace333SwTokenTest::test_未帶token的回調一律拒收、test:Ace333SwTokenTest::test_token為空字串的回調一律拒收、test:Ace333SwTokenTest::test_token錯誤的回調拒收、mr:!2148 |
| R-19 | 正式環境來源 IP 不在白名單時，回 HTTP 400、訊息「IP not in whitelist」。 | code:Ace333SWController::callAction |
| R-20 | 回調帶的玩家帳號不存在或尚未綁定會員時，回 HTTP 200、錯誤「1」、訊息「Player not found」、餘額 0。 | code:Ace333SWController::callAction |
| R-21 | 其他回調成功時回 HTTP 200、錯誤「0」與乘過匯率的餘額；業務上的失敗回錯誤「1」與訊息。 | code:Ace333SWController::bet、code:Ace333SWController::getBalance |

### Ace333SW：驗證身分與查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-22 | 驗證身分時，玩家帳號已停用回錯誤「1」、訊息「Player not found」；帳號密碼與我方記錄不符時回 HTTP 400、訊息「Invalid user」。 | code:Ace333SWController::authenticate |
| R-23 | 驗證身分通過時回會員餘額。會員狀態不是正常時，錯誤為「1」、訊息「Member is locked」。 | code:Ace333SWController::authenticate |
| R-24 | 查餘額時回會員真金加禮碼的合計乘以匯率。 | code:Ace333SWController::getBalance、test:Ace333SwTokenTest::test_token正確的回調照常回傳餘額 |

### Ace333SW：下注、派彩與彩金

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-25 | 下注時，玩家帳號已停用回錯誤「1」、訊息「Player not found」；會員不具下注資格回錯誤「1」、訊息「Account could not bet yet」；會員真金加禮碼不足時回錯誤「1」、訊息「Insufficient balance」。三種都不扣款。 | code:Ace333SWController::bet |
| R-26 | 收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），建立或更新該局注單，並建立 TBR。 | code:Ace333SWController::bet |
| R-27 | 同一局號再次收到下注回調時不擋下，以新的投注額覆寫該局注單，錢包只扣新舊投注額的差額。 | code:Ace333SWController::bet |
| R-28 | 收到派彩回調時，依局號找到注單，派彩額加入會員錢包，注單記為已結算並更新 TBR。找不到注單時回錯誤「1」、訊息「Bet record not found」，不動錢包。 | code:Ace333SWController::settle |
| R-29 | 收到彩金回調時，彩金依匯率換算後加入會員錢包，另建一筆投注為 0 的已結算注單並建立 TBR。同一局號已有注單時回錯誤「1」、訊息「Duplicate playSessionID: <局號>」，不重複加款。 | code:Ace333SWController::jackpotWin |

### Ace333SW：取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-30 | 收到取消投注回調時，依取消對象的局號找到注單，投注、有效投注、輸贏歸零，注單改為已取消並更新 TBR。錢包依前後差額異動，真金退回一般錢包、禮碼退回禮碼錢包。 | code:Ace333SWController::refund |
| R-31 | 取消投注找不到注單時回錯誤「1」、訊息「Bet record not found」；注單已取消過時回錯誤「1」、訊息「Bet record already refunded」。兩種都不動錢包。 | code:Ace333SWController::refund |

## 還沒確認的事

- Acewin 下注時先檢查餘額、後檢查重複局號。重送的下注若遇到會員餘額已不足投注額，會回錯誤碼 2（餘額不足），而不是 1（重複交易）。Acewin 收到 2 會怎麼處理，待 RD 向遊戲商確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- Ace333SW 的派彩回調不檢查注單狀態：已取消的局再收到派彩，仍會依派彩額入帳並把注單改回已結算。待 PM 與 RD 確認是否應擋下（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- Ace333SW 的取消投注不比對金額，也不檢查注單是否已派彩：已派彩的局被取消時，會退回投注並收回派彩。待 PM 與 RD 確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- Acewin 的 api.md 要求「注單已成立回 errorCode=6」，但 Acewin 的下注是下注派彩合一、注單一建立就是已結算，照字面實作會讓取消投注永遠無效，所以沒有實作。成立判準待向遊戲商確認（見 MR !2137 的 commit 說明）。
