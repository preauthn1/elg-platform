---
type: Rule
id: rules/vendor-sky1388
title: Sky1388 轉帳確認回調
description: 玩家對 Sky1388 轉入或轉出時，Sky1388 回頭向我方確認這筆轉帳是否真的由我方發起，我方怎麼核對、回什麼。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 轉帳錢包, 回調, Sky1388, 確認轉帳, 防偽造上下分, 會員, transfer wallet, callback, confirm transfer]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/TransferWallets/Sky1388Controller.php, title: Sky1388 確認轉帳回調 controller, at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2342, title: 新增 Confirm Transfer Callback 端點 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2346, title: 回退 Confirm Transfer Callback 的金額比對 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4269, title: Sky1388 確認轉帳回調 }
entities:
  - { api: "POST external/api/tw/sky1388/confirm_transfer" }
  - { class: "App\\Http\\Controllers\\External\\TransferWallets\\Sky1388Controller" }
---

## 這個主題在講什麼

Sky1388 是以轉帳錢包接入的遊戲商。我方呼叫 Sky1388 把會員餘額轉入或轉出遊戲時，Sky1388 會反向呼叫我方的確認轉帳回調，確認這筆轉帳確實由我方發起，用來防止有人偽造轉帳。我方核對成功，Sky1388 才完成這筆轉帳；核對失敗，這筆轉帳就失敗。

這個回調只做核對，不動會員錢包，也不寫任何資料。它不是無縫錢包回調，不套用無縫錢包的共用檢查。

本份目前收錄：確認轉帳回調的 IP 檢查、參數檢查、核對方式與回應。

## 怎麼觸發

沒有操作畫面。我方把會員餘額轉入或轉出 Sky1388 時，Sky1388 會呼叫這個回調。QA 可以在 dev 站對 Sky1388 做一次轉入或轉出來觸發。

## 規則

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 在正式環境，來源 IP 不在 Sky1388 遊戲商層級的 IP 白名單時，回 HTTP 401，狀態碼「9999」，訊息「IP <來源 IP> not allow」。白名單為空時不檢查。 | code:Sky1388Controller::confirmTransfer、mr:!2342 |
| R-2 | 回調缺少玩家帳號、轉帳單號或金額，或金額不是數字時，回 HTTP 400 的參數錯誤。 | code:Sky1388Controller::confirmTransfer |
| R-3 | 回調帶的玩家帳號在我方不存在，或是尚未綁定會員的池帳號時，回狀態碼「9999」，訊息「Account not found」。 | code:Sky1388Controller::confirmTransfer、test:Sky1388ConfirmTransferTest::test_returns_9999_when_account_not_found、mr:!2342 |
| R-4 | 在該會員對 Sky1388 的帳變流水中找不到這個轉帳單號時，回狀態碼「9999」，訊息「Transaction not found」，Sky1388 端的這筆轉帳因此失敗。兩種帳變流水（一般會員的，以及多錢包或活動錢包會員的）都會查。 | code:Sky1388Controller::confirmTransfer、test:Sky1388ConfirmTransferTest::test_returns_9999_when_transaction_not_found、mr:!2342 |
| R-5 | 找得到這個轉帳單號時，回狀態碼「0000」。只核對單號是否存在，不比對回調帶的金額，轉入與轉出都一樣。 | code:Sky1388Controller::confirmTransfer、test:Sky1388ConfirmTransferTest::test_returns_0000_when_transaction_log_matches、test:Sky1388ConfirmTransferTest::test_returns_0000_when_only_wallet_transaction_log_matches、mr:!2346 |
| R-6 | 核對一律查主資料庫，不查分庫。轉帳完成到收到回調的時間差很短，查分庫可能還查不到剛寫入的帳變流水。 | code:Sky1388Controller::confirmTransfer |

## 還沒確認的事

- 金額比對曾在 2026-10-01 加上、2026-10-02 撤回：當時沒取絕對值，轉出（從 Sky1388 轉回我方）一律被判金額不符，正式環境的轉出失敗約 4 小時。要不要改用絕對值把金額比對加回來，還沒有決定（target/tickets#4269）。
