# 單錢包遊戲商接入驗收 Contract
> 目的：新接一家單錢包遊戲商時，工程師（實作者與 reviewer）拿這張清單逐項驗收，全部通過才能對真實玩家開放。
> 依據：`game-integration` skill（SKILL.md 與 reference/ 下各檔、testing.md）。各區標題括號內的節名指向該 skill 的章節，程式細節以 skill 為準；本文件負責讓每一條「看得懂為什麼要檢查」。
> 用法：每接一家新遊戲商，複製本文件為 `docs/integrations/vendors/<VendorName>/acceptance.md`，逐項勾選；不適用的項目必須寫明原因，不允許留空跳過。**發新增遊戲商的 MR 時，這份 contract 必須一併附上 MR**：`acceptance.md` 隨 feature 分支提交進 MR diff，勾選清單同時貼進 MR description 供 reviewer 對照。


---

## 先讀這三個背景概念

1. **我們是平台，遊戲是別人的。** 玩家在我們網站儲值，實際玩的遊戲由「遊戲商」提供。接一家新遊戲商＝寫一段串接程式，讓兩邊系統對得上話。
2. **「單錢包」是什麼。** 玩家的錢一直放在我們這邊；遊戲商每次要扣錢（下注）或加錢（派彩），都是發一個 HTTP 請求到我們的 API 叫我們動手——這種「遊戲商主動打過來的請求」就叫**回調（callback）**，下文一律稱回調。
3. **這張清單在防什麼。** 四類真實發生過的事故：**錢算錯、重複扣款、假冒請求、統計被污染**。幾乎每一條檢查背後都有一次教訓。

---

## A. 規格文檔：先把說明書抄好再動工（「新增遊戲商完整上線步驟」步驟 0、「遊戲商整合文檔」）

- [ ] A-1 已把遊戲商給的技術文件（PDF／網頁）整理成我們自己的 `docs/integrations/vendors/<VendorName>/api.md`，內容照搬廠商原文，不摻自己的理解——之後寫程式全部以這份為準。
- [ ] A-2 文件目錄名用程式碼裡的識別名（`app/Vendors/{VendorName}/` 目錄名，單錢包帶 `SW` 後綴），不用廠商的對外品牌名，避免同名混淆。
- [ ] A-3 對接過程發現「文件寫 A、實測是 B」的落差時，已記進 `integration.md` 並寫明怎麼驗證的；反之，看程式碼就能知道的事不要抄進去（會過期失真）。
- [ ] A-4 動工前查過同事有沒有做過類似功能；要照抄既有遊戲商當範本時，挑的是符合最新規範的（如 AiGamingSW、MegaH5SW），不是舊的錯誤示範（EpicWinSW、DstPlaySW）。

## B. Driver 與帳號管理：玩家在遊戲商側的遊戲帳號（「單一錢包」的「必須實作的方法」、「建立遊戲帳號」、「遊戲帳號綁定並發規範」、「回調 Controller 骨架」、「接入通道與回調路徑宣告」）

- [ ] B-1 Driver（串接主程式）繼承 `AbstractSingleWallet`，並在 `config/vendor.php` 註冊；新的遊戲商編號（`VendorIdEnum`）已跟其他專案確認不撞號。
- [ ] B-2 「取得進入遊戲的網址」（`getGameUrl()`）回傳的資料中，電腦版網址、手機版網址、App 資訊三者至少要有一個。
- [ ] B-3 「幫玩家開遊戲帳號」（`createAccount()`）做對型態：遊戲商有開帳號 API 的，採兩段式：本地紀錄一插入就以 `member_id = -999999999` 佔位（此時玩家領不走），對方確認建成才改成 `0` 放進帳號池；對方失敗就讓它留在佔位狀態。不可「先入池、失敗才標記」，那會在對方還沒建成的空窗期被玩家綁走；對方沒有開帳號 API 的，只寫本地紀錄即可。
- [ ] B-4 注單資料表的欄位對映已在 Model 完整宣告（哪個欄位是投注金額、哪個是投注時間……；沒有的欄位也要明寫 `null`），對映檢查測試（`BetRecordColumnParityTest`）通過——漏宣告會導致金額算錯帳。
- [ ] B-5 帳號綁定併發規範（Account Binding）四條固定規則達標：用 `Cache::lock` 讓同一個會員的請求排隊、查「已綁定」時不上資料庫鎖、從帳號池撈新帳號時用 `FOR UPDATE SKIP LOCKED`（跳過被別人鎖住的列）、綁定寫入包在同一個資料庫交易裡。為什麼：100 個玩家同一秒進同一款遊戲時，沒照規範做會綁到同一帳號或資料庫死鎖（Deadlock，errno 1213）。

- [ ] B-6 自動建帳號排程已涵蓋此 vendor：Driver 實作 `ShouldScheduleAccountCreation` 介面即納入 `create_vendor_account` 排程（只實作父介面 `SupportsAccountCreation` ＝退出排程、僅供 `--vendor-id` 手動補建）；排程執行後帳號池有預建帳號、數量達 `prepared_account_number`，池子被消耗後下輪排程會補貨。注意：聚合商標記後旗下所有 vendor id 一併納入；同一遊戲商禁止 APIS 與 RD-Studio 兩端同時補池（會互撞流水號），新標記上線前 RD-Studio 對應排程必須先下線；廠商若有建號時窗限制（如 Haolong 型三方 UTC 06:00–14:00 不受理），時窗內整批略過屬預期行為，需記入 integration.md。
- [ ] B-7 Driver 已實作 `HasCallbackPath`，宣告的預設回調路徑後綴與 `routes/external/sw.php` 為這家註冊的路由前綴一致，守門測試 `DriverCallbackPathDeclarationTest` 通過（測試只驗路由存在，不驗歸屬，要人工核對是本家前綴）；經聚合商接入（集成）的，Driver 已宣告 `HasAggregatorChannel`，或 vendor id 已加進既有聚合商在 `config/vendor.php` 的 `array_fill_keys` 群組。為什麼：後台「遊戲商管理 › 廠商管理」靠這兩項顯示該遊戲商的「通道」與「回調路徑」，宣告錯了，運營看到、拿去給遊戲商設定的回調路徑就會是錯的。該列的「通道」與「回調路徑」顯示正確才算通過。

## C. 回調前置驗證：每個回調進來先驗明正身（「回調 Controller 骨架」「來源驗證與憑證」「投注前檢核」「Controller 共通樣板」）

- [ ] C-1 每個回調都先驗來源 IP 在白名單內（`Common::verifyIPWhitelistByVendorId()`，防止別人冒充遊戲商）；多個回調共用的前置檢查集中寫在 `callAction()`，不在每個方法裡複製貼上。
- [ ] C-2 簽名驗證已實作：密鑰未設定時一律拒收並記 warning，不可放行；比對用 `hash_equals()`。正式環境的 `SECRET` 已填有效值，否則回調會全數被拒。
- [ ] C-3 所有會碰玩家餘額的回調（含查餘額），第一件事就是檢查帳號是否被停用（`account->status == 0` 即拒絕），錯誤碼照 api.md 回。
- [ ] C-4 Controller 實作 `ShouldVerifyGameBettability` 介面（單錢包一律強制）；會扣錢的下注類方法要呼叫 `verifyBettability()` 確認玩家有下注資格（會員狀態、維護時段、鎖分、未領取活動獎勵等十一項檢查，清單見 skill 的 [single-wallet.md](single-wallet.md)「投注前檢核」）；查餘額、純結算、退款不用。**呼叫位置必須在交易內、鎖住會員（`getMemberLockedWallets()`）之後**——放在鎖之前會讀到等鎖前的舊快照。
- [ ] C-5 已確認「單一遊戲下架開關」（`vendor_info_games.bettable`）有人維護：遊戲清單同步流程會更新它，或已明確接受「這家遊戲商無法從後台下架單一遊戲」的風險並記錄下來。
- [ ] C-6 下注與派彩合在同一支 API 的遊戲商：只有金額大於 0（真的在下注）才做停用與資格檢查，免費旋轉派彩這類 0 元請求不能被擋。
- [ ] C-7 沒有在 Controller 裡重複記 log（框架 middleware 已自動記，再記是雙寫浪費）；log 標籤統一從欄位產生不寫死字串——Controller 用 `$actionPrefix`、Driver 用 `$vendorLabel`。

## D. 匯率換算：兩邊記帳單位可能不一樣（「vendor_info.rate 換算」）

- [ ] D-1 有些遊戲商 1 元記成 1000 點（`rate`）。收到的金額先除以 rate 轉成平台單位再入帳；回覆給對方的餘額乘以 rate 轉回對方單位。
- [ ] D-2 沒踩「兩支取餘額方法」的陷阱：`getSingleWalletBalanceByMember()` 回傳前**已經**幫你乘過 rate（不可再乘）；`getMemberLockedWallets()->getTotalBalance()` 是純平台單位（回覆前要自己乘）。搞混會差 1000 倍。
- [ ] D-3 已用非 1:1 的 rate（如 1:1000）實測查餘額與扣款／派彩各一輪且金額吻合——只用 1:1 測不算通過，因為乘除 1 什麼錯都看不出來。
- [ ] D-4 所有金額運算走 `Common::numAdd/Sub/Mul/Div/numCompare`，不用原生浮點運算（電腦的 0.1+0.2 不等於 0.3，直接加減會累積誤差）。

## E. 下注：最怕重複扣款（「同 round 多次 bet 必須累加」「INSERT-first 冪等模式」「打賞 / Tip」）

- [ ] E-1 防重複扣款（「冪等」＝同一件事收到幾次、效果都只算一次）的設計選對層級：一筆交易一張注單的，用注單號 `bet_id` 加資料庫 UNIQUE 約束擋重複；一局多筆交易的（如百家樂多口下注），另建 `{tname}_deal_records` 流水表、以交易號 UNIQUE 為防線，並採 INSERT-first 寫法（先插流水、業務錯誤就 throw 讓整筆回滾、外層接住重複鍵錯誤 1062 回「冪等成功」）。
- [ ] E-2 同一筆下注回調重送第二次，不會再扣一次錢，回應內容也符合 api.md 對重送的規定。
- [ ] E-3 餘額不足時回對應錯誤碼，而且不留半套資料（該回滾的 deal_record 有跟著回滾）。
- [ ] E-4 同一局可以加注的遊戲商（21 點分牌、多手下注）：注單金額用**累加**（`Common::numAdd`），不能用等號**覆蓋**——曾有遊戲商因覆蓋 bug 少算 246 筆流水事後補帳。新注單金額欄從 0 開始起算。
- [ ] E-5 打賞／小費／買道具類扣款：錢照扣（走 deal_records），但**絕不能**寫進 `total_bet_records`（TBR，平台的注單彙總表）被當成賭注（會污染流水、返水、活動統計）；若不得不寫進注單表則加旗標，且所有彙總路徑都有過濾。

## F. 結算（settle）與 TBR 建立（「total_bet_record 建立時機」）

- [ ] F-1 已實測確認「玩家輸錢（贏 0 元）時，遊戲商到底會不會送結算回調」，結論寫進 integration.md——這是下一條時機判斷的唯一依據，不能用遊戲類型用猜的。
- [ ] F-2 `total_bet_records`（TBR，報表與返水都靠它）的寫入時機正確：**預設等結算才寫**（未結算的單寫進去會污染統計）；下注與結算同一個回調的，當下寫；只有「輸錢不送結算」的特殊型才在下注當下寫（此型 `payout_time` 填佔位值 `1970-01-01 00:00:01`，且取消下注時必須成對把 TBR 歸零）。
- [ ] F-3 結算時接住 `changeBalance()` 的回傳值交給 `TotalBetRecordService->create($betRecord, $transactionLogId)` 建立 transaction_log 關聯（pivot）；**輸錢單（payout=0）也要建 pivot**——以前漏了這步，導致 SR14 報表明細只看得到贏錢的單。
- [ ] F-4 全部用禮碼（免費籌碼、非真金）下注的回合不建 pivot；Controller 不自己手寫查詢去撈 `status=999` 的進行中 transaction_log（已知的複製貼上型缺陷來源），一律用 `changeBalance()` 的回傳值。
- [ ] F-5 同一局分多次派彩的（保險、分牌、Bonus），每次派彩都 upsert **同一筆** TBR，`valid_bet` 取最終累加值。
- [ ] F-6 結算時找不到對應注單，照 api.md 規範回應（報錯或不派彩），不能不吭聲吞掉。

## G. 取消與退款：反悔的處理（「取消與退款」「settlement / refund 的 deal_records 沖銷模式」「transaction_logs.type 作為錢包 session marker」）

- [ ] G-1 遊戲當機、牌局作廢時的退款：把扣的錢還回去、注單標記作廢（status=2）、沖銷事件寫入 deal_records；重複退款請求不會退兩次（冪等）。
- [ ] G-2 取消發生在結算前（TBR 還沒建）就不用動 TBR；發生在結算後則 upsert 該筆 TBR 歸零。
- [ ] G-3 `transaction_logs.type` 遵守「session 標記」設計：一般回調不自己指定 type（讓系統 fallback 歸 27 `SEAMLESS_WALLET_BET_SETTLE`＝玩家在單錢包遊戲 session 中）；只有明確的退款／改單端點才顯式 `setType(28／29)`；不把型別推斷邏輯寫回 `changeBalance` 裡。
- [ ] G-4 對帳與查詢不拿 `transaction_logs.type` 分類業務細項（它分不出來），細項一律查各遊戲商自己的 `{tname}_bet_records` 與 `{tname}_deal_records`。

## H. 資料表設計：Schema 欄位與索引規範（「資料表設計」）

- [ ] H-1 資料表名用 `database/data/vendors.php` 裡的 `tname`；Model 的 `$table` 與 migration 一致；不用已廢止的欄位 macro，欄位一行一行明列。
- [ ] H-2 帳號表（`{tname}_accounts`）含四個必要欄位（vendor_info_id、member_id、username、status）＋ 一顆 `(member_id, vendor_info_id, status)` 複合索引；username 依遊戲商的玩家 ID 是否全域唯一決定 UNIQUE 方式；不建多餘索引。
- [ ] H-3 注單表（`{tname}_bet_records`）含規範必建欄位；遊戲商的唯一交易編號**一律**存到 `bet_id` 欄（UNIQUE，冪等鍵），不管對方原本叫 ticketNum 還是 transactionId；`reference_no` 只是 RD debug 目視關聯用的欄位、無程式作用，不建索引（DstPlaySW 把兩欄存反是錯誤示範；PPSW 起頭的 10 家歷史樣板已把 Model 的 `$betIdColumn` 改指 `reference_no` 遷就既有資料，那是補救不是範本）。
- [ ] H-4 注單表索引收斂為三顆：`bet_id` UNIQUE ＋ `payout_time` ＋ **`bet_time`**。索引（index）是資料庫的查詢目錄，沒有索引的查詢會整張表掃過（full table scan）——`bet_time` 索引是「查未結算注單」的硬需求，2026-09 盤點發現歷史 48 家有 21 家漏建，新遊戲商不允許再漏。反過來，其他欄位（username、game_code、status）**不建**索引，因為每多一顆索引每次寫入都變慢。
- [ ] H-5 採「一局多筆交易」設計的，流水表（`{tname}_deal_records`）照規範建，並理解它跟全域的 `deal_records`（錢包異動總表）是兩張不同的表。
- [ ] H-6 Model 註解含 `@mixin \Eloquent` 與全部欄位的 `@property` 宣告（讓 IDE 看得懂、能自動補全）。
- [ ] H-7 建表 migration 先跑 `--pretend`（只印 SQL 不執行）回報、取得確認才真的執行；已經在遠端環境跑過的 migration 之後不准回頭改內容（改了其他環境也不會重跑，只會造成各環境不一致）。

## I. 上線設定（「設定與註冊」「對帳與補帳整合」「遊戲清單同步介面約束」「新增遊戲商完整上線步驟」）

- [ ] I-1 遊戲商有提供遊戲清單 API 的：實作 `getGameList()` 並選對同步方式——要每小時自動同步的掛 `ShouldSyncGameList` 介面、只要手動同步的掛 `HasGameList`；聚合商（一個 Driver 底下多個遊戲商編號）掛自動同步會全部一起生效，需確認是預期行為。
- [ ] I-2 串接設定（`vendor_infos.info`）的 key 命名照 api.md 的參數名轉大寫（`API_URL` 是固定必備的例外）；正式環境的 `SECRET` 已填有效值。
- [ ] I-3 `vendor:add_vendor_info` 設定完成：幣別與匯率經 PM 確認、帳號前綴、最小轉帳單位、預備帳號數；開發環境掛固定測試代理 4249。
- [ ] I-4 正式環境完成雙代理配置：先只開給「測試代理」（registry 的 `testRootAgentId`，status=1）讓測試人員玩一輪驗收，確認沒問題才手動開啟「正式代理」（`rootAgentId`，status 從 0 改 1）對真實玩家開放。
- [ ] I-5 上架資料驗證 SQL（「新增遊戲商完整上線步驟」步驟 12 的七張表：vendors、vendor_infos、agent_vendors、games、agent_games、vendor_info_games、vendor_customized_items）每張都查得到紀錄；測試環境看不到遊戲先清快取再查。
- [ ] I-6 已在 `config/summary_bet_record.php` 註冊這家遊戲商（讓每小時的彙總排程涵蓋它）；合併設定陣列用 union（`+`）不用 spread（`...`）——spread 會把整數 key 重新編號、遊戲商編號全錯。
- [ ] I-7 已建立對帳卡 `docs/reconcile/vendors/{VendorName}.md`：記錄「我方注單怎麼撈」與「怎麼回頭跟遊戲商核對」兩件事——沒有這張卡，未來對帳流程會直接停在第一步。

---

## J. QA 手動驗收清單（沙箱實測；測試代理環境）

> 本區是手動 QA 的實測項目：QA 以實際操作驗證（玩遊戲、操作前後台），不直接改 DB、不偽造 HTTP 請求。單錢包沒有「轉入／轉出遊戲」的轉帳動作，餘額檢查以「平台錢包與遊戲內餘額一致」取代。

- [ ] J-1 大廳會顯示該遊戲商，遊戲清單正常出現（對應 I-5）。
- [ ] J-2 能夠進入遊戲（PC 與行動版至少各驗一種，對應 B-2）。
- [ ] J-3 進入遊戲後，遊戲介面顯示的餘額與平台錢包餘額一致；`rate` 非 1:1 的商戶，兩邊金額須吻合換算比例（對應 D-1／D-3）。
- [ ] J-4 能夠投注成功，投注後平台餘額即時反映扣款、結算後反映派彩（對應 E／F 區）。
- [ ] J-5 離開遊戲回大廳，大廳餘額正確反映這一輪的最終輸贏。
- [ ] J-6 後台 SR11、SR12、SR14 都查得到剛剛的投注且金額正確；SR14 現金紀錄 detail 須包含**輸錢**的注單（對應 F-3）。
- [ ] J-7 前台輸贏報表、投注紀錄查得到剛剛的投注。
- [ ] J-8 後台停用該會員（或遊戲商帳號）後，無法再進入遊戲或下注（對應 C-3）。
- [ ] J-9 廠商支援打賞／小費的才測：打賞扣款成功，但後台報表的有效投注**不**增加（對應 E-5）。
- [ ] J-10 後台「遊戲商管理 › 廠商管理」該遊戲商那一列的「通道」與「回調路徑」顯示正確：直連顯示直連、集成顯示所屬聚合商；回調路徑與實際交給遊戲商設定的一致（對應 B-7）。

---

## 簽核

| 角色 | 姓名 | 日期 | 結果 |
|---|---|---|---|
| 實作 RD | | | |
| Reviewer | | | |
| QA（沙箱實測） | | | |

> 各分區未全數勾選（或未附不適用原因）前，不得開啟正式代理（I-4 的第二步）。
