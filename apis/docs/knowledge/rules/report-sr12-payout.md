---
type: Rule
id: rules/report-sr12-payout
title: SR12 下注記錄的總數與派彩計算
description: 代理後台 SR12 下注記錄查哪些注單，總數區的押注、有效押注、派彩、輸贏怎麼算。
domain: 報表
subdomain: 報表
tags: [SR12, 下注記錄, 遊戲投注記錄, 新平台下注記錄, 派彩, 總數派彩, 押注, 投注, 有效投注, 有效押注, 輸贏, SR14, TBR, 報表, 代理, report, payout, bet record, win lose, valid bet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:41+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/ReportController.php
    title: SR12 查詢條件與總數計算
    at: 2026-10-22
  - kind: code
    ref: /app/Http/Resources/Agent/Report/SR12SummaryResource.php
    title: SR12 總數區欄位
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2350
    title: 總數派彩改用押注加輸贏，與 SR14 一致
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4445
    title: SR12 與 SR14 派彩對不上
entities:
  - api: "POST agent/api/report/sr12"
  - class: 'App\Http\Controllers\Agent\ReportController'
  - class: 'App\Http\Resources\Agent\Report\SR12SummaryResource'
  - class: 'App\Http\Requests\Agent\Report\Sr12Request'
  - table: total_bet_records
---

## 這個主題在講什麼

SR12 是代理後台的下注記錄報表。它從 TBR（各家注單轉成同一組欄位之後的注單）查出某一家遊戲商在一段時間內的注單，上方顯示總數，下方分頁列出明細。

總數區的派彩過去用「有效投注加輸贏」計算，押注與有效投注不相等的注單會讓 SR12 與 SR14 的派彩對不上。2026-10-05 起改成「押注加輸贏」，與 SR14 一致。

本份目前收錄：APIs 這支 SR12 的查詢範圍與總數區的計算。明細各欄怎麼顯示，以及舊代理後台（API_Agent）那一份 SR12，不在本份。

## 怎麼觸發

代理後台的 SR12 報表頁（選單位置待確認）。代理選遊戲商、時間區間與篩選條件後查詢。

## 規則

### 查詢範圍

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 查詢一次只看一家遊戲商。時間區間可以選依投注時間或依結算時間比對，沒選時依投注時間。另外可以再依遊戲類型、三方注單號、代理帳號、會員帳號篩選。 | code:ReportController::sr12、code:Sr12Request::rules |
| R-2 | 指定的代理帳號不存在時回「查無此代理」；指定的會員帳號不存在時回「查無此會員」。 | code:ReportController::sr12 |
| R-3 | 沒指定代理也沒指定會員時，只列登入代理直屬會員的注單；登入的是子帳號時，以它的主帳號為準。 | code:ReportController::sr12 |
| R-4 | 明細預設依投注時間由新到舊排序，可改成依押注金額由大到小排序，並依每頁筆數分頁。 | code:ReportController::sr12 |

### 總數區

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 總數區列出押注總數、有效押注總數、派彩、輸贏總數、禮碼總數、佣金總數，範圍是符合查詢條件的所有注單，不只當頁。 | code:ReportController::sr12、code:SR12SummaryResource::toArray |
| R-6 | 派彩等於押注總數加輸贏總數，不使用有效押注，與 SR14 的派彩一致。例如押注 10、有效押注 9.5、輸贏 9.5 的注單，派彩算 19.5。 | code:ReportController::sr12、test:Sr12Test::test_summary_payout_uses_bet_not_valid_bet、mr:!2350 |
| R-7 | 有效押注總數照樣另外列出，不受派彩算法影響。 | code:ReportController::sr12、test:Sr12Test::test_summary_payout_uses_bet_not_valid_bet、mr:!2350 |

## 還沒確認的事

- 代理後台目前實際呼叫的是舊代理後台（API_Agent）那一份 SR12。MR !2350 提到同一張工單在 API_Agent 保留 HAO_LONG、BOLAKK4D「只有贏的注單才算派彩」的特例，APIs 這份沒有這個特例。兩邊口徑要不要一致，見 target/tickets#4445。
