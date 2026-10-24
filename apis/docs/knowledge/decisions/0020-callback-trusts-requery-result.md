---
type: Decision
id: decisions/0020-callback-trusts-requery-result
title: 回調的狀態與金額以向金流商回查為準
description: AP8PAY 與 CCPayment 收到回調後向金流商回查，充值的狀態與金額以回查結果為準，回調本身只當觸發訊號。
domain: 金流
tags: [回調, 回查, 反查, 查單, 觸發訊號, 金額, AP8PAY, CCPayment, webhook, 冪等, 代收, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/Ap8Pay/DepositCallback.php, title: "防線 3：以查單結果為準", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/CCPayment/DepositCallback.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2246 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2381 }
related: [rules/payment-callback-common-checks, rules/provider-ccpayment]
decided_at: 2026-09-17
decision_status: accepted
---

# 回調的狀態與金額以向金流商回查為準

## 背景

- CCPayment 採永久收款地址：會員自己從錢包轉帳，不在我方輸入金額，所以充值訂單在款到之後才建立，金額以實收數量為準（MR !2246 的描述）。
- AP8PAY 的代收回調沒有簽名、沒有檢查來源，原本直接照回調金額上分（MR !2381 的描述）。

## 決定

收到回調後向金流商回查這筆交易，狀態與金額一律以回查結果為準，不採信回調自己帶的內容；回調只當觸發訊號。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 照回調自己帶的狀態與金額入帳（AP8PAY 原做法） | AP8PAY 的回調沒有簽名、沒有檢查來源，原本直接照回調金額上分（MR !2381 的描述） |
| AP8PAY 建單時帶金額約束付款 | 實測 AP8 的收銀台不吃建單帶的 `amount`，帶了之後輸入框仍是空的，玩家照樣輸入任意金額；留著只會讓後人以為我方約束得住金額（MR !2381 的描述與 commit 內文） |
| CCPayment 驗簽時檢查 Timestamp 時效 | 規格寫 2 分鐘內有效，但重送最晚在首發 30 分鐘後，重送會不會換新 Timestamp 尚未實測；重放對我方無害，因為回調只當觸發訊號、一律回查，且以金流商交易唯一 ID 冪等（MR !2246 的 commit 內文） |
| CCPayment 的對帳排程自己補單 | 會多一條入帳寫入路徑、測試面加倍；改成排程請金流商把回調再送一次，入帳與定案只留回調一條寫入路徑（MR !2246 的 commit 內文） |
| 回調只當觸發訊號，以回查結果為準（採用） | 無 |

## 後果

- AP8PAY 的會員充值限額對這條支付通道形同失效，因為建單金額永遠是 0；要防大額只能在收到金額之後把關，已回報 PM（MR !2381 的描述）。
- CCPayment 的回查放在交易之外，不持鎖等 HTTP（MR !2246 的 commit 內文）。
- CCPayment 回查後確認成功、幣別與鏈別都和支付通道設定相符、不是風險交易，才建立充值訂單並入帳（MR !2246 的描述）。
