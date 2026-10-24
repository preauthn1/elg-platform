---
type: Rule
id: rules/game-list-sync
title: 同步遊戲商遊戲清單
description: 向遊戲商抓遊戲清單、把新遊戲寫進我方時，哪些遊戲商會被處理、新遊戲寫成什麼樣、遊戲商下架的遊戲怎麼提示，以及各家遊戲商的收錄規則。
domain: 遊戲與遊戲商
subdomain: 遊戲與類別
tags: [遊戲清單, 遊戲, 遊戲商, 排程, Olympus, 系統操作, 更新遊戲清單, update_game_list, MetaGamingSW, PlayTech, PTSW, SMA, Ebet, DCT, TitanHub, target, 聚合商, game list, sync game list, update game list]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:39:17+08:00" }
evidence:
  - { kind: code, ref: /app/Services/GameListSyncService.php, title: 遊戲清單同步服務, at: 2026-10-22 }
  - { kind: code, ref: /app/Console/Commands/Schedule/UpdateVendorGameList.php, title: update_game_list 命令 }
  - { kind: code, ref: /app/Jobs/SyncVendorGameList.php, title: 單一遊戲商同步的背景工作 }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/UpdateGameList.php, title: Olympus 更新遊戲清單頁 }
  - { kind: code, ref: /app/Vendors/PlayTechSW/GameListSheet.php, title: PlayTech 官方活頁簿解析 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2321, title: MetaGamingSW 改以代碼格式過濾，四條產品線全數收錄 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2320, title: MetaGamingSW 改以產品線代碼過濾 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2099, title: PlayTech 遊戲清單改由官方活頁簿抓取 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2101, title: PlayTech 來源網址自動改寫為下載直鏈 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2279, title: SMA、Ebet、DCT 聚合遊戲清單同步遷入並納入每小時排程 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2083, title: Titan 與 target 聚合遊戲清單同步遷入 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2316, title: 遊戲代碼去掉前後空白再比對 }
  - { kind: ticket, ref: http://example.invalid:1688/target/op_tickets/-/work_items/3347, title: MetaGamingSW 遊戲清單收錄範圍 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4405, title: SMA、Ebet、DCT 遊戲清單同步遷入 APIs }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4034, title: Titan 與 target 遊戲清單同步遷入 APIs }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4423, title: 遊戲代碼前後空白造成重複新增 }
entities:
  - { command: update_game_list }
  - { class: "App\\Console\\Commands\\Schedule\\UpdateVendorGameList" }
  - { class: "App\\Jobs\\SyncVendorGameList" }
  - { class: "App\\Services\\GameListSyncService" }
  - { class: "App\\Filament\\Admin\\Pages\\Operations\\UpdateGameList" }
  - { class: "App\\Vendors\\MetaGamingSW\\Driver" }
  - { class: "App\\Vendors\\PlayTechSW\\Driver" }
  - { class: "App\\Vendors\\PlayTechSW\\GameListSheet" }
  - { class: "App\\Vendors\\Sma\\Driver" }
  - { class: "App\\Vendors\\Ebet\\Driver" }
  - { class: "App\\Vendors\\Dct\\Driver" }
  - { class: "App\\Vendors\\Evo\\Driver" }
  - { class: "App\\Vendors\\target\\Driver" }
  - { table: games }
  - { table: vendor_info_games }
  - { table: agent_games }
  - { table: game_names }
---

## 這個主題在講什麼

我方的遊戲清單要跟遊戲商同步：遊戲商上架新遊戲時，我方要把它寫進遊戲主檔，並建好遊戲商戶與代理的關聯，客服才能在後台開放給會員。同步只新增遊戲，不改既有遊戲；遊戲商下架的遊戲也不會自動關閉，只列出來提示人工處理。

本份目前收錄的範圍：

- 所有遊戲商共用的同步流程：排程、Olympus 手動觸發、寫入方式、「三方未提供」偵測。
- 各家的收錄規則，只寫 MetaGamingSW、PlayTech、SMA、Ebet、聚合商 DCT、聚合商 TitanHub、聚合商 target 這幾家。其他有抓清單能力的遊戲商還沒整理。

## 怎麼觸發

- 排程：每小時第 12 分執行 `update_game_list`，每家遊戲商各派一個背景工作。
- Olympus：「系統操作」底下的「更新遊戲清單」頁，選遊戲商後送出。
- 命令列：`update_game_list --vendor-id=<遊戲商 ID> --direct`，加 `--dry-run` 是乾跑；PlayTech 另外要帶 `--sheet-url=<活頁簿連結>`。

## 規則

### 觸發與處理範圍

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 排程每小時第 12 分執行一次，只處理程式裡標示為「納入自動排程」的遊戲商，每家遊戲商各派一個背景工作。聚合商標示後，旗下所有遊戲商一併納入。 | command:update_game_list、code:GameListSyncService::applySchedulableVendorScope、code:GameListSyncService::dispatchQueueSync |
| R-2 | 本份收錄的遊戲商中，納入每小時排程的是 MetaGamingSW、SMA、Ebet、聚合商 DCT（DCT、PNG、RELAX、HS）。PlayTech、聚合商 TitanHub、聚合商 target 不進排程，只能從 Olympus 或命令列手動觸發。 | code:Sma\Driver、code:Ebet\Driver、code:Dct\Driver、code:MetaGamingSW\Driver、code:PlayTechSW\Driver、code:Evo\Driver、code:target\Driver、test:Tests\Feature\Vendors\Sma\DriverTest::test_driver_is_marked_for_scheduled_game_list_sync、test:Tests\Feature\Vendors\Ebet\DriverTest::test_driver_is_marked_for_scheduled_game_list_sync、test:Tests\Feature\Vendors\Dct\DriverTest::test_driver_is_marked_for_scheduled_game_list_sync、mr:!2279、mr:!2083、mr:!2099 |
| R-3 | 排程或手動觸發，以下遊戲商都不處理：遊戲商本身停用、底下沒有任何啟用的遊戲商戶、列在停用遊戲商清單、正在維護時段內。手動指定遊戲商時也一樣。 | code:AbstractVendorBatchService::availableVendors |
| R-4 | 共用同一套對接程式的遊戲商（例如同一家聚合商旗下的多家），排程派發時每家依序延後 15 秒，避免同時打到同一個遊戲商的限流。 | code:GameListSyncService::dispatchQueueSync |
| R-5 | 同一家遊戲商的背景工作還沒跑完時，55 分鐘內再派發會直接略過。背景工作失敗不重試，等下一輪排程。 | code:SyncVendorGameList |
| R-6 | 背景工作真正開始執行時，會再確認一次遊戲商與遊戲商戶仍啟用、不在維護時段。不符合就略過，執行紀錄寫明略過原因，例如「略過：遊戲商維護中」。 | code:SyncVendorGameList::sync |

### Olympus 手動觸發

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | Olympus「更新遊戲清單」頁的遊戲商下拉選單，只列出有抓清單能力的遊戲商，包含不進排程的遊戲商。 | code:UpdateGameList::formComponents |
| R-8 | Olympus 送出後以同步模式執行：不經排程的背景工作，也不受 R-5 的 55 分鐘略過限制；執行結果固定附上遊戲商回傳的完整清單。 | code:UpdateGameList::buildParams |
| R-9 | 勾選「乾跑模式（不寫入資料庫）」時，流程照常跑完，結束時全部寫入一律回滾，資料庫不變。命令列的乾跑模式相同。 | code:UpdateGameList::buildParams、code:GameListSyncService::previewSync |
| R-10 | 完整清單每款遊戲列出遊戲代碼、名稱、遊戲類型與圖片網址，所有遊戲商都適用，客服可以直接開圖片網址確認縮圖在不在。 | code:UpdateVendorGameList::renderVendorResult、mr:!2099 |
| R-11 | 「遊戲清單來源網址」欄位只在選到需要外部提供清單連結的遊戲商時出現並且必填，目前只有 PlayTech。選其他遊戲商時不出現，也不擋送出。PlayTech 沒填就送出時擋在表單驗證，不會留下操作紀錄。 | code:UpdateGameList::formComponents、test:Tests\Feature\Olympus\UpdateGameListPageTest::test_source_url_is_required_for_ptsw、test:Tests\Feature\Olympus\UpdateGameListPageTest::test_source_url_is_not_required_for_api_based_vendors、mr:!2099 |
| R-12 | 填入的清單來源網址會原文存進操作紀錄的參數，能看操作紀錄的人都看得到這條連結。 | code:UpdateGameList::buildParams、test:Tests\Feature\Olympus\UpdateGameListPageTest::test_source_url_is_passed_as_sheet_url_option、mr:!2099 |

### 寫入方式

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 只新增遊戲商有、我方沒有的遊戲。已存在的遊戲任何欄位都不改，上下架、名稱、圖片、備註由客服在後台維護。 | code:GameListSyncService::syncGames、mr:!2279 |
| R-14 | 已存在的遊戲只補缺少的關聯：該遊戲商戶的商戶遊戲、代理遊戲、遊戲商有提供但我方還沒有的語系名稱。已有的語系名稱不覆寫。 | code:GameListSyncService::syncAgentGames、code:GameListSyncService::syncVendorInfoGames、code:GameListSyncService::syncGameNames |
| R-15 | 新增遊戲時，遊戲主檔的狀態依各家規則決定（見「各家收錄規則」），有效投注比例取遊戲商的設定，不標新遊戲、不標熱門。商戶遊戲的新增列為停用、可投注、標為新遊戲；代理遊戲的新增列為停用。新遊戲要客服在後台開啟才會開放。 | code:GameListSyncService::syncGames、code:GameListSyncService::syncVendorInfoGames、code:GameListSyncService::syncAgentGames、mr:!2320 |
| R-16 | 比對前先去掉遊戲代碼前後的半形空白；同一份清單去空白後代碼重複時，只留第一筆。我方既有遊戲的代碼也用去空白後的值比對，資料庫裡的原值不改。已經因空白重複存在的舊資料不清除。 | code:GameListSyncService::trimAndDedupeGameCodes、code:GameListSyncService::syncGames、mr:!2316 |
| R-17 | 我方自建的入口遊戲（遊戲商永遠不會回傳）完全不參與比對，不會被新增，也不會被列進「三方未提供」。 | code:GameListSyncService::sync、code:GameListSyncService::findGamesMissingFromUnion |
| R-18 | 一家遊戲商有多個啟用的遊戲商戶時，每個遊戲商戶各抓一次清單、各自寫入。某個遊戲商戶寫入失敗時只回滾那個遊戲商戶，先前完成的不回滾；該遊戲商這一輪中止，不做「三方未提供」偵測。 | code:GameListSyncService::syncVendor、code:GameListSyncService::syncMerchant |
| R-19 | 某個遊戲商戶取不到任何遊戲時，該遊戲商戶不寫入，執行結果顯示「⚠ 未獲取到任何遊戲資料」。 | code:GameListSyncService::syncMerchant、code:UpdateVendorGameList::renderVendorResult |

### 遊戲商下架的遊戲（三方未提供）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 我方有、遊戲商清單沒有的遊戲，不會自動停用。系統把該遊戲商所有啟用遊戲商戶的清單合在一起比對，列出「三方未提供但仍啟用中的遊戲」，提示人工確認後在後台關閉。列表最多列 50 款，超過時註明總數。 | code:GameListSyncService::syncVendor、code:GameListSyncService::findGamesMissingFromUnion、code:UpdateVendorGameList::outputMissingFromUnion、mr:!2279 |
| R-21 | 已停用的遊戲、後台人工改過的遊戲，不列進「三方未提供」。 | code:GameListSyncService::findGamesMissingFromUnion |
| R-22 | 只要有一個遊戲商戶取不到清單，這一輪就跳過「三方未提供」偵測，輸出「⚠ 商戶清單聯集不完整，本輪跳過「三方未提供」偵測」。 | code:GameListSyncService::syncVendor、code:UpdateVendorGameList::outputMissingFromUnion |

### 遊戲商回應異常

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | SMA、Ebet、聚合商 DCT、聚合商 TitanHub、聚合商 target 回應 HTTP 成功、但回應裡的業務碼不是成功時，該遊戲商戶當作取不到清單（照 R-19、R-22 處理），並記一筆錯誤 log。 | code:Sma\Driver::getGameList、code:Ebet\Driver::getGameList、code:Dct\Driver::getGameList、code:Evo\Driver::getGameList、code:target\Driver::getGameList、test:Tests\Feature\Vendors\Sma\DriverTest::test_get_game_list_returns_empty_when_vendor_api_fails、test:Tests\Feature\Vendors\Ebet\DriverTest::test_get_game_list_returns_empty_when_vendor_api_fails、test:Tests\Feature\Vendors\Dct\DriverTest::test_get_game_list_returns_empty_when_vendor_api_fails、test:Tests\Feature\Vendors\Evo\DriverTest::test_get_game_list_returns_empty_when_vendor_api_fails、test:Tests\Feature\Vendors\target\DriverTest::test_get_game_list_returns_empty_when_vendor_api_fails、mr:!2279 |
| R-24 | SMA、Ebet、聚合商 DCT 的 HTTP 層出錯時（例如 503），該遊戲商這一輪同步失敗並回報錯誤，不當作空清單。 | code:Sma\Driver::getGameList、code:Ebet\Driver::getGameList、code:Dct\Driver::getGameList、test:Tests\Feature\Vendors\Sma\DriverTest::test_get_game_list_throws_on_http_error、test:Tests\Feature\Vendors\Ebet\DriverTest::test_get_game_list_throws_on_http_error、test:Tests\Feature\Vendors\Dct\DriverTest::test_get_game_list_throws_on_http_error、mr:!2279 |
| R-25 | MetaGamingSW 回應的狀態不是 200 時，該遊戲商這一輪同步失敗並回報錯誤。 | code:MetaGamingSW\Driver::getGameList |

### 各家收錄規則

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-26 | MetaGamingSW 只收遊戲代碼為六位數字的遊戲，四條產品線都收；不符合格式的代碼略過。新增遊戲的主檔狀態為啟用。 | code:MetaGamingSW\Driver::getGameList、mr:!2321、mr:!2320 |
| R-27 | PlayTech 沒有清單 API，清單來源是遊戲商提供的 SharePoint 活頁簿。沒有提供來源網址就觸發時，同步中止並回報「PTSW game list: source url is required (pass --sheet-url)」，不會當作空清單。 | code:PlayTechSW\Driver::getGameList、mr:!2099 |
| R-28 | PlayTech 的來源網址是 SharePoint 共用連結時，系統自動改寫成下載直鏈再抓，原網址的查詢參數一併捨棄；已經是下載直鏈或認不出的格式時原樣使用，不報錯。log 只記站台名稱，不記連結裡的權杖。 | code:GameListSheet::resolveDownloadUrl、mr:!2101 |
| R-29 | PlayTech 下載到的不是活頁簿檔（例如連結失效或要求登入，回了一個網頁）時，同步中止並回報錯誤，錯誤訊息帶回應的狀態碼、大小與內容開頭。 | code:GameListSheet::download、code:GameListSheet::assertXlsx |
| R-30 | PlayTech 只讀「Slots 电子」與「Crash 爆点游戏」兩個分頁，遊戲類型依分頁決定：前者為 SLOTS，後者為 ARCADE，分頁內的類型欄不採用。任一分頁找不到時同步中止。 | code:GameListSheet::parseSheets、mr:!2099 |
| R-31 | PlayTech 分頁讀到「維護中」或「已下架」區塊的標題列就停止，只收使用中的遊戲。 | code:GameListSheet::readRows、mr:!2099 |
| R-32 | PlayTech 新增遊戲的圖片網址一律留空，由客服在後台補圖；名稱取英文名，英文名空白時用遊戲代碼；語系名稱收英文、中文（繁中與簡中都用同一個中文名）、韓文；有新遊戲代碼時存進備註，開遊戲仍以原遊戲代碼為準。主檔狀態為啟用。 | code:GameListSheet::toGameInfo、mr:!2099 |
| R-33 | SMA 的遊戲類型只認 slots（SLOTS）與 fishing（FISHING），其他類型歸「EVENT & OTHERS」並記警告 log。圖片取遊戲商的方形圖，主檔狀態為啟用。 | code:Sma\Driver::getGameList、test:Tests\Feature\Vendors\Sma\DriverTest::test_get_game_list_maps_response_and_signs_request、mr:!2279 |
| R-34 | Ebet 只同步老虎機（遊戲商的清單不含真人遊戲），類型一律 SLOTS，圖片一律空白，主檔狀態為停用，要人工上架。遊戲商給的供應商代碼存進備註，開遊戲時要用到；語系名稱收英文與簡中。 | code:Ebet\Driver::getGameList、test:Tests\Feature\Vendors\Ebet\DriverTest::test_get_game_list_maps_response_and_signs_request、mr:!2279 |
| R-35 | 聚合商 DCT 依遊戲商戶設定的底層遊戲商取清單。類型依遊戲商給的類型文字判斷：含 slot 為 SLOTS，含 InstantWin 或 Scratchcard 為 ARCADE，其餘歸「EVENT & OTHERS」並記警告 log。主檔狀態為停用，要人工上架；語系名稱收簡中；遊戲商回傳的整列資料存進遊戲資訊。 | code:Dct\Driver::getGameList、code:Dct\Driver::convertGameType、test:Tests\Feature\Vendors\Dct\DriverTest::test_get_game_list_maps_response_and_signs_request、mr:!2279 |
| R-36 | 聚合商 TitanHub 依遊戲商戶設定的底層遊戲商代碼取清單。語系名稱依遊戲商給的 EN、ZH、CN、TH 對應到英文、繁中、簡中、泰文。類型對照：CASINO 為 LIVE CASINO、SLOT 為 SLOTS、FISHING 為 FISHING、KENO 與 LOTTERY 為 LOTTERY、SPORTBOOK 為 SPORTS、ESPORT 為 E-SPORTS、OTHER 為 ARCADE，其餘為未分類。主檔狀態為啟用。 | code:Evo\Driver::getGameList、code:Evo\Driver::convertGameType、test:Tests\Feature\Vendors\Evo\DriverTest::test_get_game_list_maps_response_and_calls_correct_endpoint、mr:!2083 |
| R-37 | 聚合商 target 的類型對照：老虎機為 SLOTS、棋牌為 TABLE、視訊為 LIVE CASINO、機台與角鬥場與柏青斯洛為 ARCADE、彩票樂透為 LOTTERY、捕魚為 FISHING，其餘為未分類。開遊戲用遊戲商給的遊戲編號當遊戲代碼，遊戲商的別名代碼存進備註。主檔狀態為啟用。 | code:target\Driver::getGameList、code:target\Driver::convertGameType、test:Tests\Feature\Vendors\target\DriverTest::test_get_game_list_maps_response_and_calls_correct_endpoint、mr:!2083 |

## 還沒確認的事

- 聚合商 DCT 的 HS（vendor 72）有標示納入排程，但目前列在停用遊戲商清單，實際上不會被同步。HS 要不要同步，待 PM 確認（MR !2279 的描述把 HS 列為會同步）。
