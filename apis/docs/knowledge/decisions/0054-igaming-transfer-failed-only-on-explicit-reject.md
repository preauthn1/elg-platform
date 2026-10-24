---
type: Decision
id: decisions/0054-igaming-transfer-failed-only-on-explicit-reject
title: Play8oy 與 SunCity 轉帳只在三方明確拒絕時判失敗
description: iGaming 平台（Play8oy、SunCity）的轉帳回應，只有列舉的明確拒絕碼、且由 HTTP 200 或 400 帶回時才判失敗並做轉帳失敗退回，其餘一律當結果不明。
domain: 遊戲與遊戲商
tags: [轉帳錢包, 轉帳, Play8oy, SunCity, iGaming, 明確拒絕, 結果不明, TIMEOUT, FAILED, 8888, 重複入帳, HTTP 400]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Play8oy/Enums/TransactionResponseCodeEnum.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/Play8oy/Driver.php }
  - { kind: code, ref: /app/Vendors/Suncity/Driver.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2373 }
related: [rules/vendor-and-channel-management, decisions/0014-payout-reject-only-when-provably-unaccepted]
decided_at: 2026-10-07
decision_status: accepted
---

# Play8oy 與 SunCity 轉帳只在三方明確拒絕時判失敗

## 背景

- 轉帳判 FAILED 時，會員轉帳流程會立即做轉帳失敗退回（MR !2373 的 commit body）。
- iGaming 平台只有成功回 HTTP 200，業務錯誤碼一律以 HTTP 400 回（staging 實測）。對接程式每個呼叫都 `->throw()`，明確拒絕碼被攔成例外，轉帳一律標成 8888、不做轉帳失敗退回；dev 有會員轉入被 -90607 拒絕卻卡在 8888，Play8oy 線上同樣受影響（MR !2373 的 commit body）。

## 決定

- 轉帳只有三方明確拒絕（玩家不存在或凍結、金額格式與限額、餘額不足、交易號空白或過長、遊戲中不能提款）才回 FAILED，其餘含未知碼一律 TIMEOUT（MR !2373 的 commit body）。
- 查單的 -90001 交易失敗與 -90609 交易不存在為 FAILED（MR !2373 的 commit body）。
- 不再 `->throw()`：HTTP 400 帶 Code 交業務碼分類；只接受 HTTP 200 或 400 帶的拒絕碼（MR !2373 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 非 0 即 FAILED（原狀） | -90002 未知錯誤、-90003 例外錯誤、-90500 與 -90501 三方內部呼叫失敗都可能其實已入帳，此時做轉帳失敗退回就是雙重入帳；-90610 我方交易號已存在，代表前一次請求可能已成功，同樣不可做轉帳失敗退回（MR !2373 的 commit body） |
| 每個呼叫都 `->throw()`（原狀） | HTTP 400 承載的明確拒絕碼被攔成例外，「明確拒絕、判失敗、轉帳失敗退回」這條分支永遠到不了（MR !2373 的 commit body） |
| 5xx 或其他 4xx 帶拒絕碼也判 FAILED | 5xx 不自動做轉帳失敗退回；其他 4xx 可能來自 gateway 或 WAF，即使 body 帶拒絕碼也是結果不明（MR !2373 的 commit body） |
| 查單的 -90609 改成 TIMEOUT | 維持既有決定；交易剛送出時是否會短暫回 -90609 仍待三方確認（MR !2373 的 commit body） |
| 只在明確拒絕時判 FAILED（採用） | 無 |

## 後果

- Play8oy 線上的錯誤路徑跟著改變，成功路徑不變（MR !2373 的 commit body）。
- 拉注單第 2 頁起回「查無資料」視為三方資料不一致，拋例外讓時間片重跑（MR !2373 的 commit body）。
- 原則與代付的「只在能證明未受理時判明確拒絕」相同，見 [decisions/0014](0014-payout-reject-only-when-provably-unaccepted.md)。
