---
type: Decision
id: decisions/0014-payout-reject-only-when-provably-unaccepted
title: 代付只在能證明金流商未受理時判明確拒絕
description: 代付送件收到的同步回應，只有能證明金流商沒有受理時才判明確拒絕、讓取款訂單可以重新發起；其餘一律當結果未知，避免重複出款。
domain: 金流
tags: [代付, 明確拒絕, 結果未知, 重複出款, 三方失敗, 代付提交中, 拒絕碼白名單, KIRA, AntzPay, A3Pay, CCPayment, EasyPay, Cloudflare, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/Kira/Withdrawal.php, title: "isRejectedBusinessCode()", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/A3Pay/Withdrawal.php }
  - { kind: code, ref: /app/Services/Payment/Providers/CCPayment/Withdrawal.php }
  - { kind: code, ref: /app/Services/Payment/Providers/EasyPay/Withdrawal.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2246 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2271 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2274 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2312 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403 }
related: [rules/payout-submit-and-status, rules/provider-ccpayment, rules/provider-a3pay, rules/payment-callback-common-checks]
decided_at: 2026-09-21
decision_status: accepted
---

# 代付只在能證明金流商未受理時判明確拒絕

## 背景

代付送件的同步回應有兩種處理：

- 判明確拒絕：代付紀錄標三方拒絕（-2），取款訂單轉「三方失敗」(29)，可以重新發起。
- 判結果未知：取款訂單停在「代付提交中」(21)，等回調或人工到金流商後台查證。

判錯成明確拒絕的代價是重複出款（MR !2271 的描述）。CCPayment 接入時就以此為原則：拒絕碼採白名單制，只有能證明金流商沒有受理的碼才判明確拒絕（MR !2246 的描述）。

## 決定

代付送件的同步回應，只有落在該金流商拒絕碼白名單、或能解出明確拒絕的業務回應時才判明確拒絕；HTTP 錯誤、逾時、解不出業務回應、白名單外的碼一律當結果未知。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| A3Pay：HTTP 4xx 一律判拒絕 | 文件沒有錯誤碼清單，白名單外的碼不能證明未建單，判拒絕會開重複出款窗口（MR !2312 的描述） |
| KIRA：HTTP 522 判拒絕（MR !2271 採用，MR !2274 拿掉） | RB 於 2026-09-21 確認這張工單只依 KIRA 回應的業務碼判定；拿掉之後，判定也不再依賴「我方請求逾時 30 秒短於 Cloudflare 的 90 秒」這個前提（MR !2274 的描述） |
| KIRA：HTTP 520 判拒絕 | KIRAPAY 雖書面表示 520 時沒收到訂單，但和 Cloudflare 的定義不符；這類單量很少，留在 21 人工處理的成本很低（MR !2271 的描述） |
| AntzPay 比照 KIRA | 沒有 AntzPay 的書面依據，AntzPay 的 API 也沒有架在 Cloudflare 後面（MR !2271 的描述） |
| EasyPay：HTTP 非 2xx 一律當結果未知（原狀） | EasyPay 用 HTTP 400 承載業務錯誤，錢一毛都沒出去，訂單卻停在 21 等人工定案；改成「解得出業務信封就交呼叫端判定，解不出照舊結果未知」（MR !2403 的描述） |
| 只在能證明未受理時判拒絕（採用） | 無 |

## 後果

- KIRA 業務碼不是 200 就判明確拒絕，依據是 KIRAPAY 的書面確認「只要不是 200，就表示銀行那裡不會出款，可以重發」（MR !2271 的描述）。
- 白名單外的未知碼交人工處理，MR !2312 的描述認為這個代價可接受。
- 「請求根本沒送出」的情形（例如 CCPayment 幣鏈解析失敗、憑證缺漏），在送出前就回明確失敗（MR !2246 的描述）。
- 解不出業務信封的回應（502 錯誤頁、空 body）請求可能已送達三方，所以只縮小「結果未知」的範圍，不取消它（MR !2403 的描述）。
- 已知限制：訂單轉回 21、新紀錄還沒建立的空窗，如果舊紀錄收到成功回調，新請求仍會送出；MR !2271 只補了專用告警。
