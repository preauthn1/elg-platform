---
type: Rule
id: rules/vendor-and-channel-management
title: 遊戲商管理與接入通道
description: 代理後台「廠商管理」頁顯示哪些遊戲商資訊、回調路徑誰能看誰能改，以及新增遊戲商與聚合商子遊戲商時系統怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商與商戶
tags: [遊戲商, 廠商管理, 接入通道, 通道, 聚合商, 集成, 直連, 回調路徑, gamelist, 新增遊戲商, 子遊戲商, EliteHub, TTHSW, TitanHub, target, vendor, channel, callback path, 代理, callback, callback_path, api_external_url, vendor management]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:58+08:00" }
evidence:
  - kind: code
    ref: /app/Services/Agent/VendorManagementService.php
    title: 廠商管理列表與回調路徑更新
    at: 2026-10-22
  - kind: code
    ref: /app/Services/Vendor/VendorDriverCapabilityService.php
    title: 從遊戲商對接程式推導接入通道、gamelist API、預設回調路徑
  - kind: code
    ref: /database/seeders/SyncVendorSeeder.php
    title: 升級時補進新遊戲商主檔
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2408
    title: 廠商管理接入通道、gamelist API、回調路徑
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2330
    title: EliteHub 新增 28 家子遊戲商
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2219
    title: EliteHub 新增 NextSpin、Spade Gaming、Joker
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2375
    title: EliteHub 新增 WUDI、FUMA、Ideal Gaming
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4400
    title: 廠商管理顯示接入通道與回調路徑
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4374
    title: EliteHub 28 家子遊戲商
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4273
    title: EliteHub NextSpin
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4272
    title: EliteHub Spade Gaming
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4262
    title: EliteHub Joker
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4456
    title: EliteHub WUDI
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4457
    title: EliteHub FUMA
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4474
    title: EliteHub Ideal Gaming
entities:
  - api: "POST agent/api/vendor-management/list"
  - api: "POST agent/api/vendor-management/callback-path"
  - command: app:upgrade
  - command: make:vendor
  - class: 'App\Http\Controllers\Agent\VendorManagementController'
  - class: 'App\Services\Agent\VendorManagementService'
  - class: 'App\Services\Vendor\VendorDriverCapabilityService'
  - class: 'App\Http\Requests\Agent\VendorManagement\UpdateCallbackPathRequest'
  - class: 'App\Enums\Vendor\VendorChannelEnum'
  - class: 'App\Vendors\Contracts\HasAggregatorChannel'
  - class: 'App\Vendors\Contracts\HasCallbackPath'
  - class: 'App\Policies\VendorPolicy'
  - class: 'Database\Seeders\SyncVendorSeeder'
  - table: vendors
related:
  - rules/vendor-account-pool
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

遊戲商的基本資料存在我方的遊戲商主檔。有些資訊主檔裡沒有，只有遊戲商的對接程式（每家一支，下稱「對接程式」）知道，例如它經由哪一家聚合商接入（接入通道）、有沒有提供拉遊戲清單的 API（gamelist API）、無縫錢包遊戲商的回調路徑後綴預設是什麼。回調路徑是無縫錢包遊戲商發回調給我方時打的完整網址，由當站 apis 網域加上這家遊戲商專屬的回調路徑後綴組成。代理後台的「廠商管理」頁把這兩邊的資訊合在一起顯示，營運不必再查試算表。

新增遊戲商（包括聚合商底下的子遊戲商）不在後台操作，而是由 RD 在程式裡登記，部署後執行升級命令把主檔補進資料庫。

本份目前收錄的範圍：代理後台「廠商管理」頁的列表與回調路徑後綴編輯、接入通道怎麼判定、新增遊戲商與聚合商子遊戲商時主檔怎麼進資料庫。Olympus「遊戲商管理」頁的編輯規則、遊戲商的拆帳比例與維護時段不在本份。預建玩家帳號見 [rules/vendor-account-pool](vendor-account-pool.md)。

## 怎麼觸發

- 代理後台：「新遊戲商管理」底下的「廠商管理」頁。打開頁面時查詢列表；admin 角色的帳號可在同一頁修改回調路徑後綴。
- 新增遊戲商：RD 用 `php artisan make:vendor` 產生對接程式骨架，在程式裡登記遊戲商後部署，再執行 `php artisan app:upgrade` 把主檔補進資料庫。

## 規則

### 廠商管理列表

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 代理帳號沒有「廠商管理」選單權限時，查列表與改回調路徑後綴都回「權限不足」。 | code:VendorManagementController::list、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_rejects_agent_without_menu_permission、mr:!2408 |
| R-2 | 列表列出全部遊戲商，不論狀態是開啟或關閉，依最後更新時間由新到舊排序。 | code:App\Services\Agent\VendorManagementService::list、mr:!2408 |
| R-3 | 每一列除了主檔欄位，另外顯示錢包模式（無縫錢包或轉帳錢包）、接入通道、有沒有 gamelist API。後兩項由該遊戲商的對接程式宣告，不需要人工維護。 | code:App\Services\Agent\VendorManagementService::list、code:VendorDriverCapabilityService::channel、code:VendorDriverCapabilityService::hasGameList、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities、mr:!2408 |
| R-4 | 遊戲商沒有登記對接程式時，接入通道與有沒有 gamelist API 都顯示為空，代表無從得知，不是「沒有」。 | code:VendorDriverCapabilityService::driverClass、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities |
| R-5 | 接入通道的篩選選項只列出目前至少有一家遊戲商用到的通道。「direct」（直連）排第一，其餘依 TitanHub、Top55SW、EliteHub、GMSW、target、PGSSW、DCT 的順序排列。 | code:VendorDriverCapabilityService::availableChannels、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities |

### 接入通道

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 對接程式宣告了經由哪一家聚合商，接入通道就是那家聚合商的對外名稱；沒有宣告的一律是直連，顯示「direct」。 | code:VendorDriverCapabilityService::channel、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities、mr:!2408 |
| R-7 | 接入通道不看「同一支對接程式掛了幾家遊戲商」。WudiSW、FumaSW 與各自的免盤費商戶共用同一支對接程式，但都是直連；Connect93、Live22 各有自己的對接程式，但都屬於 TitanHub。 | code:HasAggregatorChannel::aggregatorChannel、mr:!2408 |

### 回調路徑後綴

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 回調路徑後綴只適用無縫錢包且有登記對接程式的遊戲商。轉帳錢包、沒登記對接程式的遊戲商不顯示回調路徑後綴。 | code:App\Services\Agent\VendorManagementService::list、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities、mr:!2408 |
| R-9 | 後台有填回調路徑後綴時顯示填的值；沒填時顯示對接程式宣告的預設值。完整的回調路徑是當站對外 API 網域接上回調路徑後綴。 | code:App\Services\Agent\VendorManagementService::list、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_overrides_and_resets、mr:!2408 |
| R-10 | 無縫錢包遊戲商的對接程式沒有宣告預設回調路徑後綴、後台也沒填時，回調路徑後綴與完整的回調路徑都是空的。 | code:VendorDriverCapabilityService::defaultCallbackPath、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_list_derives_driver_capabilities |
| R-11 | 只有角色為 admin 的代理帳號看得到回調路徑後綴。其他角色有選單權限仍可查列表，但列表不帶任何回調路徑後綴資訊，也不帶回調路徑的網域。 | code:App\Services\Agent\VendorManagementService::canManageCallback、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_non_admin_list_hides_callback_fields、mr:!2408 |
| R-12 | 只有角色為 admin 的代理帳號可以改回調路徑後綴，其他角色送出修改回「權限不足」，不寫入。 | code:VendorManagementController::updateCallbackPath、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_non_admin_cannot_update_callback_path、mr:!2408 |
| R-13 | 修改回調路徑後綴時，回調路徑後綴必須帶上。值可以留空，留空代表清掉後台填的值，改回顯示對接程式的預設值；只帶遊戲商、不帶回調路徑後綴會被驗證擋下，原本填的值不變。 | code:UpdateCallbackPathRequest::rules、code:App\Services\Agent\VendorManagementService::updateCallbackPath、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_overrides_and_resets、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_requires_callback_path_key |
| R-14 | 回調路徑後綴由一段或多段「斜線加英數字、底線、連字號」組成，最長 191 字。不以斜線開頭、結尾多一個斜線、含點、問號、井號、百分比編碼、反斜線或空段的，都被驗證擋下，不寫入。 | code:UpdateCallbackPathRequest::rules、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_rejects_invalid_format、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_accepts_multi_segment_path |
| R-15 | 對轉帳錢包或沒登記對接程式的遊戲商改回調路徑後綴會被拒絕，不寫入；遊戲商編號不存在時回「遊戲商不存在」。 | code:App\Services\Agent\VendorManagementService::updateCallbackPath、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_rejects_transfer_wallet |
| R-16 | 回調路徑後綴改成功後，遊戲商主檔的最後操作人記為這次修改的代理帳號。 | code:App\Services\Agent\VendorManagementService::updateCallbackPath、test:Tests\Feature\Agent\VendorManagement\VendorManagementTest::test_update_callback_path_overrides_and_resets |
| R-17 | 用 `make:vendor` 建立無縫錢包遊戲商時，骨架會帶一個待填的預設回調路徑後綴，並提示 RD 改成這家在回調路由註冊的路徑。 | command:make:vendor、mr:!2408 |

### 新增遊戲商與聚合商子遊戲商

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 新遊戲商的主檔由升級命令補進資料庫。升級只新增資料庫裡還沒有的遊戲商編號，已經存在的遊戲商不會被覆寫。 | command:app:upgrade、code:SyncVendorSeeder::run、mr:!2330、mr:!2375 |
| R-19 | 升級補進新遊戲商時，名稱或代碼空白的那一筆不寫入；沒有指定的欄位用預設值，其中狀態預設為開啟。 | code:SyncVendorSeeder::isValid、code:SyncVendorSeeder::formatInsertData |
| R-20 | Olympus 不能新增或刪除遊戲商。新增走升級命令，不再使用的遊戲商以停用取代刪除，因為歷史注單靠遊戲商編號對回遊戲商。 | code:VendorPolicy、test:Tests\Feature\Olympus\VendorManagementTest::test_delete_and_create_are_denied |
| R-21 | 聚合商底下新增的子遊戲商與聚合商共用同一支對接程式，接入通道因此顯示為那家聚合商。EliteHub 的子遊戲商共用同一張玩家帳號表與同一張注單表。 | code:VendorDriverCapabilityService::channel、code:App\Vendors\TTHSW\Driver::aggregatorChannel、mr:!2219、mr:!2330、mr:!2375 |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| admin 角色在廠商管理頁看一家 EliteHub 的子遊戲商，後台沒填回調路徑後綴 | 接入通道顯示 EliteHub；回調路徑後綴顯示對接程式的預設值，完整的回調路徑是當站對外 API 網域接上該後綴 | R-6、R-9、R-21 |
| 一般角色帳號打開廠商管理頁 | 看得到接入通道與 gamelist API，看不到回調路徑後綴 | R-3、R-11 |
| admin 把回調路徑後綴清成空白後送出 | 後台填的值被清掉，列表改回顯示預設值 | R-13 |
| admin 嘗試替一家轉帳錢包遊戲商填回調路徑後綴 | 被拒絕，不寫入 | R-15 |
| 部署了新的子遊戲商但還沒跑升級命令 | 資料庫沒有這家的主檔，列表不會出現 | R-18 |

## 還沒確認的事

- 後台填的回調路徑後綴目前只用於列表顯示。遊戲商實際打進來的路由由程式決定，後台改了值不會改變路由；營運是否知道這一點，待 PM 確認畫面上要不要註明（來源：讀程式碼推得，`target/tickets#4400` 未載明）。
