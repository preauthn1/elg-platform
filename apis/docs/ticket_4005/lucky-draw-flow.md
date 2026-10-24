# 抽獎券活動（Lucky Draw / V-Jackpot）完整流程

> 對應 `prizes.type_id = 5`（`PrizeTypeEnum::V_JACKPOT`）。同樣走 `lucky_draws`/`lucky_draw_tickets` 的
> 還有 `type_id = 7`（W-Jackpot 6D，`PrizeTypeEnum::W_JACKPOT_6D`），開獎 SP 換成
> `SP_payout_lottery_ticket_6d_reward.sql`，其餘表結構與流程一致，本文不重複說明。
>
> apis 側（本 repo）只承接「查詢／領獎」的讀寫端（target/tickets#4005 遷移範圍，見
> [`api-frontend-migration-4005.md`](./api-frontend-migration-4005.md) 序號 1-5）。**發券與開獎全部由舊系統的
> MySQL Stored Procedure 完成**，procedure 定義備份在 `database/procedures/`，但實際執行、排程觸發都在舊系統那側，
> apis 沒有任何程式碼呼叫這些 SP。

## 1. 用到的資料表

### 活動設定（後台配置，非 apis 寫入）

| 表 | 用途 | 關鍵欄位 |
|---|---|---|
| `events` | 活動主體 | `type_id`（`params.type=event_type`）、`agent_id`（`NULL`=全平台）、`status`、`auto_payout`（是否自動派獎） |
| `event_details` | 活動明細（時間區間、限制條件） | `event_id`、`action_id`、`start_time`/`end_time`、`draw_time`（派獎時間）、`expire_hour`（領獎期限，0=不限） |
| `prizes` | 獎勵遊戲主體 | `event_id`、`type_id = 5`（V-Jackpot）、`status`（0 停用/1 啟用，同 `event_id` 應只有一筆 `status=1`，見 §5 風險） |
| `prize_contents` | 獎項內容（一個 `prize` 可有多個內容，抽獎券場景通常對應各個中獎「等級」） | `prize_id`、`sent`（已派發數量，由開獎 SP 累加）、`status` |
| `reward_items` | 實際獎項（金額／等級／中獎號碼規則的載體） | `prize_content_id`、`level`（比對開獎號碼用的位數，1=全中，2 以上依規則比對前後段）、`type`（1 獎金／2 獎品）、`bonus`、`status` |
| `reward_item_names` | 獎項多語系名稱 | `type_id`＝`reward_items.id`、`language_id`、`prize_name` |

### 期數與票券（舊系統 SP 寫入，apis 只讀 + 領獎時局部更新）

| 表 | 用途 | 關鍵欄位 |
|---|---|---|
| `lucky_draws` | 每一期（issue）抽獎 | `event_id`、`event_detail_id`、`no`（期數）、`draw_time`、`draw_number`（開獎號碼，格式 `123456-7`）、`status`（0 未開獎/1 已開獎/2 已開未派獎/3 已開派獎中/4 取消） |
| `lucky_draw_tickets` | 會員持有的抽獎券 | `lucky_draw_id`、`member_id`（0＝尚未指派給任何會員，即票池）、`ticket_number`（`123456-7` 格式）、`reward_item_id`（開獎後寫入，0＝未開獎或未中獎）、`point`、`type`（0 未中/1 中獎）、`status`（0 未領/1 已領）、`time`（領取時間） |
| `temp_lottery_tickets`（另有 `_4d`/`1` 變體） | 預先產生的票號池，供 `SP_set_lucky_ticket` 抽取灌入 `lucky_draw_tickets` | `event_id`、`ticket_number`、`weeks_of_use`（單週/雙週分流）、`status`/`sort`（隨機取用游標） |

### 領獎憑證（開獎 SP 寫入，apis 領獎時讀取＋更新 status）

| 表 | 用途 | 關鍵欄位 |
|---|---|---|
| `prize_members` | 「這個會員可以領這筆獎」的憑證，**receive() 實際判定能不能發獎的表**，不是 `lucky_draw_tickets` | `member_id`、`prize_id`、`content_id`、`event_detail_id`、`expired_time`、`lottery_number`（＝`lucky_draw_tickets.id`）、`status`（0 未領/1 已領/3 取消，注意跟 `lucky_draw_tickets.status` 是兩條獨立狀態機） |

### 派彩結果（`receive()` 寫入）

| 表 | 用途 |
|---|---|
| `members.balance` | 直接加總獎勵金額（沿用舊版行為，不走 apis 既有多錢包分流 `WalletRepository`，見 [api-frontend-migration-4005.md](./api-frontend-migration-4005.md) 序號 4 備註） |
| `transaction_logs` | 派彩交易紀錄，`type = TransactionEnum::V_JACKPOT_REWARDS` |
| `flow_limits` | 有設定 `AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_RATE` 時寫入稽核流水 |

## 2. 如何配置這個活動（後台，非 apis 範圍）

1. 建一筆 `events`：設定活動時間、`agent_id`（`NULL` 表示全平台通用，否則限定該代理線及下線）、`status = 1`。
2. 建一筆對應的 `event_details`：設定 `draw_time`（派獎時間點）、`expire_hour`（0 表示不限期，否則以 `draw_time + expire_hour` 為領獎截止時間）。
3. 建一筆 `prizes`：`event_id` 指向上面的活動、`type_id = 5`、`status = 1`。**同一 `event_id` 應只維持一筆 `status = 1` 的 `prizes`**——`receive()`／`getIssueDetail()` 都用 `Prize::where('event_id', ...)->where('status', 1)->first()` 抓「當前有效獎項」，若同時存在多筆會撈到不確定的一筆（見 §5）。
4. 在 `prizes` 底下建 `prize_contents`（可多筆，對應不同中獎等級/組別），再於每個 `prize_content` 底下建 `reward_items`（可多筆，`level` 決定比對開獎號碼的位數規則，`level` 愈小比對愈精確／愈難中）。每個 `reward_item` 可再建多語系的 `reward_item_names`。
5. 每一期開獎前，建一筆 `lucky_draws`：`event_id`／`event_detail_id`／`no`（期數序號）、`status = 0`（未開獎）。
6. 事先（或即時）由排程把票號灌入 `temp_lottery_tickets` 供 §3 的發券程序抽取。

### 2.1 Dev 環境目前啟用中的實例（快照，2026-08-07 查證）

以 `events.status=1 AND prizes.type_id=5 AND prizes.status=1` 撈出的當前有效 V-Jackpot 活動，共 2 條代理線：

| event_id | title | agent_id | prize_id | event_detail 期間 |
|---|---|---|---|---|
| `47` | V-Jackpot | 4249 | 46 | 2020-07-26 ~ 2029-12-31，`auto_payout=0` |
| `1100` | V Jackpot | 100007 | 970 | 2025-12-22 ~ 2029-12-31，`auto_payout=0` |

兩者 `event_id` 底下都只有一筆 `status=1` 的 `prizes`，未踩到 §5 風險 2（同 `event_id` 多筆有效 `prizes`）。§5 風險 1 排查的 `ticket_id=2413441` 屬於 `event_id=47`／`prize_id=46` 這條線。

**當前有效 `event_details`**（`start_time <= 現在 <= end_time`，每週一筆週期性續期）：

| event_id | event_detail_id | 本週期間 | `draw_time` | `point`（達標門檻） |
|---|---|---|---|---|
| `47` | `6347` | 2026-08-06 11:00 ~ 2026-08-07 11:00 | 2026-08-07 11:10 | 300 |
| `1100` | `6341` | 2026-08-06 11:00 ~ 2026-08-07 10:59 | `0000-00-00`（未設定） | 10 |

**最新一期 `lucky_draws`**（依 `no` 取最大值）：

| event_id | lucky_draws.id | event_detail_id | no | draw_time | status |
|---|---|---|---|---|---|
| `47` | `1401` | `6351` | 601 | 2026-08-11 11:10 | 0（未開獎） |
| `1100` | `1385` | `5472` | 4 | 2025-12-25 11:10 | 0（未開獎） |

> `event_id=1100` 的 `event_details` 仍在每週續期，但最新 `lucky_draws` 停在 `no=4`（2025-12-23 建立），與 `47` 持續開新期（`no=601`）的節奏明顯不同——若要查證這條線是否仍在正常出券／開獎，這是個可疑的落差點，值得另外確認。

**當前有效 `prize_contents` 與 `reward_items`**（依 `level` 由精確到寬鬆排序；`reward_items.type`：1 獎金／2 獎品）：

| event_id | prize_id | prize_content_id | level | 名稱 | reward_item_id | type | bonus/獎品 |
|---|---|---|---|---|---|---|---|
| `47` | `46` | `530` | 1 | Bonus 88,888 | `1` | 1 獎金 | 88,888 |
| `47` | `46` | `532` | 3 | Bonus 888 | `3` | 1 獎金 | 888 |
| `47` | `46` | `533` | 4 | Bonus 88 | `4` | 1 獎金 | 88 |
| `47` | `46` | `562` | 5 | Bonus 18 | `5` | 1 獎金 | 18（§5 風險 1 的 `ticket_id=2413441` 中的就是這個獎項） |
| `47` | `46` | `531` | 2 | Bonus 8,888 | `6` | 2 獎品 | Perodua Axia 1.0 STYLE |
| `1100` | `970` | `2154` | 1 | Bonus 88,888 | `13` | 1 獎金 | 88,888 |
| `1100` | `970` | `2155` | 2 | Bonus 8,888 | `14` | 1 獎金 | 8,888 |
| `1100` | `970` | `2156` | 3 | Bonus 888 | `15` | 1 獎金 | 888 |
| `1100` | `970` | `2157` | 4 | Bonus 88 | `16` | 1 獎金 | 88 |
| `1100` | `970` | `2158` | 5 | Bonus 8 | `17` | 1 獎金 | 8 |

> `47` 這條線 `level=2` 是實體獎品（`type=2`，中獎會在 `receive()` 撞到 `error_message.1944`、走另一套領獎流程，見 §6.2 步驟 4）；`1100` 這條線五個等級全是獎金。

**歷史資料量／ID 範圍**（筆數多的表只給範圍，不逐筆列）：

| event_id | `event_details` id 範圍（筆數） | `lucky_draws` id 範圍（期數 no） | `lucky_draw_tickets` id 範圍（筆數） | `prize_members` id 範圍（筆數） |
|---|---|---|---|---|
| `47` | `183 ~ 6351`（825 筆） | `1 ~ 1401`（no 1~601，共 601 期） | `1 ~ 2413441`（135.8 萬筆） | `28874 ~ 57001680`（385 筆） |
| `1100` | `5469 ~ 6354`（231 筆） | `1382 ~ 1385`（no 1~4，共 4 期） | `2407982 ~ 2408109`（120 筆） | `56999026 ~ 56999052`（24 筆） |

> `lucky_draw_tickets` 的 id 是全站共用的單一 auto_increment（跨所有事件／活動共享同一張表），`47` 這條線的 id 範圍橫跨 `1 ~ 2413441` 但實際只佔 135.8 萬筆中的一部分，範圍本身不代表這條線獨佔的區間，只能當作「這條線目前活躍到哪個 id 附近」的參考；其餘表（`event_details`／`lucky_draws`／`prize_members`）則是用 `event_id`／`prize_id` 篩過的準確範圍與筆數。

> 這是查詢當下的快照，會隨後台配置變動而過期；需要最新狀態時重新查：
> - 有效活動：`events JOIN prizes ON prizes.event_id = events.id WHERE prizes.type_id = 5 AND prizes.status = 1 AND events.status = 1`
> - 當前期別：`event_details WHERE event_id = ? AND status = 1 AND start_time <= NOW() AND end_time >= NOW()`
> - 最新一期：`lucky_draws WHERE event_id = ? ORDER BY no DESC LIMIT 1`
> - 有效獎項：`prize_contents WHERE prize_id = ? AND status = 1` 與 `reward_items WHERE prize_content_id IN (...) AND status = 1`
> - id 範圍／筆數：對 `event_details`／`lucky_draws` 用 `WHERE event_id = ? SELECT MIN(id), MAX(id), COUNT(*)`；`lucky_draw_tickets` 先 `lucky_draws WHERE event_id = ? -> pluck(id)` 取得該線的 `lucky_draw_id` 集合再 `WHERE lucky_draw_id IN (...)`；`prize_members` 同理先 `prizes WHERE event_id = ? -> pluck(id)` 取得 `prize_id` 集合再 `WHERE prize_id IN (...)`

## 3. 如何發放抽獎券（舊系統，`SP_set_lucky_ticket.sql`）

### 3.0 觸發頻率與達標判定（RD-Studio-01，非 apis）

派券不是即時觸發，而是**排程批次**：`RD-Studio-01` 的 `summary_event` artisan command（`app/Console/Commands/SummaryEvent.php`），排程設定在 `RD-Studio-01/_release/app/Console/Kernel.php:686`：

```php
$schedule->command('summary_event')->cron('*/15 * * * *')->withoutOverlapping();
```

**每 15 分鐘跑一次**（`withoutOverlapping` 避免上一輪還沒跑完就疊加）。每次執行會撈出所有進行中的 `event_details`（`status=1` 且區間涵蓋現在），依 `action_id` 分派到對應統計來源表（`SummaryEvent::deposit/bet/win/firstDeposit/lose()`，各自讀不同的原始事實表）：

| `action_id` | 統計來源表 | 加總欄位 |
|---|---|---|
| 1（儲值） | `orders` | `SUM(point)`（`status=1`，依 `updated_at` 落在期間） |
| **2／8／10／14（投注額，V-Jackpot 走這條）** | **`vendor_hour_records`** | `SUM(bet)`（依 `date_hour` 落在期間，join `vendors`/`agent_vendors` 依代理線與遊戲設定過濾對壓／豪龍） |
| 3（勝分） | `vendor_hour_records` | 同上欄位、換用 `valid_win_rate` 過濾條件 |
| 4（首儲） | `orders` + `members.first_deposit` | `SUM(point)` |
| 9（輸分） | `vendor_hour_records` | `SUM(win_lose) * -1` |

V-Jackpot 兩條線（`action_id=2`）對應 `SummaryEvent::bet()`：

1. 撈 `vendor_hour_records` 在該 `event_detail.start_time ~ end_time` 區間內、該代理線（`agents.hierarchy_path` 前綴）底下的下注紀錄，依 vendor 設定排除對壓／豪龍等不計入流水的遊戲，加總每位會員的下注額 `SUM(bet)`。

   **排除設定在哪裡**：`event_details.game_join_event_used` 決定用哪種過濾方式（本文件兩條線的當期 `event_detail` 都是 `game_join_event_used = 0`，走以下黑名單邏輯）：
   - `game_join_event_used = 0`（黑名單，本文件兩條線走這個）：
     - **整個遊戲商排除**：`vendors.valid_bet_rate <= 0` 的 vendor 整條線不計入流水（程式註解寫「排除豪龍」，但這只是舉例——實際排除哪些 vendor 依當下資料而定；Dev 環境**目前** `valid_bet_rate = 0` 的是 `Pussy888`（id 23）與 `IGS`（id 26），共 2 家）。
     - **單一遊戲排除**（同一 vendor 底下特定遊戲，即「對壓遊戲」）：`vendor_info_games.valid_bet_rate = 0`（NULL 視為預設納入）。Dev 環境**目前**共 21 筆，依 `vendor_info_id` + `game_id` 逐一設定，沒有以遊戲商彙總的簡單規則。
   - `game_join_event_used = 1`（改成白名單）：只認 `vendor_info_games.join_bet_event = 1` 的遊戲，其餘全部不計。
   - 兩層設定都在後台管理，非 apis 程式碼決定；需要最新清單時重查 `vendors WHERE valid_bet_rate <= 0` 與 `vendor_info_games WHERE valid_bet_rate = 0`（或 `join_bet_event = 1`，視 `game_join_event_used` 而定）。
2. 與 `event_details.point`（達標門檻）比較，把進度寫進 `event_processes`（`point`＝累計流水、`status`：0 未達標／1 已達標／2 已派獎）。
3. 换算應發券數：`FLOOR(該會員在此活動的下注總額 / 門檻)` 為「應得次數」；若 `prizes.is_repeatedly = 0` 則封頂 1 次（不可重複），`= 1`（兩條線皆是）則可隨門檻倍數累加。扣掉「已經發過的票數」（`COUNT(lucky_draw_tickets WHERE member_id=? AND lucky_draw_id=?)`）得到 `remainder`（這次要補發的張數，單次上限 5000 張），才呼叫：

```sql
CALL SP_set_lucky_ticket(p_lucky_draw_id, p_member_id, p_ticket_number);
```

**目前達標門檻**（依 §2.1 快照的當期 `event_details`）：

| event_id | agent_id | event_detail_id | action_id | 條件 | 門檻（`point`） |
|---|---|---|---|---|---|
| `47` | 4249 | `6347` | 2（投注額） | 本週期內下注流水達到門檻的整數倍，每倍多發 1 張 | **300** |
| `1100` | 100007 | `6341` | 2（投注額） | 同上 | **10** |

> 門檻隨每週 `event_details` 續期可能調整，需要最新值時查當期 `event_detail.point`（見 §2.1 的重新查方法）。

### 3.1 `SP_set_lucky_ticket` 本身的行為

1. 若 `member_id = 0`（黑名單／不可派券的情況），強制 `ticket_number = 0`，等於本次不派券。
2. 檢查目前這期 (`lucky_draw_id`) 票池中還有幾張 `member_id = 0` 的空券；不夠本次要派發的張數時，從 `temp_lottery_tickets`（依 `event_id` + `weeks_of_use` 分流）批次抽出並插入 `lucky_draw_tickets`（`member_id = 0`, `ticket_number` 補上隨機尾碼 `-N`），一次補 5000 張，同時把用過的 `temp_lottery_tickets` 列重新洗牌（`sort = RAND()`、`status + 1`）供下次再抽。
3. 最後從池子裡（`member_id = 0`）撈 `p_ticket_number` 張既有票券，`UPDATE` 成 `member_id = p_member_id`，完成派發。**這一步沒有 `ORDER BY`**，實際指派到哪幾張票由 MySQL 執行計畫決定。

> 理論上，若步驟 2 的池子補票發生在**這一期已經開獎完畢之後**，新補進來的票即使 `ticket_number` 落在中獎號碼區間，也不會再被 §4 的開獎 SP 處理（該 SP 以 `lucky_draws.status = 0` 為前置條件，只執行一次），可能形成「票面看起來中獎、但沒有對應 `prize_members` 憑證」的資料不一致。**此為程式邏輯上的推論，尚未證實為會自然發生的情境**——見 §5 說明。

## 4. 如何開獎（舊系統，`SP_set_lottery_ticket_number.sql` → `SP_payout_lottery_ticket_reward.sql`）

1. 後台輸入本期開獎號碼，呼叫 `SP_set_lottery_ticket_number(p_lucky_draw_id, p_draw_number, ...)`：
   - 驗證格式（`123456-7`）、驗證該期 `status = 0` 且已過 `draw_time`。
   - 寫入 `lucky_draws.draw_number`，接著呼叫 `SP_payout_lottery_ticket_reward`。
2. `SP_payout_lottery_ticket_reward(p_lucky_draws_id, ...)`：
   - 依 `reward_items.level` 由小到大（愈精確的規則優先）逐一比對：`UPDATE lucky_draw_tickets SET reward_item_id, point, type = 1 WHERE lucky_draw_id = ? AND reward_item_id = 0 AND (ticket_number LIKE 前段規則 OR ticket_number LIKE 後段規則)`——已被較高優先權中獎規則吃過的票（`reward_item_id != 0`）不會再被較低等級規則覆蓋。
   - 每比對完一個 `reward_item`，把「剛剛被標記中這個獎項」的票，一併 `INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number = 票id, status = 0, ...)`——這一步是 `receive()` 真正認可的領獎憑證來源。
   - 累加 `prize_contents.sent`（已派發數量）。
   - 全部等級跑完後，若 `events.auto_payout = 1`，呼叫 `SP_payout_lottery_ticket_reward_auto` 自動派彩（直接改 `members.balance` / `member_wallets`，不經過 apis 的 `receive()`）；否則維持 `prize_members.status = 0`，等會員手動領獎。
   - 最後把 `lucky_draws.status` 設為 `1`（已派發）。**同一期只會、也只能跑一次**——重跑需要人工把 `status` 撥回 `0`，且 `prize_members` 的 `INSERT` 沒有排除重複派發的檢查（原始碼中該檢查被註解掉），重跑會對已經有憑證的票造成重複 `prize_members` 列。

## 5. 已知風險 / 資料一致性缺口（供後續調查與 PM 決策參考）

1. **§3 末段的「晚到票」推論，尚未證實為真實會發生的情境**：起因是排查 `member_id=49009`／`ticket_id=2413441`（Dev 環境，2026-08-06）這筆「已標記中獎但查無 `prize_members` 憑證」的資料時，一度推論為票池補票時序缺口所致；**經 RD 確認，該筆資料是人工在 Dev DB 直接建立的測試資料，並非透過 §3／§4 的正常發券／開獎流程產生**，因此不能當作這條時序缺口在生產環境會自然發生的證據，也沒有其他真實案例佐證。保留此推論僅供日後若再遇到類似資料時參考排查方向；`receive()` 對「查無 `prize_members`」與「併發下已被搶先領取」目前共用同一個 `error_message.2001`、訊息無法區分成因，這點是既有事實、與上述推論是否成立無關。
2. **同 `event_id` 若存在多筆 `status=1` 的 `prizes`**：`receive()`／`getIssueDetail()` 用 `first()` 撈「當前有效獎項」，多筆時撈到哪一筆不保證與開獎 SP 當初寫入 `prize_members.prize_id` 的那筆一致，同樣可能造成「查無 prize_member」的假象。

## 6. 如何領取抽獎券（apis，`LuckyDrawService`）

### 6.1 查詢端（純讀）

| 方法 | 對應路由 | 說明 |
|---|---|---|
| `getIssueList()` | `frontend/api/event/lucky-draw/issues` | 列出會員所屬代理線可見、且已到 `start_time` 的期數（全平台 `agent_id=NULL` 或自己代理線） |
| `getIssueDetail()` | `.../issues/detail` | 該期的中獎號碼、我的票券統計（總票數／總中獎金額／未領金額，過領獎期限則未領金額歸零） |
| `getIssueTickets()` | `.../issues/tickets` | 分頁列出會員這期所有票券，狀態：0 未開獎／1 已開獎已領／2 已開獎未領／3 已開獎沒中獎／4 已開獎已過期 |

### 6.2 領獎端（寫入，`DB::transaction` 包裹）

`receive(Member $member, int $ticketId)`：

1. `member.event_mode > 0`（活動鎖分模式）直接拒絕（`error_message.1934`）。
2. `lockForUpdate()` 鎖會員列。
3. `INNER JOIN reward_items` 撈票券——**未中獎票（`reward_item_id=0`）查無此券**，回 `error_message.1941`（沿用舊版行為，非「未中獎」訊息，見測試 `test_receive_throws_not_found_when_ticket_has_no_reward_item`）。
4. 依序檢查：`reward_item_id=0 && type=0` 未中獎（`1942`）／`status=1` 已領取（`1943`）／`reward_type=2` 獎品類（`1944`，走另一套實體獎品流程，不在本文件範圍）。
5. 撈 `Prize::where('event_id', ...)->where('status', 1)->first()`，查無回 `2001`（見 §5 風險 2）。
6. `UPDATE lucky_draw_tickets SET status=1 WHERE id=? AND status=0`——影響列數為 0（併發下已被搶先）回 `2001`。
7. 撈 `prize_members`（`prize_id` + `lottery_number=票id` + `status=0` + `member_id` 符合本人或 0）並 `lockForUpdate()`，查無回 `2001`（見 §5 風險 1，這是本文件記錄的實際故障案例）。
8. 標記 `prize_members.status=1` 並補回正確 `member_id`（因為極少數情況 `member_id` 會被存成 0）。
9. `creditReward()`：`members.balance += point`，寫 `transaction_logs`（`type=V_JACKPOT_REWARDS`），若代理有設定流水倍數則寫 `flow_limits`。

`receiveAll(Member $member, int $issueId)`：同一期所有 `status=0 && reward_item_id>0 && point>0` 的票券依序跑上述 6-9 步驟，單張失敗（找不到 `prize_members`）以 `continue` 跳過（不中斷整批），全部無可領取票券回 `error_message.1000`。

## 7. 相關檔案

- Controller：[`app/Http/Controllers/Frontend/EventLuckyDrawController.php`](../../app/Http/Controllers/Frontend/EventLuckyDrawController.php)
- Service：[`app/Services/LuckyDrawService.php`](../../app/Services/LuckyDrawService.php)
- Models：`app/Models/{LuckyDraw,LuckyDrawTicket,Prize,PrizeMember,RewardItem,RewardItemName}.php`
- 舊系統 SP：`database/procedures/{SP_set_lucky_ticket,SP_set_lottery_ticket_number,SP_payout_lottery_ticket_reward,SP_payout_lottery_ticket_reward_auto}.sql`
- 測試：[`tests/Unit/Services/LuckyDrawServiceTest.php`](../../tests/Unit/Services/LuckyDrawServiceTest.php)
- 遷移總表：[`api-frontend-migration-4005.md`](./api-frontend-migration-4005.md) 第 223-231 行
