---
type: Rule
id: rules/vendor-wf
title: WFSW 無縫錢包回調
description: WFSW 發回調給我方時，查餘額、投注、派彩、取消投注、活動獎金、彩金各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, WFSW, WF, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/WFController.php, title: WFSW 回調 controller, at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2284, title: bet_id 以字串綁定 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4408, title: WFSW 注單編號查詢轉字串 }
entities:
  - { api: "POST external/api/sw/wf/GetBalance" }
  - { api: "POST external/api/sw/wf/Bet" }
  - { api: "POST external/api/sw/wf/GameResult" }
  - { api: "POST external/api/sw/wf/Rollback" }
  - { api: "POST external/api/sw/wf/CashBonus" }
  - { api: "POST external/api/sw/wf/Jackpot" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\WFController" }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

WFSW 是以無縫錢包接入的遊戲商。玩家查餘額、投注、派彩、被取消投注、領活動獎金或彩金時，WFSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

本份目前收錄：六個回調入口的前置檢查、回應格式、各回調的處理與重複請求的回應。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 WFSW 的遊戲下注來觸發。

## 規則

下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。WFSW 還沒有改用共用的前置檢查，IP 白名單、簽章、帳號解析、停用與下注資格由 WFSW 自己檢查；實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為 200；查餘額回目前餘額，其他回調回異動前與異動後的餘額，餘額一律乘以匯率。 | code:WFController::balanceResponse、code:WFController::response |
| R-2 | 在正式環境，來源 IP 不在 WFSW 遊戲商層級的 IP 白名單時，回錯誤碼 900500，訊息「IP not in whitelist」。白名單為空時不檢查。 | code:WFController::callAction |
| R-3 | 回調帶的玩家帳號在我方不存在，或是尚未綁定會員的池帳號，回錯誤碼 900404。 | code:WFController::callAction |
| R-4 | 找不到與回調帶的商戶代碼相符、屬於該玩家帳號且啟用中的遊戲商戶時，回錯誤碼 900405。遊戲商戶停用時，所有回調（包括派彩與取消投注）都回這個錯誤碼。 | code:WFController::callAction |
| R-5 | 簽章與我方用遊戲商戶金鑰算出的值不符，或遊戲商戶沒有設定金鑰時，回錯誤碼 900407。 | code:WFController::checkSign |
| R-6 | 處理中發生非預期錯誤時，回錯誤碼 900500，真實錯誤只寫入 log。 | code:WFController::callAction |
| R-7 | 遊戲商以數字送來的注單編號或交易編號，一律當成文字去比對已存在的注單。這樣查詢才用得到索引；否則查詢會退化成全表掃描，超過遊戲商 5 秒逾時後被無限重送。 | code:WFController::bet、code:WFController::settle、code:WFController::rollback、code:WFController::bonus、code:WFController::jackpot、test:WFSWBetIdBindingTest::test_bet_id_is_bound_as_string_when_bet_id_arrives_as_json_number、mr:!2284 |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。玩家帳號停用時回錯誤碼 900404，不回餘額。 | code:WFController::getBalance |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 收到投注回調時，投注額除以匯率換成平台金額，從會員錢包扣除（禮碼先扣、真金補足），產生一筆投注狀態的注單，並在投注當下就建立該局 TBR。 | code:WFController::bet、test:WFSWBetIdBindingTest::test_bet_id_is_bound_as_string_when_bet_id_arrives_as_json_number |
| R-10 | 投注時玩家帳號停用，回錯誤碼 900404；會員不具下注資格，回錯誤碼 900416。兩種情況都不建注單、不扣款。 | code:WFController::bet |
| R-11 | 投注時會員真金加禮碼不足以支付投注額，回錯誤碼 900605，不建注單、不扣款。 | code:WFController::bet |
| R-12 | 同一個注單編號的投注重複送達時，回錯誤碼 900409，不再扣款、不再建注單。 | code:WFController::bet |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到派彩回調時，依注單編號找到原注單，以回調帶來的投注額、有效投注、派彩額、輸贏覆寫注單並改為已派彩，會員錢包依前後差額增減，並更新該局 TBR。 | code:WFController::settle |
| R-14 | 派彩回調的注單編號找不到原注單時，回錯誤碼 900415；注單已派彩過時回錯誤碼 900409。兩種情況都不動錢包。 | code:WFController::settle |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 收到取消投注回調時，依注單編號找到原注單，把投注額與派彩額都歸零：扣掉的投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），已加的派彩收回；注單改為已取消，並更新該局 TBR。 | code:WFController::rollback |
| R-16 | 取消投注回調的注單編號找不到原注單時，回錯誤碼 900415；注單已取消過時回錯誤碼 900409。兩種情況都不動錢包。 | code:WFController::rollback |

### 活動獎金與彩金

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-17 | 收到活動獎金回調時不需要原注單，以回調的交易編號另建一筆活動獎金注單，金額除以匯率後加入會員錢包，並建立 TBR，金額記在 TBR 的活動獎金。 | code:WFController::bonus |
| R-18 | 收到彩金回調時不需要原注單，以回調的交易編號另建一筆彩金注單，金額除以匯率後加入會員錢包，並建立 TBR，金額記在 TBR 的彩金。 | code:WFController::jackpot |
| R-19 | 同一個交易編號的活動獎金或彩金重複送達時，回錯誤碼 900409，不重複加款。 | code:WFController::bonus、code:WFController::jackpot |

### 停用的玩家帳號

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 玩家帳號停用時，派彩、取消投注、活動獎金、彩金回調照樣更新注單與 TBR 並回成功，但不異動會員的一般錢包。 | code:WFController::settle、code:BaseController::changeBalance |

## 還沒確認的事

- 注單已取消投注後，再收到同一筆的派彩回調時，程式只擋「已派彩」不擋「已取消」，會照樣把注單改回已派彩並再扣一次投注額、加派彩額。這是否為遊戲商協定允許的情境，待 RD 依三方文件確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
