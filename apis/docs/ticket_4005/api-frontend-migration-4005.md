# [GFF] API_Frontend 最後一批 API 遷移（target/tickets#4005）

來源：http://example.invalid:1688/target/tickets/-/work_items/4005
Assignee：leo｜Due：2026-08-20｜狀態：opened

## 遷移要點

* **原專案**：`/root/projects/API_Frontend/`
* **PHP 版本**：PHP 7.3 → PHP 8.4
* 本次以「**功能遷移**」為主，**以舊版行為一致為最高優先級**，不要順手進行非必要的重構、抽象化或架構調整。

### 路由與結構

* 遷移後須遵循 apis 現有的路由與目錄規範：

  * `routes/frontend/`
  * `frontend/api/*`
* 命名方式參照既有新版實作，例如 `frontend/api/event-v2/*`。
* 新路由尚未指定的，實作時依新專案規範決定，完成後回填「新路由」欄位。

### Service

* 原則上**先保留舊版 service 實作**，不要因 apis 已存在類似 service 就直接替換。
* 若發現可共用的既有 service，先記錄於「備註」，待遷移完成並通過測試後再另行評估。
* **禁止因本次遷移順手進行非必要的 service 重構或抽象化。**

### Store Procedure
* 參考資料夾 database/procedures/ 的 sql 檔案,  若不存在需要備註

### 舊版差異

* 遷移時必須參考：

  * `tpower-master`
  * `vw-master`
* 若兩個分支有差異，需先整理差異並決定新專案的處理方式。
* 差異內容及處理方式須記錄於「備註」欄位。
* 不要單純因某個版本的程式碼看起來較乾淨，就直接選其中一版。
* **兩分支有差異時，遷移後直接讀取正式 DB 的實際設定，確認新版行為與各白標舊版一致即可**——不需要為了理論上的差異另做相容處理。查證方式：以唯讀 MCP（`mysql-tpower` / `mysql-vworld` 等）撈出會走到該分支的實際資料，逐一比對新舊版結果，並把結論與筆數寫進該項的遷移細節。已依此結論處理的例子見 2-8（`deduct_principal`）、2-12（`getAllPrize` 派彩實作）。

### PHP 8.4 相容性

* 必要的 PHP 7.3 → PHP 8.4 相容性修改可以調整，但應盡量維持原有業務邏輯與行為。
* 不要因為 PHP 8.4 支援較新的語法或寫法，就額外全面改寫舊程式。

### 測試與清單

* 每個遷移項目都需撰寫並通過對應單元測試。
* 「備註」至少記錄：

  * `tpower-master` / `vw-master` 的差異
  * 必要的 PHP 8.4 相容性修改
  * 發現但暫不處理的 service 整合項目
* 序號跨表累加，不重新編號，方便總量盤點與追蹤。


---

## 前端實際使用清單對照（2026-07-30）

前端（WEB_Frontend）提供的「未遷移且實際有使用」清單共 **62 支**，與本文件序號 1–72（WEB_Frontend 呼叫段）逐一對照結果如下；其後前端另行回報序號 71 也已不再使用，故實際仍需遷移者為 **61 支**。

> **驗證範圍說明**：本次對照以前端提供的清單為準。WEB_Frontend 目前未 checkout 於本機（`/root/projects/` 下不存在），**未重新 grep 原始碼複驗**；文件既有的「呼叫位置」行號為先前盤點時所記。

**前端已廢除，標記為「不需要遷移」（4 支）**

| 序號 | 端點 | 說明 |
|---|---|---|
| 56 | `api/unlockPromotionWallet` | 前端清單標記廢除。**更新（2026-08-05）**：另一呼叫端序號 110（API_Agent）也已確認作廢，故此端點整體不需要遷移 |
| 69 | `api/event/games` | 前端清單標記廢除，無其他呼叫端 |
| 72 | `api/promotionWalletTransactions` | 前端清單標記廢除，無其他呼叫端 |
| 71 | `api/records` | 前端 2026-07-30 另行回報已不再使用。複驗後確認 **tpower-master / vw-master 的 `routes/api.php` 皆無此路由**，舊版根本無對應端點；無其他呼叫端 |

**未出現在前端清單、但已完成遷移（7 支，維持原狀）**

前端清單本身是「未遷移」清單，故已遷移項目不會列入，其缺席**不代表未使用**：序號 49、50、57、58、59、65、66。

**其餘 61 支維持既有遷移規劃**：序號 1–48、51–55、60–64、67、68、70。

> **更新（2026-08-03）**：序號 60–64（登入活動整組）經 PM 重新確認，前端實際**不需要**此功能，與本節 2026-07-30 當時的判讀不同，已改列不需要遷移，詳見第 7 章。

---

## Code Review 結果與待辦（2026-07-31，panel review）

序號 1–34 完成後執行四人 panel review（Timo 金流／Marz 舊系統對照／Rorin 安全／Rhyse 架構與測試）。**每項結論皆已由我實際對照舊版原始碼或查詢正式 DB 驗證**，下表的「歸屬」欄即為驗證結果。

> **重要結論**：5 條 Critical 全部是**舊系統既有的漏洞**（照搬而來，目前正跑在 `API_Frontend` 正式環境），本次遷移只新引入 1 條（`withLock` 遺漏）＋ 1 個流程疏漏（未跑 `composer phpmd`）。

### 待修（依處理順序）

| # | 項目 | 位置 | 來源 | 歸屬 | 狀態 |
|---|---|---|---|---|---|
| 1 | `per_page` 加 `max:100`（先擋 PII 批次外洩） | `LeaderBoardRequest.php` | Rorin | 舊版既有 | ✅ |
| 2 | `choosePrize` 的 `prize_content_id` 綁回 `$prize->id`；`sendPrize` 加 `content->prize_id === prizeMember->prize_id` 斷言 | `PrizeService::choosePrize` | Rorin | 舊版既有 | ✅ |
| 3 | `choosePrize` 的 `PrizeMember` 補 `lockForUpdate()` ＋ `where('status', 0)` | `PrizeService::choosePrize` | Rorin | 舊版既有 | ✅ |
| 4 | `validateLegacyEvent` 補代理層級 ＋ `event_exclude_agents`；`getInvitedMembers` 補 `event_id` 過濾；`achieve_people <= 0` 直接拒絕 | `EventService` | Rorin | 舊版既有 | ✅ |
| 5 | `drawPrize` / `drawAllPrizes` / `drawByDetail` 補代理層級（縱深防禦） | `PrizeService` | Rorin | 舊版既有 | ✅ 另含 `choosePrize` |
| 6 | `leaderBoardQuery` / `getMyRanking` / `getAwards` 補代理層級 | `SlotCompetitionService` | Rorin | 舊版既有 | ✅ |
| 7 | 登入送併發：以 `Member` row `lockForUpdate` 序列化（`prize_members` unique index 需分階段獨立發布） | `PrizeService::drawPrize` | Rorin | 舊版既有 | ✅ |
| 8 | `transferBackRecentVendors()` 呼叫處補 `withLock('member:{id}:transfer')` | `PrizeService` ×2 | Timo | **本次引入** | ✅ |
| 9 | 兩處 `PrizeContent` 查詢補 `lockForUpdate()`（`drawPrize` 已有，另兩支漏） | `PrizeService` | Timo | 舊版既有 | ✅ |
| 10 | `Prize::where(...)` 提到 `foreach` 外（同一期 `event_id` 恆定的 N+1） | `LuckyDrawService::receiveAll` | Marz | **本次引入** | ✅ |
| 11 | `composer phpmd:baseline` 登記 6 個高複雜度方法 | `phpmd.baseline.xml` | Rhyse | **本次疏漏** | ✅ 實際 7 項 |
| 12 | 補優惠錢包 `point_type = 4` 派彩測試（唯一零測試的金流分支） | `PrizeService::creditPromotionWallet` | Rhyse | — | ✅ 並修好測出的 bug（見下） |
| 13 | 移除誤入版控的 `test.php` | repo root | Timo | **本次疏漏** | ✅ 以刪除 commit 處理，未再改寫歷史（2 KB 一次性腳本、無憑證） |
| 14 | rebase 到最新 `origin/master`（落後 23 commit） | — | 我 | — | ✅ 無衝突，已與 `0ff39e7fc` 對齊 |

#### 測試庫狀態備註

`tests/Unit/Services`（本次遷移的全部程式）**全綠**。`tests/Unit` 與 `tests/Feature` 另有 6 支失敗，皆與本分支的 diff 無關（相關測試檔與 `origin/master` 完全相同）：

- rebase 後跑 `php artisan app:upgrade --env=testing` 補上測試庫缺少的 stored procedure，修好原本 7 支 SP 相關失敗；
- 但該指令同時把 `vendors` 主檔灌進測試庫，導致 5 支以固定 `id` 建 vendor 的測試（`SummaryBetRecordCommandTest`、`TemplateTest`）撞主鍵；
- 另 1 支 `VendorAccountsTableSchemaTest` 在 rebase 前後皆失敗。

即測試庫的失敗數由 12 降為 6，兩種狀態都不影響本分支程式。要完全清乾淨需另外處理「測試庫只同步 procedure、不灌 vendors」的佈建方式，屬測試環境議題。

#### 修正過程中發現的實際 bug（序號 12 補測試時測出）

`creditPromotionWallet()` 寫 `promotion_wallet_logs` 時漏了 `order_id` / `remark` / `result` / `operator` 四個「NOT NULL 且無 DEFAULT」的欄位。舊版靠 MySQL **非嚴格模式**補隱含預設值，apis 為嚴格模式會直接拋 `SQLSTATE[HY000] 1364`，**任何 `point_type = 4`（優惠錢包）的活動派彩都會 500**。

查 vworld 正式 `promotion_wallet_logs`：活動獎勵（`type = 3`）共 44 筆，`order_id` 全為 `0`，其餘三欄全為空字串——即非嚴格模式補進去的值。已依此明寫，寫入結果與線上資料一致。

> 同類風險：舊版凡是靠非嚴格模式省略欄位的 insert，遷移到 apis 都會炸。此類問題**只有實際跑到該分支的測試才會現形**，靜態檢查與 code review 都抓不到。

### 已驗證但不需處理

| 項目 | 驗證結果 |
|---|---|
| 鎖定順序不一致（`PrizeService` vs `LuckyDraw`/`WJackpot`） | Timo 查證：鎖的是不同活動類型的 `prize_members` 列，僅共用 `Member` 一項，**無循環等待、無死鎖路徑**。日後整合派彩邏輯時再統一 |
| float 殘留 | Timo 逐欄位核對：**無**，金額全走 `Common::num*`，餘額讀出皆有正規化 |
| `drawAllPrizes` 把 tpower 手機驗證套用到 vw（文件 2-12 漏記此差異） | 查 vworld 正式 DB：兩個進行中可達活動（event 2305/2306，agent 59457，group 60）的 `EVENT_REWARD_MOBILE_VALIDATION` = **0 且為明確設定**，擋不到任何玩家。實名檢查因 `sendPrize` 內部本有而重複無影響。**行為無實質差異** |
| SQL injection | Rorin 逐一查過所有 `DB::raw`／`whereRaw`：皆為常數或已綁定，**無拼接使用者輸入** |
| `EventNewInviteService` 的 `GET_LOCK` | Rorin 確認：走 write PDO、與交易同連線、`finally` 釋放、key 長度與粒度正確，**併發防護沒問題** |
| `LuckyDrawService` / `WJackpotService` 領獎併發 | 條件式 `UPDATE ... WHERE status = 0` ＋ 檢查影響列數，原子且綁 `member_id`，**本批最好的併發防護** |
| API 契約 | 全為新增路由，未改既有 Resource／欄位／狀態碼，**無 breaking change** |
| 舊版對照完整性 | Marz 逐行對過 `sendPrize`／`checkPrizeBlock`／`addRewardClaimRecord`／`cancelPrize`／`choosePrize`／`validateLegacyEvent`／老虎機爭霸賽整套，**無文件外的搬漏** |

### 需 PM 決定（2026-08-03 已全數確認）

| 項目 | 說明 | 決定 |
|---|---|---|
| `receiveAll` 停用獎項的處理 | 舊版 `receive_all` 的 JOIN **無 `p.status = 1`**，獎項主檔停用但票券仍在時照付；本次收緊為擋下整批（回 2001）。放寬＝主檔已停用仍派錢，收緊＝玩家手上票領不到 | ✅ **維持收緊**，不改回舊版寬鬆行為 |
| 排行榜帳號遮蔽規則 | 與第二輪「`maskEventUsername` 遮蔽強度」為同一議題，見該處結論 | ✅ **維持現狀（vw 規則，只遮中間 2 字）**，見第二輪 review 章節 |
| 登入送修好後會開始實際派彩 | 見 2-10：舊版寫入不存在的欄位，此路徑實際上從未真的派過彩；修正後會開始正常運作 | ✅ **維持修正、寫入 `lottery_point`**，接受登入送恢復正常派彩 |
| 序號 23 對 tpower 的 `flow_limits` 由 1 列變 N 列 | 見 2-12：目前無進行中活動受影響 | ✅ **N 列沒關係**，維持統一走共用 `sendPrize` 的處理方式 |

### 其餘測試缺口（記錄，未阻擋）

`sendPrize` 的 `WEEKLY_RESCUE_FUND` 分支；`EventNewInviteService::sendPrizeToWallet` 的 `point_type = 2 / 3` 分支；`EventService::buildLegacyEventRow` 的 `PERIODIC_CLAIM`（優先）／`MULTI_TIER_ACHIEVEMENT`／`BET_ONCE_A_DAY` 分支；`getLegacyEventDetail` 的每日領取＋取消狀態交叉分支。

### 範圍外（建議另開單）

- **全站無 rate limit 強制**：`RateLimitNotify` 只告警不阻擋，全專案無 `throttle` middleware。這是上述併發利用與免登入放大攻擊的共同前提。
- **派彩成功路徑無 log**：`sendPrize()` 成功後無結構化 log，出事只能靠 `transaction_logs` 反推。
- **`event_payout_cancels.remark` / `reward_claim_records.value` 存明文 PII**（真實姓名／手機／IP，沿用舊版）；已確認不外流給玩家，屬資料留存與合規面。
- **`members.event_mode` 的 `->change()` 部署風險**：需逐白標站確認現況型別，非 int 的站要排維護窗口。
- **舊系統熱修評估**：上表 5 條「舊版既有」的 Critical 現正跑在 `API_Frontend` 正式環境，其中 `prize/choose`（玩家自選派彩金額）建議獨立評估是否先在舊系統修補。

---

## Code Review 結果與待辦（2026-08-03，panel review 第二輪）

序號 35–70（積寶／刷分排行榜／中獎-平台投注排行榜／VIP／登入活動／場館遊戲與費率）完成後執行第二輪四人 panel review。與第一輪不同，本輪 **5 條有效發現中 4 條是本次遷移新引入**（第一輪多為舊版既有漏洞照搬），集中在 `RefreshRankingService` 一支檔案。

### 已修

| # | 項目 | 位置 | 來源 | 歸屬 | 狀態 |
|---|---|---|---|---|---|
| 1 | `leader_board` / `awards` 補 `assertEventAccessible()`，`awards` content 查詢補 `e.status = 1` | `RefreshRankingService` | Rorin | **本次引入**（同構的老虎機爭霸賽有、此處漏做） | ✅ |
| 2 | `leader_board` 未帶 `per_page` 且無啟用獎項時退化成 `LIMIT 0`（舊版為不加 `LIMIT`） | `RefreshRankingService`＋`SlotCompetitionService` | Marz | **本次引入**（對舊版 `take(null)` 判讀錯誤） | ✅ 兩支一併修，錯誤註解已更正 |
| 3 | 自己補位查詢誤套主排行榜的 `bet_amount > 0` / `is_calc = 1` | `RefreshRankingService` | Marz | **本次引入**（舊版 `$self_data` 刻意不設） | ✅ |
| 4 | `wallet_balance` 無鎖建錢包 → 併發撞 unique index 噴 500，改 `firstOrCreate()` | `RefreshRankingService` | Timo / Rorin | 舊版既有 | ✅ |
| 5 | `getEventsByVendor` 掉了舊版的 Redis 快取層，N+1 從 cache-miss 才付變成每次都付 | `EventService` | Marz | **本次引入** | ✅ 補 `Cache::safeRemember`，key／TTL 沿用舊版 |
| 6 | ~~`getLoginEventByDomain` 與 `getLoginEvent` 函式體 100% 重複 → 收成委派~~ | `EventService` | Rhyse | **本次引入** | ⚪ 已失效：PM 後續確認序號 60–64 整組不需要遷移，`getLoginEvent`/`getLoginEventByDomain` 已隨之整段移除（見第 7 章），此修正連同其標的一併消失 |
| 7 | 兩處 docblock 被新方法插隊成孤兒（`getLegacyEventListByDomain`、`validateLegacyEvent` 失去註解） | `EventService` | Rhyse | **本次引入** | ✅ |
| 8 | VIP 領獎補實名認證檢查（`vip/receive`、`vip/receive-rebate`） | `VipService` | Rorin | 舊版既有（舊版無此檢查） | ✅ PM 已決定，見下 |
| 9 | `hierarchy_path LIKE '{path}%'` 前綴碰撞：改為 `= path OR LIKE path/%` | `RankingService`（`getGameWinners`／`getProfits`／`getWinningRanking`／`getPlatformBettingRanking`，共用新增的 `hierarchyPathScope()`） | Marz / Rhyse | 舊版既有（`getGameWinners`／`getProfits` 為既有程式碼，本輪新增兩支沿用同一寫法） | ✅ 原列為全專案議題暫緩，使用者指示直接修；範圍限本檔 4 支方法，`AgentController`／`MarqueeController` 已是正確寫法不需動 |
| 10 | 排行榜 4 支快取改用 `Cache::safeRemember()` | `RankingService` 同上 4 支 | Marz | 全專案一致性議題 | ✅ 使用者指示直接修；`app/Services/` 下其餘 10 個檔案的 `Cache::remember()` 未動，留待日後個別遷移 |
| 11 | `ranking/winning` 快取 key 補 `languageId` | `CacheKeys::winningRankingList()` | Marz | 舊版既有（Redis key 同樣只有 `agent_id+type`） | ✅ 使用者指示直接修 |

補 7 支回歸測試（`RefreshRankingServiceTest` 4 支、`SlotCompetitionServiceTest` 1 支、`VipServiceTest` 2 支）：跨代理拒絕 ×2、無啟用獎項時撈全部、自己 `is_calc = 0` 仍補尾端、VIP 未實名拒絕 ×2。第 2、3 條在修正前的程式上會失敗，非事後補的形式測試。

#### 第 8 條：VIP 領獎的實名門檻（PM 決定，2026-08-03）

VIP 獎勵領取採 **`RealnameValidationService::isRealnameValidated()`，強制檢查、不看代理開關**，與活動獎勵走的 `isEventRewardRealnameValidated()`（受 `EVENT_REWARD_REALNAME_VALIDATION` 開關控制）不同。錯誤訊息沿用既有 `error_message.realname_invalid.event_reward`（三語系皆已存在），未新增語系鍵。

| 端點 | Service | 舊版行為 | 本次處理 |
|---|---|---|---|
| `vip/receive`（序號 54） | `VipService::claimReward()` | 無任何實名檢查 | 鎖分模式檢查後強制 `isRealnameValidated()` |
| `vip/receive-rebate`（序號 55） | `VipService::claimRebateRewards()` | 無任何實名檢查 | 同上 |

> 屬**刻意偏離舊版**：未實名會員原本領得到 VIP 獎勵與每日返水，改版後會被擋下（回實名認證提示）。上線後客服可能收到反饋，屬預期行為。

### 已驗證但不需處理

| 項目 | 驗證結果 |
|---|---|
| `groupBy` 選非聚合欄位（`getWinningRanking`） | `config/database.php` 的 `modes` 明確把 `ONLY_FULL_GROUP_BY` 從 strict mode 移除，不會拋錯 |
| 排行榜帳號遮蔽 `Common::maskString()` 是否等價舊版 SQL 公式 | 逐項比對舊版 `CONCAT/LEFT/REPEAT/RIGHT` 的 60% 規則：**演算法完全等價** |
| 積寶 `businessDayRange()` 07:00 與 `getMemberPrizes()` 16:00 cutoff 的時區 | Marz 查證兩邊 `config/app.php` 皆 `timezone = UTC`，舊版該處未做 `Asia/Taipei` 位移，**新舊同為 UTC 邊界，一致** |
| `platform_betting` type ≤ 2 的「前一 frontend 日」換算 | Marz 反推等價時刻：新舊皆算出同一個 UTC 起訖，**換算正確** |
| `claimRebateRewards` 任一筆失效整批 rollback | 舊版 `receive_rebate` 同樣單一 transaction 包整個 foreach，**all-or-nothing 行為一致** |
| 查無資料的回傳形狀與錯誤碼 | Marz 逐支比對 1901/1946/1947/1953/1934/1426/1213，**全部一致**（`awards` 例外見已修第 1 條） |
| `hierarchy_path` 空值 / 前綴碰撞可能導致跨白標傾印 | 查 tpower 正式 DB：空 `hierarchy_path` **0 筆**、有效 domain 的 agent 與其他 agent 的 path 前綴碰撞 **0 筆**，目前打不穿；碰撞寫法本身已於下方第 9 條修正 |
| `new VendorService` 手動 new | 全專案既有 5 處同寫法（`BetHelper`、`AgentGameService`、`MemberTransferService`、`RankingService`、`EventService` 自身），**屬既有慣例**，只改一處反而不一致 |

### 需 PM 決定

| 項目 | 說明 |
|---|---|
| ~~`maskEventUsername` 遮蔽強度~~ | **PM 決定：維持現狀，不改。** 查證確認 tpower-master／vw-master 兩邊舊系統原始碼逐字相同，皆為「固定遮中間 2 字」，此次遷移僅將既有生產環境行為抽成共用 `MaskTrait::maskEventUsername()`，未改變任何線上顯示 |
| ~~VIP 領獎未做實名驗證~~ | **已決定並實作**：採強制檢查、不看代理開關，見上方「第 8 條」 |

### 範圍外（建議另開單）

- **積寶 `getEvent`／`getWinList` 多活動命中時無定序**：兩支各自 `first()` / `value()` 且無 `orderBy`，自己與上線同時有進行中積寶活動時，`show` 與 `win_list` 可能落在不同活動上。查證 5 個白標正式 DB（tpower／vworld／alfawin77／me77／yes2win）：**目前每個白標同時間最多只有 1 筆 `status=1` 的積寶活動，且 `hierarchy_path` 皆只有兩層，不構成命中多筆的條件，屬結構性風險、非現正發生的 bug**。維持現狀不改；若未來某白標同時開兩場積寶活動且落在同一代理鏈上，需補顯式定序（近優先）。
- **`app/Services/` 下其餘 10 個檔案的 `Cache::remember()` 未遷移至 `safeRemember()`**：`RankingService` 本次已跟上（見第 10 條），其餘檔案屬全專案一致性議題，逐檔評估風險後再遷移。

---

## 1. 抽獎 / 彩金活動（`store/modules/eventLuckyDraw/index.js`，整檔零新版）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 1 | 抽獎期數列表 | `api/event/lucky-draw/issues`<br>`frontend/api/event/lucky-draw/issues` | WEB_Frontend<br>:53 | 是 | apis: `EventLuckyDrawController@issueList` |
| 2 | 期數明細 | `api/event/lucky-draw/issues/detail`<br>`frontend/api/event/lucky-draw/issues/detail` | WEB_Frontend<br>:99 | 是 | apis: `EventLuckyDrawController@issueDetail` |
| 3 | 我的票券 | `api/event/lucky-draw/issues/tickets`<br>`frontend/api/event/lucky-draw/issues/tickets` | WEB_Frontend<br>:148 | 是 | apis: `EventLuckyDrawController@issueTickets` |
| 4 | 領獎 | `api/event/lucky-draw/issues/receive`<br>`frontend/api/event/lucky-draw/issues/receive` | WEB_Frontend<br>:195 | 是 | apis: `EventLuckyDrawController@receive`；沿用舊版直接記 `Member.balance` 行為；apis 另有 `WalletRepository`／`Agent::is_multi_wallet` 多錢包分流機制（見 `CheckinService`），依遷移原則先保留舊版一致，待測試完成後再評估整合 |
| 5 | 一鍵領獎 | `api/event/lucky-draw/issues/receive-all`<br>`frontend/api/event/lucky-draw/issues/receive-all` | WEB_Frontend<br>:251 | 是 | apis: `EventLuckyDrawController@receiveAll`；備註同上 |
| 6 | W彩金期數列表 | `api/event/wjackpot/issues`<br>`frontend/api/event/wjackpot/issues` | WEB_Frontend<br>:54 | 是 | apis: `EventWJackpotController@issueList`；沿用舊版 `limit(3)`（issues_1272 [GFF][WTT] 領取時間限制） |
| 7 | W彩金期數明細 | `api/event/wjackpot/issues/detail`<br>`frontend/api/event/wjackpot/issues/detail` | WEB_Frontend<br>:100 | 是 | apis: `@issueDetail`；回 5 組開獎號碼，`result_number_4/5` 以逗號展開為陣列；未開獎佔位 `----`（4 碼，6D 為 `------`、V-Jackpot 為 `-------`，不可共用） |
| 8 | W彩金我的票券 | `api/event/wjackpot/issues/tickets`<br>`frontend/api/event/wjackpot/issues/tickets` | WEB_Frontend<br>:149 | 是 | apis: `@issueTickets` |
| 9 | W彩金領獎 | `api/event/wjackpot/issues/receive`<br>`frontend/api/event/wjackpot/issues/receive` | WEB_Frontend<br>:196 | 是 | apis: `@receive`；TransactionEnum 30。**與序號 4 不同**：稽核流水倍數取自 `prizes.flow_limit_multiple`（非 agent 設定 `EVENT_FLOW_LIMIT_RATE`）、`flow_limits` 不帶 `event_id`、`operator='system'`、`prize_members` 無 `member_id=0` 補償（SP 以正確 member_id 寫入，見下方 SP 欄）。另有實名檢查（2047） |
| 10 | W彩金一鍵領獎 | `api/event/wjackpot/issues/receive-all`<br>`frontend/api/event/wjackpot/issues/receive-all` | WEB_Frontend<br>:252 | 是 | apis: `@receiveAll`；**沿用舊版：查無可領取券時靜默回成功（不拋 1000），與序號 15 的 6D 版行為刻意不同** |
| 11 | W彩金6D期數列表 | `api/event/wjackpot6d/issues`<br>`frontend/api/event/wjackpot6d/issues` | WEB_Frontend<br>:55 | 是 | apis: `@issueList6d`；6D 共用 `lucky_draws`/`lucky_draw_tickets`/`reward_items`，以 `prizes.type_id = 7` 區分；同樣 `limit(3)` |
| 12 | W彩金6D期數明細 | `api/event/wjackpot6d/issues/detail`<br>`frontend/api/event/wjackpot6d/issues/detail` | WEB_Frontend<br>:101 | 是 | apis: `@issueDetail6d`；未開獎佔位 `------`（6 碼） |
| 13 | W彩金6D我的票券 | `api/event/wjackpot6d/issues/tickets`<br>`frontend/api/event/wjackpot6d/issues/tickets` | WEB_Frontend<br>:150 | 是 | apis: `@issueTickets6d`；6D 專屬多一條狀態分支：已中獎但超過 `draw_time + expire_hour` 也判為 4（已過期） |
| 14 | W彩金6D領獎 | `api/event/wjackpot6d/issues/receive`<br>`frontend/api/event/wjackpot6d/issues/receive` | WEB_Frontend<br>:197 | 是 | apis: `@receive6d`；TransactionEnum 31；6D 專屬逾期檢查 → 1948 |
| 15 | W彩金6D一鍵領獎 | `api/event/wjackpot6d/issues/receive-all`<br>`frontend/api/event/wjackpot6d/issues/receive-all` | WEB_Frontend<br>:253 | 是 | apis: `@receive6dAll`；以 `whereRaw` 過濾逾期券；**查無可領取券時拋 1000（與序號 10 不同）** |

### 1-1. 相關 Store Procedure（`database/procedures/`）

派券與派獎由 SP 負責，本次遷移的 API 只做「領取」，不改動 SP。

| SP | 用途 | 與遷移的關聯 |
|---|---|---|
| `SP_set_lucky_ticket.sql` | 產生 V-Jackpot 抽獎券 | 序號 1-5 資料來源 |
| `SP_payout_lottery_ticket_reward.sql`<br>`SP_payout_lottery_ticket_reward_auto.sql` | V-Jackpot 開獎派獎、寫 `prize_members` | 序號 4/5 領獎前提 |
| `SP_set_w_jackpot_ticket.sql` | 產生 W-Jackpot 抽獎券 | 序號 6-10 資料來源 |
| `SP_payout_w_jackpot_ticket_reward.sql` | W-Jackpot 開獎派獎；以 `wjt.member_id` 正確寫入 `prize_members(lottery_number = ticket.id, status = 0)` | 佐證序號 9/10 不需 `member_id=0` 補償 |
| `SP_set_lucky_ticket_6d.sql` | 產生 6D 抽獎券 | 序號 11-15 資料來源 |
| `SP_payout_lottery_ticket_6d_reward.sql` | 6D 開獎派獎；同樣以正確 `member_id` 寫入 `prize_members` | 佐證序號 14/15 不需補償 |
| `SP_calc_flow_limit.sql` | 稽核流水達標計算 | 僅特判 `flow_limits.type = 105`，其餘 type 值僅作來源標記 |

### 1-2. 本次遷移的已知差異與修正

| 項目 | 舊版行為 | apis 現況 | 處理 |
|---|---|---|---|
| `flow_limits.type` | 存 TransactionEnum 值（V-Jackpot=18） | apis 慣例相同（`TransactionEnum::V_REWARD` 等） | 初版誤寫死 `1`，已修正為 `TransactionEnum::V_JACKPOT_REWARDS->value` |
| 領取時 ticket 更新失敗（併發被搶先） | 拋 `2001` | — | 初版誤用 `1943`，已改回 `2001` 與舊版一致 |
| 領獎期限計算基準 | `event_details.draw_time + expire_hour` | — | 初版誤用 `end_time`，已修正為 `draw_time` |
| `receive-all` 實名錯誤訊息 | 用 `error_message.event_reward_realname_invalid`，**該 key 在舊專案不存在**，實際回傳 key 原文 | apis 有 `realname_invalid.event_reward` | **刻意修正**：統一用 `realname_invalid.event_reward`（與 `receive` 一致），不重現舊版 i18n bug |
| HTTP 回應碼 | body `code` 帶 1934/1941/2047 等，HTTP 200 | apis `ApiException` 統一 HTTP 4xx、body `code` 預設 400 | 依 apis 既有慣例（同 `CouponController`），僅保留訊息 |

## 2. 活動 / 獎品（`store/modules/events/index.js`）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 16 | 股東分潤 | `api/shareholder/profit`<br>`frontend/api/shareholder/profit` | WEB_Frontend<br>events/index.js:71 | 是 | apis: `ShareholderController@getProfit`（詳見 16-1）。**免登入端點**（舊版屬 `none_auth` 群組，依 `domain` 判定品牌），故置於 `routes/frontend/non_auth.php`，與需登入的 `shareholder/apply` 不同群組 |
| 17 | 舊版活動列表 | `api/events`<br>`frontend/api/event/legacy-list` | WEB_Frontend<br>:112 | 是 | apis: `EventController@list` → `EventService::getLegacyEventList`（詳見 2-2）。**兩分支互有獨有邏輯，已取聯集**。命名為 `legacy-list` 以與既有 `frontend/api/event/list`（V1+V2 輕量目錄）區隔，兩者用途不同不可互換 |
| 18 | 舊版活動列表（依網域） | `api/eventsByDomain`<br>`frontend/api/event/legacy-list-by-domain` | WEB_Frontend<br>:114 | 是 | apis: `EventController@listByDomain`（詳見 2-3）。兩分支完全相同。**免登入端點**（舊版屬 `none_auth`），置於 `non_auth.php` |
| 19 | 邀請活動 | `api/event/getNewInviteEvent`<br>`frontend/api/event/new-invite` | WEB_Frontend<br>:604 | 是 | apis: `EventController@newInviteEvent` → `EventNewInviteService::getEvent`（詳見 2-6）。兩分支 controller 完全相同，**僅 vw-master 掛路由** |
| 20 | 邀請獎品 | `api/event/getNewInvitePrize`<br>`frontend/api/event/new-invite/receive` | WEB_Frontend<br>:645 | 是 | apis: `EventController@newInvitePrize` → `EventNewInviteService::receivePrize`（詳見 2-6）。**含派彩**（平台點數／禮碼／遊戲錢包）與 flow_limit |
| 21 | 取消獎品 | `api/cancelPrize`<br>`frontend/api/prize/cancel` | WEB_Frontend<br>:721 | 是 | apis: `PrizeController@cancel` → `PrizeService::cancelPrize`（詳見 2-11）。兩分支**逐行完全相同** |
| 22 | 活動詳情 | `api/event`<br>`frontend/api/event/legacy-detail` | WEB_Frontend<br>:765 | 是 | apis: `EventController@view` → `EventService::getLegacyEventDetail`（詳見 2-4）。兩分支完全相同。遊戲列表改用既有 `VendorService` / `AgentGameService` |
| 23 | 取得所有獎品 | `api/getAllPrize`<br>`frontend/api/prize/draw-all` | WEB_Frontend<br>:889 | 是 | apis: `PrizeController@drawAll` → `PrizeService::drawAllPrizes`（詳見 2-12）。名稱雖為 get 實為**一次領取多筆**。tpower 424 行 vs vw 350 行，**差異 105 行；派彩實作互斥，統一走 `sendPrize`** |
| 24 | 取得獎品 | `api/getPrize`<br>`frontend/api/prize/draw` | WEB_Frontend<br>:889 | 是 | apis: `PrizeController@draw` → `PrizeService::drawPrize`（詳見 2-10）。tpower 417 行 vs vw 402 行，**差異 113 行，已取聯集** |
| 25 | 依明細取獎品 | `api/getPrizeByDetailId`<br>`frontend/api/prize/draw-by-detail` | WEB_Frontend<br>:1051 | 是 | apis: `PrizeController@drawByDetail` → `PrizeService::drawByDetail`（詳見 2-8）。兩分支**逐行完全相同**；一併建立共用派彩核心 `PrizeService::sendPrize`（**兩分支取聯集**），23／24／26 之後可直接沿用 |
| 26 | 設定獎品 | `api/setPrize`<br>`frontend/api/prize/set` | WEB_Frontend<br>:1107 | 是 | apis: `PrizeController@setPrize`（詳見 2-9）。**兩分支皆為空殼**：開頭直接回成功，之後全是不可達死碼（註解「打開就直接領取了，所以這個沒用 -- 2019-11-25」），僅為前端相容保留 |
| 27 | 選擇獎品 | `api/choosePrize`<br>`frontend/api/prize/choose` | WEB_Frontend<br>:1145 | 是 | apis: `PrizeController@choose` → `PrizeService::choosePrize`（詳見 2-9）。**tpower-master 獨有**（vw-master 無此方法也無路由）；先前備註「無對應路由」係比對 vw-master 所致，實際在 tpower-master `routes/api.php:476` |
| 28 | 活動驗證 | `api/event/validate`<br>`frontend/api/event/legacy-validate` | WEB_Frontend<br>:1182 | 是 | apis: `EventController@validateEvent` → `EventService::validateLegacyEvent`（詳見 2-5）。**tpower-master 獨有**（vw-master 無 `EventV1Controller` 也無路由）；先前備註「routes/api.php 無對應路由」係比對 vw-master 所致，實際在 tpower-master `routes/api.php:374` |
| 29 | 獎品清單 | `api/prizes`<br>`frontend/api/prize/list` | WEB_Frontend<br>:1218 | 是 | apis: `PrizeController@list` → `PrizeService::getClaimedList`（詳見 2-7）。兩分支完全相同。**本區塊唯一純唯讀端點**（21、23、24、25、26、27 皆有寫入／派彩） |

### 2-1. 序號 16 股東分潤（`shareholder/profit`）遷移細節

**分支差異（重要）**：此功能**只存在於 `tpower-master`**，`vw-master` 完全沒有 `ShareholderController` / `ShareholderService` / `ShareholderRepository`，路由也不存在。與 apis 既有 `shareholder` 路由群組標註「TP 專屬」一致，因此直接以 tpower-master 版本為準，無需取捨。

**放置位置**：舊版 `ShareholderService` + `ShareholderRepository` 兩層僅為單一聚合查詢服務；apis 無 shareholder Repository，故將 `getProfit()` 併入既有 `ShareHolderService`（同領域），查詢直接寫在 service 內，未另建 Repository。

**`DB::table()` 使用理由**：`summary_shareholder_temps`、`shareholder_categories`、`shareholder_payout_logs` 三表皆無對應 Model，且為純聚合唯讀查詢，符合 `CLAUDE.md`「Model 不存在的聚合查詢」例外。

**相關 Store Procedure**（`database/procedures/`，本次僅讀取結果，未改動任何 SP）：

| SP | 用途 |
|---|---|
| `SP_calc_shareholder.sql`／`SP_calc_shareholder_new.sql`／`SP_calc_shareholder_winlose.sql` | 計算分桶輸贏並寫入 `summary_shareholder_temps` |
| `SP_shareholder_payout.sql`（另有 `SP_shareholder_payout2.sql`） | 股東分潤派發，寫入 `shareholder_payout_logs`；本 API 只取 `status = 1`（已派發）的日期 |
| `SP_calc_point_barrels.sql`／`SP_calc_point_barrel_winlose.sql` | 分桶相關計算 |

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 找不到分潤資料 | `while (true)` 每次往前一天，**無上限**，查無資料即無限迴圈 | 加回溯上限 90 天，耗盡回 `total_profit = '0'` | 本端點**免登入**，無限迴圈會直接吊住 request，屬可被利用的 DoS 風險 |
| `domain` 查無對應代理 | `$domainInfo->customized_setting_group_id` 讀 null 屬性 → PHP 8 致命錯誤 | 明確拋 `ApiException`（1005 會員/資料不存在） | PHP 7.3 → 8.4 相容性；舊寫法在 8.4 會 fatal |

**待評估（未處理）**：逐日回查的做法在資料稀疏時會發出數十次查詢（實測回溯 44 天約 2.2 秒）。可改以單一查詢取「最近一筆已派發 `summary_time`」取代迴圈，但屬效能重構，依遷移原則本次不動，留待遷移完成後另案評估。

### 2-2. 序號 17 舊版活動列表（`api/events`）遷移細節

**與既有 `frontend/api/event/list` 的關係**：apis 既有的 `EventV2Controller@list` 是 V1+V2 合併的**輕量目錄**（9 欄：name / image / sort…）；舊版 `api/events` 是**帶每位會員計算狀態**的清單（`reward_point`、`consecutive_day`、`reached_today`、`show_exchange`、`remaining_number`…）。兩者用途不同，故新路由命名為 `event/legacy-list`，未覆蓋既有端點。

**分支差異與合併結果（取聯集）**：`new_list` 是本批唯一兩分支互有獨有邏輯者（tpower 718 行 / vw 590 行，差異 154 行）。

| 邏輯 | tpower-master | vw-master | 本次處理 |
|---|---|---|---|
| action 17 每周救援金（`reward_point`） | ✅ | ❌ | 收錄 |
| action 59 每日連續儲值（`consecutive_day` 等約 90 行） | ✅ | ❌ | 收錄 |
| action 56、prize `type_id=10`、`times_a_day` | ✅ | ❌ | 收錄 |
| `no_schedule_action_id` | `[53,54,55,58,12,14,16]` | `[53,54,55,12,16]` | 取 tpower（較完整） |
| 活動 `start_time` / `end_time` 過濾 | ✅ | ❌ | 收錄 |
| `flow_limit_game_type` | ❌ | ✅ | 收錄 |

兩邊邏輯皆由 `action_id` / `prize.type_id` / `example.invalid` 設定觸發，未設定的白標自然不會走到，故聯集不會互相干擾。

**一併遷移的 helper**（原散落在舊版 base Controller / EventService，apis 皆不存在）：`getVendorMaintenance`、`checkRewardClaimRecord`、`checkPrizeStatus`、`eventCodeEncrypt`（含週期範圍 `getPeriodRange`）。

**相關 Store Procedure**：本 API 僅讀取；產生資料的 SP 見 `SP_event_deposit.sql`、`SP_event_consecutive_deposit.sql`（action 59）、`SP_payout_weekly_rescue.sql`（action 17）、`SP_event_invite_deposit.sql`（action 16）等，均未改動。

**注意**：`action_id 31`（邀請玩家優惠）在列表查詢中會**寫入** `event_codes`（沿用舊版：無邀請碼時即時補建），屬讀取端點中的既有寫入行為，已如實保留。

### 2-3. 序號 18 依網域活動列表（`api/eventsByDomain`）遷移細節

兩分支（tpower-master / vw-master）**逐行完全相同**，無取捨。

**與序號 17 的差異**（本質是同一份列表的未登入版，沿用舊版）：

| 項目 | 17 `api/events`（需登入） | 18 `api/eventsByDomain`（免登入） |
|---|---|---|
| 對象判定 | 會員所屬代理 | `domain` → `agent_domains` → 代理 |
| 主查詢時間過濾 | `event_details` 與 `events` 皆過濾 `NOW()` | **不過濾**（未開始／已結束的活動仍會出現） |
| 排除代理 `event_exclude_agents` | 有 | 無 |
| 兌換狀態 | 依 `prize_members` 實算 | 固定 `exchanged=1, exchange=0, expired=1` |
| 進度 `now_point` | join `event_processes` 實算 | 固定 `0` |
| `reached_today`（action 55） | 依當日領取紀錄 | 固定 `0` |
| 邀請碼（action 31） | 無則**寫入** `event_codes` | 固定 `'-'`，不寫入 |
| 按鈕狀態分支 | 完整（12/16/17/59/61/52/54…） | 僅過期 / 已兌換 |
| 排序 | `events.created_at desc` | `events.id desc` |

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `getVendorMaintenance` 的代理層級 | 走 `is_agent=true` 分支，以 `explode('/', agents.hierarchy)` 取層級；但 `hierarchy` 實際格式為 `[-1,51,1,-2]`（非斜線分隔），切出來是單一字串，`whereIn` 幾乎不可能命中 → **維護狀態恆為 0** | 統一改用 `hierarchy_path` 推導的 `Agent::hierarchy_array`（與會員路徑一致） | 舊版取錯欄位，導致未登入列表永遠顯示「未維護」；修正後遊戲商維護中會如實回報 |
| `domain` 查無對應代理 | 讀 null 屬性 → PHP 8 致命錯誤 | 明確拋 `ApiException` | 同序號 16，PHP 7.3 → 8.4 相容性 |

### 2-4. 序號 22 舊版活動詳情（`api/event`）遷移細節

兩分支（tpower-master / vw-master）的 `EventController@view`（465 行）**逐行完全相同**，無取捨。

**與序號 17 列表的關係**：詳情頁是列表單筆的擴充版，兩者共用同一段 `event_details` 查詢與按鈕狀態邏輯，但詳情頁**額外帶出「參與活動的遊戲列表」**（`detail[].games`），且按鈕狀態分支較少（僅 55 / 12 / 52 / 54，無 16 / 17 / 59 / 61）。獎項內容欄位也不同：列表回 `prize_contents.*`，詳情僅回 `id / name / type / point / point_percentage / number`。action 9（老虎機輸分回扣）時獎項掛在 `prize_content` 並轉成百分比，其餘掛在 `prize`。

**共用既有 service（本次唯一一處以 apis 既有實作取代舊版 helper）**：舊版 `getGameByDetailId` 依賴 base Controller 的 `vendorList()` / `agentGameList()`，這兩支是**遊戲商 / 遊戲清單的共用基礎設施**，apis 早已於 `VendorService::getVendorIds()`、`VendorService::getVendorInfoIds()`、`AgentGameService::getGameIdList()` 完成遷移（既有 `lobby/vendors`、`game/list` 皆走這套，含快取）。重複移植等同在專案內開第二套代理層級解析，故改為呼叫既有 service；其餘查詢（`games` / `vendor_info_games` / `favourite_games` join 與回傳欄位）維持舊版原樣。兩者語意差異：apis 版會過濾 `agent_id > 1`（排除 admin）並帶 1 小時快取。

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 品牌（代理）限制 | 只以 `events.id` + `status` 查詢，**未限制代理層級**，任一會員可讀他牌活動詳情（且 action 31 會為該他牌活動寫入自己的 `event_codes`） | 補上 `whereIn('events.agent_id', hierarchy_array)`，與序號 17 列表一致 | 跨品牌越權讀取；列表已做此限制，補上後不影響任何合法來源（前端 id 皆來自列表） |
| 舊版 L1953 `$detail->now_point = ...` | 在 `foreach` 外使用迴圈殘留的 `$detail`；`$details` 為空時 PHP 8 會拋 `Attempt to assign property on null`，非空時則為重複計算（值不變） | 移除 | 死碼且在 PHP 8.4 會致命 |
| `getGameByDetailId` 內 `else { return response()->json(...) }` | 寫在 `join()` 的 closure 內，回傳值被 Laravel 忽略 → 實際上 action 2 / 3 以外**不套用任何 `join_bet_event` / `join_win_event` 條件** | 如實保留（僅 2 / 3 加條件） | 保持行為一致；已於單元測試釘住 |

**未變更之舊版行為**（一併記錄，非 bug）：`action_id 31` 在此讀取端點同樣會**寫入** `event_codes`（同序號 17）；`prizes.expire_date <= NOW()` 的活動在主查詢即被濾掉，故詳情會回空陣列。

**測試**：`tests/Unit/Services/LegacyEventDetailTest.php`（12 支）。真實資料 dry-run 涵蓋 4 個代理、action 2/11/15/16/53/54/55/59/60/61、prize type 3/5/7，皆在 70–110ms；dev DB 目前無「明細綁定遊戲商且獎項未過期」的活動，`games` 路徑僅由單元測試覆蓋（含 `join_bet_event` 旗標開關）。

### 2-5. 序號 28 舊版活動驗證（`api/event/validate`）遷移細節

**分支差異**：**tpower-master 獨有**。`EventV1Controller` / `EventV1Service` 在 vw-master 完全不存在，路由也只在 tpower-master `routes/api.php:374`。無取捨問題，直接收錄；未設定 action 60 活動的白標不會走到。

**行為**：驗證會員是否符合 action 60（tpower 邀請玩家活動）的達標條件，達標則**預先建立一筆 `prize_members`（status=0，待領取）**；實際領獎與 `event_invite_records.prize_member_id` 回寫在舊版 `PrizeController`（序號 26，尚未遷移）。`switch` 只有 `case 60`，其餘 action 一律拒絕。

達標判定（`getInvitedMembers`）依序為：全域封鎖檢查（`global_ip_block` / `global_phone_block` / `global_account_block`，比對同一 `event_detail` 下他人的 `prize_members.ip` / `members.mobile` / `members.first_name`）→ 撈該會員名下未綁定的 `event_invite_records`（依 `ip_block` / `phone_block` / `account_block` 過濾重複、要求被邀請人 `realname_validate` 與 `mobile_validate` 皆為 1 且 `status=1`，`first_deposit_days` / `first_deposit_amount` 另加首儲條件）→ 筆數未達 `example.invalid.achieve_people` 即拋錯。

**錯誤碼對應**（apis 慣例不沿用舊版數字碼，改對應既有語系鍵）：

| 舊版 | 舊版訊息 | 本次使用 |
|---|---|---|
| 1001 / 1002 | `event not found` / `action id not found`（皆為裸英文字串） | `error_message.1901`（`查無此活動`） |
| 2001 | `prize member blocked`（裸英文字串） | `error_message.1937`（`不符合參加活動的條件`） |
| 1987 | `邀請人數不足，已邀請人數為 :invite` | **新增 `error_message.1990`**（apis 的 1987 已被「一個總代理只能建立一個打卡活動」占用）。比照舊版僅有 en / zh_TW / zh_CN 三語，其餘語系走 `fallback_locale=en` |

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 查無活動 | 寫成 `new \Exception('event not found', 1001)` **未 `throw`**，接著讀 `$event->action_id` → PHP 8 致命錯誤 | 改為 `throw new ApiException` | 明顯筆誤；PHP 7.3 → 8.4 相容性 |
| 派發待領獎勵 | 先 `SELECT` 再 `INSERT`，**無交易、無鎖** | 包 `DB::transaction` + `lockForUpdate()` | 併發同時呼叫會重複建立待領獎勵（重複發獎）；CLAUDE.md 金流紅線 |
| `getInvitedMembers` 缺 `prizes` / `event_details` | 直接讀 null 屬性 → 致命錯誤 | 明確拋 `error_message.1901` | 同上 |
| `achieve_people` 未設定 | `$prizeInfo['achieve_people']` 未定義 → warning 且 `limit(null)` | `(int) ($prizeInfo['achieve_people'] ?? 0)` | PHP 8.4 相容性 |
| `first_deposit_days` 組 SQL | `'... INTERVAL ' . $prizeInfo['first_deposit_days'] . ' DAY'` 字串串接（未綁定） | 改為綁定參數並轉 `int` | CLAUDE.md 禁止未綁定 `DB::raw` |

**未變更之舊版行為**（一併記錄，非 bug）：`event_invite_records` 的查詢**不以 `event_id` 過濾**（雖然 join 了 `events`），因此該會員名下所有未綁定的邀請紀錄都會被計入，跨活動共用；如實保留。

**測試**：`tests/Unit/Services/LegacyEventValidateTest.php`（10 支）。真實資料 dry-run（agent 4249，活動 1063 / 1098 為 action 60）皆通過並建立待領獎勵（交易內 rollback），action 54 的活動如預期被拒。

### 2-6. 序號 19 / 20 新玩家邀請活動（`api/event/getNewInviteEvent`、`api/event/getNewInvitePrize`）遷移細節

**分支差異**：`EventNewInviteController`（798 行）在 tpower-master 與 vw-master **逐行完全相同**，但**只有 vw-master 在 `routes/api.php:381/383` 掛路由**；tpower 有 controller 無路由。無取捨問題。

**放置**：新增 `app/Services/EventNewInviteService.php`（對應舊版單一用途 controller 及其私有 helper），controller 方法併入既有 `EventController`（依 17–29 集中至 Event / Prize 兩個 controller 的決議）。

**行為**：`getEvent` 回傳邀請碼（`member_codes.agent_code`）＋各獎項的達標人數與兌換狀態；`getNewInvitePrize` 為**實際派彩端點**（平台點數 / 禮碼 / 遊戲錢包三選一，另寫 `flow_limits`）。週期由 `example.invalid.period` 決定（1: 今天, 2: 本週, 3: 本月，其餘為空區間）。

達標計算 `getAchieveCount` 依 level：`0` 計符合條件的邀請註冊人數；`1` 取每位被邀請人本期首筆成功訂單（`status=3`）再比對 `event_details.point`；其餘回 0。過濾條件由 `event_details` 的 `realname_block` / `ip_block` / `phone_block` / `account_block` / `tgid_block` 決定。

**達標欄位不一致（已依 PM 決定統一為 `level`）**

舊版兩支端點傳給 `getNewInvitePrizeCnt` 的第 2 參數不同：

| 端點 | 舊版傳入 | 實際欄位 |
|---|---|---|
| `getNewInviteEvent`（顯示） | `$row->type`，來自 `select 'pc.level as type'` | `prize_contents.level` |
| `getNewInvitePrize`（領獎） | `$prize_content->type`（Eloquent model） | `prize_contents.type` |

`prize_contents.type` 是「點數類型（0: 固定金額, 1: %數）」，`level` 才是獎項層級，而該參數在函式內命名為 `$prize_content_level`、語意是「0: 算人數 / 1: 算首儲」。兩者不一致時會出現「顯示可領取但領獎被拒」或反之（本該不能領卻放行）。經 PM 決定**統一為 `level`**，領獎端改讀 `prize_contents.level`；回應欄位名仍沿用舊版的 `type`（內容即 `level`）以維持前端相容。已於 `test_receive_prize_uses_level_not_type` 釘住此行為。

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 領獎端達標判定欄位 | 讀 `prize_contents.type`（點數類型），與顯示端的 `level` 不一致 | 統一改讀 `prize_contents.level` | 顯示與領獎判定不一致會誤發或誤拒獎勵；經 PM 決定統一 |
| 錢包不存在時建立錢包 | 一律 `wallet_type_id = ROLLING_CHIP(1002)`，**遊戲錢包派彩會落到禮碼錢包**，但同時寫 `wallet_type_id = CODE_GAME(2)` 的 `wallet_transaction_logs` | 依實際 `point_type` 建立對應類型錢包 | 金額進錯錢包，帳務與玩家可用餘額不符 |
| 按鈕文字 i18n | 3 處寫成 `__('prize_button.exchanged')` / `__('prize_button.exchange')`，**漏 `param.` 前綴**，回傳的是 key 原文 | 補上 `param.` 前綴 | 玩家會看到 `prize_button.exchange` 原字串；同檔案其他處（`param.prize_button.can_not_exchange`）寫法正確，屬筆誤 |
| `RollingChipLog` 欄位 | `'status' => 1000, 'remark' => TransactionEnum::STATUS_SUCCESS`（remark 被塞成 `1000`） | `status` 用 `TransactionStatusEnum::STATUS_SUCCESS`、`remark` 用 `'New Invite Reward'`（舊版已算出但只用於平台點數分支） | 明顯欄位錯置，remark 存數字無意義 |
| 首儲門檻查詢 | `whereRaw('o.point >= ' . $event->point)` ＋ `mergeBindings` | `joinSub` ＋ `where('o.point', '>=', ...)` | CLAUDE.md 禁止未綁定 `DB::raw` |
| 錯誤訊息除錯後綴 | `__('error_message.1937') . '_1'` / `'_2'` / `'_3'` | 移除後綴 | 除錯標記外洩給玩家 |
| `if (!is_object($prize_contents))` | `$prize_contents` 為 Collection，恆為物件 → 該檢查永不成立（死碼） | 移除 | 死碼 |
| 交易與鎖 | `DB::beginTransaction()` ＋手動 rollback，`GET_LOCK` 未走 write PDO | `DB::transaction()` ＋ `finally` 釋放鎖；`GET_LOCK` 改用 `DB::selectFromWriteConnection` | apis 為讀寫分離，`GET_LOCK` 若落 read replica 會與後續交易不同連線而失效（同 `PlayTechController` / `FumaSWService` 既有寫法） |

**未變更之舊版行為**（一併記錄，非 bug）：`tgid_block` 檢查不看前面是否已命中（其餘三項為 `&& $is_block == false`），會覆蓋 `block_type` 為 6；回應直接帶出 `prize_info`（`example.invalid` 原始 JSON）。

**暫不處理的 service 整合**：派彩邏輯與 `CheckinService::_sendPoint` / `_sendRollingChip`、`WalletRepository` 高度重疊（皆為主錢包／遊戲錢包／禮碼三分流）。依遷移原則先保留舊版實作，待本批全部遷完並通過測試後再評估合併。

**測試**：`tests/Unit/Services/EventNewInviteServiceTest.php`（15 支，涵蓋達標計算、level 0/1、領獎端改讀 level、重複 IP 過濾、平台點數派彩、flow_limit、多錢包分流、手機重複封鎖與 `event_payout_cancels` 寫入、既有封鎖紀錄即時拒絕）。真實資料 dry-run（agent 59497 / 活動 1224 / 會員 62815）：`getEvent` 回 2 個獎項（98ms），`receivePrize` 派發 7 點，`transaction_logs` type=13、`prize_members` status=1、`flow_limits` 1 筆（7×7），全程交易內 rollback。

### 2-7. 序號 29 已領取獎項紀錄（`api/prizes`）遷移細節

兩分支（tpower-master / vw-master）的 `PrizeController@list` **逐行完全相同**，無取捨。

**與既有 `frontend/api/event/claim-records` 的關係**：apis 既有的 `EventV2Controller@claimRecords`（`EventService::getClaimRecords`）是 **V1＋V2 合併、依日期區間查詢、不分頁**；本端點是 **V1 專屬、不限日期、分頁**，兩者契約不同不可互換。V1 那半段的 join 與欄位運算式兩者一致，屬可評估合併的重複項，依遷移原則先各自保留。

**放置**：新增 `app/Http/Controllers/Frontend/PrizeController.php` 與 `app/Services/PrizeService.php`（依 17–29 集中至 Event / Prize 兩個 controller 的決議），路由 `frontend/api/prize/list`。

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 分頁 `total` | 對 `groupBy('m.id')` 後的 query 直接呼叫 `count()`，Laravel 產生 `select count(*) ... group by m.id` 並取第一列，**`total` 恆為 1**（無資料時為 0），`pages` 因此永遠是 1 | 改以未分組的 `distinct()->count('m.id')` 取得真實筆數 | 舊版分頁資訊失效，前端無法翻頁；`data` 本身的 `skip/take` 切片是正確的，故僅修正計數 |

**未變更之舊版行為**：僅列 `prize_members.status = 1`（已領取）；`point` 於 `prize_contents.type = 1`（%數）時取 `TRUNCATE(prize_members.lottery_point, 2)`，否則取 `prize_contents.point`；`updated_at` 一律 `+8 小時`。

**測試**：`tests/Unit/Services/PrizeServiceTest.php`（6 支，涵蓋僅列本人已領取、語系標題退回、%數與固定金額兩種 point 計算、分頁與排序、空結果）。真實資料 dry-run（會員 59263）：`total=13`、每頁 5 筆、第 2 頁正常，44ms；舊版同一組資料的 `total` 會是 1。

### 2-8. 序號 25 依明細領取獎項（`api/getPrizeByDetailId`）與共用派彩核心 `sendPrize`

`getPrizeByDetailId` 本身兩分支**逐行完全相同**，但它呼叫的 `sendPrize` 是整個 V1 獎項系統的派彩核心（23／24／25／26 共用），**兩分支差異 248 行**，本次一併整理並取聯集。

**流程**：鎖分模式檢查 → 取未過期獎勵主檔 → 取可派發的獎項內容（`number > sent` 或 `number = -1`）→ 鎖定該會員在此明細下未兌換的 `prize_members` → 已抽出則沿用，未抽出則依 `percentage` 抽出並 `sent+1` → `refreshProcess` 檢查進度並刷新 → 檢查 `draw_time + expire_hour` 領獎期限 → `sendPrize` 派彩。

#### `sendPrize` 兩分支差異與合併結果

| 邏輯 | tpower-master | vw-master | 本次處理 |
|---|---|---|---|
| action 17 每周救援金 | ✅ | ❌ | 收錄（依 action_id 分流） |
| action 60 邀請玩家（回寫 `event_invite_records.prize_member_id`） | ✅ | ❌ | 收錄 |
| 隨機獎勵 `random_point_max` | ✅ | ❌ | 收錄（依欄位有值才觸發） |
| 禮碼 type：action 58 周期充值 | ✅ | ❌ | 收錄 |
| 流水限制來源 `prize_content_flow_limits` 表 | ✅ | ❌ | **兩者並存**（各品牌只有自己那份資料） |
| 流水限制來源 `example.invalid.flow_limit_vendor_id` / `flow_limit_game_type` | ❌ | ✅ | 同上 |
| 多錢包時改派遊戲錢包（`is_multi_wallet` 且流水倍數 > 0） | ❌ | ✅ | 收錄（由代理設定分流） |
| 優惠錢包（`point_type = 4`）的 `flow_limits.type` | 用 `$type`（13），且 `reference_id` 取**未定義**的 `$transaction_log_id` | 用 `104`、`reference_id` 取 `promotion_wallet_id` | **取 vw**：tpower 的寫法在 PHP 8 會是未定義變數 |
| action 31 `$setting_key` | ❌ | 指派後從未使用 | 移除（死碼） |
| `deduct_principal` | 由 `example.invalid.deduct_principal` 決定是否扣回本金 | 無此欄位，**一律扣回** | 見下方說明 |

**`deduct_principal`：已依實測統一為「一律扣」**

`flow_limit_include_principal = 1` 時流水為 `(本金 + 獎金) × 倍數`，是否再扣回本金，舊版兩分支規則相反：

| 分支 | 規則 |
|---|---|
| tpower-master | 讀 `example.invalid.deduct_principal`，**缺欄位預設不扣** |
| vw-master（含 me77 的 `vwb2c`） | 無此欄位，**一律扣** |

**查證（2026-07-30，讀取各白標正式 DB 與 API_Agent 原始碼）：**

1. **`deduct_principal` 是已停用的舊欄位**。共用後台 `API_Agent`（master）的寫入路徑（`EventController.php:942`、`:2018`）只寫 `flow_limit_include_principal`，**從不寫入 `deduct_principal`**；僅其自身派彩邏輯（`:2601-2703`）會讀。亦即後台建立的新獎勵一律不含該 key。
2. 現有資料（僅計 `include_principal = 1` ＋ `flow_limit_multiple > 0` ＋ action 17/53/54/55/58/59）：

| 白標 | 舊版分支規則 | 現有設定 | 一律扣是否與舊版相同 |
|---|---|---|---|
| tpower | 讀設定值 | `deduct_principal = 1`（2 筆，皆非進行中） | ✅ 相同 |
| vworld | 一律扣 | `= 0`（4 筆，非進行中）／缺欄位（2 筆，進行中） | ✅ 相同 |
| me77（`vwb2c`） | 一律扣 | 缺欄位（29 筆，4 筆進行中） | ✅ 相同 |

**結論**：改為一律扣後，三個白標現有資料與各自舊版**完全一致（0 筆差異）**，故移除 `deduct_principal` 判斷。實作於 `PrizeService::calculateFlowLimit()`。

> 代價：日後若需要「計入本金但不扣回」的設定，需另行提需求（後台目前也已無此欄位的寫入介面）。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `members.event_mode` 欄位型別 | 正式環境為 `int`（存 `prize_contents.id`） | **新增 migration 改為 `integer`** | apis 建表 migration 誤寫成 `boolean`，由 migration 建出的環境（含測試 DB）在 `prize_contents.id > 127` 時寫入失敗；`SP_event_deposit`、`BetHelper`、`CouponController` 早已把它當 id 用 |
| 抽獎機率總和為 0 | `rand(1, 0)`，PHP 8 拋 `ValueError` | 明確回 `error_message.2004` | PHP 7.3 → 8.4 相容性 |
| `event_details` 查無資料 | `if (is_object($details))` 無 else，直接落到函式結尾 → **回傳空 body 且交易既未 commit 也未 rollback** | 明確拋 `error_message.1901` | 交易懸空 |
| `sendPrize` 的 `$content` 為 null | 先 `Prize::find($content->prize_id)` 才判斷 `is_object($content)`，PHP 8 會先致命 | 提前判斷，維持原本「僅標記已領取」的意圖 | PHP 8.4 相容性 |
| 金額運算 | 直接以 PHP float `*` / `+` 運算 | 一律走 `Common::numAdd/numSub/numMul/numCompare` | CLAUDE.md 金流紅線 |
| 錢包／會員餘額更新 | `DB::raw('balance+' . $point)` 且未鎖定 | `lockForUpdate()` 後計算再寫回 | CLAUDE.md 併發紅線 |
| 多錢包分支的巢狀交易 | 在外層交易中再 `DB::beginTransaction()` / `DB::commit()` | 併入外層 `DB::transaction()` | 巢狀 commit 會提前結束外層交易語意 |
| Redis 清除 | `(new REDIS())->delete(['member'])` / `['event']` | 移除 | 該 Redis 結構為舊專案專屬，apis 未使用；apis 的快取由 `CacheKeys` 管理 |

**未變更之舊版行為**：`refreshProcess` 找不到 `event_processes` 紀錄即回 false（視為不合法領取）；`is_repeatedly = 1` 時只刷新第一筆明細的進度就 `break`。

**測試**：`tests/Unit/Services/PrizeDrawByDetailTest.php`（22 支，涵蓋鎖分模式、獎項過期、無可領取、抽獎與沿用已抽出、進度未達標、領獎期限、禮碼／平台點數／遊戲錢包三種派彩、流水倍數與含本金扣本金兩種算法、`max_point` 上限、`vendor_lock` 寫 `event_mode` 與 type 105、`cancel_other_reward`、隨機獎勵、兩種流水限制來源、可重複領取刷新進度、跨會員隔離）。真實資料 dry-run（交易內 rollback）：會員 62668／活動 1171 派 10 點、會員 61053／活動 1096 派 40 點，皆正確寫入 `flow_limits`；會員 61004 因鎖分模式如預期被拒。

### 2-9. 序號 26 設定獎品（`api/setPrize`）與序號 27 選擇獎品（`api/choosePrize`）遷移細節

#### 序號 26 `setPrize`：兩分支皆為空殼

`setPrize` 在 tpower-master 與 vw-master **完全相同，且第一行就 `return code 1000`**，之後的 `DB::beginTransaction()` / `sendPrize()` 全部不可達；原始碼註解為「打開就直接領取了，所以這個沒用 -- 2019-11-25」。死碼中的 `$this->sendPrize($row->id, $row->content_id, $request->ID)` 只傳 3 個參數（`sendPrize` 需 5 個），真的執行也會 fatal，可再次佐證從未被啟用。

前端清單仍列此端點為使用中，故**保留為只回成功的端點**（`PrizeController@setPrize`），不移植死碼。

#### 序號 27 `choosePrize`：tpower-master 獨有

vw-master **沒有這個方法也沒有路由**；tpower-master 路由在 `routes/api.php:476`。無取捨問題。

**行為**：處理 `prize_members.content_id = -1`（開獎後待玩家自選獎項）的待領獎勵。流程為手機驗證 → 找出待自選且未逾期的 `prize_member` → `vendor_lock` 時檢查鎖分模式 → 取未過期獎勵 → 檢查真實姓名與提款帳戶 → 重複性檢查 → 寫入領獎識別紀錄 → 更新 `content_id` 後呼叫 `sendPrize` 派彩。

**一併遷移的共用 helper**（23／24 之後可直接沿用）：

| Helper | 說明 | 分支差異 |
|---|---|---|
| `checkPrizeBlock` | 領獎重複性檢查（註冊IP／領獎IP／手機／真實姓名／全站真實姓名／Telegram），命中即寫 `event_payout_cancels` 並附中文 remark | 僅 1 行：全站姓名的 remark 文字，取 tpower 的「領取時全站真實姓名」（較精確） |
| `addRewardClaimRecord` | 寫入領獎識別紀錄（type 1 真實姓名／2 註冊IP／3 手機／4 領獎IP／5 Telegram） | tpower 多一層 `tg_user_id` 非 null 判斷，取 tpower（較嚴謹） |
| `checkMemberFirstNameAndWithdrawAccount` | 檢查真實姓名與提款帳戶 | 完全相同 |

> **與 `EventNewInviteService::checkPrizeBlock` 的關係**：兩者同名但**是不同實作**（PrizeController 版多了 `global_account_block`／type 7、每日充當日限定、手機改號後再查 `reward_claim_records`、中文 remark），舊版本來就是兩份，本次如實各自保留。

**代理設定改用既有 `Agent::getSettingValue()`**：舊版 `getAgentCustomizedSetting($agent_id, $key, $default)` 只查 `agent_customized_settings` 並用呼叫端傳入的預設值。已核對本次用到的兩個 key，`agent_setting_defines.default_value` 與舊版傳入值一致（`EVENT_REWARD_MOBILE_VALIDATION` = 0、`EVENT_NEED_BANK_ACCOUNT` = 1），故未設定時結果相同；apis 版另會套用 `agent_personal_settings`，屬既有慣例的超集。

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| Telegram 重複時的 `block_member_id` | 取 `$check_tgid->id`（`member_telegram_rels.id`），**不是會員 id** | 改取 `member_id` | 寫入 `event_payout_cancels.block_member_id` 的是錯誤的 id，且後續 `DB::table('members')->find($block_member_id)->username` 查無會員時會致命 |
| 組 remark 的會員名稱 | 直接 `->find($id)->username`，查無會員即致命 | 查無則略過該段文字 | PHP 8.4 相容性 |
| 活動主檔查詢參數 | 用 `$request->id`（與 `$request->event_id` 是不同參數）查 `events`，僅供 remark 使用；缺參數時 `$event->id` 會致命 | 統一以 `event_id` 查 | 該端點無任何 Validator，`id` 缺漏即 fatal；獎勵與明細本來就由 `event_id` 取得，同一活動 |
| 錯誤訊息除錯後綴 | `__('error_message.1949') . '_1'` / `'_2'` / `'_3'`、`1901 . '_2'` | 移除後綴 | 除錯標記外洩給玩家 |
| `event_payout_cancels.type = 7`（全站姓名）的回應碼 | `switch` 無 `case 7`，落入 `default` 回 1949（「領取失敗」） | 併入 `1952`（此真實姓名已領取過） | type 7 就是真實姓名重複，回 1949 語意不明；同一支 `checkPrizeBlock` 才會寫入 7 |

**未變更之舊版行為**：`tgid_block` 檢查不看前面是否已命中（其餘檢查為 `&& $is_block == false`），會覆蓋 `block_type` 為 6。

**測試**：`tests/Unit/Services/PrizeChooseTest.php`（15 支，涵蓋派彩與狀態更新、領獎識別紀錄寫入、無待自選／已逾期、手機驗證、`vendor_lock` 鎖分、真實姓名與提款帳戶檢查、同名封鎖與 `event_payout_cancels` 內容、全站同名 type 7、既有封鎖紀錄即時拒絕、每日型活動只比對當日、跨會員隔離）。真實資料 dry-run：dev DB 現存 `content_id = -1` 的待自選獎勵其 `prizes.expire_date` 皆已過期（2025-09-25），依舊版同樣的 `expire_date >= NOW()` 條件被正確拒絕，**無可用的成功案例可驗**，成功路徑僅由單元測試覆蓋。

### 2-10. 序號 24 取得獎品（`api/getPrize`）遷移細節

抽獎並派發的主要端點，`sendPrize` 與序號 25／27 共用。

**流程**：手機驗證 → 鎖分模式檢查 → 活動（`status=1`）→ 活動明細（時間區間內、`status=1`）→ 獎勵（未過期）→ 待領的 `prize_members`（登入送除外）→ 真實姓名與提款帳戶檢查 → 重複性檢查 → `vendor_lock` 時先把遊戲商餘額轉回本地 → 寫入領獎識別紀錄 → 抽出／沿用獎項 → 進度檢查 → `sendPrize` 派彩。

#### 兩分支差異與合併結果

| 邏輯 | tpower-master | vw-master | 本次處理 |
|---|---|---|---|
| 領獎需通過手機驗證（`EVENT_REWARD_MOBILE_VALIDATION`） | ✅ | ❌ | 收錄（由代理設定分流，未開啟即不影響） |
| 進入時的鎖分模式檢查 | ✅ | ❌ | 收錄 |
| 免進度條的 action | `[12, 60]` | `12` | 取 tpower（60 為 tpower 專屬活動） |
| 多筆明細活動需指定 detail_id | `[14]` | ❌ | 收錄 |
| 回應帶 `get_point` / `point_type` | ✅ | ❌ | 收錄（純新增欄位，不影響既有前端） |
| `sent` 由 SP 增加而端點不加的 action 清單 | `[17,51-59]` | `[11,17,51-59]` | **取 vw**：原註解寫「儲值活動跟註冊送會在 SP 增加」，註冊送即 action 11，tpower 漏列 |
| 抽出獎項時寫入領獎 IP | ❌ | ✅ | 收錄 |
| 傳給 `sendPrize` 的活動明細 | 已篩時間區間的 `$event_detail` | 以 `event_id` 重查第一筆 | **取 tpower**：vw 在多明細活動會取到錯的那筆 |

#### `prize_members.point` 欄位不存在（登入送在舊版無法完成）

舊版登入送（action 12）抽出獎項後會寫 `$update_data['point'] = $row->point`，但 **`prize_members` 沒有 `point` 欄位**（測試 DB 與正式 DB `xsgcasino` 皆已確認）。登入送新建的 `prize_member` 一定是 `content_id = 0`、一定走抽獎路徑，因此**每次都會撞到不存在的欄位而失敗**——舊版此路徑實際上是壞的。

經確認正確欄位為 `lottery_point`，本次改寫入 `lottery_point`。注意這會實際生效：`sendPrize` 對 `prize_contents.type = 1`（%數）的獎項是以 `prize_members.lottery_point` 為基數，故登入送先寫入的值會直接決定派發金額（已於 `test_login_gift_percentage_content_uses_written_lottery_point` 釘住）。

> **PM 已確認（2026-08-03）**：維持修正、寫入 `lottery_point`，接受登入送恢復正常派彩，不另行關閉該活動類型。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `prize_members.point` | 寫入不存在的欄位 | 改寫 `lottery_point` | 見上 |
| 鎖分活動的轉回餘額 | `Common::postToApis('member/transfer-back-recent')` 跨專案 HTTP 呼叫 apis | 抽出 `MemberTransferService::transferBackRecentVendors()` 直接呼叫 | 兩邊都已在 apis，自呼叫沒有意義；`MemberTransferController::transferBackRecent` 改為委派同一方法，行為不變 |
| 抽獎機率總和為 0 | `rand(1, 0)`，PHP 8 拋 `ValueError` | 明確回 `error_message.2004` | PHP 7.3 → 8.4 相容性 |
| 錯誤訊息除錯後綴 | `1901 . '_0'` / `'_1'` / `'_2'`、`1949 . '_1'` ~ `'_3'` | 移除後綴 | 除錯標記外洩給玩家 |
| 金額運算與錢包更新 | float 運算、`DB::raw('balance+…')`、未鎖定 | `Common::num*` ＋ `lockForUpdate()` | CLAUDE.md 金流／併發紅線（同 2-8） |

**未變更之舊版行為**：進入時與 `vendor_lock` 分支各檢查一次鎖分模式（重複但無害）；`event_payout_cancels` 於登入送時以 `prize_member_id = 0` 寫入（該筆 `prize_members` 尚未建立）。

**新增語系**：`error_message.1957`（遊戲商轉回餘額失敗），比照舊版僅 en / zh_TW / zh_CN 三語。

**測試**：`tests/Unit/Services/PrizeDrawTest.php`（20 支，涵蓋抽獎派彩、`get_point`/`point_type`、鎖分模式、明細時間區間、無可領取、登入送建立領獎資格與不可重複、%數登入送以 lottery_point 為基數、已抽出獎項的 `sent` 增減規則、action 11 與儲值活動不加 `sent`、語系獎項名稱、進度未達標、action 60 免進度條、手機驗證、真實姓名檢查、註冊 IP 重複封鎖、領獎識別紀錄、領獎 IP 寫入、逾期 prize_member）。

### 2-11. 序號 21 取消獎品（`api/cancelPrize`）遷移細節

兩分支（tpower-master / vw-master）**逐行完全相同**，無取捨。

**行為**：玩家自主放棄尚未領取的**儲值類活動獎勵**（action 53 首充／54 次次充／55 每日充）。取代理層級下符合條件的 `event_details`，把該會員在這些明細下 `status = 0` 且未逾期的 `prize_members` 全部改為 `status = 2`（已取消），並各寫一筆 `event_payout_cancels`（`type = 5` 玩家自主取消）。查無符合的活動明細時回 `error_message.2001`。

**共用既有 service**：舊版以 Redis key `check_prize:{member_id}`（TTL 6000 秒）快取「符合條件的 event_detail id 清單」，該查詢與 apis 既有的 `EventService::findAvailableEventDetailIds()` **完全相同**（同為 action 53/54/55、`status=1`、排除 action 8、代理層級、`event_exclude_agents`、獎勵未過期），該方法是序號未列的 `event/checkPrize`（apis `EventController@remind`）遷移時建立的。直接沿用，不另建第二套。

**刻意偏離舊版之處：**

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 快取機制與 TTL | 自訂 `REDIS` class，key `check_prize:{member_id}`，TTL 6000 秒 | 沿用 `EventService::findAvailableEventDetailIds()`：`Cache::safeRemember` ＋ `CacheKeys::availableEventDetailIds($agentIds)`，TTL 1 分鐘 | 舊 Redis 結構 apis 未使用；快取鍵改以代理層級為單位（同代理的會員共用），TTL 縮短使活動上下架更快反映。CLAUDE.md 要求高併發共用快取走 `Cache::safeRemember` |
| 交易與鎖 | 逐筆 `save()`／`create()`，**無交易、無鎖** | 整批包 `DB::transaction` ＋ `lockForUpdate()` | 取消與領獎併發時可能重複處理同一筆獎勵；CLAUDE.md 併發紅線 |
| 變數覆用 | 迴圈內以 `$prize` 覆寫外層同名的待取消 id 陣列 | 拆為不同變數 | 可讀性；PHP `foreach` 走陣列副本故舊版未出錯，行為不變 |
| `status = 2`、`type = 5` | 裸數字 | 具名常數 `PRIZE_MEMBER_STATUS_CANCELLED`、`PAYOUT_CANCEL_TYPE_MEMBER_SELF` | CLAUDE.md 禁止魔術數字 |

**未變更之舊版行為**：查無 `prizes` 時仍會先把該筆 `prize_members` 標記為已取消，只是略過 `event_payout_cancels` 的稽核紀錄（`prize_id` 為實質外鍵，正常情況不會發生）。

**測試**：`tests/Unit/Services/PrizeCancelTest.php`（9 支，涵蓋取消並寫入稽核紀錄、多筆一次取消、無符合活動明細、已領取不受影響、已逾期不受影響、`expired_time` 為 null 可取消、跨會員隔離、`event_exclude_agents` 排除、獎勵已過期）。

### 2-12. 序號 23 取得所有獎品（`api/getAllPrize`）遷移細節

名稱雖為 get，實際是**一次領取該活動所有未兌換的獎勵**（單次上限 100 筆）。

**流程**：手機驗證 → 實名驗證 → 活動 → 活動明細（時間區間內）→ `vendor_lock` 時檢查鎖分模式 → 獎勵（未過期）→ 取一筆 `prize_members` 做重複性驗證 → 真實姓名與提款帳戶檢查 → 重複性檢查 → `vendor_lock` 時轉回遊戲商餘額 → 寫入領獎識別紀錄 → 逐筆抽出／沿用獎項並派彩。

#### ⚠️ 兩分支派彩實作互斥（非可聯集）

這是本批**唯一無法取聯集**的項目：

| 項目 | tpower-master | vw-master |
|---|---|---|
| 派彩函式 | `sendPrizeWithoutFlowLimit`（**專用簡化版**） | 共用的 `sendPrize` |
| `point_percentage`（action 53/54/55 等） | **不套用**（該函式沒有 `$details` 參數） | 套用 |
| action 17 每周救援金 / 60 邀請玩家 | 不處理 | 不處理（分支本身沒有） |
| 優惠錢包（`point_type = 4`）、多錢包遊戲錢包 | 不支援 | 支援 |
| `flow_limit_include_principal` / `deduct_principal` | 不套用，流水固定 `獎金 × 倍數` | 套用 |
| `flow_limits` 寫入 | **彙總成一列**（`reference_id` 只指向最後一筆 log） | **逐筆一列**（各自的 `reference_id`） |
| `cancel_other_reward` | 不處理 | 處理 |

**本次處理：統一走共用的 `sendPrize`（vw 作法）**，不移植 `sendPrizeWithoutFlowLimit`。理由：

1. 與序號 24／25／27 一致，全批共用同一套派彩核心，避免第二套語意。
2. tpower 版對 `prize_contents.type = 1`（%數）的獎項會直接以 `prize_members.lottery_point` 為派發金額，**不乘 `point_percentage`**。儲值類活動（action 53/54/55/58/59）的 `lottery_point` 由 SP 寫入的是**儲值金額**，正常應再乘比例才是獎金，故該版在此組合下會以全額派發。
3. tpower 版彙總的 `flow_limits.reference_id` 只指向最後一筆交易紀錄，其餘交易無法回溯。

**實際資料查證（2026-07-30，讀取各白標正式 DB）**

| 白標 | action 53/54/55/58/59 ＋ `type=1` 的啟用獎項 | 目前仍在活動期間 |
|---|---|---|
| tpower | 僅 event 1951「蛇年鸿运双重礼」（action 55，2 筆獎項，比例 0.88／1.88） | **無**（活動 2025-02-11 結束、獎勵 2025-02-23 到期） |
| vworld | action 53（5 筆／4 活動）、55（4 筆／3 活動）、54（1 筆） | **有**（53 與 55 各 1 筆進行中） |

亦即：**這個組合目前實際在跑的是 vworld，而 vworld 走的正是會乘比例的 `sendPrize`**；tpower 只在 2025-02 配過一次且已結束，現無任何進行中的活動會走到該路徑（`getAllPrize` 要求 `event_details` 落在活動期間內）。tpower 另殘留 1 筆該活動的未領取紀錄（content 5728，`lottery_point` 100，舊版會派 100 而非 88），但活動已結束無法再領。

**對 tpower 品牌的實際影響**：`flow_limits` 由 1 列變成 N 列（總流水需求不變，SP 逐列計算），並開始套用 `point_percentage` 與 `include_principal`。依上表，**目前無進行中的活動會受影響**。**PM 已確認（2026-08-03）**：N 列沒關係，維持統一走共用 `sendPrize` 的處理方式，不另行實作彙總一列。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| 派彩實作 | 見上表 | 統一 `sendPrize` | 見上 |
| `event_mode` 寫入 | tpower 於迴圈外用**迴圈殘留**的 `$content_id` 寫入 | 由 `sendPrize` 於各筆內以該筆 `content_id` 寫入 | 迴圈殘留值不可靠 |
| 抽獎機率總和為 0 | `rand(1, 0)`，PHP 8 拋 `ValueError` | 明確回 `error_message.2004` | PHP 7.3 → 8.4 相容性 |
| 錯誤訊息除錯後綴 | `1901 . '_0'` ~ `'_2'`、`1949 . '_1'` ~ `'_3'` | 移除後綴 | 除錯標記外洩給玩家 |
| 鎖分活動的轉回餘額 | `Common::postToApis` 跨專案自呼叫 | `MemberTransferService::transferBackRecentVendors()` | 同 2-10 |
| Redis 清除 | `(new REDIS())->delete(['member'])` / `['event']` | 移除 | apis 未使用該 Redis 結構 |

**一併補上的封鎖類別**：tpower 此處的 `switch` 另有 `case 8`（→ 1950 IP）與 `case 9`（→ 1951 手機），已補進共用的 `assertNotBlocked()`；這兩種 type 非由前台 `checkPrizeBlock` 產生（應為後台寫入）。

**測試**：`tests/Unit/Services/PrizeDrawAllTest.php`（14 支，涵蓋一次領取多筆、沿用已抽出獎項、逐筆 `sent` 累加、逐筆 `flow_limits` 與總額、無待領、明細時間區間、鎖分模式、手機驗證、真實姓名檢查、手機重複封鎖且不派彩、領獎識別紀錄只寫一次、逾期獎勵略過、進度未達標、跨會員隔離）。

## 3. 老虎機競賽 / Jackpot（`store/modules/events/index.js`）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 30 | 競賽列表 | `api/event/slot_competition/list`<br>`frontend/api/event/slot-competition/list` | WEB_Frontend<br>:1296 | 是 | apis: `SlotCompetitionController@list` → `SlotCompetitionService::getIssueList`（詳見 3-1） |
| 31 | 競賽排行榜 | `api/event/slot_competition/leader_board`<br>`frontend/api/event/slot-competition/leader-board` | WEB_Frontend<br>:1528 | 是 | apis: `@leaderBoard`。**兩分支唯一差異在帳號遮蔽**，已依實測採 vw 規則（詳見 3-1） |
| 32 | 競賽名次 | `api/event/slot_competition/ranking`<br>`frontend/api/event/slot-competition/ranking` | WEB_Frontend<br>:1568 | 是 | apis: `@ranking` |
| 33 | 競賽領獎 | `api/event/slot_competition/receive`<br>`frontend/api/event/slot-competition/receive` | WEB_Frontend<br>:1609 | 是 | apis: `@receive`；TransactionEnum 101。**有派彩**（平台點數／禮碼），沿用舊版「禮碼不寫稽核流水」 |
| 34 | 競賽獎項 | `api/event/slot_competition/awards`<br>`frontend/api/event/slot-competition/awards` | WEB_Frontend<br>:1645 | 是 | apis: `@awards` |
| 35 | Jackpot 顯示 | `api/event/jackpot/show`<br>`frontend/api/event/jackpot/show` | WEB_Frontend<br>:1336 | 是 | apis: `EventJackpotController@show` → `EventJackpotService::getEvent`（詳見 3-2） |
| 36 | Jackpot 中獎列表 | `api/event/jackpot/win_list`<br>`frontend/api/event/jackpot/win-list` | WEB_Frontend<br>:1377 | 是 | apis: `@winList` → `EventJackpotService::getWinList`（詳見 3-2） |
| 37 | Jackpot 領獎 | `api/event/jackpot/receive`<br>`frontend/api/event/jackpot/receive` | WEB_Frontend<br>:1417 | 是 | apis: `@receive` → `EventJackpotService::receive`（詳見 3-2）。TransactionEnum 103。**有派彩** |
| 38 | Jackpot 檢查通知 | `api/event/jackpot/check_notify`<br>`frontend/api/event/jackpot/check-notify` | WEB_Frontend<br>:1457 | 是 | apis: `@checkNotify` → `EventJackpotService::getNotifications` |
| 39 | Jackpot 確認通知 | `api/event/jackpot/confirm_notify`<br>`frontend/api/event/jackpot/confirm-notify` | WEB_Frontend<br>:1492 | 是 | apis: `@confirmNotify` → `EventJackpotService::confirmNotification`（詳見 3-2） |

### 3-1. 序號 30–34 老虎機爭霸賽（`event/slot_competition/*`）遷移細節

`SlotCompetitionController`（tpower 663 行 / vw 659 行）**整檔僅差 4 行**，全部在 `leaderBoard` 的帳號遮蔽。5 支端點在舊版皆位於 `auth` 群組。

**新增**：`app/Services/SlotCompetitionService.php`、`app/Http/Controllers/Frontend/SlotCompetitionController.php`，以及 Model `EventSlotCompetition` / `EventSlotCompetitionRecord`（資料表 apis 早已有 migration，只是缺 Model）。

#### 兩分支唯一差異：排行榜帳號遮蔽

| 分支 | 規則 | `abcdef` → |
|---|---|---|
| tpower-master | 只保留頭尾各一字（原始碼註記「2025/04/28 请协助将活动中得奖玩家的账号更改为仅保留第一以及最后的字哦」） | `a****f` |
| vw-master | 中間兩字以 `**` 取代（tpower 把這段註解掉了） | `ab**ef` |

**實際資料查證（2026-07-30，讀取正式 DB）：**

| 白標 | 期數 | 玩家紀錄 | 進行中 | 最後一期結束 |
|---|---|---|---|---|
| tpower | 27 | 124,771 | **0** | 2025-03-31 |
| vworld | 146 | 181,908 | **1,464** | 2027-01-31 |

老虎機爭霸賽**目前只有 vworld 仍在營運**（進行中為 event 2148 第 22 期），tpower 最後一期已於 2025-03-31 結束——**比 tpower 那次遮蔽調整的日期（2025-04-28）還早**，代表該調整在 tpower 實際上也從未被跑到過。

依遷移要點「兩分支有差異時，讀取正式 DB 確認與舊版行為一致即可」，**採 vw 規則**，使唯一在營運的 vworld 線上顯示完全不變。以正式資料驗算：`HLky914131` → `HLky**4131`，與舊版相同。

> **可選的後續**：tpower 2025/04/28 的業務要求（只保留頭尾）本意可能是全平台適用，只是當時 tpower 已停辦、vw 分支也未同步。若 PM 要套用到 vworld，改共用的 `Common::maskEventUsername()` 一個方法即可（序號 35-39 積寶活動中獎名單沿用同一規則，見 3-2）。

> **本次抽出的共用邏輯**：原本內嵌在 `SlotCompetitionService::maskUsername()` 的遮蔽規則，遷移積寶活動時發現中獎名單（`win_list`）需要同一套規則，故抽成 `App\Helpers\Traits\MaskTrait::maskEventUsername()` 共用，`SlotCompetitionService` 已改呼叫該共用方法，行為完全不變。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `awards` 查無活動 | `$content->content` 直接讀 null 屬性 → PHP 8 致命錯誤 | 回傳 `content: null` | PHP 7.3 → 8.4 相容性 |
| 派彩金額運算 | float `+`、`DB::raw('balance+…')` | `Common::num*` ＋ `lockForUpdate()` | CLAUDE.md 金流／併發紅線 |
| `TransactionLogController::insertLog` | 舊版 controller helper | 直接 `TransactionLog::create()` | 與本批其他派彩一致（同 2-8） |
| `leaderBoard` 的 `isset($request->ID)` 判斷 | 未登入時回空陣列 | 移除（該路由在舊版即位於 `auth` 群組，必定有登入） | 死碼 |

**未變更之舊版行為**：`receive` 僅在派發**平台點數**時寫 `flow_limits`，禮碼不寫；`leader_board` 未帶 `per_page` 時以「該活動所有 `prize_contents.number` 加總」為每頁筆數；自己不在名次內時於列表尾端補上一筆、名次顯示 `--`。

**修正的遷移落差**：`leader_board` 未帶 `per_page` 且無啟用獎項（加總為 0）時，初版遷移退化成 `LIMIT 0` 使排行榜整張消失；舊版 `SUM()` over 0 rows 回 `NULL`，`take(null)` 在 Laravel `limit()` 中不產生 `LIMIT` 子句，實為「不分頁撈出全部名次」。原註解「舊版於獎項數為 0 時 take(0) 會取不到資料」判讀有誤，已更正並改為 `$perPage > 0` 才 `forPage()`。同一落差存在於刷分排行榜（序號 40–45，見 4-1），已一併修正。

**測試**：`tests/Unit/Services/SlotCompetitionServiceTest.php`（21 支，涵蓋期數列表的代理層級隔離、排行榜遮蔽與自己不遮、擠出名次時補在最後、停用會員排除、我的排名逾期顯示 2 與未中獎不顯示獎金、未派獎期數排除、獎項語系與查無活動、平台點數派彩＋流水、禮碼派彩不寫流水、未中獎／已領取／已逾期／鎖分模式／未派獎／自動派彩活動的拒絕、不可重複領取、跨會員隔離、獎金金額寫入）。真實資料驗證：以正式 vworld 資料跑排行榜查詢（event 2148 第 22 期）結果正確。

### 3-2. 序號 35–39 積寶活動（`event/jackpot/*`）遷移細節

`EventJackpotController`（tpower 482 行 / vw 484 行）**僅差 2 行**：vw 的 `show` 多回 `start_time`／`end_time`（`DATE_ADD(..., INTERVAL 8 HOUR)`），tpower 缺這兩個回應欄位。已取 vw 的聯集（多回這兩欄，tpower 前端多拿到兩個欄位不受影響）。5 支端點在舊版皆位於 `auth` 群組。

積寶活動以 `events.type_id = 3` 辨識；期數設定放 `event_jackpots`（對應舊版 `event_details` 的角色，`prize_members.event_detail_id` 指向此表 id）；中獎通知彈窗另有 `event_jackpot_records` 與多語系圖片 `event_jackpot_images`。

**新增**：`app/Services/EventJackpotService.php`、`app/Http/Controllers/Frontend/EventJackpotController.php`，以及 Model `EventJackpot` / `EventJackpotRecord` / `EventJackpotImage`（資料表 apis 早已有 migration，只是缺 Model）。

#### Store Procedure

`database/procedures/` 下**沒有**任何寫入 `event_jackpots` / `event_jackpot_records` / 對應 `prize_members` 的 SP（與序號 6-15 W-Jackpot 有 `SP_set_w_jackpot_ticket.sql` 不同）。查詢正式 DB 確認 `prize_members` 已有大量歷史中獎紀錄（如 vworld `prize_id=2327` 一批 2026-03-02 的紀錄），故中獎資格判定應由後台（`API_Agent`）或人工作業寫入，非本次遷移範圍內的排程 Command 或 SP，僅記錄此差異、不動後台。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `confirm_notify` 未綁會員 | `EventJackpotRecord::find($request->id)` 任何登入會員皆可把別人的通知標記已通知（IDOR） | 查詢加 `where('member_id', $member->id)` | 安全（依 CLAUDE.md 越權紅線，比照本批其他項目的一貫處理） |
| `receive` 領獎後才驗證票券狀態 | 只在外層鎖 `Member`，`prize_members.status` 未在交易內重驗 | 交易內以 `where('status', 0)->lockForUpdate()` 條件式取出，取不到直接視為已領取 | 併發防護（同批其他派彩端點一致做法） |
| 派彩金額運算 | float `+` | `Common::num*` ＋ `lockForUpdate()` | CLAUDE.md 金流／併發紅線 |
| `TransactionLogController::insertLog` | 舊版 controller helper | 直接 `TransactionLog::create()` | 與本批其他派彩一致 |

**未變更之舊版行為**：`receive` 僅檢查 `events.auto_payout = 0` 的活動（`auto_payout = 1` 的活動由後台自動派彩，此端點對應查詢會查無票券並回 1946，與舊版相同——**查證正式 DB 確認 vworld／tpower 目前唯一進行中的積寶活動皆為 `auto_payout = 1`，此端點目前兩品牌皆處於「無票可手動領」狀態，與舊版行為一致，非本次引入的缺陷**）；`show` 的個人獎項列表沿用「`status = 0` 或 `updated_at < 當日 16:00`」的雙重條件；`win_list` 的營業日區間為 07:00 至隔日 07:00；帳號遮蔽沿用 vw 規則（`Common::maskEventUsername()`，見 3-1）。

**測試**：`tests/Unit/Services/EventJackpotServiceTest.php`（17 支，涵蓋活動資訊與個人獎項、代理層級隔離、查無活動回 null、中獎名單遮蔽與營業日區間過濾、領獎派彩與流水、流水倍數為 0 不寫流水、未中獎／已領取／已過期／鎖分模式／跨會員票券的拒絕、通知列表語系與已讀排除、確認通知並綁會員、確認他人通知靜默失敗、確認不存在通知靜默成功）。真實資料驗證：查詢 vworld／tpower 正式 `event_jackpots`／`prize_members` 確認欄位型別與資料形狀（`flow_limit_multiple` 兩品牌分別為 `1.00`／`0.00`）與本次實作查詢結構一致。

## 4. refresh_ranking 活動（`store/modules/events/index.js`）

> **文件先前備註「routes/api.php 無對應路由」是誤判**（只比對過 vw-master）。複查確認：**tpower-master 獨有**，`RefreshRankingController`（861 行）與完整路由群組都在，vw-master 完全沒有這支 controller。查證 tpower 正式 DB：目前仍有進行中的活動（代理 71541／71561，`end_time` 到 2026-12-31），非死功能，故收錄。

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 40 | 列表 | `api/event/refresh_ranking/list`<br>`frontend/api/event/refresh-ranking/list` | WEB_Frontend<br>:1852 | 是 | apis: `RefreshRankingController@list` → `RefreshRankingService::getIssueList`（詳見 4-1） |
| 41 | 排行榜 | `api/event/refresh_ranking/leader_board`<br>`frontend/api/event/refresh-ranking/leader-board` | WEB_Frontend<br>:1891 | 是 | apis: `@leaderBoard`。帳號遮蔽為「只保留頭尾各一字」（`Common::maskUsernameKeepEnds()`），與老虎機爭霸賽／積寶活動的「遮中間 2 字」規則不同，tpower 獨有不共用 |
| 42 | 獎項 | `api/event/refresh_ranking/awards`<br>`frontend/api/event/refresh-ranking/awards` | WEB_Frontend<br>:1929 | 是 | apis: `@awards` |
| 43 | 錢包餘額 | `api/event/refresh_ranking/wallet_balance`<br>`frontend/api/event/refresh-ranking/wallet-balance` | WEB_Frontend<br>:1967 | 是 | apis: `@walletBalance`。**回應格式調整**：舊版 `data` 直接是裸字串金額，apis `ApiResponse::success()` 要求 `array\|object`，故包成 `{"balance": "..."}` |
| 44 | 場館 | `api/event/refresh_ranking/vendor`<br>`frontend/api/event/refresh-ranking/vendor` | WEB_Frontend<br>:2005 | 是 | apis: `@vendor` |
| 45 | 遊戲 | `api/event/refresh_ranking/game`<br>`frontend/api/event/refresh-ranking/game` | WEB_Frontend<br>:2044 | 是 | apis: `@game` |
| 46 | 遊戲 URL | `api/event/refresh_ranking/game_url`<br>`frontend/api/event/refresh-ranking/game-url` | WEB_Frontend<br>:2085 | 是 | apis: `@gameUrl` → `RefreshRankingService::getGameUrl`。原列為另案處理，複查後改用既有 Driver 注入活動專用商戶資訊／帳號重用 `getGameUrl()`，詳見 4-2 |

### 4-1. 序號 40–45 刷分排行榜（`event/refresh_ranking/*`，不含 `game_url`）遷移細節

`RefreshRankingController`（861 行）**tpower-master 獨有**，vw-master 無此 controller 也無路由，無取捨問題，直接收錄。

**新增**：`app/Services/RefreshRankingService.php`、`app/Http/Controllers/Frontend/RefreshRankingController.php`，以及 Model `EventVendor` / `EventRefreshRanking`（`EventRefreshRankingRecord` 與 `EventVendorInfo` / `EventVendorInfoGame` apis 早已有 Model；`event_vendors` / `event_refresh_rankings` 只補了 Model，資料表與 `WalletEnum::CODE_EVENT_RANKING = 1003` 皆已存在，顯示這批功能先前已預先鋪路）。

#### 舊版查詢邏輯轉換

- `event_vendor_agent($hierarchy)`／`getAgentHierarchyPath($member_id)`／`listParent($parentId)`：舊版走 `Agent.hierarchy`（JSON 欄位）與遞迴查詢，apis 統一改用「新版」`Agent::hierarchy_array`（語意相同，由近到遠含自己），不重新讀取舊版 `hierarchy` 欄位
- `vendor()` 的 `vendor_maintenances` 併表判斷：改用既有 `VendorService::getMaintenanceVendorIds()`（與 `MemberTransferService` 等既有程式碼共用同一支方法），不重寫原始 SQL join

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `wallet_balance` 回應格式 | `data` 直接是裸字串金額 | 包成 `{"balance": "..."}` | apis `ApiResponse::success()` 強制 `data` 為 `array\|object`，全站慣例 |
| `awards` 查無活動 | `$content->content` 直接讀 null 屬性 → PHP 8 致命錯誤 | 拋 1901 | 見下方越權修補；PHP 7.3 → 8.4 相容性一併解決 |
| `leader_board`／`awards` 未驗證 event 歸屬 | 只用 `event_id` 查，跨代理／跨白標可讀任意活動 | 開頭 `assertEventAccessible()`，`awards` 的 content 查詢補 `e.status = 1` | 與老虎機爭霸賽三支端點對齊（該處已有此防護）；端點掛共用 auth 群組，任何白標會員可枚舉 `event_id` 傾印活動文案與參賽名單 |
| `wallet_balance` 建立錢包 | 先 SELECT 再 INSERT，無交易無鎖 | 改用 `firstOrCreate()` | `member_wallets` 有 `unique(member_id, wallet_type_id)`，併發首次呼叫其中一方會撞唯一鍵噴 500 |

**修正的遷移落差**（初版遷移與舊版行為不一致，已修正回舊版語意）：

| 項目 | 舊版行為 | 初版遷移（錯） | 已修正為 |
|---|---|---|---|
| `leader_board` 未帶 `per_page` 且無啟用獎項 | `SUM(pc.number)` over 0 rows 回 `NULL` → `take(null)`，Laravel `limit()` 不產生 `LIMIT` → 不分頁撈出全部名次 | `(int) sum()` 得 `0` → `forPage($page, 0)` → 真正的 `LIMIT 0`，排行榜整張消失 | `$perPage > 0` 才 `forPage()`，否則不加 `LIMIT` |
| `leader_board` 自己補位查詢 | `$self_data` 刻意不設 `bet_amount > 0` 與 `is_calc = 1`，批次未計算時仍以 `--` 補在尾端 | 與主排行榜共用同一段 query，過濾條件一併套到自己身上 → 自己整筆消失 | `leaderBoardQuery()` 僅在 `$memberId === null`（主排行榜）時套這兩個條件 |

> `LIMIT 0` 這條同樣存在於老虎機爭霸賽（序號 30–34，見 3-1），已一併修正；該處原註解「舊版於獎項數為 0 時 take(0) 會取不到資料」對舊版行為的判讀有誤，已更正。

**未變更之舊版行為**：`event_vendors.status` 判斷沿用 `whereIn([1, 4])`（查證正式 DB 現況只用到 1，4 屬死值但保留相容）；`vendor()` 的維護中判斷（`type` 為 1 或 2）覆寫 status 為 3（`WALLET_CLOSED`）維持原始語意。

**測試**：`tests/Unit/Services/RefreshRankingServiceTest.php`（21 支，涵蓋期數列表代理隔離與個人投注額顯示、排行榜遮蔽與自己不遮／擠出補在最後／未計算紀錄排除、錢包餘額建立與沿用既有餘額與查無活動拒絕、遊戲商列表的查無設定拒絕／代理層級優先順序／維護中狀態覆寫、遊戲列表查詢與查無設定拒絕、獎項語系。另補 4 支回歸測試：`leader_board`／`awards` 跨代理拒絕、未帶 `per_page` 且無啟用獎項時撈出全部名次、自己 `is_calc = 0` 時仍補在尾端）。真實資料驗證：查詢 tpower 正式 `event_vendors`（4 家遊戲商配置）與 `event_vendor_info_games`（237 筆上架遊戲）確認查詢結構與資料形狀一致。

### 4-2. 序號 46 遊戲 URL（`event/refresh_ranking/game_url`）遷移細節

**範圍**：`game_url` 舊版依 `vendors.third_party_code` 分派到 7 種遊戲商各自的 `getEventGameUrl()`，帳號機制是獨立於玩家平常單一錢包帳號的「活動專用帳號」（`event_pgssw_accounts` / `event_uuslotsw_accounts` / `event_vpowersw_accounts` / `event_wfsw_accounts` / `event_ppsw_accounts` / `event_wudisw_accounts` / `event_fumasw_accounts`，皆已有 migration），採「預先建空帳號、首次使用時認領綁定」的模式。

**7 家全部收錄**（2026-08-04 修正）：初版依先前的盤點結論認為 PPSW／WudiSW／FumaSW「無任何代理配置、屬死分支」而不實作（`match` 對其一律拋 9994）。經指出後改為全部收錄，理由：

1. 該「死分支」結論**未在本輪重新查證**，若判斷有誤則等於用 9994 把線上功能打死，而收錄的成本極低（各一個 Model ＋ 一個 override）。
2. 更關鍵的反證：**PPSW 的活動流程在 apis 是 7 家中唯一連回調側都已完成的**——`app/Http/Controllers/External/SingleWallets/EventPpswController.php`、`routes/external/sw.php` 的 `refresh_ranking/pp/*` 路由群組、`EventPpswAccount`／`EventPpswBetRecord`／`EventPpswDealRecord` 三個 Model、以及 `app/Helpers/RefreshRankingHelper.php`（回調時建立 `event_refresh_ranking_records`）都已存在於 apis。把 PPSW 當死分支與這批既有投資直接矛盾。

因此 `claimEventAccount()` 現對齊舊版 `get_account()` 的全部 7 個 `third_party_code` 分支，對應表為 `RefreshRankingService::EVENT_ACCOUNT_MODELS`。

> **對應表的 key 必須是 `third_party_code`，不可用 `vendor_id`（2026-08-04 第三輪 code review 修正）**：初版以 `vendor_id` 當 `match` 的 key，漏掉了「同一個 `third_party_code` 對多個 `vendor_id`」的聚合遊戲商。查證 6 個白標正式 DB 後確認 yes2win 的 `vendors` 表中 `third_party_code = 'PGSSW'` 的有 **4 個** vendor：`50` Pegasus、`91` TIGER 9、`145` BWG、`177` JokerBaccarat（`config/vendor.php` 的「PGSSW 聚合單錢包」本來就把這 4 個 id 對到同一支 `PegasusSW\Driver`）。其中 **BWG 在 yes2win 是實際配置**：`event_vendor_infos` id=5、`status=1`、147 支 active game、`event_pgssw_accounts` 內有 6,491 個帳號。以 `vendor_id` 當 key 會讓 BWG／TIGER9／JokerBaccarat 全部落到 `default` 拋 9994。目前尚未爆是因為該商戶的兩筆 `event_vendors` 是 `status=4`（`game_url` 的 join 要求 `status=1`，會先拋 1406），**只差營運翻一次 status**。`WUDISW_FREE`／`FUMASW_FREE` 兩個免盤費變體也是同一個機制涵蓋（`third_party_code` 各為 `WUDISW`／`FUMASW`），不需另行列舉 id。

`default`（`third_party_code` 不在對應表內）仍直接拒絕，拋 **9994**。此處與舊版**有意不同**：舊版 `game_url()` 有兩層 switch 且都以 `third_party_code` 分派，`get_account()`（拋 **1005** "Account doesn't exist"）先於第二層 switch（拋 9994 "Vendor 不存在"）執行且分支集合相同，故舊版實際可達的是 1005、其 9994 分支是死代碼；新版統一拋 9994，語意上更貼合「該遊戲商不支援活動帳號池」。

> **更正（2026-08-04）：業務錯誤碼不會出現在 response，前端無從對 code 分流。** 先前此處寫「若前端對 1005／9994 有分流需確認」是錯的。`ApiException` 的建構子沒有帶 code（本專案 304 處 `ApiException(__('error_message.X'))` 全部如此），`ApiException::toArray()` 是 `'code' => $this->getCode() ?: 400`（`app/Exceptions/ApiException.php:19`），因此 1406／1426／9993／9994 **一個都不會落到 response**，client 看到的一律是 `code: 400` ＋ HTTP 400 ＋ `message` 文字。所以 1005 → 9994 這個改動對前端的實際表現是**訊息文字改變**（"Account doesn't exist" → "Vendor doesn't exist"），不是 code 改變；而舊版是 HTTP 200 ＋ body 帶 `code: 1005`。這是全專案既有慣例造成的差異，非本端點獨有。

> **活動回調側維持在舊系統（已確認，2026-08-04）**：舊 `API_External`（`_release/routes/api.php`）有 8 組活動回調路由群組：`refresh_ranking/{vpower,uuslot,wf,wudi,fuma,target,bwg,pp}`；apis 目前只有 `refresh_ranking/pp`。**這是既定的遷移順序，非缺口**——三方商戶端設定的回調 URL 目前一律指向舊 `API_External`，投注回調由舊系統處理，兩系統共用同一個資料庫故資料一致；`API_External` 專案之後才會另案遷移。本次只補啟動側（`game_url`），不需要也不應該為此在 apis 補回調路由。

**設計決策：改用既有 Driver 注入 `EventVendorInfo`／活動專用帳號，重用 `getGameUrl()`，不重寫三方串接邏輯。**

逐一比對 4 家既有 apis Driver（`PegasusSW`／`UUSlotSW`／`VpowerSW`／`WFSW`）的 `getGameUrl()` 後發現：業務邏輯（組請求、呼叫三方、解析回應）與一般會員自己的遊戲商帳號流程**完全相同**，差異只在於商戶設定來源（`vendor_infos` vs `event_vendor_infos`）與帳號來源（一般帳號池 vs 活動專用帳號池）。且 apis 已預先鋪好地基：

- `App\Models\EventVendorInfo` 已存在，`info` accessor 與 `VendorInfo` 逐字一致。
- `App\Vendors\Models\AbstractAccount`（所有遊戲商帳號 Model 的共用基底）已內建 `eventVendorInfo(): BelongsTo` 關聯，且 4 家對應的 `event_{vendor}_accounts` migration 早已存在。
- `App\Services\EventV2\EventV2Service`（**與本次無關**，僅供對照）已示範同一種「先以 `member: null` 建構 Driver，再用 `setMember()`／`setVendorInfo()`／`setVendorAccount()` 手動注入」的模式，證明此路徑在生產環境可行。

**因此本次改動限縮在**：

| 檔案 | 改動 | 說明 |
|---|---|---|
| `app/Vendors/BaseAbstractVendor.php` | `$vendorInfo` 型別從 `?VendorInfo` 放寬為 `VendorInfo\|EventVendorInfo\|null`；`setVendorInfo()` 參數同步放寬；新增 `useLegacyEventContext(EventVendorInfo, AbstractAccount): void`（預設實作：直接賦值兩個屬性） | 全部向下相容（僅新增可接受型別），**與 EventV2（`is_event_v2`／`EventV2Account`／`eventV2Joins`）完全無關**，未動任何既有分支 |
| `UUSlotSW`／`VpowerSW`／`PPSW`／`WudiSW`／`FumaSW` 的 `Driver.php` | 各新增一個 `useLegacyEventContext()` override；`$account` 屬性型別從各自的一般帳號 Model 放寬為 `AbstractAccount` | 這 5 家建構子都會把 `vendorInfo`/`vendorAccount` 另轉存成自己的屬性（`$infoDetail`／`$config`／`$account`），`member: null` 建構時該邏輯不會跑，需在 override 補做同一件事。型別放寬安全：各處對 `$account` 只取用 `->username`（PPSW 另取 `->currency`／`->country`／寫 `->token`，`event_ppsw_accounts` 皆有欄位），未呼叫任何一般帳號 Model 特有的方法 |
| `app/Vendors/PegasusSW/Driver.php`、`app/Vendors/WFSW/Driver.php` | **無需改動** | 兩者 `getGameUrl()` 直接讀 `$this->vendorInfo`／`$this->vendorAccount`（base class 屬性），繼承預設的 `useLegacyEventContext()` 即可 |

> **為何新增 `useLegacyEventContext()` 而不重用既有的 `setVendorInfo()` + `setVendorAccount()`**（該組合正是 `EventV2Service::_closeGameAccount()` 的作法）：`setVendorInfo()` 內部會強制呼叫 `setInfo()`，而 **WFSW／PegasusSW 兩家沒有 override `setInfo()`**（它們的 `getGameUrl()` 直接讀 `$this->vendorInfo->info`），會落到 `BaseAbstractVendor::setInfo()` 的預設實作而拋 `VendorSetupException`。`useLegacyEventContext()` 刻意不觸發 `setInfo()`，由需要的子類別自行在 override 內呼叫，因此不是重複的第四個 setter。

**新增 6 支 Model**（`app/Vendors/Models/`，與既有 `EventPpswAccount` 同一位置與風格）：`EventPgsswAccount`、`EventUuslotswAccount`、`EventVpowerswAccount`、`EventWfswAccount`、`EventWudiswAccount`、`EventFumaswAccount`（`EventPpswAccount` 早已存在，見上方 PPSW 回調側說明）。全部**直接繼承 `AbstractAccount`**，與對應的一般帳號 Model（`UUSlotswAccount` 等）是**平行**關係而非繼承關係——兩者對應不同資料表、不同帳號池，「事件帳號 IS-A 一般帳號」語意不成立（初版曾讓 `EventUuslotswAccount`／`EventVpowerswAccount` 繼承一般 Model 以滿足 Driver 私有屬性型別，經 code review 後改為放寬 Driver 屬性型別，見上表）。

> **活動帳號池的 `status` 欄位在兩版本都形同虛設**（2026-08-04 查證，勿誤以為新版該補）：7 張表中只有 `event_ppsw_accounts` 與 `event_fumasw_accounts` 有 `status` 欄位（已於 yes2win／tpower 正式 DB 以 `information_schema` 確認），`event_wudisw_accounts`／`event_uuslotsw_accounts`／`event_vpowersw_accounts`／`event_wfsw_accounts`／`event_pgssw_accounts` 皆無（對照一般帳號池 `wudisw_accounts` 等則有）。而**舊版 `get_account()` 也完全沒有過濾 `status`**，故 `claimEventAccount()` 一律不加 `status` 條件屬刻意逐行對齊，非遺漏（`getVendorAccount()` 對一般帳號池則有）。實際影響為零：yes2win 上這兩張表的全部資料（61,954 ／ 14,115 筆）都是 `status=1`。

**帳號認領邏輯**（`RefreshRankingService::claimEventAccount()`）：比照 `BaseAbstractVendor::getVendorAccount()` 的併發防護手法（`Cache::lock` 序列化＋`FOR UPDATE SKIP LOCKED` 認領未綁定列＋deadlock 有限重試 3 次＋`catch (Throwable)` 兜底 rollback＋綁定成功寫 `Log::info` 留軌跡），而非舊版陽春的 `DB::beginTransaction()` 純鎖法；差異僅在配對欄位（`event_vendor_info_id` 而非 `vendor_info_id`）與事件帳號池無 `status` 欄位。

**帳號池的代理維度（沿用舊版設計，非本次引入）**：`event_{vendor}_accounts` 只有 `event_vendor_info_id` 沒有 agent 欄位，若多個代理的 `event_vendors` 指向同一個 `event_vendor_info_id`，其會員會共用同一個帳號池——舊版 `get_account()` 同樣只用 `event_vendor_info_id` 配對，行為一致。

**已知風險（維持現狀，非本次處理）**：認領時需對代理設定的目標遊戲商查詢／綁定一次帳號，鎖著整段認領邏輯期間會有一次同步的資料庫操作（非多支遊戲商即時查詢，這點與序號 60-62 登入活動被暫緩的「N 支即時查詢」量級不同），現行設計已足夠。

**測試**：

- `tests/Unit/Services/RefreshRankingGameUrlTest.php`（20 支）：PGSSW 端到端案例（認領未綁定帳號並啟動遊戲、會員已有綁定帳號時不誤動池中其他帳號、無可認領帳號時拒絕、**認領失敗時不殘留未關閉的交易**、代理無配置時拒絕、遊戲未掛在任何已配置商戶下時拒絕、不支援的遊戲商拒絕）＋ **`open_window` 取 `games` 而非 `vendors` 的回歸測試**（同一遊戲商底下兩款 `open_window` 不同的遊戲必須分別回 0／1；`setUp` 刻意把 `vendors.open_window` 設為 1、game factory 為 0，取錯表就會被擋下）＋ **12 家 mapping 回歸測試**（data provider：7 個 `third_party_code` base 分支 ＋ 5 個「同 code 不同 vendor_id」的聚合案例 BWG／TIGER9／JokerBaccarat／WudiSW 免盤費／FumaSW 免盤費，以「池子空時回 9993 而非 9994」斷言有解析到正確的活動帳號表；初版把 PPSW／WudiSW／FumaSW 當死分支拋 9994、且以 `vendor_id` 當 key 漏掉聚合遊戲商，此測試即為兩項回歸的守門員）。
- `tests/Feature/Vendors/{UUSlotSW,VpowerSW,PPSW,WudiSW,FumaSW}/EventGameUrlTest.php`（各 2 支，共 10 支）：**5 家有 override 的 Driver 全部都有 Driver 層測試**，各自驗證 override 正確填入該家的私有屬性（`$infoDetail`／`$config`／`$account`）並成功組出遊戲網址、以及注入其他家事件帳號時擋下。PPSW 另驗證啟動時會在活動帳號寫入新 `token`（供既有回調端 `EventPpswController` 驗證）。
- 既有 `tests/Feature/External/SingleWallets/{UUSlotSW,VpowerSW,PegasusSW}`（68 支）與全專案測試（3071 支）於改動後全數重跑通過，確認一般會員帳號的既有遊戲流程未受影響。

#### 本節 code review 修正（2026-08-04）

| # | 項目 | 嚴重度 | 處理 |
|---|---|---|---|
| 1 | `claimEventAccount()` 只 catch `QueryException`，自己拋的 `ApiException`（找不到可認領帳號）會帶著**未 rollback 的交易**穿透 `finally`（那裡只 release cache lock）。後續同一 request 內的寫入都會落在懸空交易裡，連線歸還時被隱式 rollback 而遺失 | **Major** | ✅ 補 `catch (Throwable) { DB::rollBack(); throw; }`（對照 `BaseAbstractVendor::getVendorAccount()` 本來就有這段，是我抄漏），並補一支斷言 `DB::transactionLevel()` 回到 baseline 的回歸測試（修正前實測為 2 vs 1，確實會失敗） |
| 2 | `EventUuslotswAccount`／`EventVpowerswAccount` 繼承一般帳號 Model，語意上「事件帳號 IS-A 一般帳號」不成立，且會繼承到指向不存在欄位（`vendor_info_id`）的 `vendorInfo()` 關聯 | Minor | ✅ 改為直接繼承 `AbstractAccount`，Driver 私有屬性型別放寬為 `AbstractAccount`；`instanceof` 防護同步改為檢查對應的**事件**帳號類別（更精準：能擋下誤傳其他家事件帳號） |
| 3 | `claimEventAccount()` 綁定成功無任何 log（`getVendorAccount()` 有 `Log::info`），帳號池用完或綁定異常時無軌跡可查 | Minor | ✅ 補 `Log::info` |
| 4 | VpowerSW 的 `useLegacyEventContext()` override 是與 UUSlotSW 同構但獨立的一份程式碼，原本無任何測試直接覆蓋 | Minor | ✅ 補 `tests/Feature/Vendors/VpowerSW/EventGameUrlTest.php`（2 支） |

**已查證為非問題**（避免後續重複追）：

| 項目 | 查證結果 |
|---|---|
| `member: null` 建構後 `public readonly ?Member $member` 從未賦值（未初始化 readonly 屬性） | 4 家 Driver 的 `getGameUrl()` 及其呼叫鏈（含 `VpowerSW::userLogin()`、`WFSW::makeSignature()`）**都沒有讀 `$this->member`／`$this->agent`**，不會觸發 Error |
| `$vendorInfo` 型別放寬後可能讀到 `event_vendor_infos` 不存在的欄位 | `getGameUrl()` 路徑只讀 `vendorInfo->info` 與 `vendorInfo->currency`，兩者 `event_vendor_infos` 皆有欄位；`deposit_limit`／`rolling_chip_status`／`type`／`cost` 等只存在於 `vendor_infos` 的欄位在此路徑上不會被讀到 |
| `RankingService::hierarchyPathScope()` 的 `= path OR LIKE path/%` 在巢狀 where 下 AND/OR 優先權 | 傳 Closure 給 `where()` 會走 Laravel 的 `whereNested()`，原始碼註解明載 "wrapped in parentheses"（`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:955-959`），括號正確 |
| `exit_url` 使用者可控字串直接送三方（open redirect 面） | 與既有 `app/Http/Requests/Frontend/Game/GetUrlRequest.php` 的 `exit_url` 驗證規則**完全相同**（`required\|url`，無 domain 白名單），屬全專案既有慣例而非本次引入；若要收緊應全站一起處理 |
| `Cache::lock(...)->block(5)` 逾時 | `block()` 在 `try` 之外，逾時拋 `LockTimeoutException` 時尚未取得鎖，無鎖可洩漏；與 `BaseAbstractVendor::getVendorAccount()` 的寫法一致 |
| **`findEventVendorAgentId()` 的代理掃描範圍與舊版不同**（2026-08-04 第四輪 review 提出，**已查證為誤報**） | **不成立，兩版完全一致。** 誤報來自對 `agents.hierarchy` 欄位格式的推斷。舊版 `event_vendor_agent()`（舊版 `:777-790`）對 `$agent->hierarchy` 做 `explode(',')` ＋ `array_shift()` ＋ `array_pop()`，看似砍掉「自己」與「最頂層」；但查 tpower 正式資料，該欄位**帶哨兵值**：agent 71561 的 `hierarchy` 是 `[-1,71561,71541,1,-2]`。`array_shift` 砍掉的是 `-1`、`array_pop` 砍掉的是 `-2`（舊碼註解「第一個和最後一個是無效的」指的正是哨兵），剩下 `["71561","71541","1"]` ＝ [自己, 父, …, 根]。新版 `hierarchy_array`（`/1/71541/71561` → `array_reverse`）＝ `[71561, 71541, 1]`，**內容與順序皆相同**；Admin（`[-1,1,-2]` → `["1"]` vs `[1]`）亦相同 |
| 活動帳號池跨代理共用是否造成跨租戶污染 | 池表只有 `event_vendor_info_id` 無 agent 欄位，多代理指向同一 evi 即共用——**舊版 `get_account()` 完全相同，屬既有設計非本次引入**。查 tpower／yes2win 正式資料，兩站的每個 evi 都綁 2 個代理，但那兩個是**父子關係**（tpower：71541 `/1/71541` 與 71561 `/1/71541/71561`；yes2win：100003 與 100004），不是互不相干的租戶，現行資料未踩到跨租戶情境。且 `event_vendor_info_id` 本身即代表「商戶」，同一 evi 下的帳號在三方那側本來就同商戶 |
| 語系代碼與舊版的差異 | **有差異但非本次引入。** 舊版各家傳 `languages` 表的 per-vendor 欄位（`$language->target`／`->uuslot`／`->wfsw`），新版走各 Driver 自己的 `match`。PegasusSW **等價**（`ZH_TW/ZH_CN → zh-cn`、其餘 `en-us`，與 tpower `languages.target` 邏輯相同，僅大小寫不同且 apis 本來就送小寫）；UUSlotSW 有 3 個語系不同（Melayu 舊 `ml-my` → 新 `en-us`、Vietnamese 舊 `vi-vn` → 新 `en-us`、Burmese 舊 `en-us` → 新 `my-mm`），三者在 tpower 皆 `status=1`，而 tpower 活動商戶全為 MYR 幣別。**但該 `match` 是 apis 一般會員遊戲流程本來就在用的**，故此差異是「活動路徑改與 apis 一般路徑一致」而非與舊系統一致；要改會連帶影響一般流程，**需 PM 決定，不可單方面調整**。另注意 `UUSlotSW::mapLanguageCode()` 讀的是 `Context::getHidden(LANGUAGE_ID)` 而非 `getGameUrl()` 傳入的 `$params->language`（Context 由 `Frontend/DetectLanguage` middleware 設，前端路由上必定有值，目前不會壞） |

#### 請求／回應契約與舊版的差異（需與 WEB_Frontend 對齊，2026-08-04 第四輪 review 補記）

新端點沿用 **apis 既有的遊戲啟動契約**（與 `GameController::getUrl()` 同一組 Request／Resource），而非舊系統的契約。因為路由本身就換了（`api/event/refresh_ranking/game_url` → `frontend/api/event/refresh-ranking/game-url`），前端切換時本來就要改呼叫。

> **決定（PM 確認 2026-08-04）：統一用新版回傳格式，不為相容舊版做 alias 或 fallback。** 後端不接受 `domain`、不還原頂層裸 `data` 與拼錯的 `url.moblie`。**前端需依下表調整**，這是切換此端點的前置條件。

| | 舊版 | 新版 |
|---|---|---|
| 回跳網址參數 | `domain`，**未驗證、可為空**（舊版 `:638/:647/:655`） | `exit_url`，**`required\|url`**（與既有 `Frontend/Game/GetUrlRequest` 一致）。不接受 `domain` |
| 其他驗證 | 只驗 `game_id`（`required\|numeric`，舊版 `:560-562`） | `game_id`（`required\|integer\|min:1`）＋ `exit_url` |
| 成功回應 | `{code:1000, message, data:"<url 字串>", url:{moblie, pc}, open_window}` | `{code:1000, …, data:{pc, mobile, open_window, app_infos?}}`（`GetGameUrlResource`） |
| 錯誤回應 | HTTP 200 ＋ body 帶 `code:1406\|1426\|9993\|1005` | HTTP 400 ＋ `code:400` ＋ `message`／`file`／`tid`（見上方錯誤碼更正框） |

差異重點：頂層裸 `data`（URL 字串）與 `url.moblie`（舊版拼錯的欄位名）都不存在了，`open_window` 從頂層移進 `data`。**若前端沿用舊 payload（送 `domain`、不送 `exit_url`）會拿到 422，活動遊戲完全開不起來**（fail-closed，秒級可見）。PPSW／WudiSW／FumaSW 三家實際上不使用 `exit_url`，但仍受必填規則約束。

**另一項需要前端接手的：回跳網址的 path 後綴。** 舊版是由**後端**在 `exit_url`／`domain` 後面接上活動專屬路徑，各家一致（例：`vendorPpsw.php:38`、VPower／UUSlot／WFSW 同款）：

```
$exitUrl .= '/refresh-ranking/game/list/' . $this->vendorId . '?vendorName=' . $this->name;
```

一般（非活動）流程接的則是 `/game/list/{vendorId}`。apis 在**更早的一般遊戲網址遷移**就把「後端組 path 後綴」整個拿掉了（`GameController.php:428,432` 直接吃 `request->input('exit_url')` 全文），活動流程沿用同一慣例，因此**兩個流程現在都不加後綴**——屬既有架構決定、非本票引入的落差，但前端若要維持舊的返回行為，需自行把 `/refresh-ranking/game/list/{vendorId}?vendorName={name}` 組進 `exit_url`。

#### 商戶金鑰經 GET query 明文落 log — 既有現況，**不處理、勿再提報**（PM 確認 2026-08-04）

`PegasusSW::getGameUrl()` 把 `merchantSecretKey`（＝ `event_vendor_infos.info['API_KEY']`）當 **GET query 參數**送出（`app/Vendors/PegasusSW/Driver.php:48,57` 的 `Http::logAction('PegasusSW_getGameUrl')->get($url, $params)`）。而 `HttpLoggerCustom` 的遮罩機制**遮不到它**：

- `extractRequestContext()` 的 `params` 只從 **request body** 解析（`app/Logging/HttpLoggerCustom.php:189-201`），GET 的 body 是空的，所以 `params` 為 `[]`
- `url` 取 `(string) $request->getUri()`，**含完整 query string 且從不遮罩**
- `maskSensitive()` 只作用於 `$request['params']` 與 response body（`:114-119`）

⇒ **即使補 `maskKeys: ['merchantSecretKey']` 也無效**，金鑰仍以明文躺在 log 的 `url` 欄位裡。

**處置：不處理。** PM 已確認（2026-08-04）「金鑰明文的遊戲商有一大堆」——以 GET query 送 secret 是眾多既有 vendor driver 的普遍寫法，`HttpLoggerCustom` 不遮 `url` 是已知且接受的現況，非本票引入（一般會員流程的 `vendor_infos` 金鑰早已同樣落 log），本次遷移的增量僅是「活動商戶那一組金鑰」也開始進同一個 log channel。非會員端可觸發，需 log 讀取權才成立。

> ⚠️ **給後續 review 的註記**：這一項**不要再當 finding 提報**。`CLAUDE.md` 的紅線「憑證入 log 必先 `Common::maskCredentials()`」仍然適用於**新寫的程式碼**（自己組 log context 時該遮就遮），但既有 vendor driver 經 `Http::logAction()` 把 secret 帶在 GET query 裡這件事屬已接受現況，不在單一票的守備範圍。

#### 「reuse 既有 Driver」吃掉的舊版活動特有規則（2026-08-04 第四輪 review，7 家系統性對照）

本次策略是「重用既有 Driver ＋ 注入活動情境」，這個策略隱含一個假設：**舊系統的 `getEventGameUrl()` 與一般 `getGameUrl()` 行為相同**。逐一 diff 舊系統 7 個 vendor 類別的兩支方法後，該假設**有兩家不成立**：

| Vendor | 舊版 event 與一般流程的差異 | 新版狀態 |
|---|---|---|
| **VpowerSW** | 一般 `getGameUrl()` 的參數陣列**連 `lang` 這個 key 都沒有**（`VendorVpowerSingleWallet.php:34-78`）；event `getEventGameUrl()` 寫死 `'lang' => 'en'`（同檔 `:96`） | ✅ **維持新版行為（PM 已確認 2026-08-04：舊版是漏加語言，新版才正確）**：統一走 `mapLanguageCode()`（`app/Vendors/VpowerSW/Driver.php:171-185`）送玩家實際語言。已補測試 `tests/Feature/Vendors/VpowerSW/EventGameUrlTest.php::test_event_game_url_sends_member_language_not_hardcoded_english`（繁中會員斷言送 `lang=cn`），防止日後被「還原」成寫死 en |
| **PPSW** | event `getEventGameUrl()` 寫死 `'platform' => 'WEB'`（`vendorPpsw.php:55`）。註：舊系統的 PPSW **只有** event 方法、沒有一般方法（舊 `GameController` 完全沒引用 PPSW），故無一般流程基準可比 | ✅ **維持新版行為（PM 已確認 2026-08-04：舊版寫死 WEB 屬缺陷）**：依 `$params->deviceType` 動態送 MOBILE/WEB（`app/Vendors/PPSW/Driver.php:107`）。留意這是舊系統從未在正式環境行使過的手機版路徑，上線後值得觀察首批行動端啟動 |
| PGSSW | event 版少了 `rtrim($api_url, '/')` ＋ 補斜線（`VendorPgssw.php:92` 是 `$this->api_url . 'game/launch-link'`），設定值無尾斜線時會拼出錯誤 URL——舊系統 event 側的潛在 bug | ✅ 新版統一用一般流程寫法（`PegasusSW/Driver.php:42-43` 有 rtrim），**意外把舊 bug 修掉了**，方向對玩家有利 |
| WudiSW／FumaSW／WFSW／UUSlotSW | **兩支舊方法本來就是同一套邏輯**（WudiSW 連註解掉的 `// 'back_url'` 死代碼兩邊都一樣；FumaSW 的 `windowMode`／`lobbyURL`／`backBtn`／`mute` 兩邊同樣寫死 `'0'`） | ✅ 不存在差異 |

**PPSW `currency`／`country` 的來源改變 — 已查證為非問題**：舊版讀商戶層級（`vendorPpsw.php:53-54` 的 `$this->infoDetail->currency`／`->country`，經 `PpSwVendorInfoDetailDTO:17-18` 由 `info` 的 `CURRENCY`／`COUNTRY` 大寫 key 映射而來），新版讀帳號層級（`$account->currency`／`->country`）。查 yes2win 正式 DB（唯一有 PPSW live 的白標）：`event_ppsw_accounts` 61,954 筆，`currency`／`country` **零筆空值**、各只有 1 個相異值 `MYR`／`MY`；而 `event_vendor_infos.info` 的 `CURRENCY`／`COUNTRY` 同樣是 `MYR`／`MY`。**兩個來源的值完全相同**，行為無差異。

**`Context::getHidden(LANGUAGE_ID)` 蓋過 `$params->language` — 只有 2 家、且屬既有行為**：`UUSlotSW::mapLanguageCode()`（`:152-164`）與 `VpowerSW::mapLanguageCode()`（`:171-185`）都無參數、一律讀 Context；其餘 5 家（WudiSW／FumaSW／PPSW 吃 `?LanguageEnum` 參數並 fallback Context，PegasusSW／WFSW 必吃 `$params->language->value`）都正確消費呼叫端傳入值。這 2 家的寫法遠早於本票，且兩個舊方法本來就都吃外部傳入語言，故非本票落差。Context 由 `Frontend/DetectLanguage` middleware 設，前端路由上必定有值。

**`event_pgssw_accounts` 缺 `event_vendor_info_id` 索引 — 建議作廢，不補 migration**：該表確實是 7 張中唯一沒有此索引的（其餘 6 張都有），但**不該補**。查 tpower：該欄位 `COUNT(DISTINCT) = 1`（656,650 列全同一值），索引選擇性為零，優化器不會選它。`claimEventAccount()` 兩條查詢都是 `(event_vendor_info_id, member_id)` 雙欄過濾，而 `member_id` 才是選擇性好的那個——Query 1 帶具體 member_id 命中約 1 列，Query 2 的 `member_id = 0` 收斂到 5,000 列（且 InnoDB 二級索引尾端隱含 PK，掃出來本身即 id 序，`ORDER BY id LIMIT 1` **不需 filesort**）。EXPLAIN 實測 `type: ref, key: member_id_index, rows: 5000`，**不是全表掃描**。另全 codebase 掃過，除 `claimEventAccount()` 外只有 `EventPpswController.php:610` 用到該欄位且是 INSERT 的值、非查詢條件，「只用 evi 過濾」的查詢並不存在。補一個 cardinality 1 的索引只會增加批次補池位的寫入成本。

#### 本節 code review 修正（2026-08-04，第三輪：marz ＋ timo ＋ 6 白標正式 DB 查證）

| # | 項目 | 嚴重度 | 處理 |
|---|---|---|---|
| 1 | `open_window` 取 `vendors.open_window`（vendor-level），舊版取的是 `$game->open_window`（game-level，`select` 於舊版 `RefreshRankingController.php:606`、回傳於 `:673`）。`open_window` 本身是 `GameListSyncService` 逐款遊戲從遊戲商同步寫入 `games` 的屬性，同一遊戲商底下各遊戲可以不同 | **Major（線上已生效）** | ✅ 改回 `(int) $game->open_window`，`$game` 查詢補 `select` 該欄位。**查證影響面**：yes2win 掛在活動下的 911 支遊戲中有 **36 支** `games.open_window ≠ vendors.open_window`，全部集中在 WUDI（`vendors`=1、旗下 36 款遊戲皆為 0），且該商戶是 live（`status=1`、2 個 agent binding、24,063 帳號池）——即這 36 支遊戲舊版回 0（頁內開）、新版會回 1（另開分頁／外部瀏覽器）。tpower 全部 0/0 無差異 |
| 2 | 活動帳號表對應以 `vendor_id` 當 key，舊版 `get_account()` 是以 `third_party_code` 分派；一個 code 對多個 vendor_id，聚合遊戲商（BWG／TIGER9／JokerBaccarat／兩個免盤費變體）全部落到 `default` 拋 9994 | **Major（潛伏）** | ✅ 改為 `EVENT_ACCOUNT_MODELS` 常數表以 `third_party_code` 為 key（7 個值已對照舊版 `VendorEnum::CODE_*` 常數與正式 DB 逐一確認）。取 vendor 的順序刻意維持舊版（先 `Vendor::find()` 再認領帳號，並補上舊版有、新版原本缺的 1426 檢查）——不可改成先 `getDriver()`，否則未配置的遊戲商會先拋 `VendorNotFoundException` 蓋掉這裡要回的 9994。詳見上方 4-2 的說明框 |
| 3 | 新增的註解「舊版 switch 的 default 同樣是直接拒絕（拋 1005）」與程式實際拋的 9994 自相矛盾（測試內同一句註解亦然）——舊版有兩層 switch，實際可達的是 `get_account()` 的 1005，第二層的 9994 是死代碼 | Minor | ✅ 註解改為如實描述「有意不同」並說明兩層 switch 的可達性，行為維持 9994（語意更貼合），並註明若前端對 1005／9994 有分流需一併確認 |
| 4 | 方法層 docblock 仍寫「僅支援 4 家、其餘 3 家屬死分支不實作」，與同一 commit 已改的 class 層 docblock 矛盾 | Minor | ✅ 同步更新為 7 家 |

**本輪一併查證的結論**（非修正項，記錄供後續參考）：

| 項目 | 查證結果 |
|---|---|
| 「PPSW／WudiSW／FumaSW 無代理配置屬死分支」這個原始結論 | **錯誤，且錯在只查了 tpower**。yes2win 上這三家全是 live：`event_vendor_infos` 各有一筆 `status=1`＋2 個 agent binding，帳號池分別為 PPSW 61,954／WUDI 24,063／Fuma 14,115 筆。即**不收錄的話 yes2win 玩家點這三家的活動遊戲會直接拋 9994**。跨白標盤點：tpower 有 50／82／117／120（WFSW 已綁 17,128 個帳號，真實使用中）、vworld 有 50／82／117、alfawin77／me77／vplus33 只有 117 且 `status=0` |
| `WUDISW_FREE`（223）／`FUMASW_FREE`（214）收錄是否正確 | **正確**。yes2win `vendors` 表確有這兩筆（「WudiSW free」／「FumaSW free」），`third_party_code` 分別就是 `WUDISW`／`FUMASW`，故與本體共用同一張活動帳號表 |
| 三張新表是否存在（不存在會變成 SQL error 而非乾淨的 9993） | `event_ppsw_accounts`／`event_wudisw_accounts`／`event_fumasw_accounts` 在 tpower 與 vworld 都存在（0 列），池子空時正常回 9993 |
| `catch (Throwable)` 與 `catch (QueryException)` 的順序是否會重複 rollback | 不會。PHP 多重 catch 由具體到廣泛就近匹配，deadlock 重試仍先被 `QueryException` 攔下；且 `DB::rollBack()` 在無活動交易時（`toLevel < 0`）直接 return，不拋錯 |
| 5 家 Driver 的 `instanceof` guard 失敗是否會安靜略過（拿一般帳號打活動 URL 的帳號污染） | 不會，5 家全部直接拋 `VendorSetupException`，屬 fail-loud |
| `$vendorInfo`／`$account` 型別放寬對 150 支 vendor 的一般會員流程是否有迴歸 | 無。`->vendorInfo->rolling_chip_status`／`->type` 的使用點都在 `WcasinoSWController`／`UUSlotSWController`／`CosmoPlaySW`／`TFGamingSW`，皆不在動到的 5 家內，且 `useLegacyEventContext()` 只由 `getGameUrl()` 呼叫，不會流入這些路徑 |

## 5. 排行榜（`store/modules/events/index.js`）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 47 | 中獎排行 | `api/ranking/winning`<br>`frontend/api/ranking/winning` | WEB_Frontend<br>:1688 | 是 | apis: `RankingController@winning` → `RankingService::getWinningRanking`（詳見 5-1） |
| 48 | 平台投注排行 | `api/ranking/platform_betting`<br>`frontend/api/ranking/platform-betting` | WEB_Frontend<br>:1730 | 是 | apis: `@platformBetting` → `RankingService::getPlatformBettingRanking`（詳見 5-1） |
| 49 | 分潤排行 | `api/ranking/profit_list`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |
| 50 | 遊戲得獎排行 | `api/ranking/game_winner_list`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |

### 5-1. 序號 47–48 中獎排行榜／平台投注排行榜（`ranking/winning`、`ranking/platform_betting`）遷移細節

`RankingController` 的這兩支方法在 tpower-master 與 vw-master **逐行完全相同**，無取捨問題。與序號 49/50（已存在的 `gameWinners`/`profits`）同一支 Controller，本次補齊剩下兩支。

查證正式 DB：`ranking_tbr_records` 近 1 天達標（`win_lose >= 500`）紀錄 tpower 4,001 筆、vworld 1,708 筆，兩品牌皆持續有真實資料流入。

**新增**：`RankingService::getWinningRanking()` / `getPlatformBettingRanking()`，新增 Model `RankingTbrRecord`（資料表早有 migration，只是缺 Model；查詢仍走 `DB::table()` 以貼近舊版原始 join）。

**時間區間換算**：舊版以 `DateTime` 手動加減 `-8 hours` 湊 UTC+8 邊界；apis 改用既有 `Carbon::toFrontendTime()` / `toSystemTime()` macro（與本檔已遷移的 `gameWinners`/`profits` 相同機制）：

| type | 中獎排行 | 平台投注排行 |
|---|---|---|
| 1（即時） | 不套時間篩選，依 `payout_time` 排序 | 與 type 2 相同（見下） |
| 2（日） | frontend 當日 00:00–23:59 | **frontend 前一日**（舊版註記「24+8=32 小時前」，刻意慢一天顯示） |
| 3（週） | frontend 當週一至週日 | 同左 |
| 4（月） | frontend 當月 | 同左 |

**帳號遮蔽**：舊版以原始 SQL `CONCAT/LEFT/RIGHT/REPEAT` 公式遮蔽，比對後與既有 `Common::maskString()`（遮中間 60%）算法一致，故直接沿用該既有 helper，不重寫 SQL 字串公式。

**`hierarchy_path` 篩選**：舊版 `whereRaw("a.hierarchy_path LIKE '$agent->hierarchy_path%'")` 為字串直接內插（該值來自 DB 非使用者輸入，非直接可利用的注入點，但仍是反面示範）；apis 初版改用參數化的 `where('a.hierarchy_path', 'like', $agent->hierarchy_path.'%')`，與本檔既有 `getGameWinners`/`getProfits` 寫法一致——**但兩者共有前綴碰撞問題**（`LIKE '/1/715%'` 會誤配到兄弟代理 `/1/7151`），已於 code review 第二輪第 9 條修正為共用的 `hierarchyPathScope()`（`= path OR LIKE path/%`），4 支方法（含既有的 `getGameWinners`/`getProfits`）一併對齊。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `hierarchy_path` 篩選 | `whereRaw` 字串內插，且 `LIKE '{path}%'` 前綴碰撞 | 參數化 `hierarchyPathScope()`：`= path OR LIKE path/%` | 避免字串內插反面示範；前綴碰撞問題見上，與 `AgentController`／`MarqueeController` 既有正確寫法對齊 |
| 平台投注排行名次 | `<=` 判斷在遞減排序下恆為真，逐列依序編號（無同分同名次） | 直接以 `index + 1` 賦值，行為等價但拿掉恆真判斷式 | 簡化死碼，行為不變 |
| 快取讀取 | Redis 手動 `exists`/`get`/`set` | `Cache::safeRemember()` | 防快取擊穿，與同檔 `getFakeData()` 慣例一致 |
| `ranking/winning` 快取 key | 只有 `agent_id + type` | 補 `languageId` | 查詢結果含語系相依的 `game_name`，缺這維度會讓不同語系互相污染顯示 |

**未變更之舊版行為**：`win_lose >= 500` / `sum_bet >= 500` 門檻維持寫死（舊版註記「先固定500」）；平台投注排行的名次**不採同分同名次**（與既有 `RankingService::setRank()` 的「同分同名次」規則不同，兩者刻意不共用）；快取 TTL 沿用 300 秒（`CacheTtl::DEFAULT`）。

**測試**：`tests/Unit/Services/RankingWinningAndPlatformBettingTest.php`（11 支，涵蓋帳號遮蔽與遊戲商／遊戲名稱、低於門檻排除、代理層級隔離、type=1 即時不套時間篩選改依派彩時間排序、遊戲名稱語系、快取 key 不因語系互相污染、代理層級前綴碰撞排除、平台投注同分不同名次逐列編號、低於門檻排除、type=2 抓前一 frontend 日、代理層級隔離）。真實資料驗證：查詢 tpower／vworld 正式 `ranking_tbr_records` 確認近 1 天達標筆數，兩品牌資料皆活躍。

## 6. VIP / 錢包（`store/modules/points/index.js`）

> **文件先前的「否」誤判**：序號 51／52 其實**早已遷移**（`VipController@info`／`@rewardHistory`，已掛路由），只是本文件先前盤點時漏記。實際本次要補的只有序號 53–55。

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 51 | VIP 資訊 | `api/vip/info`<br>`frontend/api/vip/info` | WEB_Frontend<br>:233 | 是 | 先前已遷移，本文件補記。apis 另有 `VipController@contents`（VIP 等級列表，新功能，無舊版對應） |
| 52 | VIP 獎勵紀錄 | `api/vip/reward_history`<br>`frontend/api/vip/reward-history` | WEB_Frontend<br>:267 | 是 | 先前已遷移，本文件補記 |
| 53 | VIP 流水限制 | `api/vip/flow_limit`<br>`frontend/api/vip/flow-limit` | WEB_Frontend<br>:301 | 是 | apis: `VipController@flowLimit` → `VipService::getFlowLimits`（詳見 6-1） |
| 54 | VIP 領取 | `api/vip/receive`<br>`frontend/api/vip/receive` | WEB_Frontend<br>:336 | 是 | apis: `@receive` → `VipService::claimReward`（詳見 6-1）。**有派彩**（平台點數／禮碼） |
| 55 | VIP 返水領取 | `api/vip/receive_rebate`<br>`frontend/api/vip/receive-rebate` | WEB_Frontend<br>:371 | 是 | apis: `@receiveRebate` → `VipService::claimRebateRewards`（詳見 6-1）。**有派彩**，一次領取所有待領的「每日返水」 |
| 56 | 解鎖優惠錢包 | `api/unlockPromotionWallet` | WEB_Frontend<br>:407 | **不需要遷移** | 前端已廢除（見「前端實際使用清單對照」）。**另一呼叫端序號 110（API_Agent）2026-08-05 也確認作廢**，此端點整體不需要遷移 |
| 57 | 上筆紀錄轉移 | `transferFromLastLog`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |
| 58 | 遊戲錢包轉帳 | `transferGameWallet`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |
| 59 | 單一錢包餘額 | `singleWalletBalance`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |

### 6-1. 序號 53–55 VIP 流水限制／領取／返水領取（`vip/flow_limit`、`vip/receive`、`vip/receive_rebate`）遷移細節

`VipController`（809 行）在 tpower-master 與 vw-master **逐行完全相同**，無取捨問題。apis 既有 `VipController`/`VipService`（序號 51/52）已採 Eloquent 關聯的現代化寫法，本次三支延續同一風格，不落回舊版 `DB::table` 原始查詢。

查證正式 DB：`member_vip_prizes` 待領取（`status = 0`）筆數 tpower 17,147、vworld 18,400，兩品牌皆為高頻使用中的功能。

**`flow_limit` 分頁**：沿用既有 `PrizeController@list`（序號 29）已建立的 `LengthAwarePaginator` + `OnlyPaginateResource` 慣例，`page`/`per_page` 改為必填（與該既有端點一致），不採舊版「未帶時預設 50」。

**`receive_rebate` 的 `vip_reward_id = 7`**：舊版與既有 `VipService::getThisRebateRecord()`（序號 51 的一部分）皆寫死 `vip_reward_id = 7` 識別「每日返水」。查證正式 DB：`type = 2`（額外返水）與 `vip_reward_id = 7` 在 tpower／vworld 現況皆為 1:1 對應，`type` 欄位語意也更清楚；但為與同檔既有寫法一致，**沿用寫死的 `7`**，不引入第二種識別方式。

#### 刻意偏離舊版之處

| 項目 | 舊版行為 | 本次處理 | 理由 |
|---|---|---|---|
| `receive` 禮碼派發的 `before_balance` | 先以「未鎖」讀取取得 `before_balance`，事後才 `lockForUpdate()` 重取但不重讀餘額 | 鎖定後才讀 `before_balance` | CLAUDE.md 併發紅線；舊版讀取與鎖定之間有競態空窗 |
| `receive`／`receive_rebate` 待領獎勵查詢 | 查完才個別檢查 `status`／`expired_time`，未在同一鎖內原子化 | 改用 `where('status', 0)->lockForUpdate()` 條件式取出，取不到即視為未中獎／已領取 | 併發防護（與本批其他派彩端點一致） |
| 錯誤訊息除錯後綴 | `__('error_message.1941') . '_1'` / `'_2'` / `'_3(...)'` | 移除後綴 | 除錯標記外洩給玩家（與 2-6 同類處理） |

**未變更之舊版行為**：`receive`/`receive_rebate` 只檢查鎖分模式（`event_mode > 0`），不檢查手機/真實姓名驗證（與其他派彩端點不同，舊版本來就沒有這些檢查）；`vip_content_rewards.point_type` 非 2（禮碼）者一律派發平台點數（含未使用的 `point_type = 3` 實體獎勵，查證正式 DB 兩品牌皆無配置）；金額上限 `max_point > 0` 時封頂；稽核流水僅在 `flow_limit_multiple > 0` 時寫入。

**測試**：`tests/Unit/Services/VipServiceTest.php`（22 支，涵蓋流水限制分頁與排除取消稽核狀態、單筆領取的固定金額／%數／額外返水三種類別與金額上限、禮碼派發與既有錢包累加、稽核流水寫入與略過、未中獎／已領取／已過期／獎勵停用／類型不符／鎖分模式／跨會員的拒絕、批次返水領取的多筆一次入帳、非返水獎勵不誤領、無 VIP／無有效返水設定的拒絕）。真實資料驗證：查詢 tpower／vworld 正式 `vip_content_rewards` 確認 `type=2` 與 `vip_reward_id=7` 的對應關係。

## 7. 登入活動（`store/modules/login/index.js`）

> **PM 已確認（2026-08-03）：序號 60–64 整組不需要遷移**，新版前端不使用登入活動功能。60–62（參加／解鎖／檢查）原本待處理，63/64 原本已遷移（`EventService::getLoginEvent`/`getLoginEventByDomain`），確認不需要後已整段移除（Controller 方法、Service 方法、`FormRequest`、routes、測試 `LoginEventTest.php`）。`app/Models/LoginEvent`（`login_events`/`login_event_names` 對應 Model）**保留不動**——該 Model 於本次遷移之前即已存在，`LoginService::prepareResponseData()`（登入回應組裝，非本 ticket 範圍）仍會用到，見下方風險記錄。

| 序號 | 功能 | 舊路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 60 | 參加登入活動 | `api/joinLoginEvent` | WEB_Frontend<br>login/index.js:186 | **不需要遷移** | 見 7-1 |
| 61 | 解鎖登入活動 | `api/unlockLoginEvent` | WEB_Frontend<br>:239 | **不需要遷移** | 見 7-1 |
| 62 | 檢查登入活動 | `api/checkLoginEvent` | WEB_Frontend<br>:285 | **不需要遷移** | 見 7-1 |
| 63 | 取得登入活動 | `api/getLoginEvent` | WEB_Frontend<br>:337 | **不需要遷移** | 曾短暫遷移為 `EventService::getLoginEvent`，PM 確認整組不需要後已移除，見 7-1 |
| 64 | 取得登入活動（依網域） | `api/getLoginEventByDomain` | WEB_Frontend<br>:339 | **不需要遷移** | 曾短暫遷移為 `EventService::getLoginEventByDomain`，同上已移除 |
| 65 | 登入 | `login`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |
| 66 | Telegram Bot 系列 | `tgBot/*`<br>（已存在） | WEB_Frontend<br>同檔 | 是 | 已遷移，僅列參考 |

### 7-1. 序號 60–64（登入活動整組）確認不需要遷移

**背景（60-62 原本的處理難點）**：`joinLoginEvent`／`checkLoginEvent` 共用私有方法 `checkLoginEventQualifications()`（221 行，兩分支逐行完全相同）：會員參加後把 `members.balance` 直接設為活動贈點、寫入 `members.event_mode = login_events.id`（進入鎖分模式），且參加前需即時查詢該會員可用的**所有**遊戲商目前餘額（含維護中檢查）確認總殘值 < 1 才可參加。`unlockLoginEvent`（207 行，兩分支逐行完全相同）則是解鎖流程：需即時查詢並回收**所有**遊戲商的目前餘額回平台、判斷是否達標解鎖或歸零強制解鎖，並寫入回收流水。

原評估：這與序號 46（`refresh_ranking/game_url`）同一類量級但範圍更大——`game_url` 是固定的 7 家（各自一次帳號認領），這裡則是「不限定哪幾家，該代理設定了哪些遊戲商就要全部即時查詢／回收」，且直接操作 `members.balance` 歸零與併發鎖定的核心欄位。查證發現 apis 既有的 `VendorService::getDriver()` ＋ `AbstractTransferWallet::getBalance()`／`MemberTransferService::transferBackRecentVendors()` 已解決「呼叫三方」這塊（同一套抽象已用於鎖分活動解鎖回收），可行性比原估高；但仍需對代理設定的**全部**遊戲商（非僅近期用過的子集）逐一即時查詢，鎖著 `Member` 列期間跑 N 次即時餘額查詢的延遲/併發風險並未消失。

**另查證發現的架構疑慮（記錄備查，非阻擋因素）**：apis 現行 `members.event_mode` 已被多處覆寫為「綁定 `prize_contents.id`」語意（`FlowLimitService`／`VipService`／`PrizeService`／`EventJackpotService`／`SlotCompetitionService` 皆如此），但 `LoginService::prepareResponseData()`（登入回應組裝，序號 65 的既有邏輯）仍假設 `event_mode` 存的是 `login_events.id`（`LoginEvent::where('id', $member->event_mode)...`）。這是舊系統沿用至今、與新語意重疊使用同一欄位的既有設計，目前 `login_events` 無任何進行中活動、無實際碰撞（查證見下），但若日後任何一邊的 ID 剛好與另一邊的現行鎖定值重疊，`LoginService` 那段既有判斷可能誤判。因登入活動確認不需要遷移，此疑慮不會再被本 ticket 放大，但既有的 `LoginService.php:264` 邏輯本身仍是懸而未決的技術債，留給日後動到 `event_mode` 或 `LoginService` 時處理。

**查證正式 DB（2026-08-03）**：tpower／vworld 的 `login_events` **目前皆無任何進行中的活動**——tpower 最後一筆 `status=1` 已於 2022-02-12 結束，vworld 最近的兩筆（id 12/13，預定 2026 年 2 月）皆為 `status=0`（停用，從未真正上線）。`members.event_mode > 0` 現有的鎖定會員（tpower 3,361 筆）逐一核對後，其 `event_mode` 值皆對應既有 `prize_contents.id`（即序號 23-27 已遷移的 vendor_lock 派彩流程），**沒有一筆對應到 `login_events.id`**，代表登入活動這個功能實際上自 2022 年後就沒有真正跑過。

**最終決定（2026-08-03）**：PM 確認新版前端不需要登入活動功能，序號 60–64 整組標記**不需要遷移**。63/64 曾短暫遷移完成（`EventService::getLoginEvent`/`getLoginEventByDomain`、對應 Controller 方法、`frontend/api/event/login-event`／`login-event-by-domain` 路由、`LoginEventByDomainRequest`、`tests/Unit/Services/LoginEventTest.php`），確認後已整段移除，不留冗餘程式碼。

## 8. 場館 / 遊戲

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 67 | 活動場館遊戲 | `api/event/vendorGames`<br>`frontend/api/event/vendor-games` | WEB_Frontend<br>gameApp/index.js:118 | 是 | apis: `EventController@vendorGames` → `EventService::getEventsByVendor`（詳見 8-1）。兩分支逐行完全相同 |
| 68 | 優惠遊戲 | `api/getPromotionGames`<br>`frontend/api/game/promotion-games` | WEB_Frontend<br>homeGames/index.js:1691 | 是 | apis: `GameController@promotionGames` → 新增 `GameService`（詳見 8-1）。**vw-master 獨有**，tpower-master 無此端點 |
| 69 | 活動遊戲 | `api/event/games` | WEB_Frontend<br>homeGames/index.js:1769 | **不需要遷移** | 前端已廢除（見「前端實際使用清單對照」）。無其他呼叫端 |

### 8-1. 序號 67–68 活動場館遊戲／優惠遊戲遷移細節

**序號 67**：`EventController::gamesByVendor`（兩分支逐行完全相同）依賴共用私有方法 `getGameByDetailId`，此方法**已在序號 22（活動詳情）遷移為 `EventService::getLegacyEventDetailGames()`**（改用既有 `VendorService`/`AgentGameService` 取代舊版 `vendorList`/`agentGameList`，見文件 2-4）。本次直接複用同一支方法，僅新增 `getEventsByVendor()` 負責「依 `vendor_id` 篩活動、逐筆掛上遊戲列表」的外層邏輯，未重複移植遊戲可玩性判斷。

**序號 67 的快取層**：舊版整段查詢包在 `if (!$redis->exists($key))` 內（key = `event:list:{member_id}:{language}:{vendor_id}`，`REDIS::set()` 預設 TTL 3600），逐筆活動查遊戲清單的 N+1 成本僅在 cache-miss 時付出。初版遷移未帶快取層，等於每次 request 都全額付 N+1；已補回 `Cache::safeRemember(CacheKeys::eventGamesByVendor(...), CacheTtl::ONE_HOUR->value, ...)`，key 組成與 TTL 沿用舊版（key 必須帶 `member_id`：回傳的 `games.disable` 取決於該會員鎖分狀態，不可跨會員共用）。已知取捨：per-member 快取撐 1 小時期間，會員中途被鎖分／解鎖時 `disable` 旗標會是舊值——此為舊版既有行為，遷移期先對齊，要縮短 TTL 另提 PM。

**序號 68**：`GameController::getPromotionGames`（vw-master 獨有）回傳「近一個月會員自己投注過的遊戲」與「當日全平台投注過的遊戲」兩組列表，各自標註是否因鎖分模式被禁玩（`disable`）。查證：`disable` 判斷所需的 `event_vendor_locks` 概念，apis 已在別處以更完整的 `FlowLimitService::isGameDisabled()`（讀 `prize_content_flow_limits`）統一取代，**與 `GameController::list()` 既有端點共用同一套邏輯**，故直接沿用、不重寫舊版 `event_vendor_locks` 專屬查詢與其手動 SQL 字串組合（原始碼有 `a.type_id` 誤植成不存在的別名的錯字，沿用既有方法後此問題不復存在）。因原業務邏輯在 `GameController` 中屬跨查詢的獨立單元，依 CLAUDE.md「業務下沉 Service」紅線新增 `App\Services\GameService` 承接（apis 原本沒有 GameService，`GameController` 既有方法多為直接寫在 Controller，本次不沿用該例外）。

**測試**：`tests/Unit/Services/EventVendorGamesTest.php`（5 支）、`tests/Unit/Services/GameServiceTest.php`（7 支）。真實資料驗證：查詢 vworld 正式 `event_details`（`vendor_id > 0` 且啟用中）共 9,705 筆，確認查詢結構與資料形狀一致。

## 9. 代理（`store/modules/agent/index.js`）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 70 | 場館費率列表 | `api/vendor/rate/list`<br>`frontend/api/vendor/rate-list` | WEB_Frontend<br>agent/index.js:337 | 是 | apis: `VendorController@rateList` → `VendorService::getRateList`（詳見 9-1）。同檔 unlimit / threeHierarchy / memberTeam 全數已遷移。兩分支逐行完全相同 |

### 9-1. 序號 70 遊戲商費率列表遷移細節

`VendorController::rate_list`（兩分支逐行完全相同，約 30 行）：依代理層級由近到遠找第一層有設定 `agent_vendors` 的代理，取其配置的遊戲商列表（含 `vendor_images` 客製化名稱／費率覆蓋）；找不到即回空陣列。業務邏輯新增為 `VendorService::getRateList()`（`VendorService` 已注入 `VendorController`），不落在 Controller。

**測試**：`tests/Unit/Services/VendorRateListTest.php`（5 支，涵蓋預設費率、`vendor_images` 覆蓋、查無設定回空、代理層級由近到遠找上層設定、停用的 `agent_vendors` 排除）。真實資料驗證：查詢 tpower 正式 `agent_vendors`／`vendor_infos`（皆啟用中）共 1,658 筆，確認查詢結構與資料形狀一致。

## 10. 交易紀錄（`store/modules/recordList/index.js`）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 71 | 紀錄 | `api/records` | WEB_Frontend<br>recordList/index.js:80 | **不需要遷移** | 前端 2026-07-30 回報已不再使用。**兩分支 `routes/api.php` 本來就沒有這條路由**（已複驗），前端呼叫應為死路徑；無其他呼叫端 |
| 72 | 優惠錢包交易 | `api/promotionWalletTransactions` | WEB_Frontend<br>:368 | **不需要遷移** | 前端已廢除（見「前端實際使用清單對照」）。無其他呼叫端 |

---

## 純新版 endpoint（無舊版對應，新功能，不需遷移）

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 73 | 新版活動列表 | 無（新功能）<br>`frontend/api/event-v2/list` | WEB_Frontend<br>events/index.js:169 | 不適用 | 新版活動系統 |
| 74 | 新版活動詳情 | 無（新功能）<br>`frontend/api/event-v2/detail` | WEB_Frontend<br>:421 | 不適用 | |
| 75 | 新版活動參加 | 無（新功能）<br>`frontend/api/event-v2/join` | WEB_Frontend<br>:458 | 不適用 | |
| 76 | 新版活動參加明細 | 無（新功能）<br>`frontend/api/event-v2/join-detail` | WEB_Frontend<br>:494 | 不適用 | |
| 77 | 新版活動提領 | 無（新功能）<br>`frontend/api/event-v2/withdraw` | WEB_Frontend<br>:526 | 不適用 | |
| 78 | 新版活動退出 | 無（新功能）<br>`frontend/api/event-v2/quit` | WEB_Frontend<br>:562 | 不適用 | |
| 79 | 領取紀錄 | 無（新功能）<br>`frontend/api/event/claim-records` | WEB_Frontend<br>events/index.js:1259 | 不適用 | |
| 80 | 最近遊玩遊戲 | 無（新功能）<br>`frontend/api/game/recently-game-ids` | WEB_Frontend<br>homeGames/index.js:1727 | 不適用 | |
| 81 | 遊戲小工具 URL | 無（新功能）<br>`frontend/api/game/widget-url` | WEB_Frontend<br>playgame/index.js:368 | 不適用 | |
| 82 | 最近場館 | 無（新功能）<br>`frontend/api/vendor/recently-vendor-ids` | WEB_Frontend<br>vendors/index.js:247 | 不適用 | |
| 83 | 行為追蹤 | 無（新功能）<br>`frontend/api/member/tracking-events` | WEB_Frontend<br>List/index.js:409 | 不適用 | |
| 84 | 檢查資料完整度 | 無（新功能）<br>`frontend/api/member/check-profile` | WEB_Frontend<br>List/index.js:600 | 不適用 | |
| 85 | 存款類型(新) | 無（新功能）<br>`frontend/api/deposit/types` | WEB_Frontend<br>deposit/index.js:98 | 不適用 | |
| 86 | 取得會員帳號 | 無（新功能）<br>`frontend/api/guest/get-member-accounts` | WEB_Frontend<br>驗證流程新增 | 不適用 | |

---

## 其他呼叫端（全部未遷移，且新版無對應端點可切）

### WEB_CustomerServiceSocket（16 條，已全數遷移至 apis，呼叫端尚未切換）

Base URL 來自 env `DEFAULT_API_URL`，實際發送在 `app/ThirdParty.php:580` 私有 `post()`。屬 api.php 的 `third_party` 群組（:634-670）。

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 87 | 取會員餘額/資訊 | `getInfo`<br>`frontend/api/third-party/info` | WEB_CustomerServiceSocket<br>ThirdParty.php:37 | 是 | apis: `ThirdPartyController@info` |
| 88 | 取代理餘額/資訊 | `getAgentInfo`<br>`frontend/api/third-party/agent-info` | WEB_CustomerServiceSocket<br>:60 | 是 | apis: `@agentInfo` |
| 89 | 取登入設定 | `getLoginSetting`<br>`frontend/api/third-party/login-setting` | WEB_CustomerServiceSocket<br>:83 | 是 | apis: `@getLoginSetting` |
| 90 | 發簡訊驗證碼 | `sendSms`<br>`frontend/api/third-party/send-sms` | WEB_CustomerServiceSocket<br>:107 | 是 | apis: `@sendSms` |
| 91 | 驗證簡訊碼 | `checkCode`<br>`frontend/api/third-party/check-code` | WEB_CustomerServiceSocket<br>:126 | 是 | apis: `@checkCode` |
| 92 | 驗證代理 token | `checkAgentToken`<br>`frontend/api/third-party/check-agent-token` | WEB_CustomerServiceSocket<br>:146 | 是 | apis: `@checkAgentToken` |
| 93 | Google 驗證 | `googleAuth`<br>`frontend/api/third-party/google-auth` | WEB_CustomerServiceSocket<br>:164 | 是 | apis: `@googleAuth` |
| 94 | 確認會員密碼 | `checkPassword`<br>`frontend/api/third-party/check-password` | WEB_CustomerServiceSocket<br>:186 | 是 | apis: `@check_password` |
| 95 | 紅包/開洗分 | `transaction`<br>`frontend/api/third-party/transaction` | WEB_CustomerServiceSocket<br>:228 | 是 | apis: `@transaction` |
| 96 | (新)紅包/開洗分 | `newTransaction`<br>`frontend/api/third-party/new-transaction` | WEB_CustomerServiceSocket<br>:269 | 是 | apis: `@newTransaction` |
| 97 | 代理間轉帳 | `agentTransaction`<br>`frontend/api/third-party/agent-transaction` | WEB_CustomerServiceSocket<br>:318 | 是 | apis: `@agentTransaction` |
| 98 | (新)代理間轉帳 | `newAgentTransaction`<br>`frontend/api/third-party/new-agent-transaction` | WEB_CustomerServiceSocket<br>:353 | 是 | apis: `@newAgentTransaction` |
| 99 | 玩家轉帳 | `memberTransfer`<br>`frontend/api/third-party/member-transfer` | WEB_CustomerServiceSocket<br>:395 | 是（早於本輪批次即存在） | apis: `@memberTransfer`；2026-08-06 查證發現此端點在本 ticket 之前已存在於 apis（非本批次新增），但 CS socket 尚未切換呼叫，遷移文件先前誤列為「否」，已更正 |
| 100 | 查稽核/流水狀態 | `checkFlowLimit`<br>`frontend/api/third-party/check-flow-limit` | WEB_CustomerServiceSocket<br>:479 | 是 | apis: `@checkFlowLimit` |
| 101 | 取銀行帳號列表 | `getBankList`<br>`frontend/api/third-party/bank-list` | WEB_CustomerServiceSocket<br>:543 | 是 | apis: `@getBankList` |
| 102 | LiveChat token 操作 | `liveChat/{op}`<br>`frontend/api/third-party/live-chat/{get-token,get-fresh-token,has-token,invalidate}` | WEB_CustomerServiceSocket<br>:560 | 是 | apis: `@liveChatGetToken` / `@liveChatGetFreshToken` / `@liveChatHasToken` / `@liveChatInvalidate`；全新子系統（見 16-1） |

另補：`csSetting`（api.php:664 `@csSetting`，CS 後台設定彙總）原清單漏列，但同屬 `third_party` 群組，本次一併遷移至 `frontend/api/third-party/cs-setting`。

備註：`Setting.api_url` 欄位（`OrderTypeController.php:805`、`UserController.php:1344` 寫入）為歷史遺留，僅供顯示，`ThirdParty::post()` 實際改讀 env，與此遷移無關。

**呼叫端尚未切換**：WEB_CustomerServiceSocket 本身未修改，`DEFAULT_API_URL` 仍指向舊版 `API_Frontend`。上述 16 條僅完成「apis 側建置對應端點」，實際切換呼叫（改 `DEFAULT_API_URL` 或改個別 action 路徑）留待 CS 後台團隊另行排期，非本次遷移範圍。

#### 16-1. 遷移細節與刻意偏離舊版之處

**契約層級的一致偏離**（沿用本文件既有慣例，見 §1-2）：

| 項目 | 舊版行為 | apis 現況 |
|---|---|---|
| HTTP 回應碼 | body `code` 帶固定業務碼（1000/1005/1007/1022/2001…），HTTP 皆 200 | 統一走 `ApiException`／`ApiResponse`：成功 200，失敗 400（`ApiException` 對非合法 HTTP 範圍的 code 一律收斂為 400，故舊版 `1408`〔代理餘額不足〕等自訂碼在 apis 已無法保留，一律 400） |
| 部分端點 `data` 形狀 | `transaction`/`newTransaction`/`agentTransaction` 系列回 `data: <純數字餘額>` | 改包成 `data: {"balance": ...}`；LiveChat 4 支原回「裸物件/裸布林」，改包成標準 `{code,message,data,tid}`，`data` 為 `{"hasToken":...}`/`{"invalidated":...}`/帳號物件 |

已於**本次查證確認 WEB_CustomerServiceSocket 的 `ThirdParty::post()` 用純 `curl_exec()` + `json_decode()`，完全不檢查 HTTP status code**，故上述 HTTP 狀態碼差異對呼叫端無影響；但 CS socket 各 wrapper 方法會檢查 `$result['code'] == 1000`，與 apis 目前回傳的 `code:200` 不同。

> ⚠️ **切換前置條件（2026-08-06 panel review 升級，見 16-2；同日已修正 code 契約，見 16-3）**：此契約差異**不是**「待呼叫端切換時再處理」的一般備註，而是**cutover 當天會確定觸發**的問題——`transaction`/`newTransaction`/`agentTransaction`（呼叫方在 apis 側交易 commit 之後才判斷 `code`）沒有任何冪等機制，契約碼落差會讓呼叫端把「apis 已成功入帳」誤判為失敗，客服重試 = 重複入帳。**列為 CS socket 實際切換呼叫的 go/no-go 前置條件**：
> 1. ~~`code` 契約需二擇一相容~~ **已解決**：apis 側 `ThirdPartyController` 全面改用 `ApiResponse::customCode(1000, ...)` 成功、`ApiException` 全數補回舊版對應數值 code（見 16-3），與 CS socket 既有的 `== 1000`／`== 1408` 等判定完全相容，CS socket 側**不需修改**任何 code 判定邏輯。
> 2. **三支金流端點須先補冪等（呼叫端 request_no／唯一索引）——仍未解決**，是實際切換前唯一剩下的硬性前置條件。
> 3. 切換日先灰度單一代理驗證再全量放行。

#### 16-3. Code 契約修正與 WEB_CustomerServiceSocket 側同步調整（2026-08-06，接續 16-2）

承 16-2 的 panel review 追問：修好 `newTransaction`/`memberTransfer` 的 code 契約後才發現問題比原先記錄的更深——`ApiException` 先前**全數**未帶數值 code 參數，導致 JSON body 的 `code` 欄位（不只 HTTP status）一律收斂成 `400`，`newTransaction`/`memberTransfer` 依賴的 `1408`／`1005`／`2004`／`2047` 等具體業務碼**完全無法分辨**，CS socket 的分岔邏輯會全部失效。

**apis 側修正**：

- `App\Helpers\ApiResponse::customCode()` 擴充支援可選的 `$data` 參數（向後相容，既有 3 個呼叫點不受影響）。
- `ThirdPartyController` 全部 23 處成功回應改用 `ApiResponse::customCode(1000, data: ...)`，與舊版成功碼 `1000` 一致。
- 全部 37 處 `throw new ApiException(...)` 補上舊版對應的數值 code（逐一對照 `API_Frontend` 原始碼取值，含 2 個「code 與 message key 數字不一致」的舊版既有怪異慣例：`newTransaction`/`newAgentTransaction` 的代理餘額不足回 `code=1408` 但沿用 `error_message.1022` 的文字）。HTTP status 仍收斂為 400（非合法範圍），但 JSON body 的 `code` 保留原始數值，CS socket 據此判斷的邏輯不需改動。
- `memberTransfer` 收款方未實名的特例回應，原本把 `code:20471` 塞在 `data` 裡層（頂層仍是舊的 `code:200`），改為直接以 `ApiResponse::customCode(20471, message)` 回在頂層，與其餘端點的 code 位置一致；這是 apis 刻意新增的區分（舊版付款方／收款方未實名共用同一個 `2047`，僅訊息文字不同）。
- 補 5 個測試（`ThirdPartyResponseCodeContractTest`）鎖定 code 契約：成功 `1000`、會員不存在 `1007`、餘額不足 `1022`、代理餘額不足 `1408`、收款方未實名 `20471`。

**WEB_CustomerServiceSocket 側修正**（`app/ThirdParty.php`，master 分支直接修，依專案既有慣例）：

- 16 個 `post($data, $action)` 呼叫的 `$action` 字串改成 apis 對應的 kebab-case 路由（例：`getInfo`→`third-party/info`、`newTransaction`→`third-party/new-transaction`）。
- `getLiveChat()` 新增 `mapLiveChatOp()` 私有方法，把 `LiveChatController` 傳入的舊版 camelCase op 名稱（`getToken`/`getFreshToken`/`hasToken`/`invalidate`）轉成 apis 的 kebab-case 路徑片段。
- **不需**變更任何 `$result['code'] == 1000`／`== 1408` 等判定邏輯——apis 側已修正到完全相容。
- 已用 `php -l` 驗證語法正確；本檔案在 `_release/tests/` 下**無對應既有單元測試**（唯一相關測試 `ThirdPartyAuthenticateTest` 測的是接收端 middleware，非本檔案的對外呼叫邏輯），本次未新增測試覆蓋（該類別直接用 `curl_exec()`，非 Laravel HTTP client，需額外包一層才能有效 mock，超出本次修正範圍）。

**部署前提（本次未執行，需維運確認）**：`ThirdParty.php` 的 `post()` 是 `env('DEFAULT_API_URL') . $action` 直接字串相接；action 字串已改成帶 `third-party/` 前綴，因此 `DEFAULT_API_URL` 的值需相應改成指向 apis 的 `frontend/api/` base path（例如 `https://<apis-host>/frontend/api/`），這是環境變數層級的調整，不在本檔案的程式碼異動範圍內，也不是本次遷移執行的動作。

**沿用舊版但刻意不重現的行為**（皆屬低風險、已在程式內加註說明）：

- **登入活動殘留檢查**：`info` 的 `has_login_event_reward`（原呼叫 `SP_check_login_event`）與 `transaction`/`newTransaction` 洗分後呼叫的 `SP_cancel_login_event`，因登入活動整組已於本 ticket 第 5 章確認廢除，故不再呼叫，`has_login_event_reward` 固定回 `0`，洗分不再嘗試取消登入活動資格。
- **單一錢包開分自動轉入遊戲商**：`transaction`/`newTransaction` 的 `platform=2` 分支（開分/開分贈點時自動 `vendorTransfer` 轉入遊戲商）未重現，僅完成平台錢包入帳；此為既有 `VendorController` 整合的獨立子功能，依遷移原則先保留舊版 service、待測試完成後另行評估（同既有備註慣例，見 §1-4 序號 4 備註）。
- **大額洗分通知的第二重複頻道**：舊版另查 `params` 表發送「technology」站點頻道的重複告警，僅保留主要風控頻道（`RISK_CONTROL_TG_CHAT_ID`）通知。
- **`info` 的 `flow_timit` 筆誤分支**：舊版 `transfer_change_flow_limit` 分支寫入拼字錯誤的屬性 `flow_timit`（非 `flow_limit`），對外回應無實際影響，屬無害筆誤，不重現。
- **20 秒自我逾時中止機制**：舊版 `transaction`/`newTransaction` 內建計時器，執行超過 20 秒自行 rollback；apis 未重現此節流保護（非業務邏輯，且無等價機制可沿用）。

**遷移中發現並修正的 apis 既有問題**（非本次新增功能，屬順帶修復；已個別驗證不影響其他呼叫方）：

- `App\Http\Middleware\Frontend\ThirdParty\ThirdPartyAuthMember` 在 `from`（代理）路徑會對 `null` 的 `$member` 呼叫 `->makeHidden('agent')` 導致 500；此前僅 `memberTransfer`（純 `username` 路徑）使用該 middleware，未觸發此分支。改為 `$member?->makeHidden('agent')`。
- `App\Data\SmsSendData::$member` 型別原為 `Member` 強型別，但實際三方簡訊發送邏輯（`SmsGatewayService::sendSms()` 與各 `Suppliers\*Gateway`）從未讀取該屬性內容，僅用於落 `SmsSendRecord` 的 `agent_id`/`member_id`（在 `SmsGatewayService` 內部完成，不經過 `SmsSendData`）；改為 `Member|Agent` 聯合型別以支援本次代理端簡訊驗證碼（序號 90），不影響既有會員簡訊發送流程。**panel review 補充**：`App\Services\SmsGateways\Suppliers\FgGateway.php` 是唯一會實際讀取 `SmsSendData::$member` 的 Supplier（`$member->agent->sms_gateway_group_id`），傳入 `Agent` 時 `Agent::agent()` 解析出的是**上層代理**而非自身，設定層級可能取錯；僅當白標把 `fg` 通道分配給代理簡訊驗證流程時才會觸發，屬設定相關、非必然，記錄於「範圍外」待另案處理，本次未修 `FgGateway`。
- `App\Models\AgentAuthentication::google2fa_secret` 的加解密 accessor 目前**無任何寫入路徑使用**（`agent_authentications.secret` 實際由舊系統 `API_Agent` 的 `Google2FAController` 寫入明文），且 `secret` 欄位 `varchar(50)` 也裝不下加密後密文——該 accessor 屬未完工/不相容的既有程式碼。序號 93（`googleAuth`）改直接讀取原始 `secret` 屬性以符合production現況，未嘗試修復此既有加密機制（超出本次遷移範圍，建議另開票處理）。

**新建基礎設施**：

- Model `App\Models\TransactionLogAgent`（對應既有表 `transaction_log_agents`，序號 97/98 使用）：表本身已存在，但先前 apis 全 repo 無對應 Eloquent Model。
- Model `App\Models\LiveChatAccount` ＋ Service `App\Services\LiveChatService`（序號 102）：完整重建舊版 `API_Frontend` 的 `LiveChatService`，串接 LiveChat 官方 OAuth API（`https://example.invalid/customer/token`）換取／延續 access token；`origin` header 改用 `config('app.url')`（動態），不沿用舊版寫死的舊系統網域。**資料表 `live_chat_accounts` 本身已存在**（`2025_06_19_091321_create_live_chat_accounts_table` migration，早於本次遷移，先前僅有表、無 Model／Service）；本次一度誤建了一支重複的新 migration，經 panel review 抓出（若保留、日後 `down()` 會把既有正式資料表整張刪除）已刪除。

**已修正：`transaction_logs.agent_id` 寫入實際操作代理（2026-08-07）**

初版遷移時，`App\Services\TransactionLogCreateService`（序號 95/96 經 `writeCsTransactionLog()` 呼叫）固定把 `transaction_logs.agent_id` 寫成**會員自身所屬代理**（`member->parent`），而非請求 `agent_username` 解析出的代理，並曾記錄為「可接受的契約偏離」。經指出後複查確認**這是實際寫錯值，非可接受偏離**：

- `transaction_logs.agent_id` 的 migration 欄位註解即為「代理系統編號(**同一個玩家可能由不同代理開洗分**)」——欄位語意本就是「實際異動／扣款的代理」，一律取 `member->parent` 會讓同一玩家的所有列都記成同一個代理，該欄位失去意義。
- 舊版 `API_Frontend` `ThirdPartyController` 的 `transaction`（:1106）與 `newTransaction`（:1359/1578/1793）皆寫 `$log['agent_id'] = $agent->id`（`agent_username` 解析結果），與上述欄位語意一致。
- 影響範圍：`newTransaction` 真正被扣款的是 `$agent`（`updateAgentBalance($agent, ...)`），記成 `member->parent` 會使代理對帳／報表歸屬錯誤。

**修正方式**：`TransactionLogCreateService` 新增 `setAgentId(int $agentId)` fluent setter，`agent_id` 改為 `$this->agentId ?? $this->member->parent`——未呼叫時行為完全不變，全專案既有 30+ 個呼叫點（單一錢包投注／派彩、Vendor Libs、排程補帳等）不受影響，不動既有多錢包／活動 V2 分流不變量；`ThirdPartyController::writeCsTransactionLog()` 增收 `Agent $agent` 參數並 `->setAgentId($agent->id)`。

`memberTransfer`（序號 99）**維持 `member->parent` 不變**：舊版該處本就是 `$operator_transaction_log['agent_id'] = $operator->parent` / `$member_transaction_log['agent_id'] = $member->parent`（玩家對玩家轉帳，無操作代理），與上述兩支不同，不可一併改。

補 2 支回歸測試（`ThirdPartyTransactionTest`）：`transaction` / `newTransaction` 各一，以「非會員所屬代理」發起請求，斷言 `transaction_logs.agent_id` 為該操作代理；兩支在修正前的程式上均會失敗。

**待確認事項（未阻擋，記錄追蹤）**：

- `newTransaction` 洗分（type=5）扣抵稽核流水的開關設定鍵，舊版為 `WITHDRAWAL_CLEAR_FLOW_LIMIT`（setting_id 6065），apis 現有鍵值中查無完全對應的 setting_id；語意最接近的既有鍵是 `WITHDRAWAL_FLOW_LIMIT_SETTING_001`（6605）。**panel review 補充**：舊系統另有第三個相似鍵 `WITHDRAWAL_DEDUCT_FLOW_LIMIT`（6064，中文說明與 apis 的 6605 逐字相同，但舊系統實際只用於後台顯示、未接入真正的扣抵流程）——三鍵語意互相打結是舊系統本身遺留的糊塗帳，非本次遷移造成。三鍵目前所有白標皆無實際設定值（皆吃預設值＝1／扣除），暫以 6605 對應並於程式內加註，待正式上線前與 PM／後台確認鍵值是否需要新增對齊（連同 6064 這條額外線索一併帶給 PM）。

#### 16-2. Panel Review 結果與修正（2026-08-06）

序號 87-102 完成後執行四人 panel review（Timo 金流／Marz 舊系統對照／Rorin 安全／Rhyse 架構與測試）。**5 條 Warning 已修正**，其中 1 條屬本次新增的真實業務邏輯 bug（非無害筆誤），另有多項記錄追蹤但不阻擋合併。

### 已修

| # | 項目 | 位置 | 來源 | 狀態 |
|---|---|---|---|---|
| 1 | 領紅包稽核流水計算：發紅包方帳號乾淨（`hasFlowLimit<=0`）時，`transfer_flow_limit` 應維持舊版初始值 0 並仍走同真實姓名判斷，本次遷移誤寫成無條件回滿額——導致「同真實姓名 + 對方帳號乾淨」情境下錯誤鎖住收款方全額稽核流水 | `calcRedEnvelopeAuditAmount` | Marz | ✅ 改為 `transferFlowLimit` 預設 `'0'`，姓名判斷移到區塊外無條件執行，與舊版邏輯一致 |
| 2 | `transferOperatorFlowLimit` 的稽核流水轉移 remark 簡化成固定字串 `'transfer'`，遺漏 after／by 稽核明細且未疊加原 remark，與同檔既有 `memberTransfer` 慣例不一致 | 同上（`transferOperatorFlowLimit`） | Marz | ✅ 補回 `transfer,after:{afterReach},by:{transactionLogId}` 明細並疊加原 remark，僅完全消耗（`status=9`）分支維持純 `'transfer'`（與舊版一致） |
| 3 | `payment_password`／`agent_token`／`secret`（Google 2FA 一次性碼）三個敏感欄位未在 `MaskTrait::maskRules()` 規則表內，明文落 `RequestLogger` | `MaskTrait.php` | Rorin | ✅ 補三個鍵；`agent_token` 沿用既有 `token` 遮罩樣式 |
| 4 | LiveChat `access_token`／cookie（`__lc_cid`／`__lc_cst`）未遮罩：`Http::logAction()` 未帶 `maskKeys`（明文落 HTTP log），API 回應 `data` 亦無對應遮罩規則 | `LiveChatService.php`／`MaskTrait.php` | Rorin | ✅ `logAction` 補 `maskKeys`；`MaskTrait` 補三個鍵，回應 log 副本同步遮罩（不影響實際回應給呼叫端的明文內容） |
| 5 | `sendAgentSmsValidation` 兩處失敗通知（查無符合國際電話冠碼／查無簡訊內容）BotNotify 訊息帶代理完整手機號明文 | `ThirdPartyController.php` | Rorin | ✅ 改用 `Common::maskData($mobile, 'mobile')` |
| 6 | `newAgentTransaction` 的 `from`/`to` 依呼叫端傳入順序各自 `lockForUpdate()`，A→B 與 B→A 併發時鎖序相反可能死鎖 | `ThirdPartyController.php` | Rorin | ✅ 改依 `id` 排序後一次性 `lockForUpdate()` 取回兩筆再分辨 from/to |
| 7 | `transaction`/`agentTransaction`/`newAgentTransaction` 的 `amount`/`tax` 缺 `min:0`，理論上可傳負值造成餘額運算方向反轉（洗分傳負數變開分、不扣代理錢包） | 對應 `*Request.php` | Timo／Rorin | ✅ 三個 Request 補 `min:0`；`AgentTransactionRequest`/`NewAgentTransactionRequest` 的 `from`/`to` 補回與其他 13 個 Request 一致的長度限制；`TransactionRequest` 的 `remark`/`operator` 長度上限（原 50/30）放寬對齊 DB 實際欄寬（`varchar(191)`） |
| 8 | `transaction`/`newTransaction` 主分支的 `transaction_type` 判斷全走裸整數（`=== 14`、`[11,12,14]` 等），與同檔案私有方法（`createVipFlowLimitForDeposit` 等）已使用 `TransactionEnum` 不一致 | `ThirdPartyController.php` | Timo | ✅ 主分支全改用 `TransactionEnum::X->value`，新增 `AGENT_DEDUCT_SKIP_TYPES`／`FLOW_LIMIT_TRANSACTION_TYPES` 常數 |
| 9 | 誤建重複的 `live_chat_accounts` migration（該表已存在，`down()` 若執行會刪除既有正式資料） | `database/migrations/2026_08_06_084309_*` | Rorin | ✅ 刪除該 migration 檔案，改於文件註明表已存在 |

補 5 支回歸測試（`ThirdPartyTransactionTest`）：紅包同真實姓名＋發送方帳號乾淨不應鎖流水（bug 回歸測試）、紅包稽核流水跨多筆列轉移、`newTransaction` 洗分扣抵稽核流水單筆全額消耗與跨多筆列扣抵。第 1 條在修正前的程式上會失敗，非事後補的形式測試。

### 已驗證但不需處理（本次不修，記錄追蹤）

| 項目 | 驗證結果 |
|---|---|
| `ThirdPartyDecryptRequest` 的 key 比對用 `!=` 非 `hash_equals()`、無 timestamp/nonce 防重放 | 屬 third_party 群組既有慣例（`memberTransfer` 上線時即如此），非本次引入；重放需先取得請求原文（log／流量鏡像），非無門檻攻擊。搭配上方切換前置條件的冪等補強後，重放的實際破壞力會顯著下降。**建議另案**替 third_party 群組整體補 nonce/timestamp，屬跨端點基礎設施變更，不在本次範圍 |
| `frontend/api/third-party/*` 是否有 IP 白名單 | 應用層目前無此群組專屬 IP 白名單 middleware；**需與維運確認 infra 層是否已限制**，若否，建議優先補一層 IP 白名單，優先度高於程式邏輯本身 |
| `check_password`／`googleAuth`／`sendSms` 無失敗次數限制與告警 | 金鑰未外洩前非可利用；金鑰單點＋跨 repo 共用＋無輪替，一旦洩漏這三支即構成完整接管工具鏈的一部分。**建議另案**補 per-target 失敗計數＋告警 |
| `csSetting` 以 `parent` 通配回傳整組設定（含 `RISK_CONTROL_TG_CHAT_ID` 等營運識別碼） | fail-open 設計：日後任何新增的同 `parent` 設定都會自動被吐出去。今天不敏感，建議改明確白名單 `setting_id` |
| `ThirdPartyAuthMember` 的 `from` 分支未檢查代理 `status`（member 分支有） | 若為刻意讓 CS 對停權代理仍可操作，屬設計決定，非 bug；建議補註解避免日後被誤修 |
| `AgentAuthentication.secret` 明文存 DB（全專案既有議題，非本次引入） | 現在「修好」加密反而會 brick 全代理 2FA（寫入截斷＋與 API_Agent 格式不相容）；本次維持讀明文屬正確取捨，加密遷移需分階段（欄位放寬→雙讀相容→API_Agent 改寫入→回填），**另案處理** |
| LiveChat `access_token`／cookie 明文存 `live_chat_accounts` | log 已補遮罩（見已修第 4 條），DB 落地加密屬可排期加固，優先度低於 log（DB 有存取控制，log 會外流到日誌平台與備份） |
| `agentInfo`／`getLoginSetting`／`googleAuth`／`csSetting`／`getBankList` 重複查詢 middleware 已解析過的 Agent | 唯讀端點、非迴圈 N+1，影響輕微；可改用 `Context::getHidden(ContextKeyEnum::AGENT->value)` 省一次查詢，非阻擋項 |
| `newAgentTransaction` 無限額代理（`is_balance_unlimited`）完全跳過 from 側扣款 | 已比對舊版 `API_Frontend` 逐字一致，屬忠實遷移；程式註解已註明是「完全不扣款」而非單純略過檢查 |

### 範圍外（建議另開單）

- third_party 群組（含既有 `memberTransfer`）整體補簽名防重放（nonce/timestamp + `hash_equals()`）與驗證端點節流告警。
- `AgentAuthentication.secret` 分階段加密遷移。
- `app/Logging/HttpLoggerCustom.php` 對 `Cookie`／`Set-Cookie`／`Authorization` header 預設遮罩（全專案性缺口，不限本次端點）。
- `App\Helpers\Traits\MaskTrait::maskRules()` 目前為鍵名精確比對、fail-open；長期建議改 suffix/substring 比對（含 `password`/`token`/`secret`）降低漏鍵風險。
- `App\Services\SmsGateways\Suppliers\FgGateway.php` 對 `SmsSendData::$member` 的型別分支處理（見上方「已知的契約偏離」）。
- Controller 拆分：`App\Http\Controllers\Frontend\ThirdPartyController` 已膨脹到 18 個 public action，金流／SMS 相關 private helper 建議日後抽成獨立 Service（`CsTransactionService`／`AgentSmsService`），LiveChat 已示範正確分層可作範本。
- 金流端點（`transaction`/`newTransaction`/`agentTransaction`/`newAgentTransaction`）補結構化 audit log（member_id/agent_id/type/amount/operator/IP），目前僅 `transaction_type=14`（補點）有 BotNotify，其餘型別出事只能回推 `transaction_logs`。

### RD-Studio-01（排程 Commands，103–108 全部不需要遷移到 apis）

Base URL 來自 env `FRONTEND_API_URL`（含 `/api/`）或 `F2E_API_URL`（網域根，呼叫端自補 `api/`）。

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 103 | 派發代理結算報表分紅 | `paySettlementReportAgent`<br>**不經 apis** | RD-Studio-01<br>PayoutSettlementReportAgent.php:152 | **不需要遷移** | 2026-08-05 決定：不透過 apis 代理，直接把邏輯遷進 RD-Studio-01（見下方「103–105 遷移方式」）。原對應 api.php:27 `SettlementReportController@userSettlementReportBonus` |
| 104 | 派發代理三階層獎金 | `agentThreeHierarchyBonus`<br>**不經 apis** | RD-Studio-01<br>PayoutThreeHierarchyBonusAgent.php:131 | **不需要遷移** | 同上。原對應 :26 `ThreeHierarchyController@userThreeHierarchyBonus` |
| 105 | 派發代理獎金 | `agentThreeHierarchyBonus`（同 action）<br>**不經 apis** | RD-Studio-01<br>PayoutUnlimitBonusAgent.php:132 | **不需要遷移** | 同上（與 104 共用同一 action 與同一 service 方法） |
| 106 | 偵測前先登入取 token | `login`<br>無 | RD-Studio-01<br>VendorDetect.php:98（post 定義 :234-236） | **不需要遷移** | 已將 RD-Studio-01 的 `VendorDetect` 遷移到 apis（`app/Console/Commands/Schedule/VendorDetect.php`，`php artisan vendor_detect`），排程在 apis 內直接呼叫 Vendor Driver，不再需要先登入取 member token（RD-Studio-01 `Kernel.php:611` 的 `vendor_detect` 排程已註解停用） |
| 107 | 取遊戲商餘額 | `getVendorBalance`<br>無 | RD-Studio-01<br>VendorDetect.php:107 | **不需要遷移** | 同序號 106：已將 `VendorDetect` 遷移到 apis，改由排程直接呼叫 Driver `getBalance()`，不再走前台 HTTP 端點 |
| 108 | 查單一錢包餘額轉回平台 | `api/singleWalletBalance`<br>無 | RD-Studio-01<br>TurnMemberBalanceToPlatform.php:101（host :100，`F2E_API_URL`） | **不需要遷移** | 2026-08-05 確認作廢：唯一呼叫端 RD-Studio-01 `TurnMemberBalanceToPlatform`（`turn_member_balance_to_platform`）是 RD 自用工具，**未掛任何排程、`Kernel.php` 無註冊**，僅能手動執行。複查全 `/root/projects/` 無其他呼叫端（API_Frontend `TgBotController.php:1237` 僅為 ToDo 註解）。原對應 :335 `VendorController@singleWalletBalance` |

#### 103–105 遷移方式（2026-08-05）

這三支不進 apis：呼叫端本身就是 RD-Studio-01 的排程指令，改成**在 RD-Studio-01 內直接寫 DB**，消掉整條跨專案 HTTP 鏈。

舊鏈路：

```
RD-Studio-01 payout 指令
  → curl API_Frontend api/paySettlementReportAgent（SettlementReportController@userSettlementReportBonus）
  → curl API_Frontend api/agentThreeHierarchyBonus（ThreeHierarchyController@userThreeHierarchyBonus）
      └→ ThirdParty::agentTransaction() → curl WEB_CustomerServiceSocket ThirtyPartyController@agentTransaction
```

新做法（RD-Studio-01 分支 `feature/4005`）：

| 檔案 | 內容 |
|---|---|
| `app/Services/AgentBonusPayoutService.php`（新增） | `paySettlementReportBonus()` / `payThreeHierarchyBonus()`，逐行搬 API_Frontend 兩個 controller 方法；`writeCsTransactionLog()` 取代 `ThirdParty::agentTransaction` → CS socket 那一跳 |
| `app/Models/CsUser.php`、`app/Models/CsTransactionAgent.php`（新增） | `$connection = 'cs'`，對應客服 DB 的 `users` / `transaction_agents` |
| `app/Providers/AppServiceProvider.php` | 補註冊 `r_big_float` 自定義驗證規則（原在 API_Frontend） |
| 三支 payout 指令 | `post()`（curl）→ `payout()`（呼叫 service）；`use_new_balance` 判斷不再需要（原用來決定打前台或 CS_API） |

**連線分邊**（沿用各專案原本的預設連線，不可混用）：

| 資料 | 連線 |
|---|---|
| `agents.balance`、`transaction_log_agents` | 預設 `mysql`（`DB_DATABASE`，平台 DB） |
| `users`（查 id）、`transaction_agents` | `cs`（`CS_DB_DATABASE`，客服 DB，原 CS socket 的預設連線） |
| `params`、各報表表 | 預設 `mysql`（原本就是） |

**刻意保留的舊版怪異行為**（依「以舊版行為一致為最高優先級」，皆已於程式碼註記）：

1. `userThreeHierarchyBonus` 的餘額判斷寫成 `($my->balance - $my->balance) >= $amount`，恆為 `0 >= amount`，等同「只有發放者 `is_balance_unlimited = 1` 才會發」。
2. 兩支都是**發放者與收款者雙邊 `increment`**，不是從發放者扣款。
3. 「發放者無上限就不發放」的註解其實檢查的是**收款代理** `$user->is_balance_unlimited`，且回 1000（呼叫端因此把該列標記為已完成）。
4. `QueryException` 只回 `message` 不回 `code`，呼叫端 `isset($res['code'])` 因此不更新報表 status，下次會重跑。

**必要的行為差異（1 項）**：舊版 controller 在開頭 `beginTransaction`，validation 失敗 / 金額 0 / 代理不存在等提前 `return` 的分支既沒 commit 也沒 rollback，靠 HTTP 請求結束隱式回滾；改成指令內同步呼叫後必須明確 `rollback`，否則未關閉的交易會殘留並包住迴圈後續每一列。淨結果與舊版相同（該列不寫入任何資料）。

**部署注意**：清除 user 快取沿用 `REDIS::delete(['user'])`（`keys('*:user*')`，不受 prefix 影響），前提是 RD-Studio-01 的 `REDIS_HOST` 與前台 / CS 指向同一個 Redis 實例。

### API_Agent（2 條，1 條已於別分支完成、1 條確認不需要遷移）

Base URL 來自 env `F2E_API_URL`，發送用 `curlPost()`（`VendorBalanceController.php:181`，URL 拼接 :200）。

| 序號 | 功能 | 舊路由 / 新路由 | 呼叫專案 / 呼叫位置 | 是否已遷移 | 備註 |
|---|---|---|---|---|---|
| 109 | 取遊戲商餘額 | `api/getVendorBalance`<br>`agent/api/vendor/balance` | API_Agent<br>VendorBalanceController.php:74（host :73） | 是 | 對應 :319 `VendorController@getBalance`；已於另分支（`feature/4005_agent`）以 Agent 面向 `VendorController::balance()` 直接呼叫 Vendor Driver `getBalance()` 實作，取代 API_Agent 中轉，已併入 `master` |
| 110 | 解綁優惠錢包 | `api/unlockPromotionWallet`<br>無 | API_Agent<br>VendorBalanceController.php:148（host :147） | **不需要遷移** | 2026-08-05 確認作廢不用做（原對應 :339 `VendorController@unlockPromotionWallet`） |
