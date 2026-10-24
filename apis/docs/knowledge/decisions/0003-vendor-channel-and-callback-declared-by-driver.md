---
type: Decision
id: decisions/0003-vendor-channel-and-callback-declared-by-driver
title: 接入通道與回調路徑由 Driver 宣告
description: 遊戲商的接入通道與回調路徑預設由 Driver 自己宣告，不另存對照表。
domain: 遊戲與遊戲商
tags: [遊戲商, Driver, 接入通道, 回調路徑, vendor, config/vendor.php, 聚合遊戲商]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /config/vendor.php, title: "vendor id → Driver class 註冊表", at: 2026-10-21 }
  - { kind: code, ref: /app/Vendors/BaseAbstractVendor.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2408 }
decided_at: 2026-10-06
decision_status: accepted
---

# 接入通道與回調路徑預設由 Driver 宣告，不另存對照表

後台「廠商管理」要顯示每家遊戲商的接入通道（經哪家聚合商，或直連）、有無 gamelist API、回調路徑。這些事實只有 apis 的 Driver 知道，而 `config/vendor.php` 只記 vendor id 對應到哪個 Driver class：

- 從同一個 Driver 掛幾個 id，推不出它是不是「集成」（經聚合商接入）。例如 WudiSW、FumaSW 的本體加上 FREE 變體共用同一個 Driver，卻是直連；Titan 的 C93、L4 各有獨立的 Driver，卻屬於 TitanHub。
- external 路由的 prefix 與 vendor id 也沒有程式上的對應。

2026-10 決定：

- 集成的 Driver 自己宣告接入通道名稱。
- 無縫錢包的 Driver 自己宣告預設的回調路徑後綴。
- 沒有宣告，就是直連，或沒有預設回調路徑。
- 接入通道不開放在後台人工覆寫。
- 回調路徑後綴可以在 `vendors` 的新欄位覆寫，清空就退回 Driver 的預設。

## 考慮過的選項

- **照「同一個 Driver 掛多個 id 就是集成」的字面規則推導**：不需要任何宣告，但上線當下就有 4 家與運營表不符。
- **字面規則加例外名單**：兩套規則疊加，新接遊戲商時最容易漏改。
- **沿用 `vendors.third_party_code`**：它已把聚合商的子遊戲商標成同一個代碼（EVO、TTHSW、TOP55SW、GMSW、target、PGSSW、DCT），但它的語意是「共用同一套 API 與帳號池」。WUDISW、FUMASW、PP 的本體與 FREE 變體也是同一個代碼，拿來判斷集成，會把直連標成集成。而且它是後台人工維護的欄位，沒有程式檢查。
- **用一次性 seeder 把對照表寫進 DB**：143 家無縫錢包要逐一對過，之後新接遊戲商要記得補資料，DB 與程式碼會變成兩個事實來源。

## 後果

- 新接遊戲商時，接入通道與預設回調路徑在 Driver 裡一次定好，列表自動正確。聚合商的子遊戲商共用 Driver，自動繼承。
- 推導結果與運營表不符時，回報後改 Driver，不在後台改。
- 沒掛 Driver 的舊遊戲商（dev 庫 26 家），三欄都顯示一條橫線當佔位符，反映 apis 不認識它，不假裝成直連。
