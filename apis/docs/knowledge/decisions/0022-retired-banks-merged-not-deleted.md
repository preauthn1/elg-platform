---
type: Decision
id: decisions/0022-retired-banks-merged-not-deleted
title: 已合併或改名的銀行停用併入現行條目，不刪除
description: 標準銀行清單裡已合併或改名的銀行，舊條目留在分檔並標記併入現在那家；寫進 standard_banks 時設為停用、名稱與別名併入現行條目，金流商的代付銀行碼不動。
domain: 金流
tags: [標準銀行, standard_banks, 銀行別名, 合併, 改名, retired_into, sync_visible, StandardBankRegistry, cash-flow:sync, bank:sync-mappings, 代付銀行碼, 銀行, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/CashFlow/Bank/StandardBankRegistry.php, title: "retired_into 與 sync_visible 的規則", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2393 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2401 }
related: [rules/standard-bank-list]
decided_at: 2026-10-12
decision_status: accepted
---

# 已合併或改名的銀行停用併入現行條目，不刪除

## 背景

- 標準銀行清單（`app/Console/Commands/CashFlow/Data/banks/*.php`）裡有 31 家銀行已經合併或改名（IDR 30 家、VND 1 家），清單上卻還跟現在那家各列一條（MR !2401 的描述）。
- RB 的決策是：已合併或改名的銀行，舊名一律收進現在那家，舊條目退場（MR !2393 的描述）。

## 決定

舊條目留在清單分檔，加上 `retired_into` 指向現在那家；寫進 `standard_banks` 時這列設成停用，名稱與別名併進現在那家；金流商的代付銀行碼（`cash_flow_banks`）一個都不動。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 直接從清單分檔刪掉舊條目 | `cash-flow:sync` 讀的是清單分檔，每跑一次先停用該金流商所有對照列、再重新啟用比對得到的列。舊條目一刪，只靠別名才對得到的名稱（例如 TopPay 的「BPD Riau Dan Kepri」）會改對到另一列平台銀行，原本那列停在停用，綁那列的會員出款會查不到碼（MR !2401 的描述） |
| 一併收斂金流商新舊名稱各帶的碼 | 同一家金流商常把新舊名稱各列一筆、各帶一個碼，31 家金流商設定檔共 53 筆；要留哪個碼只能逐家查證，不能從名稱推（MR !2401 的描述） |
| 改名時連 `standard_name` 一起改 | `standard_name` 是各站平台銀行的名稱，`cash-flow:sync` 靠它找既有列，改了會多建一列平台銀行（MR !2401 的描述） |
| 加過渡欄位 `retired_into` 與 `sync_visible`，只改「這是哪一家銀行」這一層（採用） | 無 |

## 後果

- 兩個過渡欄位等各站舊名銀行列收成一列之後再拿掉（MR !2401 的描述）。
- 已經是別家現行條目的名稱不會被併進來，例如「Maybank Syariah」（MR !2401 的描述）。
- 部署時 seeder 失敗會寫 log、整批回滾並回傳 0，不擋部署；上線後要先確認 `standard_banks` 已有 31 列停用（MR !2393、!2401 的描述）。
- 舊版 seeder 不寫 status，回退發版不會把停用的列改回啟用；這次停用的 id 與 code 記在 log，是人工復原的依據（MR !2401 的描述）。
- 部署後要先跑 `bank:sync-mappings --repair --dry-run` 確認，再正式跑；代付查碼用的是 `bank_code`，不影響出款（MR !2401 的描述）。
