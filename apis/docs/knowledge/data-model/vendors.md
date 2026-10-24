---
type: Table
id: data-model/vendors
title: 遊戲商
description: 'vendors 是遊戲商主檔，記錄每家遊戲商的拆帳比例、有效投注轉換比例與無縫錢包設定。'
domain: 遊戲與遊戲商
tags:
  - vendors
  - 遊戲商
  - 拆帳比例
  - 無縫錢包
  - 廠商管理
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/Vendor.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_vendors_table.php
entity:
  table: vendors
  model: App\Models\Vendor
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 系統編號 }
    - { name: name, type: varchar(50), nullable: false, comment: 遊戲商名稱 }
    - { name: code, type: varchar(32), nullable: false, comment: 遊戲商表格前綴 }
    - { name: tname, type: varchar(32), nullable: true, comment: agent後台會用tname來找對應的xxx_accounts }
    - { name: third_party_code, type: varchar(20), nullable: true, comment: '三方代碼; 如果是聚合遊戲商, 就用同一個代碼' }
    - { name: rate, type: 'decimal(11,5)', nullable: false, comment: 平台點數與遊戲點數的比例 }
    - { name: percentage, type: 'decimal(11,5)', nullable: false, comment: 拆帳比例 }
    - { name: image, type: varchar(191), nullable: false, comment: 遊戲商圖片 }
    - { name: app_icon, type: varchar(191), nullable: true, comment: 遊戲商app的圖示 }
    - { name: info, type: text, nullable: false, comment: API使用資訊 }
    - { name: is_free, type: tinyint(1), nullable: false, comment: 是否為免盤費商戶 }
    - { name: is_new, type: tinyint(1), nullable: false, comment: '是否顯示新遊戲商標籤0:不顯示,1:顯示' }
    - { name: is_hot, type: tinyint(1), nullable: false, comment: '是否顯示熱門遊戲商標籤0:不顯示,1:顯示' }
    - { name: is_event, type: tinyint(1), nullable: false, comment: '是否顯示活動遊戲商標籤0:不顯示,1:顯示' }
    - { name: need_login, type: tinyint(1), nullable: false, comment: '是否需另外登入0:不需要1:需要' }
    - { name: only_one_game, type: tinyint(1), nullable: true, comment: 只有一款遊戲 }
    - { name: is_single_wallet, type: tinyint, nullable: true, comment: 是否為單一錢包遊戲 }
    - { name: is_slow, type: tinyint(1), nullable: false, comment: '遊戲商API回應是否較慢，0:否、1:是' }
    - { name: event_lock, type: tinyint(1), nullable: false, comment: '是否可以參加鎖分活動0:不可，1:可以' }
    - { name: show_on_mobile, type: tinyint(1), nullable: false, comment: 'Mobile版是否顯示0:不顯示，1:顯示' }
    - { name: show_on_pc, type: tinyint(1), nullable: false, comment: 'PC版是否顯示0:不顯示，1:顯示' }
    - { name: show_on_app, type: tinyint, nullable: false, comment: 'APP版是否顯示 0:不顯示，1顯示' }
    - { name: show_on_profit_share, type: tinyint(1), nullable: false, comment: 佔成會員是否顯示 }
    - { name: calc_on_profit_share, type: tinyint(1), nullable: false, comment: 佔成會員是否計算至佔成(目前4D跟M9BET排除) }
    - { name: open_window, type: tinyint, nullable: false, comment: '是否另開視窗; 0:不另開, 1:另開' }
    - { name: summary_daily, type: tinyint, nullable: false, comment: 'summary時間; 0:不計算, 1:每小時, 2:每天' }
    - { name: whitelist, type: text, nullable: true, comment: '白名單; ip1,ip2,ip3,ip4,...' }
    - { name: callback_path, type: varchar(191), nullable: true, comment: '無縫錢包回調路徑後綴；NULL 代表用 Driver 預設' }
    - { name: valid_bet_rate, type: 'decimal(4,2)', nullable: false, comment: 有效投注轉換比例 }
    - { name: calc_flow_limit, type: tinyint, nullable: true, comment: 是否計算稽核流水 }
    - { name: remark, type: varchar(191), nullable: true, comment: 備註 }
    - { name: memo, type: text, nullable: true }
    - { name: calc_time_zone, type: tinyint, nullable: false, comment: 後台跟三方後台的計算時區 }
    - { name: unbind, type: tinyint(1), nullable: false, comment: '是否可以解綁; 0:否, 1:是' }
    - { name: sort, type: int, nullable: true, comment: 排序 }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:關閉,1:正常' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
  read_by:
    - App\Models\Vendor
  written_by:
    - App\Models\Vendor
---

<!-- knowledge:generated start -->
## 表

`vendors`

Model：`App\Models\Vendor`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 系統編號 |
| `name` | varchar(50) | 否 | 遊戲商名稱 |
| `code` | varchar(32) | 否 | 遊戲商表格前綴 |
| `tname` | varchar(32) | 是 | agent後台會用tname來找對應的xxx_accounts |
| `third_party_code` | varchar(20) | 是 | 三方代碼; 如果是聚合遊戲商, 就用同一個代碼 |
| `rate` | decimal(11,5) | 否 | 平台點數與遊戲點數的比例 |
| `percentage` | decimal(11,5) | 否 | 拆帳比例 |
| `image` | varchar(191) | 否 | 遊戲商圖片 |
| `app_icon` | varchar(191) | 是 | 遊戲商app的圖示 |
| `info` | text | 否 | API使用資訊 |
| `is_free` | tinyint(1) | 否 | 是否為免盤費商戶 |
| `is_new` | tinyint(1) | 否 | 是否顯示新遊戲商標籤0:不顯示,1:顯示 |
| `is_hot` | tinyint(1) | 否 | 是否顯示熱門遊戲商標籤0:不顯示,1:顯示 |
| `is_event` | tinyint(1) | 否 | 是否顯示活動遊戲商標籤0:不顯示,1:顯示 |
| `need_login` | tinyint(1) | 否 | 是否需另外登入0:不需要1:需要 |
| `only_one_game` | tinyint(1) | 是 | 只有一款遊戲 |
| `is_single_wallet` | tinyint | 是 | 是否為單一錢包遊戲 |
| `is_slow` | tinyint(1) | 否 | 遊戲商API回應是否較慢，0:否、1:是 |
| `event_lock` | tinyint(1) | 否 | 是否可以參加鎖分活動0:不可，1:可以 |
| `show_on_mobile` | tinyint(1) | 否 | Mobile版是否顯示0:不顯示，1:顯示 |
| `show_on_pc` | tinyint(1) | 否 | PC版是否顯示0:不顯示，1:顯示 |
| `show_on_app` | tinyint | 否 | APP版是否顯示 0:不顯示，1顯示 |
| `show_on_profit_share` | tinyint(1) | 否 | 佔成會員是否顯示 |
| `calc_on_profit_share` | tinyint(1) | 否 | 佔成會員是否計算至佔成(目前4D跟M9BET排除) |
| `open_window` | tinyint | 否 | 是否另開視窗; 0:不另開, 1:另開 |
| `summary_daily` | tinyint | 否 | summary時間; 0:不計算, 1:每小時, 2:每天 |
| `whitelist` | text | 是 | 白名單; ip1,ip2,ip3,ip4,... |
| `callback_path` | varchar(191) | 是 | 無縫錢包回調路徑後綴；NULL 代表用 Driver 預設 |
| `valid_bet_rate` | decimal(4,2) | 否 | 有效投注轉換比例 |
| `calc_flow_limit` | tinyint | 是 | 是否計算稽核流水 |
| `remark` | varchar(191) | 是 | 備註 |
| `memo` | text | 是 |  |
| `calc_time_zone` | tinyint | 否 | 後台跟三方後台的計算時區 |
| `unbind` | tinyint(1) | 否 | 是否可以解綁; 0:否, 1:是 |
| `sort` | int | 是 | 排序 |
| `status` | tinyint | 否 | 狀態 0:關閉,1:正常 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
<!-- knowledge:generated end -->

## 做什麼

這張表是遊戲商主檔，一列代表一家遊戲商，記錄名稱、拆帳比例（平台與遊戲商之間分收益的比例）、有效投注轉換比例、是否為無縫錢包、回調路徑後綴等設定。代理後台「廠商管理」頁、注單彙總與各家對接程式都讀這張表決定遊戲商層級的行為。新遊戲商由 `php artisan app:upgrade` 補進資料庫，Olympus 不能新增或刪除，只能停用。規則見相關規則。

## 相關規則

* [rules/vendor-and-channel-management](../rules/vendor-and-channel-management.md)
