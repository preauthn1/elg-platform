## 主旨

無工單：調整代付回調的除錯訊息等級，避免正式環境因為 log level 設太低而被洗版，屬於自發的小修，未開立需求單。

## 驗收進度

無

## 變更

- `app/Services/Payment/Providers/XxxPay/WithdrawalCallback.php`：回調收到的除錯訊息改用 `info` 等級，取代原本的 `debug`。

## 設計決定

無

## 跟原計畫不同的地方

無

## 測試

`docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test --compact tests/Feature/Payment/XxxPayWithdrawalCallbackTest.php"`：12 項全部通過，沒有新增測試，原有的 12 項照樣通過。

本機測試庫的三方 API 皆為假回應。

## 上線步驟

無，只調整 log 等級，不需要額外動作。

## 待決與後續

無

## 報告資料

```yaml
type: Task Report
title: "MR fix(payment): 代付回調除錯訊息收斂為 info 等級"
description: 調整代付回調的除錯訊息等級，避免正式環境因為 log level 設太低而被洗版。
tags: [logging, payout]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-19T15:30:00+08:00" }
sources: []
project: target/gffv3/apis
tickets: []
commits: [d4e5f6a]
mergedAt: null
release: none
tests: { command: "docker exec php-fpm8 bash -c 'cd /var/www/APIs && php artisan test --compact tests/Feature/Payment/XxxPayWithdrawalCallbackTest.php'", total: 12, added: 0, failed: 0 }
acceptance: []
decisions: []
planChanges: []
openItems: []
```

🤖 Generated with [Claude Code](https://example.invalid/claude-code)
