---
type: Decision
id: decisions/0060-make-vendor-stub-declares-callback-path
title: 無縫錢包腳手架預先宣告回調路徑，由守門測試擋下沒改的值
description: 用 `php artisan make:vendor` 產生的無縫錢包對接程式直接實作回調路徑契約並放佔位值，再由測試檢查每個宣告都符合格式、對得到已註冊的路由。
domain: 遊戲與遊戲商
tags: [make:vendor, 腳手架, stub, 回調路徑, HasCallbackPath, 單一錢包, 無縫錢包, 守門測試, 廠商管理]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Stubs/DriverSingleWallet.stub, title: "implements HasCallbackPath，預設回 /external/api/sw/TODO", at: 2026-10-22 }
  - { kind: code, ref: /app/Console/Commands/Maker/MakeVendorDriver.php }
  - { kind: test, ref: /tests/Unit/Vendors/DriverCallbackPathDeclarationTest.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2408 }
related: [rules/vendor-and-channel-management, decisions/0003-vendor-channel-and-callback-declared-by-driver]
decided_at: 2026-10-13
decision_status: accepted
---

# 無縫錢包腳手架預先宣告回調路徑，由守門測試擋下沒改的值

## 背景

後台「廠商管理」的回調路徑欄，靠對接程式實作回調路徑契約組出來（見 [decisions/0003](0003-vendor-channel-and-callback-declared-by-driver.md)）。既有 47 個無縫錢包對接程式都已宣告，但 `php artisan make:vendor` 產生的無縫錢包對接程式不帶這個契約；新接遊戲商漏掉時，回調欄只會顯示一條橫線，沒有任何地方提醒（MR !2408 的 commit body）。

## 決定

- 無縫錢包 stub 直接實作契約，預設回傳佔位值 `/external/api/sw/TODO`；命令建完多印一行警告（MR !2408 的 commit body）。
- 新增守門測試，對所有宣告回調路徑的對接程式檢查兩件事：格式符合後台改寫後綴用的規則且不含 TODO；去掉開頭斜線後能對到已註冊的路由（MR !2408 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| stub 不實作契約，只在命令輸出提醒 | 漏掉時一樣沒有任何東西會紅（MR !2408 的 commit body） |
| 命令依遊戲商名稱自動推導路徑 | 路由前綴不跟類別名稱走（例如 WFSW 對應 sw/wf、Astar 對應 sw/astar/gamepool），推導出來的值多半是錯的，反而看起來像填好了（MR !2408 的 commit body） |
| 測試直接引用請求驗證類別的格式規則 | 那條 regex 寫在 `rules()` 陣列內，沒有可引用的常數；在測試裡複寫並註明出處，不為了測試去改請求類別（MR !2408 的 commit body） |
| stub 帶佔位值，交給守門測試在 CI 擋下（採用） | 無 |

## 後果

- 轉帳錢包的 stub 不動，轉帳錢包沒有回調路徑（MR !2408 的 commit body）。
- 守門測試一次列出所有不合格的對接程式與後綴；`sw/pp` 這類前綴不會誤命中 `sw/pps…`（MR !2408 的 commit body）。
