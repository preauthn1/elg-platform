---
type: Command
id: apis/console/fetch_bet_record
title: '拉取遊戲商注單並轉入 TBR'
description: '我方主動向遊戲商拉注單時，排程、命令列與 Olympus 頁各怎麼觸發，注單怎麼寫入。'
domain: 遊戲與遊戲商
tags:
  - fetch_bet_record
  - 拉取注單
  - TBR
  - 乾跑
  - Olympus
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Vendor/FetchBetRecordCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/Vendor/FetchBetRecordCommand.php
  signature: fetch_bet_record
  schedule:
    - '*/5 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan fetch_bet_record`

Command：`App\Console\Commands\Vendor\FetchBetRecordCommand`

拉取遊戲商注單並同步至 total_bet_records（派發 FetchVendorBetRecord Job；單一錢包只支援 --dry-run 比對）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--vendor` | 選項（帶值） | 指定 vendor_id（預設跑所有實作 ShouldScheduleBetRecordFetch 的遊戲商） |  |
| `--vendor-info` | 選項（帶值） | 指定 vendor_info id（需搭配 --vendor；預設該商全部啟用中商戶） |  |
| `--start` | 選項（帶值） | 開始時間，格式 yyyy-MM-dd@HH |  |
| `--end` | 選項（帶值） | 結束時間，格式 yyyy-MM-dd@HH |  |
| `--minutes` | 選項（帶值） | 拉取最近 N 分鐘 |  |
| `--date` | 選項（帶值） | 拉取指定日期（全天），格式 yyyy-MM-dd |  |
| `--direct` | 選項（旗標） | 同步跑完整個窗口不派發 queue（救援用，放寬至 SupportsBetRecordFetch） |  |
| `--update` | 選項（旗標） | 金額異動時更新既有注單列並重轉 TBR（預設純新增去重；取消單靠此生效） |  |
| `--dry-run` | 選項（旗標） | 乾跑模式，只打三方與 DB 比對、不寫入，列出將新增／金額異動的注單 |  |
| `--use-cache` | 選項（旗標） | 沿用 1 小時內快取的三方回應（乾跑後正式跑免重打三方；拉取失敗不進快取） |  |

排程：`*/5 * * * *`
<!-- knowledge:generated end -->

## 做什麼

排程每 5 分鐘自動對已加入排程的遊戲商（目前是 ACE333、SunCity）派發拉單任務，向遊戲商查詢一段時間內的注單寫進該遊戲商自己的注單表並轉成 TBR；也能在命令列指定遊戲商同步跑完整個時間窗口，或加乾跑模式只比對三方與我方資料的差異、不寫入。無縫錢包遊戲商的注單已由回調即時記帳，這支命令對它們只能乾跑比對，不會重複入帳。規則見相關規則。

## 相關規則

* [rules/bet-record-pull](../../rules/bet-record-pull.md)
