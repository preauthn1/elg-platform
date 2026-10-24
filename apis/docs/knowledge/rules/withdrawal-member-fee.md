---
type: Rule
id: rules/withdrawal-member-fee
title: 會員取款的會員手續費與建單檢查
description: 會員在會員 App 送出取款時，系統怎麼算會員手續費、怎麼數當日第幾筆、什麼情況擋下。
domain: 金流
subdomain: 會員提現（洗分）
tags: [金流, 取款, 提款, 提現, withdrawal, 會員手續費, 手續費, 手續費階梯, 當日第幾筆, 會員群組, 取款銀行, 綁定銀行帳號, 銀行歸屬, 支付組別, 財務群組, bank, 流水, 稽核流水, flow limit, 免盤費, 免盤費遊戲商戶, 新人專享挑戰, 新人挑戰, FumaSW, WudiSW, 會員]
entities:
  - api: POST frontend/api/withdrawal/banks
  - api: POST frontend/api/withdrawal/bind-bank-account
  - api: POST frontend/api/withdrawal/check-bank-account
  - api: POST frontend/api/withdrawal/confirm-payment-password
  - api: POST frontend/api/withdrawal/create-order
  - api: POST frontend/api/withdrawal/flow-limit
  - api: POST frontend/api/withdrawal/orders
  - class: App\Services\WithdrawalFeeResolver
  - class: App\Services\MemberService
  - class: App\Models\MemberGroup
  - class: App\Services\WithdrawalService
evidence:
  - kind: code
    ref: /app/Services/WithdrawalFeeResolver.php
    title: 取款手續費計算（群組階梯與銀行兩條路徑、1958 守門）
    at: 2026-10-20
  - kind: code
    ref: /app/Http/Controllers/Frontend/WithdrawalController.php
    title: 前台建單入口，在會員鎖內取當日序號並寫入訂單
  - kind: code
    ref: /app/Services/MemberService.php
    title: 當日取款次數的計數（getTodayWithdrawalCount）
  - kind: code
    ref: /app/Models/MemberGroup.php
    title: 群組階梯的讀取與排序
  - kind: code
    ref: /database/procedures/SP_calc_flow_limit.sql
    title: 流水達成度的排程計算，免盤費遊戲商的流水只計入新人專享挑戰第二階段挑戰流水
  - kind: code
    ref: /app/Services/WithdrawalService.php
    title: 取款建單前的流水檢查（checkFlowLimit，未達回 1980）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2325
    title: 會員群組平台手續費階梯，會員提款依當日第幾筆計費
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2151
    title: 提款建單與綁定銀行帳號補上銀行歸屬檢查
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2204
    title: 免盤費遊戲商流水隔離規則擴大到全體會員，不限參加過新人挑戰者
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3104
    title: 提款建單／綁定銀行未驗歸屬，可換銀行改扣點倍率
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/3926
    title: "[GFF][TP]新人專享挑戰活動"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4353
    title: 下線會員管理 > 會員群組 - 平台手續費階梯設定
  - kind: code
    ref: /docs/手續費欄位與報表對照.md
    title: 會員手續費與金流商手續費的欄位與報表對照
related:
  - rules/member-group-fee-tiers
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

會員在會員 App 填金額、選取款銀行後送出取款。系統依序做這幾件事：

1. 做一連串建單前檢查：鎖分模式、綁手機、風控限制、活動進行中、流水（會員要累計達到的投注量門檻）、近期改過綁定資料、真實姓名、每日次數、餘額、金額上下限。
2. 檢查通過後，算出這筆的會員手續費。
3. 從會員錢包扣掉取款金額，建立一張待審核的取款訂單，等客服或代付處理。

本份目前收錄的範圍：

- 會員手續費。
- 當日第幾筆。
- 取款銀行歸屬，含綁定銀行帳號。
- 流水檢查裡，免盤費遊戲商的流水怎麼算。

其餘建單前檢查的條目，隨後續 MR 補入。

會員手續費是平台向取款的會員收的費用，會讓會員實收變少。來源有兩種：

- 取款銀行上的設定，每家銀行一個值。
- 會員所屬群組的「平台手續費階梯」：依當日第幾筆取款決定費率，可以設成「前幾筆免費、之後才收」。

階梯的設定方式在 [rules/member-group-fee-tiers](member-group-fee-tiers.md)。

## 怎麼觸發

會員 App › 取款（提現）頁送出申請。這一步沒有後台畫面；建好的取款訂單在代理後台 › 自助洗分 › 洗分訂單審核看得到。

綁定取款銀行帳號在會員 App › 取款（提現）頁 › 綁定銀行帳號（畫面名稱待確認）。

流水的達成度沒有畫面可操作，由系統排程定時依會員的有效投注計算。取款送出時，系統讀當下已算好的達成度。

## 規則

### 會員手續費

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 會員送出取款時，手續費在建單當下算好，並寫進這張取款訂單。之後群組階梯或銀行設定改了，已送出的訂單（審核中或已完成）不重算、不追溯、不退差額。 | code:WithdrawalController::createOrder |
| R-2 | 會員所屬群組啟用中且設有平台手續費階梯時，手續費依「這筆是當日第幾筆」取階，取的是「從第幾筆起」小於或等於本筆序號的最後一階。本筆序號還沒到第一階的起點時，手續費為 0。這時取款銀行上的手續費完全不計入：階梯是取代銀行設定，不是和它相加。 | code:WithdrawalFeeResolver::resolve |
| R-3 | 命中的階梯是百分比時，手續費等於取款金額乘以該百分比（填 3 代表 3%）；是固定金額時，手續費就是該金額。 | code:WithdrawalFeeResolver::resolve |
| R-4 | 會員沒有群組、群組已停用，或群組沒設階梯時，手續費沿用取款銀行的設定：固定金額就收該金額，百分比就收取款金額乘以該比例。這是原本的行為，不受群組功能影響。 | code:WithdrawalFeeResolver::resolve |
| R-5 | 依群組階梯算出的手續費大於或等於取款金額時，擋下這筆取款，回應「手續費不得大於或等於提現金額」（錯誤碼 1958），不建訂單、不扣餘額。依銀行設定算出的手續費不做這個檢查，維持原本行為。 | code:WithdrawalFeeResolver::resolve |

### 當日第幾筆

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 「當日第幾筆」以台北時間（UTC+8）的一天為界。該會員當日已建立、狀態不是「失敗」（被駁回）也不是「退款」（被退回）的取款訂單數，加 1 就是本筆的序號。隔天從第 1 筆重新起算。它和「每日取款次數上限」數的是同一個數字。 | code:MemberService::getTodayWithdrawalCount |
| R-7 | 某一筆被駁回或退回後，它不再佔序號。之後同一天送出的新取款訂單，用重新數過的序號計費；已送出的取款訂單維持原本的手續費（見 R-1）。當日的取款訂單全部被駁回或退回後再送，又從第 1 筆起算。這是 PM 定的規則，不另做防堵。 | code:MemberService::getTodayWithdrawalCount |
| R-8 | 同一會員同時送出多筆取款時，序號依到達順序各取一個（例如十筆是第 1 到第 10 筆），不會都算成第 1 筆。 | code:WithdrawalController::createOrder |
| R-9 | 每張取款訂單都記下這筆是當日第幾筆，以及手續費是依銀行還是依群組階梯算的，供對帳與查證。不是會員在會員 App 建的取款訂單（代理或客服代建），序號記為 0。 | code:WithdrawalController::createOrder |

### 取款銀行歸屬

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 會員送出取款時，選的取款銀行必須屬於會員所屬代理的支付組別，而且是啟用中。條件與取款頁銀行清單相同（清單另可依取款類型篩選，送單時不看類型）。不符合時擋下這筆取款，回應「訂單參數錯誤，請重新輸入」（錯誤碼 1315），不建訂單、不扣餘額、不留帳變流水。 | code:WithdrawalController::createOrder |
| R-11 | 會員綁定取款銀行帳號時，套用與 R-10 相同的條件，不符合時回應「訂單參數錯誤，請重新輸入」（錯誤碼 1315），不寫入綁定。一次送出多筆帳號時，只要有一筆的銀行不符合，整批都不綁。 | code:WithdrawalController::bindBankAccount |
| R-12 | 銀行編號不存在、存在但屬於別的支付組別，或已停用，三種情況回的是同一則訊息。會員無法從回應分辨是哪一種，也就無法藉此試出別的支付組別有哪些銀行。 | code:WithdrawalController::createOrder |

### 免盤費遊戲商的流水

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 會員在免盤費遊戲商（哪些遊戲商算免盤費由各站的遊戲商設定決定）下注累積的有效投注，只計入新人專享挑戰第二階段的挑戰流水。會員身上的其他流水（充值、優惠、其他活動帶來的流水，以及新人專享挑戰第四階段的彩金流水）都不會因此增加達成度，即使那筆流水沒有限定遊戲商也一樣。 | code:SP_calc_flow_limit |
| R-14 | R-13 不分會員一律適用。從沒參加過新人專享挑戰的會員，在免盤費遊戲商下注一樣不會讓充值或其他流水前進。 | code:SP_calc_flow_limit |
| R-15 | 因此只在免盤費遊戲商下注，無法讓 R-13 所列的流水打滿。代理開啟取款流水檢查時，會員的流水總達成度未達總門檻，送出取款會被擋下，回應「未達稽核流水」（錯誤碼 1980）。 | code:WithdrawalService::checkFlowLimit |

## Examples

群組階梯設「第 4 筆起 3%、第 11 筆起固定 50」，取款銀行手續費固定 20：

| 情境 | 本筆序號 | 手續費 | 依據 |
|---|---|---|---|
| 當日第 1 到 3 筆，取款 1,000，未達第一階 | 1 到 3 | 0 | R-2 |
| 當日第 4 筆，取款 1,000 | 4 | 30（不是 50，也不是 20） | R-3 |
| 第 2 筆被駁回後再送一筆，駁回的不佔序號 | 4 | 30 | R-7 |
| 當日第 11 筆，取款 1,000，命中固定金額那一階 | 11 | 50 | R-3 |
| 階梯設「第 1 筆起固定 100」，取款 100 | 1 | 擋下，錯誤碼 1958 | R-5 |
| 會員沒有群組，取款 1,000，沿用銀行設定 | 1 | 20 | R-4 |
| 十筆同時送出 | 1 到 10 各一次 | 前 3 筆 0，第 4 筆起 30 | R-8 |

會員身上只有一筆充值帶來的流水，門檻 1,000、已達成 0，未限定遊戲商；代理已開啟取款流水檢查：

| 情境 | 充值流水達成度 | 送出取款 | 依據 |
|---|---|---|---|
| 在 FumaSW 免盤費下注，有效投注 500，會員沒參加過新人專享挑戰 | 仍為 0 | 擋下，錯誤碼 1980 | R-13、R-14、R-15 |
| 在一般遊戲商下注，有效投注 1,000（一般遊戲商不受免盤費規則限制） | 1,000，打滿 | 不因流水擋下 | R-13 |
| 送出取款時改送別的支付組別的銀行編號 | 不變 | 擋下，錯誤碼 1315 | R-10、R-12 |
