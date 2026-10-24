---
type: Rule
id: rules/vendor-spadegamingsw
title: SpadegamingSW 單一錢包回調
description: Spadegaming 的無縫錢包版發回調給我方時，查餘額、下注、取消下注、派彩、紅利各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 單一錢包, 回調, SpadegamingSW, Spadegaming, 會員]
entities:
  - api: POST external/api/sw/spadegaming
  - class: 'App\Http\Controllers\External\SingleWallets\SpadegamingSWController'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/SpadegamingSWController.php
    title: SpadegamingSW 回調 controller
    at: 2026-10-20
  - kind: outline
    ref: http://0.0.0.0:3000/doc/05-99-sg-spadegamingsw-SkI6xiV4Wb
    title: Outline 原頁（遷入後刪除）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2378
    title: 無縫錢包 controller 重構
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3098
    title: 撤單事故
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

SpadegamingSW 是遊戲商 Spadegaming 的無縫錢包版。它與轉帳錢包版的 SpadeGaming 是同一家遊戲商，只是接法不同。

玩家查餘額、下注、取消下注、派彩、發紅利時，SpadegamingSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。玩家餘額由我方保管。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 SpadegamingSW 的遊戲下注來觸發。

## 規則

本份作廢的 R-2、R-3、R-4、R-5、R-8、R-11 不再使用（這些檢查已併入無縫錢包回調的共用檢查）。

所有無縫錢包共用的檢查，包括 IP 白名單、簽章驗證、帳號解析、停用、下注資格、匯率換算，寫在 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)，本份不重複。SpadegamingSW 的錯誤碼也在那份文件的對照表。

### 共通：回應格式與請求驗證

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時回應碼為 0，並回填請求帶來的序號與遊戲商戶代碼；失敗時填對應錯誤碼。 | code:SpadegamingSWController |
| R-6 | 轉帳回調有三種情況會回錯誤碼，三種都不動錢包：金額為負數時回錯誤碼 50113；遊戲代碼在我方不存在時回錯誤碼 2；轉帳類型不是下注、取消、派彩、紅利之一時回錯誤碼 3。 | code:SpadegamingSWController |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。 | code:SpadegamingSWController |

### 下注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），產生一筆下注注單，並回扣款後的餘額。 | code:SpadegamingSWController |
| R-10 | 下注時，會員真金加禮碼不足以支付投注額，回錯誤碼 50110，不建注單、不扣款。 | code:SpadegamingSWController |
| R-12 | 同一個轉帳編號（transferId）的下注重複送達時，回當前餘額與成功碼 0，不再扣款、不再建注單。 | code:SpadegamingSWController |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到派彩回調時，依參考編號（referenceId）找到原下注注單，派彩額加入會員錢包，產生一筆派彩注單，並建立或更新該局 TBR。輸局派彩 0 也會送達，並建 TBR。 | code:SpadegamingSWController |
| R-14 | 派彩回調的參考編號找不到原下注時，回錯誤碼 109，不動錢包。 | code:SpadegamingSWController |
| R-15 | 同一個轉帳編號的派彩重複送達時，回當前餘額與成功碼 0，不重複加款。 | code:SpadegamingSWController |
| R-16 | 棋牌類遊戲（遊戲代碼以 C- 開頭）派彩時，該局玩家淨贏時有效投注歸零，淨輸時有效投注等於淨輸額。 | code:SpadegamingSWController |
| R-17 | 派彩帶有特殊遊戲（免費遊戲、紅利遊戲）且已完成數不為 0 時，該筆派彩自成一筆 TBR，不併入原下注的 TBR。 | code:SpadegamingSWController |
| R-18 | 輸局派彩 0 時，該局 TBR 仍關聯到下注當時建立的現金交易紀錄，讓這局出現在現金紀錄明細。 | code:SpadegamingSWController |
| R-19 | 全額以禮碼下注的局，TBR 不關聯任何現金交易紀錄。 | code:SpadegamingSWController |

### 取消下注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 收到取消下注回調時，依參考編號找到原下注注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），並產生一筆取消注單。取消注單的輸贏是原下注的反向。 | code:SpadegamingSWController |
| R-21 | 取消下注回調的參考編號找不到原下注時，回錯誤碼 109，不動錢包。 | code:SpadegamingSWController |
| R-22 | 同一筆下注已被取消過，再收到針對它的取消回調時（即使轉帳編號不同），回當前餘額與成功碼 0，不再退款。兩筆取消幾乎同時到達時，也只退一次。 | code:SpadegamingSWController、ticket:op-3098 |
| R-23 | 不同筆下注各自可以被取消一次，互不影響。 | code:SpadegamingSWController |

### 紅利

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-24 | 收到紅利回調時不需參考編號，紅利額直接加入會員錢包，產生一筆紅利注單並建立 TBR。 | code:SpadegamingSWController |
| R-25 | 同一個轉帳編號的紅利重複送達時，回當前餘額與成功碼 0，不重複加款。 | code:SpadegamingSWController |
