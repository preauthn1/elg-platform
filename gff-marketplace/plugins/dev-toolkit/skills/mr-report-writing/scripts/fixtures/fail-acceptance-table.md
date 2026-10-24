<!-- expect: 驗收進度」的表頭 -->
## 主旨

實作 [#4137](http://example.invalid:1688/target/tickets/-/work_items/4137)：代付建立訂單與扣款不同交易導致金額可能不一致，改為同一交易內完成並補齊回調冪等。

## 驗收進度

| 預期結果 | 規則 | 自動 | 備註 |
|---|---|---|---|
| 代付建立訂單與扣款在同一個資料庫交易內完成，扣款失敗時訂單不會建立 | rules/payout-submit-and-status#R-7 | ✅ `tests/Feature/Payment/PayoutTransactionBoundaryTest.php` | |
| 代付回調處理具備冪等性，同一筆回調重複送達不會重複入帳 | rules/payout-submit-and-status#R-7 | ✅ `tests/Feature/Payment/PayoutTransactionBoundaryTest.php` | |
| 既有代付商戶的扣款金額與匯率換算結果不受本次改動影響 | 無 | 無 | 需實際站點手動驗證 |

手動驗收要等 QA 在 vplus33 測試站實際送出一筆代付、確認扣款金額與匯率換算結果一致後才能進行。

## 變更

- `app/Services/Payment/Providers/XxxPay/Withdrawal.php`：建立代付訂單與扣款整合進單一資料庫交易，扣款失敗時訂單隨之回滾，不再殘留佔號訂單。
- `app/Services/Payment/Providers/XxxPay/WithdrawalCallback.php`：改用 `findAndLockWithdrawalOrder()` 搭配 `checkWithdrawalOrderStatus()`，重複回調直接拋 `WithdrawalOrderException(200)`。
- `app/Services/Payment/Providers/YyyPay/Withdrawal.php`：比照 XxxPay 補上同一筆交易邊界。
- `app/Services/Payment/Providers/ZzzPay/Withdrawal.php`：比照 XxxPay 補上同一筆交易邊界。
- `app/Services/Payment/PayoutTrait.php`（新檔）：抽出代付交易邊界與回調冪等的共用邏輯，供三家金流商復用。
- `docs/knowledge/rules/payout-submit-and-status.md`（新檔）：補齊代付事務邊界規則，規則 ID 是 `rules/payout-submit-and-status#R-7`。
- `tests/Feature/Payment/PayoutTransactionBoundaryTest.php`（新檔）：涵蓋交易回滾與回調冪等兩條驗收。

## 設計決定

| 決定 | 理由 | 放棄的做法 |
|---|---|---|
| 建立代付訂單與扣款包進同一個 `DB::transaction()` | 避免扣款成功但訂單建立失敗（或反之）造成金額不一致；三方 API 呼叫本身排除在交易外，符合禁止把三方呼叫放進事務的規則 | 分成兩次個別寫入後靠排程做事後對帳，對帳有延遲，期間玩家餘額會短暫不一致 |
| 回調冪等改用 `findAndLockWithdrawalOrder()` 搭配 `checkWithdrawalOrderStatus()` | 跟代收回調共用同一套鎖定與狀態檢查慣例，降低日後維護心智負擔 | 沿用舊的「先查後判斷」寫法，在高併發下有競態風險，重複回調可能重複入帳 |
| 共用邏輯抽成 `PayoutTrait` 而非複製進每個金流商 | 本次改動影響三家金流商的 `Withdrawal.php`，抽共用 trait 可避免三份幾乎相同的程式碼各自漂移 | 直接在三個 `Withdrawal.php` 分別複製貼上修改，之後規則異動要改三處，容易漏改 |

## 跟原計畫不同的地方

- **納入 YyyPay、ZzzPay**：原計畫只改 XxxPay 一家金流商，實作時發現另外兩家金流商有相同的扣款順序問題，一併納入本次改動範圍。
- **追加 `PayoutTrait`**：因三家金流商都要改，原計畫沒有規劃共用層，實作中追加抽出共用 trait，避免三份程式碼各自漂移。

## 測試

`docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test --compact tests/Feature/Payment/PayoutTransactionBoundaryTest.php"`：8 項全部通過，其中 5 項是新加的。原有的 3 項照樣通過。

新增的測試：

- `tests/Feature/Payment/PayoutTransactionBoundaryTest.php`（5 項）
  - 扣款失敗時訂單不會建立
  - 扣款成功時訂單狀態正確寫入
  - 重複回調只入帳一次
  - 回調早於扣款完成時正確等待鎖
  - 非白名單 IP 的回調直接拒絕

原有的 `WithdrawalServiceTest` 3 項照樣通過，不逐項列出。

本機測試庫的三方 API 皆為假回應，未覆蓋實際金流商網路逾時情境。

## 上線步驟

1. 發版後，請各站（VWorld、TPower、Yes2Win、Alfawin77、Me77、VPlus33）維運依既有排程手動觸發該站代付服務的重啟，套用新的交易邊界邏輯。
2. 重啟後，對任一代付商戶手動送出一筆小額代付，確認訂單狀態與扣款金額一致後再開放該站正常出款。

## 待決與後續

- 代付狀態查詢 API 的交易邊界尚未檢視，留待下一張單處理，詳見 [#4199](http://example.invalid:1688/target/tickets/-/work_items/4199)。

## 報告資料

```yaml
type: Task Report
title: "MR refactor(payment): 代付建立訂單與扣款交易邊界改造"
description: 代付建立訂單與扣款不同交易導致金額可能不一致，改為同一交易內完成並補齊回調冪等。
tags: [4137, payout, transaction-boundary]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-19T14:00:00+08:00" }
sources:
  - { id: "tickets#4137", resource: "http://example.invalid:1688/target/tickets/-/work_items/4137", title: "代付事務邊界改造" }
project: target/gffv3/apis
tickets: ["tickets#4137"]
commits: [a1b2c3d, b2c3d4e, c3d4e5f]
mergedAt: null
release: null
tests: { command: "docker exec php-fpm8 bash -c 'cd /var/www/APIs && php artisan test --compact tests/Feature/Payment/PayoutTransactionBoundaryTest.php'", total: 8, added: 5, failed: 0 }
acceptance:
  - { item: "代付建立訂單與扣款在同一個資料庫交易內完成，扣款失敗時訂單不會建立", rule: "rules/payout-submit-and-status#R-7", automated: covered, manual: pending }
  - { item: "代付回調處理具備冪等性，同一筆回調重複送達不會重複入帳", rule: "rules/payout-submit-and-status#R-7", automated: covered, manual: pending }
  - { item: "既有代付商戶的扣款金額與匯率換算結果不受本次改動影響", rule: null, automated: none, manual: pending }
decisions:
  - { id: D-4137-1, summary: "建立代付訂單與扣款包進同一個 DB::transaction()", rejected: "分成兩次個別寫入後靠排程做事後對帳", supersedes: null }
  - { id: D-4137-2, summary: "回調冪等改用 findAndLockWithdrawalOrder() 搭配 checkWithdrawalOrderStatus()", rejected: "沿用舊的「先查後判斷」寫法", supersedes: null }
  - { id: D-4137-3, summary: "共用邏輯抽成 PayoutTrait 而非複製進每個金流商", rejected: "直接在三個 Withdrawal.php 分別複製貼上修改", supersedes: null }
planChanges:
  - "原計畫只改 XxxPay 一家金流商，實作時發現 YyyPay、ZzzPay 有相同問題，一併納入"
  - "因三家金流商都要改，追加把共用邏輯抽成 PayoutTrait"
openItems:
  - "代付狀態查詢 API 的交易邊界尚未檢視，留待下一張單處理"
```

🤖 Generated with [Claude Code](https://example.invalid/claude-code)
