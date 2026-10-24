---
type: Rule
id: rules/vendor-motivationsw
title: MotivationSW 單一錢包回調
description: MotivationSW（對外品牌 Gameboy）發回調給我方時，查玩家、查餘額、下注、轉出、退款、結算、轉回、事後扣款與補款各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 單一錢包, 回調, MotivationSW, Gameboy, 會員]
entities:
  - api: GET external/api/sw/motivation/player/check/{account}
  - api: GET external/api/sw/motivation/transaction/balance/{account}
  - api: POST external/api/sw/motivation/transaction/game/bet
  - api: POST external/api/sw/motivation/transaction/game/credit
  - api: POST external/api/sw/motivation/transaction/game/debit
  - api: POST external/api/sw/motivation/transaction/game/endround
  - api: POST external/api/sw/motivation/transaction/game/refund
  - api: POST external/api/sw/motivation/transaction/game/rollin
  - api: POST external/api/sw/motivation/transaction/game/rollout
  - class: 'App\Http\Controllers\External\SingleWallets\MotivationSWController'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/MotivationSWController.php
    title: MotivationSW 回調 controller
    at: 2026-10-20
  - kind: outline
    ref: http://0.0.0.0:3000/doc/05-99-ms-motivationsw-Y2tpNPCQa3
    title: Outline 原頁（遷入後刪除）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2378
    title: 無縫錢包 controller 重構
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4510
    title: 事後扣款（debit）方向修正
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T18:00:00+08:00" }
---

## 這個主題在講什麼

遊戲商 MotivationSW（對外品牌 Gameboy）以無縫錢包接入。玩家查詢、下注、轉出、退款、結算、轉回、事後補款時，MotivationSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

玩家餘額由我方保管，遊戲商不持有餘額。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 MotivationSW 的遊戲下注來觸發。

## 規則

本份作廢的 R-2、R-3、R-4、R-6、R-10、R-13 不再使用（這些檢查已併入無縫錢包回調的共用檢查）。

所有無縫錢包共用的檢查，包括 IP 白名單、wtoken 驗證、帳號解析、停用、下注資格、匯率換算，寫在 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)，本份不重複。MotivationSW 的錯誤碼也在那份文件的對照表。

### 共通：回應格式

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200，成功時回應內的狀態碼為「0」，失敗時填對應錯誤碼。 | code:MotivationSWController |
| R-5 | 發生非預期錯誤（例如資料庫錯誤）時，回錯誤碼 1100 與固定訊息「Internal server error」，真實錯誤只寫入 log，不回給遊戲商。 | code:MotivationSWController |
| R-7 | 回應中的時間一律以 UTC-4 時區格式化。 | code:MotivationSWController |

### 查玩家與查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 查玩家時，帳號存在就回存在（data=true）。尚未綁定會員的池帳號（尚未綁定會員的玩家帳號）也回存在。 | code:MotivationSWController |
| R-9 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。尚未綁定會員的池帳號回 0。 | code:MotivationSWController |

### 下注與轉出

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），產生一筆下注狀態的注單，並回扣款後的餘額。 | code:MotivationSWController |
| R-12 | 下注時，會員真金加禮碼不足以支付投注額，回錯誤碼 1005，不建注單、不扣款。 | code:MotivationSWController |
| R-14 | 轉出（rollout）與下注的處理相同，差別只在注單狀態記為轉出。轉出的額度之後由轉回結回。 | code:MotivationSWController |

### 退款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 收到退款回調時，依交易代碼（mtcode）找到原下注注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），注單狀態改為已退款，並回退款後的餘額。 | code:MotivationSWController |
| R-27 | 同一筆交易代碼已經退過款，再收到退款回調時，回錯誤碼 1100，不再退款。 | code:MotivationSWController |
| R-28 | 收到退款回調但交易代碼找不到原下注注單時，回錯誤碼 1100，不動錢包。 | code:MotivationSWController |

### 結算與轉回

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-16 | 收到結算（endround）回調時，該局每一筆派彩依匯率換算後加入會員錢包，每筆派彩各產生一筆結算狀態的注單，並建立或更新該局的 TBR。TBR 輸贏等於派彩合計減投注合計。 | code:MotivationSWController |
| R-17 | 同一局有多筆派彩時，TBR 的輸贏累加每一筆派彩，不會只算到第一筆。 | code:MotivationSWController |
| R-18 | 同一局已經結算過，再收到結算回調時，直接回當前餘額，不重複入帳、不重複建注單。 | code:MotivationSWController |
| R-19 | 收到結算回調但該局找不到任何下注注單時，回錯誤碼 1014。 | code:MotivationSWController |
| R-20 | 會員處於鎖分模式，或玩家帳號與遊戲商戶已停用時，結算與轉回仍照常入帳。停用只擋下注，不擋結算。 | code:MotivationSWController |
| R-21 | 收到轉回（rollin）回調時，依局號找到轉出注單，以轉回金額減轉出額作為該局輸贏，加入會員錢包，產生一筆轉回狀態的注單並建立 TBR。 | code:MotivationSWController |
| R-22 | 收到轉回回調但該局找不到轉出注單時，回錯誤碼 1014。 | code:MotivationSWController |
| R-29 | 魚機與街機的轉回回調不帶桌號（tableid 為空或沒送）時，仍照 R-21 結回錢包，不因缺桌號擋下。 | code:MotivationSWController |
| R-30 | 轉回時，老虎機、街機、魚機的有效投注取該局的投注額，不用遊戲商送來的有效投注（這三類遊戲商固定送 0）；牌桌與真人取遊戲商送來的有效投注。 | code:MotivationSWController、test:RollinTest::test_rollin_valid_bet_by_gametype |

### 事後扣款與補款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 收到事後補款（credit）回調時，依局號找到已結算或已轉回的注單，補款額加入會員錢包，注單輸贏加上補款額並改為已補款狀態，TBR 同步加上補款額。 | code:MotivationSWController |
| R-24 | 收到事後扣款（debit）或補款（credit）回調，但該局找不到已結算或已轉回的注單時，回錯誤碼 1014。 | code:MotivationSWController |
| R-25 | 收到事後扣款（debit）回調時，依局號找到已結算或已轉回的注單，扣款額從會員真金錢包扣除，注單輸贏減去扣款額並改為已扣款狀態，TBR 同步減去扣款額。 | code:MotivationSWController、ticket:4510 |
| R-26 | 事後扣款時，會員真金不足以扣除扣款額，回錯誤碼 1005，錢包、注單與 TBR 都不變。 | code:MotivationSWController |

## 還沒確認的事

MotivationSW 有幾條行為與 MotivationSW 的文件或平台慣例不一致，待 PM 與 RD 拍板後才寫進規則：

- 同一交易代碼重複送達的下注、退款、轉回，目前回 1100，而不是回前次餘額。
- 退款找不到原注單，目前回 1100，而不是 1014。
- 下注金額小於等於 0，目前回 1100，而不是 1003。
- 轉回時，原本以禮碼下注的額度會以真金結回。
- 已結算的局再收到退款，目前不會擋。
