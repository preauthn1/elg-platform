---
type: Decision
id: decisions/0019-easypay-callback-signature-if-present
title: EasyPay 回調帶了簽名才驗
description: EasyPay 的回調只在帶了 mdfive_val 時驗簽，帶了就一定要驗得過；沒帶一律放行，不強制驗簽。
domain: 金流
tags: [EasyPay, 回調, 簽名, 驗簽, mdfive_val, API_PRIVATE_KEY, 金流商戶, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/EasyPay/EasyPayTrait.php, title: "signParams() 與 isCallbackSignatureValid()", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2412 }
related: [rules/payment-callback-common-checks]
decided_at: 2026-10-13
decision_status: accepted
---

# EasyPay 回調帶了簽名才驗

## 背景

EasyPay 的 `mdfive_val` 簽名按商戶開關、預設關閉。開啟後，代收與代付的建單請求都必須帶簽名，否則三方直接拒收（MR !2412 的描述）。

## 決定

金流商戶設定有 `API_PRIVATE_KEY` 時，建單帶 `mdfive_val`；回調只在三方真的帶了 `mdfive_val` 時才驗，帶了就一定要驗得過，沒帶一律放行。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 回調強制驗簽 | 多數商戶還沒開啟簽名，回調不會帶 `mdfive_val`；強制驗簽會把那些商戶的回調全擋掉、充值不上分（MR !2412 的描述） |
| 帶了才驗（採用） | 無 |

## 後果

- 沒有 `API_PRIVATE_KEY` 的金流商戶建單不帶簽名、回調不驗，行為與改版前相同（MR !2412 的描述）。
- 上線順序不可顛倒：先部署、確認正式跑的是新版，最後才請廠商開啟簽名開關。廠商先開、我方還沒帶簽名時，該商戶的建單會全部回 422，充值與取款整條斷（MR !2412 的描述）。
- VWorld（SGD）那套 API 是否支援簽名尚未確認，所以刻意沒設私鑰（MR !2412 的描述）。
