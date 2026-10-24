---
type: Rule
id: rules/vendor-afbsw
title: AFBSW 無縫錢包回調
description: AFB Gaming 的無縫錢包版（AFBSW）發回調給我方時，憑證檢查、查餘額、下注、派彩與取消投注各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, AFBSW, AFB Gaming, AFB, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:59+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AFBSwController.php
    title: AFBSW 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2127
    title: AFBSW 取消投注時歸零投注量
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2137
    title: 回調補上 API Key 比對
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2178
    title: 回調憑證未設定改為拒收
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3067
    title: AFBSW 取消投注注單仍計入投注量
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3077
    title: AFBSW 回調補上 API Key 比對
entities:
  - { api: "POST external/api/sw/afb" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\AFBSwController" }
  - { table: afbsw_bet_records }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

AFBSW 是遊戲商 AFB Gaming 的無縫錢包版。會員查餘額、下注、取得派彩，或 AFB 取消投注時，AFB 會發回調給我方。所有回調打同一個入口，以請求裡的方法名稱分辨要做什麼。我方依回調增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），再把餘額回給 AFB。

AFBSW 的回調還沒改用無縫錢包的共用前置檢查流程，IP 白名單、憑證、玩家帳號解析由自己的入口檢查，所以行為與錯誤碼寫在本份。下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)；AFBSW 實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

本份目前收錄：前置檢查、查餘額、下注、派彩與取消投注。

## 怎麼觸發

沒有操作畫面。會員在 AFB 的遊戲內操作時，由 AFB 發回調觸發。QA 可以在 dev 站進入 AFBSW 的遊戲下注來觸發。

## 規則

### 回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態為 0、訊息「success」，並回乘過匯率的餘額；被擋下時狀態為 1，訊息寫明原因，不回餘額。 | code:AFBSwController::__invoke |
| R-2 | 請求缺少商戶代碼、方法名稱或玩家帳號，或方法名稱不是查餘額、下注、派彩、下注派彩、取消投注之一時，回狀態 1。 | code:AFBSwController::_validateRequest |
| R-3 | 正式環境來源 IP 不在白名單時，回狀態 1、訊息「IP not in whitelist」。 | code:AFBSwController::__invoke |
| R-4 | 玩家帳號不存在或尚未綁定會員時，回狀態 1、訊息「member not found」；商戶代碼對不到該玩家帳號所屬、且啟用中的遊戲商戶時，回狀態 1、訊息「vendor info not found」。 | code:AFBSwController::__invoke |
| R-5 | 回調帶的 key 與遊戲商戶設定的 API Key 不符、沒帶 key，或該遊戲商戶沒有設定 API Key 時，一律回狀態 1、訊息「Invalid API key」，錢包與注單都不動。 | code:AFBSwController::isApiKeyValid、test:AFBSwApiKeyTest::test_callback_is_rejected_when_api_key_does_not_match、test:AFBSwApiKeyTest::test_callback_is_rejected_when_key_is_absent、test:AFBSwApiKeyTest::test_callback_is_rejected_when_api_key_is_not_configured、test:AFBSwApiKeyTest::test_refund_with_wrong_api_key_does_not_touch_bet_record、mr:!2137、mr:!2178 |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 查餘額時，回會員真金加禮碼的合計乘以匯率。 | code:AFBSwController::getBalance、test:AFBSwApiKeyTest::test_callback_is_accepted_when_api_key_matches |

### 下注與派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），建立一筆下注狀態的注單。下注當下不建 TBR，派彩後才建。 | code:AFBSwController::bet |
| R-8 | 注單的投注時間取交易編號前 10 碼當作時間戳換算。 | code:AFBSwController::bet |
| R-9 | 會員不具下注資格時，回狀態 1、訊息「Account could not bet yet」，不建注單、不扣款。遊戲商戶停用時在前置檢查就被擋下（R-4）。 | code:AFBSwController::bet |
| R-10 | 同一個交易編號的下注重複送達時，回狀態 1、訊息「Duplicate transaction」，不重複扣款。 | code:AFBSwController::bet |
| R-11 | 收到派彩回調時，依交易編號找到注單，以回調帶的實際投注與實際派彩覆寫注單，錢包只異動前後差額，注單改為已派彩並建立 TBR。 | code:AFBSwController::paid |
| R-12 | 派彩時找不到注單回狀態 1、訊息「Bet transaction not found」；注單已派彩回「Bet record already settled」；注單已取消回「Bet record already refunded」。三種都不動錢包。 | code:AFBSwController::paid |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到取消投注回調時，注單改為已取消，投注、有效投注、派彩、輸贏一律歸零，並建立投注量為 0 的 TBR。被取消的注單不計入投注量、報表與代理返利。 | code:AFBSwController::refund、test:AFBSwRefundTest::test_refund_zeroes_bet_and_writes_zero_turnover_to_total_bet_records、mr:!2127 |
| R-14 | 取消金額由取消前後的差額推得：原本扣的真金退回一般錢包、禮碼退回禮碼錢包。回調帶的金額先依匯率換算再比對。 | code:AFBSwController::refund、test:AFBSwRefundTest::test_refund_returns_rolling_chip_portion_to_rolling_chip_wallet、test:AFBSwRefundTest::test_refund_converts_vendor_amount_by_rate、mr:!2127 |
| R-15 | 取消投注帶的金額換算後與原投注額不符時，回狀態 1、訊息「Refund amount mismatch」，錢包與注單都不動。 | code:AFBSwController::refund、test:AFBSwRefundTest::test_refund_is_rejected_when_amount_differs_from_placed_bet、mr:!2127 |
| R-16 | 取消投注時找不到注單回狀態 1、訊息「Bet transaction not found」；注單已派彩回「Bet record already settled」；注單已取消過回「Bet record already refunded」。三種都不動錢包。 | code:AFBSwController::refund、test:AFBSwRefundTest::test_refund_returns_not_found_when_bet_does_not_exist、test:AFBSwRefundTest::test_refund_is_rejected_when_bet_already_refunded |

## 還沒確認的事

- 下注回調不檢查會員餘額是否足夠，餘額不足時仍會扣款建單。待 RD 確認錢包層是否另有擋下，以及 AFB 的規格是否要求回餘額不足（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 方法名稱檢查允許「下注派彩」（betPaid），但入口沒有對應的處理。收到這種回調時會走到錯誤處理，回應的狀態值不是 1。待 RD 確認 AFB 是否會送這種回調（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 處理中發生非預期錯誤（例如資料庫錯誤）時，回應的狀態值直接取錯誤本身的代碼，不一定是 1，可能是 0。待 RD 確認 AFB 會不會把它當成功（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 下注資格檢查不看玩家帳號是否停用。玩家帳號停用時，下注照常建單並回成功，但一般錢包不扣款，禮碼照扣。是否符合預期，待 PM 或 RD 判斷（2026-10-22 回填時盤點程式碼發現，尚無工單）。
