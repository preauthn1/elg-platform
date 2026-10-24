# 多幣別錢包 — 受影響的 stored procedure 與資料表清單

> 盤點日期：2026-08-14
> 設計決策見 [`multi-currency-wallet-design.md`](multi-currency-wallet-design.md)，查證證據原在 `multi-currency-wallet-feasibility-review.md`（09-18 刪除，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-feasibility-review.md`）。
>
> 🔴 **本清單的範圍已於 08-27 擴大，拿它當改造範圍依據之前先讀** [`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md)：
>
> - **本文的「73 支」是對的**（08-28 逐支比對確認：73 ＝ `SP_transfer_bet_record_*` 72 支 ＋ `SP_transfer_event_record_pgs`，與實際檔案零差集）。⚠️ 簡報第 14 節曾把它「更正為 72」，那是 glob 只掃 `bet_record_*` 所致的誤判——`SP_transfer_event_record_pgs` 同樣 `INSERT INTO total_bet_records`、同樣具名 INSERT ＋ ODKU、同樣已 JOIN `vendor_infos`，**改法相同、不可漏掉**。
> - **writer 遠不止這些 SP**：本 repo 內另有 `TotalBetRecordService`、`EventTotalBetRecordService`、`SummaryBetRecordService`（＋Wesw／Motivationsw）、`BetRecordRecovery/Revert`、`Patch/BackfillMemberHourSummaries`、`Vendor/PullBetRecordCommand`、`Vendor/PlayTech/ReconcileBetAccumulation`、`Vendor/PragmaticPlay/FixRecords` 等 PHP 寫入路徑，**只改 SP 會留下一批把 `currency_id` 寫成 NULL／DEFAULT 的路徑**。完整清點是 第零期的交付，落檔於 `database/data/bet_records/writers.php`。✅ **2026-08-31 已完成並定版：308 個寫入點**（定版當時 307；轉檔程序 73／本專案 27／四個舊 repo 208，09-18 依 registry 實數更正），全部指定 owner 與並行防護；契約文件 [`docs/multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md) 第四節即為定版內容。⚠️ **09-03 補充**：307 裡屬 `member_wallets` 的 126 筆（舊口徑，分 repo 明細見 7.1「舊數字」欄）已被第七節的重盤取代，`writers.php` 該區塊與契約第四節分佈表**待第五期重登記**；注單類 181 筆不受影響。
>
> - **09-03 補盤兩件事**：(1) 六站生產 SP 實查——寫入錢包的 SP 每站 16～19 支、生產集合比 repo 多出備份與 ADMIN 副本、INSERT 明列欄位故加欄後會**靜默寫幣別 0**（第一節末「09-03 六站生產實查」）；(2) 四個舊 repo 的 `member_wallets` PHP 寫入點重盤 **222 個（單分支下界、非聯集）**，舊「126 個」作廢（新增第七節；**7.1 的表是這組數字的單一來源**，決議⑰）。
>
> **stored procedure（以下簡稱 SP）**：直接寫在資料庫裡的程式。它繞過 PHP，所以 PHP 端改了它不會跟著改——這是本案最容易漏掉的一層。

---

## 判讀方式說明

功能欄依「檔名 ＋ 實際寫入的資料表 ＋ 呼叫來源」三者交叉判讀。**呼叫來源特別重要**：APIs 專案內零引用**不代表廢棄**，實測有三種其他來源：

| 來源 | 說明 |
|---|---|
| **MySQL 排程** | 資料庫自己的排程器。TPower 生產庫共 48 個排程、**47 個啟用中**，直接 `CALL` 這些 SP |
| **舊系統** | API_Agent 與 RD-Studio-01 也會呼叫，客服後台的 `_for_cs` 系列即屬此類 |
| **其他 SP** | SP 之間互相呼叫，例如 `SP_check_member_vip_keep` 會呼叫 `SP_payout_vip_level_rewards` |

專案內共 **213 支 SP**，本次受影響 **114 支**。

---

## 一、寫入錢包的 SP（repo 15 支；六站生產每站 16～19 支）

這類直接改會員餘額。多幣別化後如果不改，它們會寫到「幣別欄位＝預設值」那一列——**不會報錯，只會靜默寫錯錢包**。

| SP | 原本功能 | 目前寫入 | 呼叫來源 | 需要改動的原因 |
|---|---|---|---|---|
| `SP_event_deposit` | 存款觸發的活動獎金派發 | 中央錢包<br>會員錢包<br>活動錢包 | APIs ＋ 舊系統 | 三種錢包都要帶幣別；獎金必須派到與存款訂單同幣別的錢包 |
| `SP_event_deposit_for_cs` | 同上，客服後台版本 | 中央錢包<br>會員錢包 | 待確認（見備註） | 同上 |
| `SP_event_deposit_wallet_for_cs` | 同上，客服後台錢包版本 | 中央錢包<br>會員錢包 | 舊系統 | 同上 |
| `SP_event_first_deposit` | 首存活動獎金派發 | 中央錢包 | 待確認（見備註） | 獎金要派到對應幣別的錢包 |
| `SP_event_register` | 註冊活動獎金派發 | 中央錢包<br>會員錢包 | APIs ＋ 其他 SP | 新會員的預設幣別決定獎金落在哪個錢包 |
| `SP_event_register_tmp` | 註冊活動（疑似舊版備份） | 中央錢包<br>會員錢包 | 無任何引用 | **先確認是否可刪**，能刪就不必改 |
| `SP_event_gift_point` | 贈點活動派發 | 中央錢包<br>會員錢包 | APIs ＋ 舊系統 | 同上 |
| `SP_payout_event_invite` | 邀請好友活動派彩 | 中央錢包<br>會員錢包 | APIs ＋ 舊系統 | 邀請人與被邀請人可能使用不同幣別，需各自認錢包 |
| `SP_payout_vip_level_rewards` | VIP 等級獎勵派彩 | 中央錢包<br>會員錢包 | `SP_check_member_vip_keep` | 獎勵金額依幣別而異，且要派進對應錢包 |
| `SP_payout_three_hierarchy_bonus` | 三層代理獎金派彩 | 中央錢包<br>會員錢包 | **MySQL 排程** | 獎金來源是跨幣別彙總的統計（見第三節），源頭錯這裡就跟著錯 |
| `SP_payout_slot_competition_auto` | 拉霸競賽派彩 | 中央錢包<br>會員錢包 | `SP_payout_slot_competition`<br>（其上層由排程呼叫） | 排名依投注額計算，跨幣別相加會讓排名失真 |
| `SP_payout_lottery_ticket_reward_auto` | 彩票獎勵派彩 | 中央錢包<br>會員錢包 | `SP_payout_lottery_ticket_reward`<br>`SP_payout_lottery_ticket_6d_reward` | 獎勵要派到對應幣別錢包 |
| `SP_payout_refresh_ranking_sub` | 刷新排行榜獎勵派彩 | 中央錢包<br>會員錢包 | `SP_payout_refresh_ranking` | 同上 |
| `SP_reset_refresh_ranking_wallet` | 每天重置刷新排行榜錢包 | 會員錢包 | **MySQL 排程** | 排行榜錢包屬點數型，依決策 17 幣別欄位固定填 0，重置範圍要跟著調整 |
| `SP_calc_member_vip_deposit_amount` | VIP 存款累計計算 ＋ 晉級獎金 | 中央錢包<br>會員錢包 | APIs ＋ `SP_calc_vip_flow_limit` | 存款累計必須分幣別統計，否則跨幣別相加會讓 VIP 晉級門檻失真 |

**備註（待確認）**：`SP_event_deposit_for_cs` 與 `SP_event_first_deposit` 在 APIs、舊系統、其他 SP、MySQL 排程四處都查不到呼叫來源。但同系列的 `SP_event_deposit_wallet_for_cs` 確實由舊系統呼叫，所以不能直接判定廢棄——**動手前需向熟悉客服後台的人確認**。

### 09-03 六站生產實查

查法：六站唯讀 MCP 對 `information_schema.ROUTINES` 的 `ROUTINE_DEFINITION` 以 regexp 比對 `INSERT INTO member_wallets`／`UPDATE member_wallets`。

| 站台 | 含 `member_wallets` 的 SP | 其中 INSERT | 其中 UPDATE |
|---|---|---|---|
| TPower | 19 | 17 | 18 |
| VWorld | 19 | 17 | 18 |
| Me77 | 18 | 16 | 17 |
| Alfawin77 | 17 | 16 | 16 |
| Yes2Win | 16 | 15 | 15 |
| VPlus33 | 16 | 15 | 15 |

- **VWorld 19 支逐名核對**：上表 15 支中 14 支在列（`SP_event_first_deposit` 只寫中央錢包、不碰 `member_wallets`，與上表一致），另多出 repo `database/procedures/` 沒有的 5 支：`SP_event_deposit_bak`、`SP_event_deposit_neil`（兩支備份副本，皆 INSERT＋UPDATE）、`ADMIN_reissue_vip_level_reward`（INSERT＋UPDATE）、`Admin_clear_member_wallet_rolling_chip`（只 UPDATE）、`ADMIN_Member_Blance_Check`（只讀）。🔴 **生產 SP 集合比 repo 大，第四期直寫 SP 改造清單不能只看 repo**；其他五站只查了計數、未逐名核對（決議檔第八節）。
- ⚠️ 上表計數是「`ROUTINE_DEFINITION` 含 `member_wallets` 字串」的支數，含只讀的 `ADMIN_Member_Blance_Check`；真正直寫的是 INSERT 15～17／UPDATE 15～18 那兩欄。
- 🔴 **INSERT 明列欄位** `(member_id, wallet_type_id, balance, status, admin)`——抽查 `SP_event_deposit`／`SP_payout_three_hierarchy_bonus`／`SP_payout_vip_level_rewards`／`SP_reset_refresh_ranking_wallet` 四支原文皆如此，其餘未逐支核對。`member_wallets.currency_id` 加上之後，這些 SP **照跑、照建、不報錯，只是幣別是 0**——這正是本節「靜默寫錯錢包」的具體機制（依據：`database/migrations/2026_09_01_160000_add_currency_id_to_member_wallets_table.php:46` 為 `INT UNSIGNED NOT NULL DEFAULT 0`），也是 Task 10 改唯一鍵前「`currency_id = 0` 歸零」稽核要抓的對象——「活躍列」未定義（`status=1`？`balance>0`？），零餘額殘列改鍵後仍會被二元組 `->first()` 撈到並遮蔽正確幣別列，判準應為**全表歸零或明訂殘列處置**。
- ⚠️ **五支 VWorld 副本在四個舊 repo（含全部遠端分支）與 APIs 都查不到任何呼叫端**（圓桌實證，marz）；名稱形狀（`_bak`／`_neil`／`ADMIN_`）屬 DBA 手動一次性程序，客服後台走的是 `_for_cs` 系列。Task 10 閘門判準應為「六站主庫 `information_schema.EVENTS`＋SP 互相 `CALL` 交叉比對後仍有呼叫者的那些全數改完」，無呼叫者的副本改列「改鍵後禁止手動執行」的運維注意事項（`SP_event_deposit_bak` 是 INSERT 明列欄位，手動跑一次就是一筆幣別 0）。
- RD-Studio-01 盤點懷疑的 9 支（`SP_summary_rolling_chip_record` 等，見第七節 7.4）於 VWorld 實查皆不含 `member_wallets`。
- 排程狀態無法從唯讀 MCP 判斷：五站 MCP 連的是從庫，`information_schema.EVENTS` 一律 `SLAVESIDE_DISABLED`、`LAST_EXECUTED` 為 NULL，不能拿來判定排程有沒有在跑（決議⑮的 TPower 每日重置排程同此限制）。

---

## 二、注單轉檔 SP（73 支）

**原本功能**：把各遊戲商的原始注單轉進總注單表。一個遊戲商一支，命名為 `SP_transfer_bet_record_{遊戲商}`，另有一支 `SP_transfer_event_record_pgs`（活動專用）。

**需要改動的原因**：這是 BR → TBR 的**實際執行者**。設計要在總注單加上「商戶帳號」與「幣別」兩個欄位，而寫入這兩個欄位的動作就發生在這 73 支裡——**PHP 端的 TotalBetRecordService 改了不會生效**。

**改動內容**：`INSERT INTO total_bet_records` 的欄位清單與 `SELECT` 來源各加兩欄。多數 SP 本來就有 join 遊戲帳號表（可取得商戶帳號），少數沒有的要補 join。

<details>
<summary>73 支完整清單</summary>

`4D`、`ace333`、`aes`、`ag`、`baison`、`bng`、`bola2`、`bolakk4d`、`boracayfish`、`c93`、`ct`、`dct`、`dg`、`ds88`、`ebet`、`esport`、`evo`、`evo2`、`fishing`、`gfg2`、`gw99`、`ia`、`igs`、`isb`、`j3`、`joker`、`ky`、`l4`、`lbb`、`lfc888`、`lucky`、`m8bet`、`mega888`、`menang88`、`mgc`、`mt`、`n918`、`new_ia`、`npt`、`ns`、`obet33`、`pg`、`target`、`pgslot`、`play8oy`、`plus100`、`pp`、`pp_event`、`pt`、`pussy888`、`qtech`、`rm`、`sa`、`saba`、`sbo`、`scr`、`skybet`、`sky1388`、`sl`、`sma`、`spadegaming`、`v8`、`vplay`、`vpower`、`vpower_new`、`vspoker`、`vsport`、`wbet`、`wm`、`xe88`、`ybet`、`ygg`，另加 `SP_transfer_event_record_pgs`

</details>

---

## 三、彙總寫入 SP（8 支）

**原本功能**：把每日統計再彙整成各種代理層級的報表。多數由 MySQL 排程每天執行。

**需要改動的原因**：彙總鍵要加入幣別。⚠️ 其中三張目標資料表**已有唯一鍵且不含幣別**，加了幣別之後同一組鍵會變成兩筆，會**直接撞唯一鍵導致寫入失敗**——不是數字算錯而已。

| SP | 原本功能 | 呼叫來源 | 備註 |
|---|---|---|---|
| `SP_summary_bet_record_by_agent` | 每天彙整每日統計到代理層 | MySQL 排程 | 目標表有唯一鍵，需一併調整 |
| `SP_summary_bet_record_team` | 團隊業績彙總（分潤依據） | 待確認 | 跨會員相加，下線幣別不同即混算 |
| `SP_summary_bet_record_reward_by_agent` | 彙整 SR19 Reward | MySQL 排程 | 依 COMMENT |
| `SP_summary_bet_record_fee_by_agent` | 彙整 SR19 簡訊費 | MySQL 排程 | 依 COMMENT |
| `SP_summary_bet_record_vreward_by_agent` | 彙整 SR19 V-Reward | MySQL 排程 | 依 COMMENT |
| `SP_summary_bet_record_three_hierarchy_by_agent` | 三層代理彙總 | MySQL 排程 | COMMENT 標示為 V-Reward，疑似複製後未更新，**需確認實際用途** |
| `SP_summary_rolling_chip_record` | 泥碼彙總 | 待確認 | 泥碼是真實資金，兩站合計 44 萬餘額 |
| `SP_summary_rolling_chip_record_by_agent` | 泥碼彙總到代理層 | 待確認 | 同上 |

---

## 四、讀取統計做計算的 SP（18 支）

**原本功能**：讀每日統計，據以計算獎金、分潤、報表。

**需要改動的原因**：這些查詢目前沒有幣別維度，加總時會把不同幣別的金額直接相加。其中多支的計算結果會**直接派錢給會員或代理**，算錯就是資金損失。

| 影響等級 | SP | 原本功能 |
|---|---|---|
| 🔴 直接派錢 | `SP_calc_three_hierarchy_bonus`<br>`SP_calc_three_hierarchy_bonus_by_win_lose`<br>`SP_calc_unlimit_bonus` | 三層／無限層級代理獎金計算，全部由 MySQL 排程每天執行 |
| 🔴 直接派錢 | `SP_payout_vip_other_reward`<br>`SP_payout_weekly_rescue` | VIP 其他獎勵、週救援金派發 |
| 🟠 分潤金額 | `SP_calc_profit_share_winlose`<br>`SP_calc_shareholder_winlose`<br>`SP_calc_shareholder_winlose2` | 股東分潤輸贏計算 |
| 🟠 分潤金額 | `SP_calc_point_barrels`<br>`SP_calc_point_barrel_winlose` | 分桶與佔成計算 |
| 🟠 業績判定 | `SP_calc_agent_achievement` | 代理業績達標計算 |
| 🟡 報表顯示 | `SP_simple_settlement_report_pnl`<br>`SP_simple_settlement_report_team_commission` | 結算報表：損益、團隊佣金 |
| 🟡 報表顯示 | `NOTIFY_Summary_report` | 彙總報表通知 |
| 🟡 對帳 | `sr11` | 對帳報表（SR11） |
| — | `SP_summary_bet_record_team`<br>`SP_summary_bet_record_by_agent`<br>`SP_summary_rolling_chip_record` | 同時屬第三節，既讀又寫 |

---

## 五、資料表清單

### 必須加欄位

| 資料表 | 現況 | 要加什麼 | 為什麼 | 資料量 |
|---|---|---|---|---|
| `member_wallets` | 唯一鍵為「會員＋錢包類型」 | `currency_id`<br>**唯一鍵改三元組** | 錢包要能分幣別。⚠️ 線上索引實名與 migration 原始碼不同，DDL 須執行期偵測 | TPower 50.8 萬<br>VWorld 34.2 萬 |
| `members` | 無幣別欄位 | `active_currency_id` | 記錄會員當前選用的幣別 | — |
| `vendor_infos` | 有 `currency` 字串欄（varchar(5)） | `currency_id` | 正規化為編號，供查詢與繼承使用。✅ 09-07 版控已加欄（`2026_09_07_000002`，索引 `vendor_infos_currency_id_index`），生產待部署 | 小（六站 70～614 列） |
| `event_vendor_infos` | 有 `currency` 字串欄（varchar(191)） | `currency_id` | 活動用單一錢包的商戶主檔，與 `vendor_infos` 技術對齊（決議⑲）。✅ 09-07 版控已加欄（`2026_09_07_000003`），生產待部署；回填／guard／稽核見第二期 Task 13 | 小（六站 16 列，清一色 MYR） |
| `agent_vendors` | 僅三支單欄索引 | `currency_id`<br>＋複合索引 | 代理綁定遊戲商要指定幣別 | 小 |
| `wallet_transaction_logs` | 有 `rate` 但語意是點數比例 | `currency_id`<br>＋**新的**匯率快照欄 | 多幣別後「會員＋錢包類型」不再唯一決定錢包。既有 `rate` 精度僅 4 位，不可挪用當匯率 | 大 |
| `transaction_logs` | 無幣別欄位 | `currency_id` | **單錢包代理的帳本**，與上表二選一使用，只改一邊會斷 | 大 |
| `member_transfer_logs` | 無幣別欄位 | `currency_id` | 會員互轉要記幣別，否則事後無法對帳 | 中 |
| `promotion_wallets`<br>`promotion_wallet_logs` | 獨立於 member_wallets | `currency_id` | 活動錢包自成一套，不受錢包表決策涵蓋 | 中 |
| `withdrawal_orders` | **無幣別欄位**（但有 9 位精度的 `rate`） | `currency_id` | 提款單不記幣別則出款對帳斷，且與存款不對稱 | 大 |
| ~~各遊戲商注單表~~ | 26 張有商戶帳號欄<br>12 張有三方幣別字串 | ~~`vendor_info_id`<br>`currency_id`<br>＋索引~~ **不改（09-03 決議⑭）** | ~~注單源頭，幣別自此注入~~ 幣別與商戶改在總注單建立當下由遊戲帳號所屬商戶決定 | ~~**126 張<br>約 1.95 億筆**~~ 0 |
| `total_bet_records` | 只有會員與遊戲商編號 | `vendor_info_id`<br>`currency_id` | 幣別在這層遺失，下游全部跟著錯 | 大 |
| `summary_bet_records` | DB 層無唯一鍵 | `currency_id`<br>＋納入彙總鍵 | 對帳與分潤主要來源 | 中 |
| `summary_bet_records_team` | 跨會員相加 | `currency_id`<br>＋納入彙總鍵 | 團隊業績會把下線不同幣別相加 | 中 |
| `rolling_chip_logs`<br>`summary_rolling_chip_records`<br>`summary_rolling_chip_record_by_agents` | 皆無幣別 | `currency_id` | 泥碼是真實資金（兩站合計 44 萬餘額） | 中 |

### 必須加欄位且要改唯一鍵（風險最高）

這三張表**已有唯一鍵且不含幣別**，加幣別後同鍵會變兩筆，直接撞鍵寫入失敗。而且 **APIs 專案內完全沒有引用它們**，寫入者在 RD-Studio-01——改的時候最容易漏掉。

| 資料表 | 現有唯一鍵 |
|---|---|
| `summary_bet_record_agents` | 時間＋代理＋遊戲商＋遊戲類型 |
| `summary_bet_record_agent_teams` | 時間＋資料時間＋代理＋遊戲商＋遊戲 |
| `summary_bet_record_by_days` | 時間＋會員＋遊戲商＋遊戲 |

✅ **已查明：這三張都不是分區表**，加幣別進唯一鍵沒有分區限制。

但另有兩張是分區表，加欄位時要注意：

| 資料表 | 分區方式 | 分區數 | 影響 |
|---|---|---|---|
| `total_bet_records` | LIST，依遊戲商編號 | 兩站都 **298 個** | 現有唯一鍵已含分區欄位，加幣別不受限；但**加欄位能否用秒級模式需在測試庫實測** |
| `summary_bet_records` | RANGE，依年份 | TPower 12／VWorld 7 | 目前 DB 層無唯一鍵，幣別只進程式的彙總鍵，不受分區限制；日後若要補唯一鍵，必須包含 `summary_time` |

### 需要新增的表

| 資料表 | 用途 |
|---|---|
| 代理組別幣別表（`agent_group_currencies`，09-09 定名） | 定義一群代理允許使用哪些幣別、其中一列標記為預設，是所有 `currency_id` 欄位的合法值來源。掛在 `agent_groups` 的 `agent_group_type = 8`，由 `agents.currency_group_id` 指過去（決議㉕；原暫名 `organization_currencies`、掛組織層，09-09 改） |

### 已有幣別、不需異動

`orders`、`order_histories`、`order_types`、`agent_cash_flows`、`banks`、`cash_flow_banks`、`standard_banks`、`organizations`。

⚠️ 但其中 `agent_cash_flows.currency_id` 與 `banks.currency_id` 允許空值，`order_types` 與 `organizations` 預設為 0。**加幣別過濾條件前必須先盤點並補齊**，否則這些資料會被靜默過濾掉，造成某白標的通道整批消失。

### 待業務裁決

| 資料表 | 說明 |
|---|---|
| ~~`event_vendor_infos`~~ | ✅ 09-07 決議⑲納入第二期（只做技術對齊），主檔已加欄，本列移到上方「必須加欄位」表。仍待業務裁決的只剩「活動注單要不要分幣別」（見決議⑲）。原記錄：活動用單一錢包的帳號表以 `event_vendor_info_id` 指向它、注單寫 `event_total_bet_records`，與 `vendor_infos` 鏈路完全分開 |

---

## 六、數量總表

| 類別 | 數量 | 風險 |
|---|---|---|
| 寫入錢包的 SP | 15 支 | 靜默寫錯錢包 |
| 注單轉檔 SP | 73 支 | 幣別無法傳到下游 |
| 彙總寫入 SP | 8 支 | 撞唯一鍵寫入失敗 ／ 混幣相加 |
| 讀統計做計算的 SP | 18 支 | 獎金與分潤算錯（其中 5 支直接派錢） |
| **SP 合計** | **114 支**（專案共 213 支；🔴 生產另有 repo 沒有的副本，VWorld 多 5 支，見第一節 09-03 補充） | |
| 六站生產直寫錢包的 SP | 每站 16～19 支（INSERT 15～17） | 加欄後靜默寫幣別 0 |
| 四個舊 repo 的 `member_wallets` PHP 寫入點 | **222 個（單分支下界、非聯集，09-03 口徑）**——分 repo 明細見 7.1（單一來源，本表不重述） | 建立點拿不到幣別、讀取面撈錯列（第七節） |
| 必改資料表 | ~~20 類（含 126 張注單表）~~ 19 類（09-03 決議⑭移除 126 張注單表；本檔逐張計，泥碼三張各算一類——簡報把它們算一類故為 17，表的集合相同） | |
| 新增資料表 | 1 張 | |
| 會員幣別解析鏈斷點（六站基準值） | 見第八節 | 解析不出者影子同步只能寫 sentinel 0；啟用中沒有「活人且找不到歸屬」 |

---

## 七、四個舊 repo 的 `member_wallets` PHP 寫入點（09-03 盤點）

> 盤點方式：四個唯讀 agent 各盤一個 repo（`rg` 全量掃 `MemberWallet`／`member_wallets`／`DB::table` 動態表名，逐行 `sed` 取原文驗證，含被 `.gitignore` 排除的 `vendor/`），主 session 另以六站生產庫核對幣別來源與 stored procedure。**計數定義**：一行實際對 `member_wallets` 發出 INSERT／UPDATE 的程式碼算一個寫入點；`lockForUpdate()->find()`、`->first()` 只加鎖或讀取的不算。全程未改任何檔案。

**本節名詞**（四個逐筆清單共用）：

| 名詞 | 意思 |
|---|---|
| 寫入點 | 一行實際對 `member_wallets` 發出 INSERT／UPDATE 的程式碼；`lockForUpdate()->find()`、`->first()` 只加鎖或讀取的不算 |
| 建立點 | 寫入點中會新增列的那些（`create`／`firstOrCreate`／`insertIgnore`／`factory()->create()`） |
| 泥碼錢包（1002） | 代碼常數 `ROLLING_CHIP`；舊系統與後台介面稱「禮碼」，逐筆清單的場景欄照舊系統用語寫「扣禮碼／退禮碼」 |
| 遊戲錢包（2）／排行榜錢包（1003） | `CODE_GAME`（已廢除、存量待清）／`CODE_EVENT_RANKING`（決議⑮廢除中） |
| 多型 | 同一行程式依代理設定 6008（`isAgentMultipleWallet()`）決定寫 `MemberWallet(2)` 還是 `members.balance` |
| 三態多型 | `MemberWallet(1002)`／`members`／`promotion_wallets` 共用同一個 helper |
| 兩跳 | 幣別解析路徑 `members.parent` → `agents.organization_id` → `organizations.currency_id`；「一跳」＝手上已有 Agent 實例 |
| 單分支口徑 | API_External／API_Frontend 一白標一分支，數字只代表被掃的那支分支，不是全部白標的上界 |

### 7.1 總數：222 個（單分支下界），舊「126」作廢

> 🔒 **單一來源（RB 2026-09-04 拍板，決議⑰）**：下表是本案「舊 repo `member_wallets` 寫入點」數字的唯一權威。其他位置（設計文件、決議清單、計劃、第零期契約、`writers.php` 註解）只引用總數＋口徑日期並指回本表，**不重述分 repo 明細**（RB 09-06 擴大射程：舊 126 口徑的分 repo 明細與下方各分支命中數同樣只在本節出現；讀取面明細暫不比照）；重盤時只改本表並更新口徑日期，其他處數字若與本表不符一律以本表為準。
> **目前口徑：2026-09-03**（程式快照時點見本節末段「程式快照時點」）。

| repo | 掃描分支 | 寫入點 | 其中建立錢包 | 舊數字 | 差異說明 |
|---|---|---|---|---|---|
| API_External | `tpower-master` | **156** | 2 | 83 | 83 無法用任何分支重現；`master` 只有 29 且 2024-09-23 起停更，不是任何白標的線上版本 |
| API_Frontend | `vw-master` | **41** | 19 | 36 | 36 的口徑無法重建；報告推測舊數漏掉的是「寫入動詞離符號十幾行」的路徑，例如整支 `CheckinService.php` 一字不提 MemberWallet、只透過 Repository 取 Model 再 `save()` |
| RD-Studio-01 | 當前 checkout | **18** | 6 | 從未盤 | 17／18 走 Model 類名，只搜表名會漏 |
| API_Agent | 當前 checkout（14 支部署分支命中數皆相同） | **7**（＋`target/support` 2 ＝ 9） | 3（＋1） | 7 | 版控內數字相同，但漏了 composer 套件 `target/support` 的入款建錢包路徑 |
| **合計** | | **222**（含 `target/support` 224；單分支下界） | **30**（＋1；單分支下界，見下） | 126 | |

🔴 **222 不是聯集。** API_External 與 API_Frontend 都是「一個白標一條分支」，各分支程式量差距很大（`git grep` 逐分支；⚠️ 兩 repo 用的度量不同，數字只能在同一列內比、不能跨列比）：

| repo | 各分支命中數（度量） |
|---|---|
| API_External（錢包寫入動詞命中數） | `tpower-master`／`tpower-new`／`yes2win`／`alfawin77-new`／`vplus33`／`fon666` 159；`vw-master`／`vwb2c` 59；`wintoto` 44；`master` 29（死分支） |
| API_Frontend（`member_wallets`／`MemberWallet` 符號命中數） | `vw-master`／`vwb2c` 96；`tpower-master`／`yes2win`／`vplus33`／`alfawin77-new`／`fon666`／`tpower-new` 74；`origin/master` 69；`wintoto` 66 |
| API_Agent（三種寫入 pattern 命中數） | 14 支部署分支皆 8，無站台差異 |

🔴 **單分支口徑不是上界（圓桌實證，marz）**。`vw-master` 有 `tpower-master` 完全沒有的檔案：`SpadeGamingSwController.php:384`／`:389`／`:553`（三個 1002 update）、`Services/SingleWallet/BalanceService.php:185`／`:242`／`:281`（PPSW 七個呼叫端走的漏斗）；兩支都有的檔案 `vw-master` 也更多（`PgsController` 7 vs 4、`EpicWinController` 5 vs 3、`AstarController` 5 vs 4），其中三個是 `tpower-master` 沒有的**建立點**：`PgsController.php:2495`（`createGameWallet()`）、`EpicWinController.php:1880`、`AstarController.php:2283`。跨 10 支存活分支取聯集，API_External 的建立點至少 **6 個**而非 2 個；7.1 合計的「30 個建立點」與 156／222 一律是**單分支下界**。API_Frontend 同理：`vw-master` 建立點 19、`tpower-master` 15、聯集 20（tpower 系獨有 `RefreshRankingController.php:327` 建立 1003 錢包）；`vw-master` 獨有的是 `ThirdPartyController`（3）、`RollingChipRepository`（2）、`MobileBindService`（2）——`CouponController` 兩支分支行號相同、不是獨有。**第五期若要覆蓋全部白標，必須逐分支再盤，且要先拍板盤哪些分支**（決議檔第七節）。

⚠️ **分支與六站的對照未建立**：兩個 repo 都沒有 `me77` 分支（只有 `feature/tp/3114_me77_*` 這類 feature 分支，前綴暗示屬 TPower 分支族），`fon666`／`wintoto`／`vwb2c` 又不在六站之列。Me77 生產有 18 支寫錢包的 SP（第一節），這站確實在跑；照 7.1 的分支清單發版，Me77 可能整站漏改，也可能隨 `tpower-master` 覆蓋——文件目前判不出。逐站驗證前要先補分支↔站台對照表（決議檔第八節）。

⚠️ **程式快照時點**：本機 API_External 最後 fetch 2026-07-03（`vw-master` 落後 origin 42 個 commit，marz 比對過不含 `MemberWallet` 新增行）、API_Frontend 2026-06-08、API_Agent 2026-09-03。「09-03」是盤點日不是快照日，重跑前先 fetch。

### 7.2 直接影響第五期設計的結論

1. **幣別來源的鏈路形狀確立，但每一跳都可能靜默退化**。`members` 表沒有幣別欄、沒有組織欄，只有 `parent`（09-03 生產庫 `SHOW COLUMNS` 證實；`agents` 表也沒有幣別欄，唯一來源是 `organizations.currency_id`）。路徑是 `members.parent` → `agents.organization_id` → `organizations.currency_id`，舊系統本來就這樣寫（API_Frontend `MemberController.php:1341-1343`）。但四種情境會讓它拿到錯的值而不報錯：
   - 第一跳斷掉：TPower 60.9% 會員（約 60 萬筆）的 `members.parent` 指向不存在的代理（第二期計劃已記，marz 六站實測）。
   - 第二跳給預設值：`agents.organization_id` 的 migration 是 `default(1)`（API_Frontend `2020_10_08_064829_alter_agents_20201008.php:17`），沒設過的代理會「成功」解析成組織 1。
   - 第三跳是 0：VWorld 6 個組織 `currency_id=0` 且都有啟用中代理（決議檔第七節）。
   - 搬移不同步：代理層級搬移只更新 `parent`／`hierarchy`／`hierarchy_path`，不動 `organization_id`（API_Agent `AgentMigrateController.php:337-339`），搬過的代理名下會員會拿到搬移前的組織幣別。
   
   而 `member_wallets.currency_id` 的 DDL 是 `INT UNSIGNED NOT NULL DEFAULT 0`（`database/migrations/2026_09_01_160000_add_currency_id_to_member_wallets_table.php:46`），**「解析到 0」與「根本沒帶值」寫出來一模一樣**，稽核分不出來。第五期每個建立點的驗收條件必須含「解析不到或解析到 0 時中止並告警，禁止靜默寫 0」。會員 token 端點可用 middleware 塞進 request 的 `ORGANIZATION_ID`（API_Frontend `Authenticate.php:96`、`TgBotAuthenticate.php:108`）省一跳；Repository 層可能被 Console 情境呼叫，不建議依賴 request。⛔ **入款路徑不能拿 `orders.currency_id` 當來源**：API_Frontend 建單時硬寫 `'1'`（`OrderController.php:366`、`DepositController.php:138`／`:174`、`BankController.php:203`），拿它建錢包等於全部幣別 1。
2. **建立錢包的入口必須改簽章，且金額型別要一起改**。API_Frontend 三個 Repository 入口的實際簽章是 `createMemberGameWallet(int $memberId, int $balance)`、`createMemberRollingChipWallet(int $memberId, int $balance)`、`getOrNewMemberWallet(int $memberId, int $walletTypeId)`（`WalletRepository.php:39`／`:47`／`:71`），TG Bot helper 只收 `$member_id`（`TgBotController.php:1154`）——函式內都拿不到幣別。其中 `int $balance` 是**金額**：呼叫端 `CheckinService.php:196`／`:257` 傳的獎勵金額同函式另一分支以 `Common::num_add(…, 4)` 計算，PHP 7.3 強制型別下小數會被靜默截斷（首次打卡、綁機獎勵走 create 拿整數，之後走 update 拿四位小數；曝險金額需實測獎勵設定有無小數）。改簽章時幣別與金額型別一起改，走 `Common::num_*`。API_External 的通用建立漏斗 `WalletRepository.php:129` 在**正式流程**呼叫 `factory(MemberWallet::class)`，欄位預設值來自 `database/factories/MemberWalletFactory.php`（`balance=0／first_deposit=null／status=1／admin=0`）——這支 factory 是加欄位時的第二個必改檔，不是測試議題。呼叫端 `app/` 內 16 行（`createMemberGameWallet` 11、含 repo 內部 2；`getOrCreateMemberGameWallet` 5），**兩個入口都要改**，不是「改一個簽章」。API_Agent 四處四種 payload 形狀、RD-Studio-01 六處一致（建議先收成一個 helper 再改）。`MemberWallet` 用 `$guarded`，加欄位後忘了帶值會**靜默寫入預設值不報錯**——第五期最該補的測試。API_Frontend `UnlimitController.php:626`／`ThreeHierarchyController.php:616` 手上的 `$member`／`$agent` 被 `select()` 投影裁過，`organization_id` 不在欄位裡，直覺寫 `$agent->organization_id` 會拿到 null 不報錯。
3. **`INSERT IGNORE` 與 `firstOrCreate` 的冪等保護會隨唯一鍵加寬而反轉**。
   - API_Frontend `WalletRepository.php:71-93` `getOrNewMemberWallet()`：read → `SQLHelper::insertIgnore`（`:87`，註解「Prevent unique constraint error」）→ 用同一組條件 read 回來。它防的正是即將被拿掉的兩元組約束：改三元組後 `currency_id=0` 與 `currency_id=N` 不再衝突，`INSERT IGNORE` 從「撞鍵→忽略」變成「成功插入重複列」（任務系統每次領獎都走這條，高頻）。三種踩法：查詢改三元組、insert 沒帶幣別 ⇒ 寫進 `(m,t,0)`、回讀 `(m,t,X)` 查不到回 null，簽章宣告 `: MemberWallet` ⇒ TypeError；insert 帶了、查詢沒改 ⇒ 永遠撈到 legacy 0 列；兩邊都沒改 ⇒ 靜默沿用 0。`INSERT IGNORE` 本身還會把欄位層級錯誤降成 warning。
   - `firstOrCreate` 三處（API_External `WmLiveSingleWallet/TransferService.php:154`、API_Frontend `MobileBindService.php:151`、`RollingChipRepository.php:17`）：Laravel 只把**第一參數**放進 WHERE。幣別放第一參數 ⇒ legacy `currency_id=0` 列查不到、走 create、三元組下不撞鍵 ⇒ **同會員同型別兩列＝餘額分裂**；放第二參數 ⇒ WHERE 仍是二元組、必定撈到既有列（可能是錯幣別）並直接動餘額，既有列的 `currency_id` 永遠補不上。兩條都錯，正解是查詢與 payload 同時帶幣別，且先處理 legacy 0 列。
4. **讀取面比寫入面更痛，而且四個 repo 都有**。唯一鍵改三元組後，`where member_id + wallet_type_id ->first()` 會撈到錯幣別的列。API_External `MemberWallet::` 130 次（扣 1 次 `::class` 得 129 處查詢），123 處散在 49 個檔案裡寫死條件、6 處集中在 `WalletRepository`；API_Frontend `DB::table('member_wallets')` 讀取 12 處、`MemberWallet::` 51、`where('wallet_type_id')` 37；RD-Studio-01 `MemberWallet::` 23、`where('wallet_type_id')` 15；API_Agent `MemberWallet::` 7 ＋ `DB::table` 1——那 1 處就是代理開分主路徑 `AgentWalletController.php:397` 二元組讀 id、`:443` 再 `lockForUpdate()->find($id)`：改鍵後**讀到錯幣別列 → 鎖錯列 → 錢加到錯幣別錢包**，比 7.3 的孤兒列嚴重。另三個 repo 的讀取面只有計數、未逐筆盤，第五期須補。`lockForUpdate()` 的鎖範圍也會變：現況 `where(member_id, wallet_type_id)` 打在唯一索引全匹配上（查無列時的 gap lock 正是防併發重複建錢包的機制）；改三元組後 WHERE 若維持二元組，降級為索引前綴 ⇒ next-key lock 鎖住整個前綴區間，跨幣別互相阻塞；WHERE 改三元組則鎖範圍回復，但 legacy 0 列查不到。
5. **`target/support` 是跨 repo 的共用套件**（composer `repositories` 指向 `target/components/support.git`，被各 repo `.gitignore` 排除、不在任何 repo 版控內）。其 `src/Services/CashFlowService.php:198`／`:209`（API_Agent 內的副本行號；API_External 內的副本是 `:209`／`:220`，兩 repo 裝的套件版本不同）於入款確認時建立並寫入 `wallet_type_id=2` 錢包，在 API_Agent（`OrderController.php:322` 人工上分、`:776` 審核通過）是活路徑，在 API_External 的唯一呼叫端（`routes/web.php:79-80`）整段被註解。該套件另有平行 Model `target\support\Models\MemberWallet`，目前無寫入呼叫端。**不改它，入款建出來的錢包就沒有幣別。**
6. **產生器樣板也要改**：API_External `resources/stubs/controllers/vendors/`（`__Controller.stub:575`／`:594`、`scripts/Bet.stub:285`／`:290`）含 4 條錢包寫入，日後 `make:vendor` 接新遊戲商會複製出不帶幣別的程式碼。`tests/` 另有 6 個 `factory(MemberWallet::class)->create()` 改 schema 後會一起紅。

### 7.3 順手抓到的疑似缺陷（處置分三類）

| 處置 | repo | 位置 | 疑點與後果 |
|---|---|---|---|
| **另開單**（✅ 09-04 RB 裁決單獨開單；09-06 已建 `target/tickets#4298`，修法推薦 `down()` 改 no-op） | API_Frontend | `database/migrations/2023_04_11_084334_create_wallet_types_table.php:9` | **DDL 地雷**：類名建 `wallet_types` 但 `$table_name` 寫成 `member_wallets`，`up()` 有 `hasTable` 守衛從未執行，`down()` 的 `dropIfExists('member_wallets')` 沒守衛。其後尚有 357 支 migration，`migrate:rollback` 打不到，觸發限 `migrate:reset/refresh/fresh` 或誤以為只回滾 wallet_types |
| 待 PM 裁決（09-04 RB：起草詢問稿送 PM，不直接併第五期） | API_Frontend | `EventNewInviteController.php:674` | `:650`／`:654` 依 `$point_type` 決定 `$wallet_type`，`:656` 也用它查詢，但建立時 `:668` 硬寫 `ROLLING_CHIP`。`point_type=3`（遊戲錢包獎勵）時兩種後果：會員**已有**禮碼錢包 ⇒ `create` 撞兩元組唯一鍵拋 1062、整筆派獎失敗（線上最可能的形態）；**沒有** ⇒ 真金獎勵誤建成禮碼錢包（帶流水限制，玩家實質損失） |
| 待 PM 裁決（09-04 RB：起草詢問稿送 PM，不直接併第五期） | API_External | `EventUUSlotSingleWallet/Libs/CashBonus.php:73` | 查 `CODE_GAME`(2)，同目錄 `Bet`／`GameResult`／`Jackpot`／`Rollback` 與姊妹家族 `EventWFSingleWallet/Libs/CashBonus.php:79` 全查 `CODE_EVENT_RANKING`(1003)。後果二選一：會員有 type=2 ⇒ 活動彩金派進真金遊戲錢包（多給）；沒有 ⇒ `:78` 拋 Wallet not found、活動彩金整條失敗 |
| 待 PM 裁決（09-04 RB：起草詢問稿送 PM，不直接併第五期） | API_Agent | `AgentWalletController.php:408` | `MemberWallet::create()` 在 `DB::beginTransaction()`（`:418`）之外，rollback（`:496`／`:503`／`:536`）不回收，留下 `balance=0` 孤兒列；同函式 `AgentWallet::create()`（`:386`）同病。四個 repo 中唯一的交易外建立點（`target/support` 與 `ThirdPartyController` 的 create 都在交易內）。嚴重度**中**：`:443` 交易內有 `lockForUpdate()` 重取，併發安全、無金額影響；孤兒列在多幣別下是 `currency_id=0` 殘列，會落進 Task 10 的歸零判準。同函式真正該擔心的是 `:397` 的二元組讀取（7.2 第 4 點） |
| 改造時單獨處理 | API_External | `EventFumaSW/Libs/Bet.php:52` | 用無鎖的 `WalletRepository::getEventWalletByMember()`（`:148-152`），足額檢查（`:120`）與 `payWallet()`（`:131`）的 double check 都讀同一份無鎖快照再 `decrement` ⇒ **併發可透支成負餘額**。是孤例不是慣例：姊妹家族 `EventWudiSW/Libs/Start.php:59` 用的是同 Repository 帶鎖的 `getEventWalletLockedWallets()`（`:80-85`），修法就是改呼叫它。寫的是 1003（將廢除） |
| 改造時單獨處理 | API_External | `EventFumaSW/Libs/Settle.php:58` | 同上無鎖但只 `increment`（原子），影響僅 before／after log 與回給三方的餘額失真，量級較低 |
| 改造時單獨處理 | API_External | `Rcb988Controller.php:2585`／`:2604` | **不是缺陷，是全 repo 156 個寫入點中唯一的原子更新**（註解明寫「使用 DB::raw 避免並發寫入時 stale-read 覆蓋問題」），別改回 read-then-write。真風險兩個：`DB::raw('balance - ' . floatval($amount))` 把 float 轉字串拼進 SQL，DECIMAL 欄位被迫與 double 運算、受 `precision` ini 限制 ⇒ 金額精度風險（踩「金額不走原生浮點」紅線）；`:2581` 的足額檢查用 in-memory 值，與原子扣款不同步。改造時改綁定參數＋`Common::num_*` |

### 7.4 推翻與排除

- **「API_Agent 代理開分是無守衛的 type=2 活路徑」不成立，但嫌疑不能轉去有同款守衛的地方**。`AgentWalletController.php:362-367` 有多錢包開關（`AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH` = 6008）守衛，`git blame` 為 2026-07-09 起就存在，開關為 0 直接拋 9999。09-03 稿曾把嫌疑轉向 API_Frontend `VendorController.php:4767`／`:5437` 與 `target/support` 入款——**圓桌實證三者全在同一個 6008 之後**（`VendorController.php:4750`／`:5420` `checkMemberSetting(..., MULTIPLE_WALLET_SWITCH)`、`CashFlowService.php:155-183` `if ($is_multiple_wallet)`），用「六站開關全關」赦免 API_Agent 就得一併赦免它們。**唯一不看開關的 type=2 建立路徑是 API_Frontend `ThirdPartyController.php:1903`**：`newTransaction()`（`:1473-2050`）全函式沒有任何 6008 引用（該檔的 6008 只在 `:1154`／`:1158`／`:2645`），`transaction_type=-4` 開分 → `:1889` 查無錢包 → `:1903` 無條件 `MemberWallet::create`，唯一前置是代理需有 `CODE_SECOND_WALLET`；三方代理批次開分的量級也對得上近 30 天 21,246 筆。**可一刀分辨的驗證法**：查那 21,246 筆對應的 `wallet_transaction_logs.type` 是否為 `TYPE_GAME_WALLET_BACKEND_DEPOSIT`（`ThirdPartyController.php:1918`）。⚠️ 兩個保留：「六站開關全關」是 09-03 時點值，30 天內是否被開過未驗證（開關可從後台改）；量測口徑須為有效值 `IFNULL(acs.value, asd.default_value)` 逐代理群組檢查（四 repo 的判定都這樣寫：API_External `WalletRepository.php:97-107`、API_Frontend `VendorController.php:7758-7769`、`CashFlowService.php:370-381`；API_Agent `AgentCustomizedSettingService.php:100-102` 走 `group_value ?? default_value`，`AgentWalletController.php:364` 另加 `?? 0`，fallback 語意不同）。
- RD-Studio-01 `WalletRepository.php:115` 是壞的死碼（`factory(MemberWallet::class)` 但 repo 只有 `UserFactory`，執行必拋 `InvalidArgumentException`；且唯二上游零呼叫端）——建議刪除而非改造。
- RD-Studio-01 懷疑的 9 支 SP（`SP_summary_rolling_chip_record`、`SP_Job_Vendor_Hour_Record`／`_Rerun`、`SP_summary_bet_record_team`、`SP_set_lucky_ticket`／`_6d`、`SP_set_w_jackpot_ticket`、`SP_set_prize_member`、`SP_summary_event_main`）於 VWorld 生產庫 `information_schema.ROUTINES` 實查**皆不含 `member_wallets`**。
- API_External 5 個 `updateSubBalance()` 多型漏斗（`Ace333sw:2419`、`Acewin:1728`、`Astar:1996`、`Rcb988:2577`、`Wcasino:2432`）判定為不寫 `member_wallets`，是靠追全部呼叫端得出（只收 Member 與 promotion_wallets），不是型別保證；日後多傳一次 MemberWallet 就變寫入點。
- API_Frontend `DB::table('member_wallets')` 12 處皆為讀取或 `leftJoin`，無寫入（但改鍵後讀取本身就是錯誤來源，見 7.2 第 4 點）；RD-Studio-01 `gobin/getBetRecords` 執行檔 `strings` 掃描無錢包字串；HTTP 面不存在。
- RD-Studio-01 `HandleDgFailedTransactions` 的排程註解原文是「改用 apis 排程」（`Kernel.php:672-673`），APIs 側有對應排程仍在跑——處置要跟 APIs 那支一起看，不是單純的人工死路徑。

### 7.5 逐筆清單

<details>
<summary>API_External 156 筆（分支 <code>tpower-master</code>，路徑相對 <code>_release/</code>）</summary>

錢包類型代號：`1002`＝禮碼／泥碼、`1003`＝活動刷新排行榜、`2`＝遊戲錢包、**多型**＝`MemberWallet(2)` 或 `members.balance`，由 `WalletRepository::isAgentMultipleWallet()`（代理設定 6008）決定；**三態多型**＝`MemberWallet(1002)`／`members`／`promotion_wallets` 共用漏斗。依類型：1002 共 50、1003 共 23、2 明確 3、多型 75、三態多型 5。建立錢包在本分支只有 2 筆（`WalletRepository.php:129` 與 `WmLiveSingleWallet/TransferService.php:154`，皆 type=2；`vw-master` 另有 3 筆，見 7.1）；**1002 與 1003 在本 repo 沒有任何建立點，只有讀寫**。

**Controllers（27）**

| 位置 | 動詞 | 場景 | 類型 |
|---|---|---|---|
| `app/Http/Controllers/Ace333swController.php:810` | update | Ace333 單一錢包下注，禮碼足額扣款 | 1002 |
| `app/Http/Controllers/Ace333swController.php:815` | update | 同上，禮碼不足清零 | 1002 |
| `app/Http/Controllers/Ace333swController.php:2445` | update | `updateAddBalance()` 漏斗（`:1921` 傳禮碼錢包） | 三態多型 |
| `app/Http/Controllers/AcewinController.php:633` | update | Acewin 下注扣禮碼 | 1002 |
| `app/Http/Controllers/AcewinController.php:638` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/AcewinController.php:1754` | update | `updateAddBalance()` 漏斗（`:1172` 注單取消退禮碼） | 三態多型 |
| `app/Http/Controllers/AstarController.php:500` | update | Astar 下注扣禮碼 | 1002 |
| `app/Http/Controllers/AstarController.php:506` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/AstarController.php:2022` | update | `updateAddBalance()` 漏斗（`:1050` 退禮碼） | 三態多型 |
| `app/Http/Controllers/BoleSingleWalletController.php:197` | update | Bole 下注／派彩 | 多型 |
| `app/Http/Controllers/BoleSingleWalletController.php:232` | update | Bole 結算 | 多型 |
| `app/Http/Controllers/BoleSingleWalletController.php:276` | update | Bole 回滾 | 多型 |
| `app/Http/Controllers/EpicWinController.php:269` | update | EpicWin 下注扣禮碼 | 1002 |
| `app/Http/Controllers/EpicWinController.php:277` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/EpicWinController.php:612` | update | EpicWin rollback 退禮碼 | 1002 |
| `app/Http/Controllers/EventPgsController.php:1045` | update | target 活動：首投把排行榜錢包還原成活動點數 | 1003 |
| `app/Http/Controllers/EventPgsController.php:1076` | update | `updateMemberWallet()` 漏斗，活動投注扣點（同時寫 `is_bet`） | 1003 |
| `app/Http/Controllers/ImpswController.php:228` | update | IMP 單一錢包交易扣／加款 | 多型 |
| `app/Http/Controllers/PgsController.php:213` | update | target 下注扣禮碼 | 1002 |
| `app/Http/Controllers/PgsController.php:217` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/PgsController.php:1010` | update | target 注單取消退禮碼 | 1002 |
| `app/Http/Controllers/Rcb988Controller.php:719` | update | Rcb988 下注扣禮碼 | 1002 |
| `app/Http/Controllers/Rcb988Controller.php:724` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/Rcb988Controller.php:2604` | update | `updateAddBalance()` 漏斗（`:1416`／`:1697`／`:1991` 退禮碼），用 `DB::raw('balance + …')` | 三態多型 |
| `app/Http/Controllers/WcasinoController.php:652` | update | Wcasino 下注扣禮碼 | 1002 |
| `app/Http/Controllers/WcasinoController.php:657` | update | 禮碼清零 | 1002 |
| `app/Http/Controllers/WcasinoController.php:2458` | update | `updateAddBalance()` 漏斗（`:1416`／`:1766` 退禮碼） | 三態多型 |

**Repositories（1）**

| 位置 | 動詞 | 場景 | 類型 |
|---|---|---|---|
| `app/Repositories/WalletRepository.php:129` | create（`factory(MemberWallet::class)->create()`） | 建立會員遊戲錢包，通用建立漏斗；`app/` 內 16 行呼叫（直呼 11、經 `getOrCreateMemberGameWallet` 5），兩個入口都要改 | 2 |

**Services（128）**（格式：`檔案:行` 動詞 場景 `類型`）

- **BoongoSingleWallet（9）**：`RollbackService.php:191` save 退禮碼 `1002`｜`RollbackService.php:229` update 回滾主錢包 `多型`｜`TransferService.php:224` update 扣禮碼 `1002`｜`:229` 禮碼清零 `1002`｜`:263` update 轉出扣款 `多型`｜`:367` update 轉入加款 `多型`｜`:456` 扣禮碼 `1002`｜`:461` 禮碼清零 `1002`｜`:495` update 主錢包 `多型`
- **CRSingleWallet/Libs（10）**：`CancelBet.php:110` update 取消注單退款 `多型`｜`CancelBet.php:172` save 退禮碼 `1002`｜`PlaceBet.php:142` update 下注扣款 `多型`｜`PlaceBet.php:208` 扣禮碼 `1002`｜`:213` 禮碼清零 `1002`｜`RevertBet.php:131` update 撤注退款 `多型`｜`:202` 扣禮碼 `1002`｜`:207` 禮碼清零 `1002`｜`SettleBet.php:104` update 派彩 `多型`｜`UnsettleBet.php:106` update 取消結算 `多型`
- **EventFumaSW/Libs（2）**：`Bet.php:145` decrement 活動下注 `1003`｜`Settle.php:63` increment 活動派彩 `1003`
- **EventUUSlotSingleWallet/Libs（6）**：`Bet.php:100` update 活動下注 `1003`｜`Bet.php:232` update 首投重置錢包 `1003`｜`CashBonus.php:86` update 活動 CashBonus **`2`（疑似，見 7.3）**｜`GameResult.php:92` update 派彩 `1003`｜`Jackpot.php:87` update 彩金 `1003`｜`Rollback.php:79` update 回滾 `1003`
- **EventVpowerSingleWallet/Libs（4）**：`Bet.php:211` update 首投重置 `1003`｜`Bet.php:281` update 下注扣點 `1003`｜`Refund.php:129` update 退款 `1003`｜`Settlement.php:64` update 結算 `1003`
- **EventWFSingleWallet/Libs（6，皆 update）**：`Bet.php:102` `1003`｜`Bet.php:234` 首投重置 `1003`｜`CashBonus.php:89` `1003`｜`GameResult.php:92` `1003`｜`Jackpot.php:87` `1003`｜`Rollback.php:79` `1003`
- **EventWudiSW/Libs（4）**：`End.php:67` increment 活動結束派彩 `1003`｜`Refund.php:95` increment 退款 `1003`｜`Reward.php:88` increment 獎勵 `1003`｜`Start.php:132` decrement 開局扣點 `1003`
- **FumaSW/Libs（2）**：`Bet.php:161` decrement 下注 `多型`｜`Settle.php:66` increment 結算 `多型`
- **MegaWinSingleWallet/Libs（3，皆 update，皆多型）**：`PlaceBet.php:117` 下注｜`SettleBet.php:102` 結算｜`UnsettleBet.php:96` 取消結算（禮碼寫入委派 `ShareServices/RollingChipService`，不重複計）
- **MotivSingleWallet/Libs（6，皆 update，皆多型）**：`Bet.php:166` 下注｜`Credit.php:69` 入款｜`Debit.php:69` 扣款｜`Endround.php:69` 結束回合｜`Refund.php:158` 退款｜`Rollin.php:74` 轉入
- **OnebxSW**：原 Libs 四支（Bet／Rollback／Settle／Undo）的 decrement／increment／update 直寫，2026-09 隨 target/tickets#4375 併入 `OnebxSWController`，動錢一律改走 `BaseController::changeBalance()`，本 vendor 不再有自己的 member_wallets 寫入點（原四筆併入 BaseController 那一筆計）
- **ShareServices/RollingChipService（3，禮碼共用服務）**：`:56` decrement 禮碼扣款 `1002`｜`:61` update 禮碼清零 `1002`｜`:103` increment 禮碼退款 `1002`
- **SingleWallet/FFF（6）**：`Actions/DeductBalance.php:150` update 扣款 `多型`｜`Actions/Settlement.php:111` update 結算 `多型`｜`TransferService.php:40` 扣禮碼 `1002`｜`:45` 禮碼清零 `1002`｜`:120` increment 退禮碼 `1002`｜`:156` increment 退主錢包 `多型`
- **SingleWallet/PlayTech（7）**：`Actions/Bet.php:142` update 下注 `多型`｜`Actions/Settlement.php:119` update 派彩 `多型`｜`Actions/TransferFunds.php:97` update 轉帳 `多型`｜`TransactionService.php:44` 扣禮碼 `1002`｜`:49` 禮碼清零 `1002`｜`:124` increment 退禮碼 `1002`｜`:160` increment 退主錢包 `多型`
- **SpadegamingSingleWallet/TransferService.php（7）**：`:263` 扣禮碼 `1002`｜`:268` 禮碼清零 `1002`｜`:302` update 轉出 `多型`｜`:414` update 轉入 `多型`｜`:515` save 退禮碼 `1002`｜`:553` update 回滾 `多型`｜`:653` update 補償 `多型`
- **TFGamingSingleWallet/Libs（4，皆多型）**：`Bet.php:162` decrement｜`Rollback.php:147` increment｜`Settle.php:74` increment｜`Unsettle.php:78` increment
- **UUSlotSingleWallet/Libs（8）**：`Bet.php:127` update 下注 `多型`｜`Bet.php:197` 扣禮碼 `1002`｜`:202` 禮碼清零 `1002`｜`CashBonus.php:108` update `多型`｜`GameResult.php:110` update `多型`｜`Jackpot.php:108` update `多型`｜`Rollback.php:102` update `多型`｜`Rollback.php:168` save 退禮碼 `1002`
- **VpowerSingleWallet/Libs（3，皆 update，皆多型）**：`Bet.php:178`｜`Refund.php:159`｜`Settlement.php:66`
- **WESingleWallet/Libs（7）**：`Credit.php:104` update 入款 `多型`｜`Debit.php:122` update 扣款 `多型`｜`Debit.php:191` 扣禮碼 `1002`｜`:196` 禮碼清零 `1002`｜`Resettlement.php:112` update 重結算 `多型`｜`RollBack.php:103` update 回滾 `多型`｜`RollBack.php:165` save 退禮碼 `1002`
- **WFSingleWallet/Libs（8）**：`Bet.php:125` update 下注 `多型`｜`Bet.php:190` 扣禮碼 `1002`｜`:195` 禮碼清零 `1002`｜`CashBonus.php:106` `多型`｜`GameResult.php:110` `多型`｜`Jackpot.php:106` `多型`｜`Rollback.php:102` `多型`｜`Rollback.php:168` save 退禮碼 `1002`
- **WcasinoSingleWallet/Libs（8，皆 update，皆多型）**：`Bet.php:172`｜`BetCancel.php:142`｜`Resettlement.php:85`｜`Rollback_BetCancel.php:67`｜`Rollback_Resettlement.php:68`｜`Rollback_Settlement.php:141`｜`Settlement.php:72`｜`SettlementCancel.php:68`
- **WmLiveSingleWallet（7）**：`Actions/DeductBalance.php:117` update 扣款 `多型`｜`Actions/IncreaseBalance.php:81` update 加款 `多型`｜`TransferService.php:42` 扣禮碼 `1002`｜`:47` 禮碼清零 `1002`｜**`TransferService.php:154` `firstOrCreate` 建立遊戲錢包 `2`**｜`:218` increment 退禮碼 `1002`｜`:254` increment 退主錢包 `多型`
- **WudiSW/Libs（4，皆多型）**：`End.php:73` increment｜`Refund.php:142` increment｜`Reward.php:94` increment｜`Start.php:167` decrement

</details>

<details>
<summary>API_Frontend 41 筆（分支 <code>vw-master</code>，路徑相對 <code>_release/</code>）</summary>

依類型：`CODE_GAME`(2) 17、`ROLLING_CHIP`(1002) 20、動態 4；`CODE_SHOP`(1004) 只經 #3／#8 的動態路徑到達，`CODE_PC`(1) 無寫入點。建立錢包 19 筆（#1、2、3、4、10、12、15、17、18、23、25、26、28、30、32、33、36、38、39），只改餘額 22 筆。

| # | 位置 | 動詞 | 場景 | 類型 | 建立點的幣別可得性 |
|---|---|---|---|---|---|
| 1 | `app/Repositories/WalletRepository.php:40` | `MemberWallet::create` | `createMemberGameWallet()`，打卡發獎建遊戲錢包 | 2 | 只有 `int $memberId`，**必改介面** |
| 2 | `app/Repositories/WalletRepository.php:48` | `MemberWallet::create` | `createMemberRollingChipWallet()` | 1002 | 同上，**必改介面** |
| 3 | `app/Repositories/WalletRepository.php:87` | `SQLHelper::insertIgnore('member_wallets')` | `getOrNewMemberWallet()`，任務系統取或建（註解寫明避開 unique constraint） | 動態（實測 2 或 1004） | 同上，raw 路徑，**必改介面**、影響面最大 |
| 4 | `app/Repositories/RollingChipRepository.php:17` | `firstOrCreate` | 綁手機獎勵發禮碼前確保錢包存在 | 1002 | `Member` 實例，兩跳 |
| 5 | `app/Repositories/RollingChipRepository.php:38` | update | 同上加禮碼 | 1002 | — |
| 6 | `app/Services/CheckinService.php:193` | save | 打卡獎勵派點到遊戲錢包 | 2 | — |
| 7 | `app/Services/CheckinService.php:254` | save | 打卡獎勵派禮碼 | 1002 | — |
| 8 | `app/Services/Missions/MissionService.php:229` | update | 任務獎勵入帳 | 動態 2／1004（`:191`／`:200`） | — |
| 9 | `app/Services/Missions/MissionService.php:290` | update | 任務獎勵發禮碼 | 1002 | — |
| 10 | `app/Services/ValidateToken/MobileBindService.php:151` | `firstOrCreate` | 綁定手機獎勵，確保遊戲錢包存在 | 2 | `$this->member`，兩跳 |
| 11 | `app/Services/ValidateToken/MobileBindService.php:171` | update | 同上加值 | 2 | — |
| 12 | `app/Http/Controllers/ThirdPartyController.php:1903` | `MemberWallet::create` | 三方 `newTransaction` 代理開分（type=3），沒錢包就建；**唯一不看 6008 開關的 type=2 建立路徑**（7.4） | 2 | `$agent`（`:1542`）是操作代理且只 select 6 欄、`$self_agent`（`:1546`）才是上級但只 select `id`／`transaction_all`，兩者都無 `organization_id`，**須另查 `members.parent`**（同 #23／#38） |
| 13 | `app/Http/Controllers/ThirdPartyController.php:1937` | update | 代理對會員開分加值 | 2 | — |
| 14 | `app/Http/Controllers/ThirdPartyController.php:1950` | update | 代理對會員扣分（type=4） | 2 | — |
| 15 | `app/Http/Controllers/CouponController.php:193` | `MemberWallet::create` | 兌換券派發禮碼，缺錢包則建 | 1002 | `$memberId`／`$parentId`，兩跳多一查 |
| 16 | `app/Http/Controllers/CouponController.php:216` | update（`DB::raw('balance+…')`） | 兌換券禮碼入帳 | 1002 | — |
| 17 | `app/Http/Controllers/VendorController.php:4767` | `MemberWallet::create` | `getSingleWalletBalanceNew()` 查餘額時順手建遊戲錢包（有 6008 守衛，`:4750`） | 2 | `$member`，兩跳 |
| 18 | `app/Http/Controllers/VendorController.php:5437` | `MemberWallet::create` | `transferFromLastLog()`（`:5414`）依最後一筆 transaction_log 把遊戲商餘額一鍵轉回平台時順手建遊戲錢包（有 6008 守衛，`:5420`） | 2 | `$member`，兩跳；會員 token 端點 |
| 19 | `app/Http/Controllers/VendorController.php:6350` | update | 平台 → 遊戲商轉點，扣平台餘額 | 2 | — |
| 20 | `app/Http/Controllers/VendorController.php:6381` | update | 轉點三方失敗，餘額回補 | 2 | — |
| 21 | `app/Http/Controllers/VendorController.php:6449` | update | 遊戲商 → 平台轉點 | 2 | — |
| 22 | `app/Http/Controllers/UnlimitController.php:610` | increment | 無限代理獎金提領（`withdrawal()`，`:510`；另有獨立的 `rebate()`），發禮碼 | 1002 | — |
| 23 | `app/Http/Controllers/UnlimitController.php:626` | `MemberWallet::create` | 同上，缺錢包以獎勵金額建立 | 1002 | `$member`／`$agent` 被 `select()` 裁過、無 `organization_id`，**須另查** |
| 24 | `app/Http/Controllers/PrizeController.php:1349` | mass update（`DB::raw`） | 活動獎項派禮碼 | 1002 | — |
| 25 | `app/Http/Controllers/PrizeController.php:1365` | `MemberWallet::create` | 同上，缺錢包以獎金額建立 | 1002 | `$member`，兩跳 |
| 26 | `app/Http/Controllers/PrizeController.php:1461` | `MemberWallet::create` | 活動獎項派遊戲錢包（有流水限制） | 2 | `$member`，兩跳 |
| 27 | `app/Http/Controllers/PrizeController.php:1471` | update | 同上入帳 | 2 | — |
| 28 | `app/Http/Controllers/VipController.php:433` | `MemberWallet::create` | VIP 獎勵（禮碼型），缺錢包則建 | 1002 | `$member`，兩跳 |
| 29 | `app/Http/Controllers/VipController.php:442` | update | VIP 獎勵入帳 | 1002 | — |
| 30 | `app/Http/Controllers/VipController.php:622` | `MemberWallet::create` | VIP 獎勵第二處 | 1002 | 兩跳 |
| 31 | `app/Http/Controllers/VipController.php:631` | update | 同上入帳 | 1002 | — |
| 32 | `app/Http/Controllers/TgBotController.php:1154` | `MemberWallet::create` | TG Bot 查餘額時順手建錢包 | 動態（`:1175` 傳 2、`:1184` 傳 1002） | helper 只收 `$member_id`，**建議改簽章** |
| 33 | `app/Http/Controllers/SlotCompetitionController.php:461` | `MemberWallet::create` | 老虎機競賽兌獎派禮碼 | 1002 | `$member`，兩跳 |
| 34 | `app/Http/Controllers/SlotCompetitionController.php:484` | update（`DB::raw`） | 同上入帳 | 1002 | — |
| 35 | `app/Http/Controllers/EventNewInviteController.php:663` | update | 新邀請活動獎勵入帳 | 動態 1002／2（`:650`／`:654`） | — |
| 36 | `app/Http/Controllers/EventNewInviteController.php:674` | `MemberWallet::create` | 同上缺錢包則建 | **硬寫 1002（疑似 bug，見 7.3）** | `$member`，兩跳 |
| 37 | `app/Http/Controllers/ThreeHierarchyController.php:600` | increment | 三層代理獎金提領（`withdrawal()`，`:524`；另有獨立的 `rebate()`，`:306`），發禮碼 | 1002 | — |
| 38 | `app/Http/Controllers/ThreeHierarchyController.php:616` | `MemberWallet::create` | 同上缺錢包則建 | 1002 | 同 #23 投影裁切，**須另查** |
| 39 | `app/Http/Controllers/WalletController.php:59` | `MemberWallet::create` | 主錢包 ↔ 遊戲錢包互轉，缺錢包則建 | 2 | `$member`，兩跳 |
| 40 | `app/Http/Controllers/WalletController.php:86` | update | 主錢包 → 遊戲錢包 | 2 | — |
| 41 | `app/Http/Controllers/WalletController.php:97` | update | 遊戲錢包 → 主錢包 | 2 | — |

另：`admin` 欄位多處 `$create_data` 帶 `'admin' => 0`，但本 repo 建表 migration 無此欄且無後續 `Schema::table`，推論欄位在 repo 之外加的（生產庫實有：第一節 09-03 補充的 SP INSERT 欄位清單含 `admin`）。tpower 系分支另有 `RefreshRankingController.php:327` 建立 1003 錢包，不在本清單。

</details>

<details>
<summary>RD-Studio-01 18 筆（路徑相對 <code>_release/</code>）</summary>

6 個建立點的 create payload 固定六欄 `member_id / wallet_type_id / balance / first_deposit / status / admin`，寫法一致，建議收成單一 helper 再一次改完。**六個建立點目前沒有一個拿得到幣別**（`Member` 模型無幣別欄，全 repo 無「會員→幣別」查詢路徑，只有 `$vendorInfo->rate` 與硬編碼的 per-vendor 幣別 map 如 `GetSabaBetRecord.php:35-60`）。

| # | 位置 | 動詞 | 場景 | 類型 | 觸發來源 |
|---|---|---|---|---|---|
| 1 | `app/Repositories/WalletRepository.php:115` | `factory()->create()` | 建立會員遊戲錢包 | 2 | **死碼**（無 factory、零呼叫端，見 7.4） |
| 2 | `app/Services/ShareServices/RollingChipService.php:56` | decrement | 注單結算扣禮碼 | 1002 | 排程 `clotp2psw_sync_cancelled_record` 每小時 :08／手動 clotlssw |
| 3 | `app/Services/ShareServices/RollingChipService.php:61` | update | 禮碼不足歸零 | 1002 | 同上 |
| 4 | `app/Services/ShareServices/RollingChipService.php:103` | increment | 注單取消退禮碼 | 1002 | 同上 |
| 5 | `app/Console/Commands/PayoutEventJackpot.php:326` | update | 積寶活動派禮碼 | 1002 | 排程 `payout_event_jackpot` 每日 07:00 |
| 6 | `app/Console/Commands/PayoutEventJackpot.php:337` | `MemberWallet::create` | 積寶派禮碼時補建禮碼錢包 | 1002 | 同上 |
| 7 | `app/Console/Commands/PayoutEventJackpot.php:371` | `MemberWallet::create` | 積寶派真金時補建遊戲錢包 | 2 | 同上 |
| 8 | `app/Console/Commands/PayoutEventJackpot.php:380` | update | 積寶派真金入遊戲錢包 | 2 | 同上 |
| 9 | `app/Console/Commands/TurnMemberBalanceToGameWallet.php:123` | `MemberWallet::create` | 主／遊錢包互轉補建遊戲錢包 | 2（字面硬編碼） | 手動（未在 Kernel） |
| 10 | `app/Console/Commands/TurnMemberBalanceToGameWallet.php:162` | update | 主錢包轉入遊戲錢包 | 2 | 手動 |
| 11 | `app/Console/Commands/TurnMemberBalanceToGameWallet.php:174` | update | 遊戲錢包轉回主錢包 | 2 | 手動 |
| 12 | `app/Console/Commands/HandleDgFailedTransactions.php:216` | save | DG 問題注單退款（`$wallet` 型別二義：Member 或 MemberWallet） | 2（僅當 `$type !== 'member'`） | 排程已註解停用（`Kernel.php:672-673`，註解原文「改用 apis 排程」，APIs 側有對應排程），人工執行即生效 |
| 13 | `app/Console/Commands/SummaryEvent.php:1163` | update | 活動派獎發禮碼 | 1002 | 排程 `summary_event` 每 15 分 |
| 14 | `app/Console/Commands/SummaryEvent.php:1174` | `MemberWallet::create` | 活動派禮碼時補建禮碼錢包 | 1002 | 同上 |
| 15 | `app/Console/Commands/SummaryEvent.php:1208` | `MemberWallet::create` | 活動派真金時補建遊戲錢包 | 2 | 同上 |
| 16 | `app/Console/Commands/SummaryEvent.php:1216` | update | 活動派真金入遊戲錢包 | 2 | 同上 |
| 17 | `app/Console/Commands/Clotp2psw/Clotp2pswSyncCancelledRecord.php:234` | update | 注單取消回滾真金（`isAgentMultipleWallet()` 決定寫 MemberWallet 或 members） | 2（多型） | 排程每小時 :08 |
| 18 | `app/Console/Commands/Clotlssw/ClotlsswSyncCancelledRecord.php:234` | update | 同上（另一遊戲商） | 2（多型） | 手動（Kernel 只排 create／games） |

另：本 repo 呼叫 78 個相異 SP 名稱，其中 `SP_transfer_bet_record_*` 69 支＋`SP_transfer_event_record_pgs` 只動注單，且含動態組名（`'call SP_transfer_bet_record_' . $vendor_tname`），實際 SP 集合大於字面清單。

</details>

<details>
<summary>API_Agent 7 筆＋<code>target/support</code> 2 筆（路徑相對 <code>_release/</code>）</summary>

| # | 位置 | 動詞 | 場景 | 類型 | 建立點的幣別可得性 |
|---|---|---|---|---|---|
| 1 | `app/Http/Controllers/AgentWalletController.php:408` | `MemberWallet::create` | 代理後台開分／補分／扣分，會員無遊戲錢包則建（有 6008 開關守衛，`:362-367`） | 2 | `$member_parent`（Agent 實例）已在手，**一跳最佳**；⚠️ 建立在交易之外（7.3） |
| 2 | `app/Http/Controllers/AgentWalletController.php:454` | update | 同上加減餘額 | 2 | — |
| 3 | `app/Http/Controllers/TransactionController.php:571` | increment | 代理後台贈送禮碼（`member` endpoint，type=5） | 1002 | — |
| 4 | `app/Http/Controllers/TransactionController.php:582` | `MemberWallet::create` | 贈送禮碼時無禮碼錢包則建並帶入點數 | 1002 | `$parent = Agent::find($member->parent)`（`:524`）已在手；注意用 `$parent` 不是操作者 `$agent` |
| 5 | `app/Http/Controllers/TransactionController.php:664` | decrement | 代理後台扣除禮碼（type=7） | 1002 | — |
| 6 | `app/Http/Controllers/EventController.php:2650` | update | 活動獎項補派獎 `payoutCancelPrize`，`point_type=2` 派禮碼 | 1002 | — |
| 7 | `app/Http/Controllers/EventController.php:2661` | `MemberWallet::create` | 同上無禮碼錢包則建 | 1002 | 只有 `$member->parent`，需多一跳；在派獎迴圈內留意 N+1 |
| 8 | `vendor/target/support/src/Services/CashFlowService.php:198` | `MemberWallet::create` | 儲值訂單確認入款 `updateMemberBalance()`，多錢包模式下無遊戲錢包則建 | 2 | `$order` 與 `$member` 在手；⛔ `orders.currency_id` 在 API_Frontend 建單時硬寫 1（7.2 第 1 點），不可當來源，仍須走兩跳 |
| 9 | `vendor/target/support/src/Services/CashFlowService.php:209` | update | 同上把訂單點數加進遊戲錢包 | 2 | — |

#8／#9 的觸發點：`app/Http/Controllers/OrderController.php:322`（`manualDeposit` 人工補單上分）、`:776`（`reviewOrder`，`status==3` 審核通過）。同 `member` endpoint 內 type=1／2／3／4／6 走 `target/resource` Client 發 `DoTransactionMembers` 改對端 `members.balance`，不是本表寫入點，改動時分支語意不要混。

API_Agent 呼叫、名稱疑似動錢包的 SP：`AgentWalletController.php:484` `SP_event_deposit_wallet_for_cs`、`MemberController.php:618` `SP_event_gift_point`、`MemberRealnameValidateController.php:243` `SP_payout_event_invite`、`RefreshRankingController.php:867` `SP_payout_refresh_ranking`、`TransactionController.php:1010` `SP_cancel_login_event`、`VJackpotController.php:455` `SP_set_lottery_ticket_number`、`CashFlowService.php:315`／`:327` `SP_event_deposit`／`SP_event_invite_deposit`——前三支與 `SP_event_deposit` 已在第一節寫入錢包的 SP 清單內；`SP_payout_refresh_ranking` 本身不在，第一節收的是它呼叫的 `SP_payout_refresh_ranking_sub`。

</details>

### 7.6 改鍵前後的止血與回滾（圓桌補登，rorin／timo）

第七節盤的是「有什麼」，這一小節補「上線後發現舊 repo 沒改完怎麼辦」。現況：分裂列不報錯、不拋例外、API 照常回 200，發現靠客訴（天級）；舊 repo 沒有 feature flag，沒有分鐘級開關；三元組唯一鍵的 migration `down()` 會撞重複列 1062 失敗（見下）。

| 時點 | 動作 | owner（待指定） |
|---|---|---|
| 改鍵前（hard gate） | 全量稽核「同 `(member_id, wallet_type_id)` 是否已存在多列」，不為零不得改鍵；逐站 `SHOW CREATE TABLE` 比對欄位集合（六站 schema 已知有漂移：`admin` 欄不在任何 repo 的 migration 裡）；逐站確認兩元組唯一索引**存在**（不存在代表可能已有重複列，`ADD UNIQUE` 會直接失敗） | |
| 改鍵當下 | `DROP INDEX` 與 `ADD UNIQUE` 寫在**同一句 `ALTER TABLE`**——拆兩句的期間唯一保護完全消失；改鍵當日重跑六站 `information_schema.ROUTINES` 與盤點清單 diff | |
| 改鍵後 | 每日「同 `(member_id, wallet_type_id)` 多列」告警（第一期校驗排程的比對語意要重寫，計劃已登記未排期）；六站 SP 集合定期 diff 告警（副本可被隨時貼回） | |
| 事故時 | 分裂列合併＝餘額相加，是金流動作，須為獨立、可 dry-run、可稽核的命令，並作為 `down()` 的前置步驟 | |

🔴 **Task 10 migration 的 `down()` 實質不可回滾**（改鍵 migration 尚未實作，指的是計劃 Task 10 段落的 `down()` 草稿）：三元組期間出現重複列後，重建兩元組唯一鍵必撞 1062；且 `down()` 寫死重建成 `unique_index`，而 `up()` 自己承認各站索引實名不同（`uni_member_wallets_member_id_wallet_type_id`）。修法（另開單，屬程式改動）：`down()` 開頭偵測重複列、撞到明確中止並列出清單；沿用 `up()` 記錄的舊索引實名。

⚠️ **閘門顆粒度**：Task 10 前置第 1 項寫「在該站」（逐站）、第 3 項寫「六站實查清單全數改完」（六站齊）；舊 repo 一白標一分支逐分支發版，六站不可能同時到齊。計劃已改為逐站判準（每站：該站舊 repo 分支已發版＋該站 SP 清單已改完＋該站重複列稽核為零）；**RB 2026-09-04 認可，列為決議⑯**。

⚠️ **對三方的影響**：129 處讀取改帶幣別後 response schema 不變（仍是單一 balance），不構成 breaking change；但切換當下同一玩家在遊戲商側看到的餘額口徑會變（從「該類型唯一那列」變「該幣別那列」），會產生對帳差異，要決定是否通知三方、是否停機切；回傳前的 `rate` 換算以商戶幣別為準，依賴第二期 `vendor_infos.currency_id` 補齊（決議檔前置阻斷：32 個啟用中商戶目前填不出來）。

---

## 八、會員幣別解析鏈六站基準值（2026-09-07，`currency:audit-orphan-parent`）

> 🔒 **單一來源（決議⑰）**：本案「孤兒 parent／解析鏈斷點」的數字只在本節，其他檔引用時寫結論不重抄數字。量測法＝`php artisan currency:audit-orphan-parent` 的判定 SQL，2026-09-07 由 Lynn（本案 AI 助理）以各站 dbc（白標資料庫唯讀直連代理）從庫執行同一條 SQL（TPower 全表掃 45 秒；命令本體尚未部署，部署後 ops 可直接重跑刷新）。判定值依鏈路順序：`parent_zero`（parent ≤ 0）／`parent_missing`（agents 無此列）／`organization_missing`／`organization_currency_zero`／`currency_not_in_master`／`resolved`；「啟用中」＝`members.status=1`。時間欄位為 MCP 回傳原值、未換算時區：判讀以日為準，8.3 引用到分鐘的時窗亦為原值。

### 8.1 六站分布

| 站 | 會員總數 | resolved | parent_missing | parent_zero | organization_currency_zero | 解析不出合計 | 其中啟用中 |
|---|---|---|---|---|---|---|---|
| TPower | 998,466 | 390,434（39.10%） | **579,009（57.99%，啟用中 0）** | 29,023（2.91%，啟用中 119） | 0 | 608,032（60.90%） | **119（占啟用中 382,619 的 0.03%）** |
| VWorld | 450,940 | 417,980（92.69%） | 0 | 29,023（6.44%，啟用中 119） | 3,937（0.87%，啟用中 3,865） | 32,960（7.31%） | 3,984（占啟用中 318,855 的 1.25%） |
| Yes2win | 212,687 | 212,685 | 0 | 0 | 2（啟用中 2） | 2 | 2 |
| Alfawin77 | 40,492 | 40,490 | 0 | 0 | 2（啟用中 2） | 2 | 2 |
| Me77 | 11,487 | 11,482 | 3（啟用中 3） | 0 | 2（啟用中 2） | 5 | 5 |
| VPlus33 | 5,505 | 5,503 | 0 | 0 | 2（啟用中 2） | 2 | 2 |

`organization_missing` 與 `currency_not_in_master` 六站皆 0。六站啟用中會員解析不出幣別者合計 **4,114 列**（parent_zero 那批在兩站各算一列）＝VWorld 組織未填幣別 3,865 ＋ 兩站共有的 parent_zero 119×2 ＋ Me77 孤兒 3 ＋ 四小站根組織 `gff` 2×4。

**對圓桌「TPower 60.9%」的校正**：60.9% ＝ parent_missing 57.99% ＋ parent_zero 2.91%，數字正確；但**啟用中會員只有 119 名解析不出幣別（0.03%）**，其餘全是非啟用帳號。治理主體不是「六成會員」，是「60 萬筆死帳怎麼處置」。

### 8.2 TPower `parent_missing`（579,009 名）的性質

| 面向 | 實測 | 意義 |
|---|---|---|
| 帳號狀態 | 全部 `status=0`（未認證）；建立 2019-09-30～2024-05-30 | 沒有一名啟用中 |
| 活動 | 380,174 名曾登入，最後登入 2024-05-30；`last_activity_at` 全 NULL；2025 年後零登入 | 2024-05-30 之後整群靜止 |
| 缺失 parent | 19,393 個相異 id；前 12 名各掛 4,169～35,125 名（60538／60557／60517／53239／58391／60539／38829／60534／49152／60565／38434／33909） | 高度集中在少數代理 |
| 子代理痕跡 | 前 12 名缺失 parent 在 `agents.hierarchy_path` 皆 0 筆下線 | 代理被刪時沒留子代理，組織無法由下線推回 |
| `member_agent` | 456,055 名有列，其中 456,009 名的 `parent_id` 就是同一個缺失代理；只有 **1 名**指向仍存在的代理，餘 45 名指向另一個同樣不存在的代理 | 該表是會員建立當時的快照，代理刪除後同樣失效；`agent_relation` 是會員上線鏈（形如 `0,329988,`）不是代理鏈 |
| `agent_migrate_logs` | type=會員 的紀錄 0 筆 | 沒有搬移史可用 |
| 錢包 | 12,403 名有 `member_wallets` 列；**3,869 名餘額 > 0，全在禮碼錢包（1002），合計 74,055.49、單筆最大 3,201.34**，最後異動 2024-05-30 | 不能無條件當零資產處置；禮碼可否作廢需 PM 判 |

### 8.3 `parent_zero`（TPower 與 VWorld 各 29,023 名）

兩站指紋完全相同：筆數 29,023、id 1～64,835、id 總和 422,952,928、建立時間全落在 2019-09-30 13:23～14:22（上線日匯入批）；啟用中 119 名兩站亦同（id 18,023～64,835）。**全部從未登入（`last_login` 為 NULL，兩站皆然）、沒有任何 `member_wallets` 列**（含 119 名啟用中），`member_agent` 0 筆線索。🔴 因為是 NULL，死帳判準必須寫 `last_login IS NULL OR last_login < …`，只寫 `<` 會漏掉這 238 列（決議㉓已照此寫）。判為同源歷史匯入資料，兩站要一起處置。

### 8.4 非孤兒但解析為 0：`organization_currency_zero`

| 站 | 組織 | 會員／啟用中 | 代理數 |
|---|---|---|---|
| VWorld | 6 `vworldbn` | 2,852／2,830 | 111 |
| VWorld | 20 `GFF USDT` | 983／978 | 12 |
| VWorld | 2 `MMGames` | 53／11 | 1 |
| VWorld | 18 `JiliGames` | 34／33 | 2 |
| VWorld | 12 `gff` | 11／11 | 3 |
| VWorld | 3 `vworldau` | 4／2 | 2 |
| Yes2win／Alfawin77／Me77／VPlus33 | 1 `gff`（根組織） | 各 2／2 | 各 1（agent 100001） |

這一類不是歸屬問題，是組織主檔沒填幣別：VWorld 六個組織歸 Task 7（vworldbn 幣別判定）與組織幣別補齊；四小站根組織 `gff` 各只掛 2 名（疑為系統／測試帳），補上 `organizations.currency_id` 即消失。

### 8.5 Me77 `parent_missing`（3 名）

會員 id 3／4／5，parent 100003／100004，2025-06-17 建立、皆啟用中；`member_agent` 無列、缺失 parent 無子代理。無任何線索，需人工判定（疑為建站測試帳號）。✅ 09-08 RB 確認為建站測試帳號、拍板停用（09-07 唯讀補查：`member_wallets` 零列、`total_bet_records` 零筆、建立當日登入後零活動；`agents` 自 100001 直接跳 100005，100002～100004 缺號）；執行走後台或 op 工單，不裸寫 DB。✅ 09-08 04:17Z 已由後台停用，唯讀複驗三列 `status=0`、`parent`／`last_login` 未動。

---

## 附錄：查證方式

| 項目 | 方式 |
|---|---|
| SP 寫入標的 | 掃 `database/procedures/*.sql` 的 `UPDATE` / `INSERT INTO` 語句 |
| SP 呼叫來源 | 交叉比對 APIs `app/`、API_Agent、RD-Studio-01、SP 之間的 `CALL`、以及 TPower 生產庫的 `information_schema.EVENTS` |
| 資料表現況 | TPower 與 VWorld 生產庫的 `information_schema`，以及版控中的 migration |
| 唯一鍵與索引 | `information_schema.STATISTICS` |
