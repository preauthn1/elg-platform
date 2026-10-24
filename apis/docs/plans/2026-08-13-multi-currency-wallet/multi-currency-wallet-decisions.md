# 多幣別錢包 — 決議清單（agent 單一真相）

> 整理自簡報 `docs/reports/multi-currency-wallet-target-flow.html` 的 **08-27 版**｜本檔產出：2026-08-28｜**09-03 會議三條裁決補入決議⑭⑮，簡報決議清單已同步回寫；09-04 RB 三項裁決補入 ⑯⑰、第七節兩條結案，簡報已同步；09-06 Bonn 校閱後 RB 把 ⑰ 射程擴大到舊口徑分 repo 明細與分支層命中數，各檔殘留已收斂**
>
> **這份文件的用途**：簡報是給人看的會審材料（297 KB、17 節），agent 無法只載入其中一節。本檔把「執行時需要遵守的結論」抽出來，一項一行，附簡報 anchor 供追溯。
>
> **權威順序（2026-09-18 起）**：本檔 > [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md)（工作順序總表）> [`docs/multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md) > 各工作包的計劃與設計文件（`multi-currency-wallet-phase2-plan.md`、`wallet-type2-cleanup-and-unique-key-plan.md`、`multi-currency-wallet-design.md`、兩份專題設計）。下游文件與本檔衝突時以本檔為準。~~簡報 > 本檔~~：簡報 `docs/reports/multi-currency-wallet-target-flow.html` 停在 09-14 版、未含決議㉚～㊲，**09-18 起降為歷史會審材料，不再作衝突仲裁**；對外現行版是 PM 簡報 `docs/reports/multi-currency-wallet-pm-brief.html`。第零期與第一期計劃已於 09-18 依「落實即刪除」規則刪除，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/<檔名>`。
>
> 🆕 **09-08 新增一份專題設計文件**：[`agent-group-currency-table-design.md`](agent-group-currency-table-design.md)（代理組別幣別表；🔄 **09-09 隨掛載層級改變由 `organization-currency-table-design.md` 更名重寫，舊檔名已不存在**）。層級同 `multi-currency-wallet-design.md`，是決議㉕ 的展開；與本檔衝突時以本檔為準。✅ **09-09 簡報已同步**（名詞表、要新增的表、待定論卡片、決議㉕ 結論段皆已改為代理組別模式）。原本記的四個衝突行號已因簡報多次改版而失效，不再列出；日後查對請以內容搜尋為準，不要引用行號。
>
> ⚠️ **契約（`docs/multi-currency-wallet-phase0-contract.md`，09-18 自本目錄搬出）有一個例外要注意**：它的幣別與 `rate` 兩節是 第零期的定版交付，數字全部來自生產庫實測。**當它與上游（簡報／本檔）的數字衝突時，先確認是不是上游還沒同步**——2026-08-31 就發生過簡報的 `rate` 表少一種值、曝露面數字停在舊版的情況，那是上游漏改，已依契約回寫。上游確實已同步而仍衝突，才依權威順序辦。
>
> 🆕 **09-17 與 PM 開會後，RB 逐題對齊並拍板八項（決議㉚～㊲）**。其中三項是**撤回已拍板的舊決議**：決議㉖（USDT 對應 USD）整項撤回、決議三的「變體 id 不刪列」與其 09-10 的「加資料庫旗標」撤回、簡報第 1 節的業務規則「會員不能同時跨幣別參加同一檔活動」撤回。舊決議的原文保留並加上撤回註記，方便追溯；**執行時一律以㉚～㊲為準**；09-18 順序定案見決議㊳與 [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md)。會議紀錄在 PM 的 Notion（語音轉文字，其中「里馬」是禮碼錢包、「派美亞」是 PayMaya）。
>
> **本檔由 AI 整理**：決議內容為 RB 於 08-18／08-19／08-27／09-03 拍板，支持證據為生產庫唯讀查詢與本機原始碼實查。標「待拍板」「未驗證」者一律禁止當已決／已驗結論引用。

---

## 決策狀態的三種標記

| 標記 | 意思 | 能不能拿來動手 |
|---|---|---|
| **已拍板** | RB 或會議已定案 | ✅ 可以 |
| **待拍板** | 兩案並陳或待 PM／業務裁決 | ❌ 不可，先問 |
| **未驗證** | 尚無工具實證的推估 | ❌ 不可當事實引用 |

---

## 一、動手前必看的四條紅線

1. **改依時間分區會打破注單冪等契約** — MySQL 要求分區鍵含於每個 UNIQUE KEY；改時間分區會讓 `UNIQUE(vendor_id, bet_record_id)` 從「跨全歷史唯一」降級為「同一時間值內唯一」，同一筆注單重跑轉檔會插兩列而不報錯。**換表不會讓這條規則消失**——新表同樣不可用時間分區。（`#tbr-mech`）
2. **冷熱分離是同一個病的另一個入口** — 資料搬進冷表後熱表沒那列，`ON DUPLICATE KEY UPDATE` 撞不到，重跑一樣插重複列。已由決議八化解（見下）。（`#decisions-0827`）
3. **具名 INSERT 不會帶新欄位** — 轉檔 SP 全部是具名欄位 INSERT，加了 `currency_id` 也永遠落 DEFAULT。**兩案都要改 SELECT，範圍是 73 支**＝`SP_transfer_bet_record_*` 72 支 ＋ `SP_transfer_event_record_pgs`（活動專用，不在該 glob 內但同樣寫 TBR、同樣具名 INSERT ＋ ODKU、同樣已 JOIN `vendor_infos`）。（`#verify-0827` 更正一）
4. **`DEFAULT 主幣別` 只在單幣別站台正確** — VWorld 現在就是多幣別營運，`vendor_id=22` 底下混雜七種幣別，填 DEFAULT 會把既有 SGD／PHP／THB／VND 注單全標成 MYR。（`#verify-0827` 更正二）

---

## 二、08-27 收斂決議（已拍板，共八項）

> 出處 `#decisions-0827`。⚠️ 簡報該節開頭仍寫「七項決議」，實際為八項（決議八為後補），以八項為準。

| # | 決議 | 執行時的意思 |
|---|---|---|
| 一 | **幣別權威來源＝`vendor_infos`，一幣別一商戶** | 幣別綁在遊戲帳號（按商戶建立）而非會員狀態，「投注當下 vs 回調當下幣別不同」的時間差問題自動消失。VWorld 已這樣運作多年。72 支 SP 中 71 支已 JOIN `vendor_infos`（`event_record_pgs` 亦已 JOIN），帶幣別只需 SELECT 多一欄 |
| 二 | **TBR 存「會員錢包幣別」的金額，換匯在遊戲商層（Driver）入境即完成** | TBR 之後全程不再換算，現況算式一行不改。**強制配套：TBR 要逐筆快照 `rate`**（rate 是後台可改的活資料）。⚠️ 初版曾寫「TBR 存三方原幣」，已推翻——`vendor_infos.rate` 同時承擔單位換算與匯率換算（AlfaWin77 的 4D 商戶 `currency=MYR`／`rate=0.00025`） |
| 三 | **`currencies` 主檔只收 ISO 4217 標準碼** | ⛔ **09-17 部分撤回，見決議㉛㉜**：「變體 id 不刪列」與 09-10 的「加資料庫旗標」都撤回，變體代碼改為**從主檔清除**；「千分制用 `rate` 表達、`KVND` 走 `vendor_infos.currency` 字串、`currency_id` 維持 `VND`」這部分**維持有效**，並由決議㉜補上玩家端的顯示單位。以下為原文。<br>千分制與仙位一律用 `rate` 表達，不佔獨立 `currency_id`。`KVND` 走 `vendor_infos.currency`（字串、送三方），`currency_id` 維持 `VND`——**兩者外觀不一致是設計，必須寫進欄位註解**。變體 id（4/5/7/8）不刪列、~~不加 DB 旗標~~，改在 `CurrencyEnum` 加「可作為會員錢包幣別」白名單（實測四個 id 在 12 張業務表皆為空集合，停用零遷移）。<br>🔄 **09-10 RB 裁決推翻其中的「不加 DB 旗標」**：改為在 `currencies` 加旗標欄位表達用途，且不另建 `wallet_currencies` 表。理由是「這個幣別能不能當錢包幣別」全平台只有一個答案，屬於幣別自己的屬性，不是「誰拿去用」的關係；另建表則要決定 id 空間並遷移 `member_wallets` 的引用，換到的東西一樣。**「不刪列」與「主檔只收標準碼」兩項仍然有效**；白名單本身（第二期 Task 8 已實作的 `CurrencyEnum::walletAllowed()`）去留，待「旗標是營運可即時調整、還是從 `currency_aliases.php` 同步下來的投影」拍板後再定。<br>⚠️ **同日另查出「12 張業務表皆為空集合」不完整**：VWorld 的 `ia_bet_records` 有 `currency_id = 4`（VND2）**19,841 筆**、`ia_daily_win_losses` 有 **91,966 筆**（VND2）與 2 筆（KVND），這兩張表不在當初複驗的清單內。⇒ 變體 id **並非全域零使用**，只是不作為錢包幣別；「停用零遷移」的前提要重新評估。細節見 [`channel-wallet-currency-split-design.md`](channel-wallet-currency-split-design.md) |
| 四 | **歷史幣別要「填對」，不填預設值** | 遷移時 `INSERT ... SELECT` JOIN `xxx_accounts` → `vendor_infos.currency_id` 填對。**此決議只有新表遷移案能達成**，是目前支持新表遷移案最強的獨佔理由。實作利多：`ia_bet_records` 已有 `currency_id` 先例；ace333／aes／ag／epic_sw／isb／sbo／wm／ygg／ppsw_game_rounds 有 `currency`、dg 有 `currencyId`、pg 有 `Currency`、pt 有 `currency_code`（四種命名並存，須正規化） |
| 五 | **冷熱分離：熱表走實體表、冷表按月一張，套用既有 `AbstractArchive`** | 熱表＝最近三個月（`deleteMonths` 預設正好 3）；冷表＝每月一張 `{table}_{YYYYMM}`；逾期冷表自動 DROP。機制形狀吻合且已在生產運作（`ArchiveCommandLogs`／`ArchiveActivityLogs`，`routes/console.php:19-20`）。**但有七個缺口必須先補**（見下） |
| 六 | **SBR 走加欄位，但 `delete` 條件與 `GROUP BY` 同步加幣別** | SBR 不另建新表（量級只有 TBR 的 1/250、卻是 21 支報表來源）。因 SBR **沒有業務唯一鍵**、冪等靠 delete-then-insert，兩項配套為與 DDL **同批上線**的強制條件：① `GROUP BY` 加 `currency_id`（否則金額混算）② `delete` 條件加 `currency_id`（否則重跑刪掉另一幣別）。這段程式碼在 RD-Studio-01 |
| 七 | **`games` 與 `vendor_info_games` 都不加幣別欄位** | `vendor_info_games` 唯一鍵是 `(vendor_info_id, game_id)`，而 `vendor_info_id → vendor_infos.currency_id` ⇒ 幣別已隱含。`games` 尤其不該加——加了會讓 `game_id` 分裂，連帶 TBR 的 `game_id` 分裂，28 支報表的遊戲維度全要改 |
| 八 | **冷表原則上只用於查詢，唯一寫入路徑是指定的 artisan 命令** | 注單搬進冷表後視為唯讀；補帳走專用命令直接改冷表，**不放行「重跑轉檔 SP 補三個月以前的注單」**。同時關掉決議五的冪等紅線，並讓熱表按月分區不再新增風險敞口。另兩個方向同時否決：②「補跑前把冷表搬回熱表」③「建應用層冪等表」（後者改列技術債方向） |

### 決議五的七個缺口（套用 `AbstractArchive` 到 TBR 之前必須處理）

| # | 缺口 | 狀態 |
|---|---|---|
| 1 | 🔴 `chunkById` 游標是 `id > ?`、不含 `vendor_id` ⇒ 每頁掃全部 298 分區（約 206 萬頁×fan-out） | 解法：外層逐 `vendor_id`、內層才 `chunkById` |
| 2 | 規模差三個數量級（`activity_logs` 單日 20 萬列 vs TBR 20.6 億／842 GB），光 `usleep` 累積約 28.6 小時 | 首次歷史搬遷需另一套一次性工具 |
| 3 | `upsert($data, ['id'])` 的唯一鍵假設——TBR 有兩個唯一索引，ODKU 會同時看兩者 | **行為需實測驗證**，不可假設與 log 表相同 |
| 4 | `deleteOldData()` 用 `DELETE` 而非 `DROP PARTITION` | 08-27 有解：決議八已讓熱表按月分區不新增敞口 ⇒ 可用 `DROP PARTITION` 根治 |
| 5 | 🔴 `AbstractArchive.php:82` 整月搬遷包在**單一 transaction** | transaction 邊界必須重寫為逐批提交＋checkpoint |
| 6 | 🔴 TBR 的 `id` 是下游身份，**6 張表**存 `total_bet_record_id`，搬表後 relation 失效 | 需 hot／cold locator 或保留全域 ID 對照 |
| 7 | `dateColumn` 選哪欄、補跑時修哪張表 | 已定一半（改冷表、經指定命令）；**未定義的是「怎麼判定它已在冷表」** |

> ⚠️ **決議八未涵蓋的路徑**：擋的是「人為指定舊區間重跑」。但**遲結算**（很久以前下注、最近才結算）走的是**正常日跑**——時間區間落在最近三個月內、不算補跑，可是那列可能早已在冷表 ⇒ ODKU 一樣撞不到。純靠時間區間擋不住，這是缺口 7 必須定義的部分。
>
> ⚠️ 「`AbstractArchive` 已在生產跑所以可套 TBR」是**最危險的類比**——它證明的是小型月表的 archive lifecycle，不是 20.6 億列 bootstrap、跨表全域唯一與 late mutation 的正確性。

---

## 三、TBR／SBR 方案：現行狀態

> 出處 `#tbr-mech`。**命名已改**（08-27）：不要再用「方案 A／B」。

| 08-27 起的名稱 | 原稱 | 內容 |
|---|---|---|
| **原表加欄位案** | 方案 A | 在現有 TBR／SBR 上 `ADD COLUMN currency_id ... ALGORITHM=INSTANT`，不動分區、表名、唯一鍵 |
| **新表遷移案** | 方案 B（決議④） | 另建新表（保留全域唯一鍵、複合索引一次設計好），雙寫後切換 |
| **P5** | 原稱 P5-lite；**舊的「P5 原案」已被它取代** | 新表只裝**最近三個月（熱表）**，舊 TBR 原地當冷資料來源，切點後移出的資料進月份冷表、背景慢慢搬 |

- **狀態：待拍板，但 08-27 後實質倒向新表遷移案。** 天平傾斜的兩個原因：① 決議四要求歷史幣別「填對」，而 DEFAULT 只能填單一值 ② P5 讓最大代價（全量遷移綁住上線）消失。**會審仍需正式拍板，但不要照 08-19 的舊建議走。**
- **SBR 幾乎沒有討論空間** — 已由決議六定案走加欄位。真正需要技術決策的只有 TBR。
- **P5 換來三個新問題**：① 背景搬遷期同一筆注單可能同時在熱表與舊表，查詢入口需去重 ② 數週～數月的背景任務需 checkpoint 與續跑 ③ 三套結構並存期（舊 TBR ＋熱表＋月份冷表），查詢入口要能分辨。
- **熱表實際量級（08-27 實測）：3.55 億筆／145 GB**，比預期大得多。
- **分區選項處置**：P0 不處理（短期可接受）／P1 削減分區數（首選試驗）／P2 改時間分區（**否決**，破壞冪等）／P3 時間＋vendor 子分區（**否決**，同上）／P4 移除分區（**08-27 否決**）／**P5 正選**。

---

## 四、階段編號與閘門（08-27 已重編，舊編號作廢）

> 出處 `#plan`。簡報 08-27 從「兩期」改為**六期**，階段代號＝`期數.序號`（0.1～5.5）。

| 期 | 階段 | 交付 | 閘門 | 現在能動？ |
|---|---|---|---|---|
| **第零期**（新增） | 0.1～0.3 | 完整 writer inventory、canonical BR 清單、幣別／rate 版本契約、跨 repo owner 與 fencing 規則。**契約定版見 [`docs/multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md)**——幣別、`rate`、注單表清單、writer 清單（308 個寫入點的 owner 與並行防護；09-18 依 registry 實數更正，原寫 307）四項全數定版 | 無 | ✅✅ **已完成（09-01，九個 task 全交付）** |
| 第一期 | 1.0～1.2 | 寫入點收斂、錢包表結構、影子同步＋雙寫開關（**Task 1～8 ＋ 11～13**；Task 9 type=2 清除與 Task 10 改唯一鍵已於 08-28 移出第一期） | 無（第零期的幣別契約**已於 09-01 凍結**，前置滿足） | ✅✅ **已交付（09-01，11 項全數收官）** |
| 第二期（新增） | 2.1～2.3 | `currencies` 補幣別＋`code` 加 unique、`vendor_infos.currency_id`、`CurrencyEnum` 白名單、一致性 guard | 無 | ✅ 立即，須先於第一期影子資料 |
| 第三期 | 3.1～3.4 | TBR 新表建置（含 `currency_id`／`vendor_info_id` 兩欄，來源規則見決議⑭）、`AbstractArchive` 擴充、TBR ID relation 對策、單站單 vendor 垂直 pilot。**126 張 BR 表不加欄位（09-03 決議⑭），原 3.1「126 張 BR 幣別來源」改為「TBR 兩欄的來源規則」** | Gate 1 | Gate 1 拍板後 |
| 第四期 | 4.1～4.3 | 72 支 SP ＋ `event_record_pgs` 改 SELECT＋改目標、**所有 PHP writer 一併改**、SBR 加欄位＋配套 | Gate 2 | Gate 2 後 |
| 第五期 | 5.1～5.5 | 28 支報表加幣別維度、切換寫入／讀取、開放多幣別、舊系統改造、停寫舊欄位；**廢除錢包清除、唯一鍵改三元組**（08-28 自第一期移入，未另編階段代號，子單 `target/tickets#4250`）。**09-03 補盤——舊系統改造的實際範圍**：四個舊 repo 的 `member_wallets` PHP 寫入點 **222 個**（09-03 口徑；**分 repo 明細、單分支下界與 `target/support` 加計以影響盤點 7.1 為單一來源，本檔不重述**——決議⑰）（API_External／API_Frontend 一白標一分支、程式量差數倍，須先拍板盤哪些分支）；30 個以上建立點全要補幣別（來源兩跳：會員→上級代理→組織，每跳都可能靜默退化，解析到 0 必須中止）、四 repo 讀取面全要帶幣別（API_External 129 處，其餘三 repo 只有計數）；另六站生產每站 16～19 支 SP 直寫且 INSERT 明列欄位。舊「126 個」作廢。逐筆清單見 [`multi-currency-wallet-impact-inventory.md`](multi-currency-wallet-impact-inventory.md) 第七節 | Gate 2 ＋觀察期 | 最後 |

**新舊代號對照**：舊 `1.0/1.1/1.2` 不變；舊 `1.3` 內的主檔治理抽出成 `2.1～2.3`；舊 `1.3 注單鏈路` 拆成 `3.1～3.4 ＋ 4.1`；舊 `1.4 統計與報表` 拆成 `4.2 ＋ 5.1`；舊 `2.1～2.4` 重編為 `5.2～5.5`。

### 閘門定義（**Gate 2 的定義已被推翻，注意**）

| 閘 | 內容 | 狀態 |
|---|---|---|
| **Gate 0** | 分區表能否 `ADD COLUMN ... ALGORITHM=INSTANT` | ✅ 08-19 已完成並通過 |
| **Gate 1** | TBR／SBR schema 設計定案 | 🔴 待拍板（見第三節） |
| **Gate 2** | 🔴 **不是「等 RD-Studio-01 排程遷入」**。現行定義＝**找全所有 writer → 指定 owner → 同一 vendor×時段 fencing → 驗證覆蓋率** | ✅ **已解除（2026-08-31 RB 拍板）**。308 個寫入點（09-18 依 registry 實數更正，原寫 307）全部指定 owner 與並行防護，`artisan bet-record:audit-writers` 退出碼 0。第四期可開工，須帶三項風險（見 `docs/multi-currency-wallet-phase0-contract.md` 第六節） |

> **為何 Gate 2 改定義**：08-27 實查發現 TBR／SBR 的 writer 遠不止 72 支 SP，本 repo 內另有 `TotalBetRecordService`、`EventTotalBetRecordService`、`SummaryBetRecordService`（＋Wesw／Motivationsw 兩支 vendor 專用）、`BetRecordRecovery/Revert`、`Patch/BackfillMemberHourSummaries`、`Vendor/PullBetRecordCommand`、`Vendor/PlayTech/ReconcileBetAccumulation`、`Vendor/PragmaticPlay/FixRecords` 等。只改 72 支 SP 會留下一批把 `currency_id` 寫成 NULL／DEFAULT 的路徑。（`#codex-review` 缺口一）

---

## 五、08-18／08-19 會議決議（仍有效的部分）

> 出處 `#open`。與 08-27 決議衝突處，一律以 08-27 為準。

> 🔴 **2026-08-28 改為逐條列出。** 原本這一節把 ①②③⑤⑥⑦⑨⑩⑪⑬ 十條壓成一行「編號串 ＋ 分號分隔的內容串」，讀者只能靠位置一格一格數著對應。**08-28 補寫各 task 依據時就因此對位滑格，把 ⑨ 寫成 ⑩、② 寫成 ③**（三處，已修）。壓縮格式省下的行數，遠不及對錯編號的代價。

| # | 決議 | 狀態 |
|---|---|---|
| **①** | 第一期包含所有 schema 異動＋資料同步，**同步走 Queue** | 有效 |
| **②** | 原 2.1「寫入點收斂」屬程式重構、非資料同步，**提前為第一期 1.0** | 有效 |
| **③** | 第二期規模過大，**分階段獨立提交**，並設計新舊錢包切換開關 | 有效 |
| **④** | 原「不動舊表、另建新表＋依月份分表＋近三個月 View」 | 🔴 **已被 08-27 取代**——兩個技術前提實測皆不成立；View 查詢入口亦已否決。現行結論見第三節 |
| **⑤** | `member_wallets` 使用中的三種類型全部拆分幣別 | 有效，但**類型清單已由 ⑬ 更新**（原列 1／1002／1003，定案為 1／3／1002） |
| **⑥** | 受影響的 SP 原則上**改寫為 PHP 走 Queue**，不再修補 SQL | 有效 |
| **⑦** | 活動與禮碼納入影響範圍：活動清單隨當前幣別過濾、同一活動依幣別各建獎勵設定、限制會員不得同時跨幣別參加同一檔活動 | 有效 |
| **⑧** | `member_wallets` 的 type=1／2 整批清除（VWorld 1,053 個會員合計 121,991.62 元銷帳） | 已定調。執行前四件事：① 備份快照與清除記錄 ② 知會業務／財務側留痕 ③ **排在改唯一鍵之前**（⚠️ 原寫「排在 1.1 改唯一鍵之前」，改唯一鍵已於 08-28 移出第一期、推到第四期之後，**時序條件跟著放寬**）④ **同時關閉自動建立來源**（註冊流程仍在自動建 type=2） |
| **⑨** | **觀察期最長 2 個月**，並以**排程每日校驗**新舊資料作為無縫接軌的驗證依據 | 有效 |
| **⑩** | 每一期的小階段保持獨立、**可先行交付正式環境**而不影響整體功能 | 有效 |
| **⑪** | 新舊錢包同步（影子同步＋雙寫開關）**全部整合在第一期 1.2 交付**，開關預設關閉、觀察期開啟驗證 | 有效 |
| **⑫** | 開工前提：RD-Studio-01 的排程須先全數遷移到 APIs | 🔴 **已被 Gate 2 新定義吸收**，見上一節 |
| **⑬** | 錢包類型定案（08-19）：**一般錢包(1)／活動錢包(3)／禮碼錢包(1002)** 三種，全部多幣別；**type id 不重編**（id 是跨系統契約：舊系統直寫、SP 硬編、兩站合計約 70 萬存量列）；重新規劃落在 Enum 語意層；type=2 與 1004 廢除，~~1003 趨近停用待 PM 裁決~~ **1003 已於 09-03 會議裁決廢除（前置見 ⑮）** | 有效 |
| **⑭** | **各遊戲商注單表（`xxx_bet_records`）不加 `currency_id`／`vendor_info_id`（09-03 會議，RB 09-03 拍板）**。兩欄記在 TBR，於 TBR 建立當下決定：即時寫入點取回調載入的商戶（`$account->vendorInfo`）；轉檔 SP 取既有 `xxx_accounts` → `vendor_infos` join。**理由**：加欄位的 DDL 秒級不難，難的是 126 張表的寫入路徑（四個舊 repo＋RD-Studio-01）從此持續填值、歷史回填（BR 建表 migration 117 支中 104 支無 `member_id` 須繞帳號表；舊文件寫「123 張中 107 張」是含非建表檔的口徑，兩數同源）與 12 張破百萬筆大表建索引；而 BR 層沒有任何按幣別的消費者（報表全讀 TBR／SBR）。決策**可逆**：日後要加 `ALGORITHM=INSTANT` 隨時可補，只是歷史列為預設值。**前提六站實查（09-03）**：所有遊戲商帳號表皆有 `vendor_info_id`（缺欄的只有銀行帳戶／抖音／鬥雞／`event_*sw` 活動帳號表）；72 支 `SP_transfer_bet_record_*` 有 71 支已 `JOIN vendor_infos ON vi.id = v.vendor_info_id`，剩 `pp_event` 用的 `pp_accounts` 亦有該欄。**重啟條件**（任一成立重開）：①證實遊戲帳號會改綁到不同商戶且需要從 BR 重建歷史 TBR；②出現 BR 層按幣別的消費者。**連動**：「126 張表的分批策略」作廢；第三期 3.1 改為 TBR 兩欄來源規則；大表 DDL 設計決策 10 補 `vendor_info_id`。⚠️ 「126 張」沿用規劃期口徑（第零期 0.2 定版口徑＝registry 141 筆／`kind=vendor` 120 張，見 `docs/multi-currency-wallet-phase0-contract.md`），本條講的是「不加欄位」，不論哪個口徑結論相同。⚠️ 活動注單另一條線：`event_*sw_bet_records` 寫的是 `event_total_bet_records` 不是 TBR，主檔 `event_vendor_infos` 只有 `currency` 字串無 `currency_id`——~~**是否納入第二期主檔治理登記待拍板**~~ ⇒ 09-07 已由決議⑲裁決納入第二期（只做技術對齊，主檔加欄已進版控） | 有效 |
| **⑮** | **1003 排行榜錢包廢除（09-03 會議 PM 裁決到位，RB 09-03 確認）**；三種錢包定案不變＝主錢包(1)／活動錢包(3)／泥碼錢包(1002)。⚠️ 09-03 會議曾提「泥碼改 type 2」，**RB 裁定維持 1002**：2 被廢除遊戲錢包存量佔用（六站 09-03 實查 type=2 合計 332,910 列＝VWorld 75,374／TPower 70,428／Yes2Win 133,542／Alfawin77 36,786／Me77 11,313／VPlus33 5,467；VWorld 1,053 筆非零餘額；VWorld／TPower／Yes2Win／Me77 四站 09-02 仍被舊系統寫入）、13 支 SP 硬編 `WalletTypeId = 1002`、舊 repo 126 個寫入點引用（09-03 重盤 222 個（單分支下界），數字以影響盤點 7.1 為單一來源；數字變大只讓本條理由更強）——重用會讓過渡期「舊 2＝遊戲錢包」與「新 2＝泥碼」不可區分。**1003 存量（09-03 六站實查）**：TPower 295,148（每日固定時點被 `SP_reset_refresh_ranking_wallet` 重置——`updated_at` 觀測值 09-02 08:00:01，SP 內註解的 EVENT 範本寫 UTC 16:00，**實際排程器與時點未驗證**，須查生產 `information_schema.EVENTS`）／Yes2Win 11,746（04-01 起無更新）／VWorld 1,770（2025-01 起無更新）／Me77 2（皆非零餘額）／Alfawin77、VPlus33 0。全 repo 硬編 `wallet_type_id = 1003` 的 SP **只有 `SP_reset_refresh_ranking_wallet` 一支**（`SP_set_lottery_ticket_number` 內的 1003 是錯誤碼，不是錢包型別）。**廢除前置（實作另排，不進 MR !2109）**：①查明並停用 TPower 每日重置排程；②`EventPpswController` 五處 `getMemberEventWallet()`／`getLockedMemberEventWallet()` 改走活動錢包(3)——這兩個入口帶 `?? throw LogicException` 守衛，1003 進 `deprecatedTypes()` 後會顯性中止；③`RefreshRankingService.php:242` 直呼 `getOrCreateWallet(CODE_EVENT_RANKING)` **不經守衛**，deprecated 型別查無列時回 null、下一行取 `->balance` 是 null 屬性存取的致命錯誤，須先停寫或自補判斷；④Me77 2 筆餘額處置。順序：①②③完成前**不得**把 1003 加進 `WalletEnum::deprecatedTypes()`。圓桌登記的「1003 雙建立者起始餘額競態」技術債隨廢除作廢 | 有效 |
| **⑯** | **改唯一鍵（計劃 Task 10）的閘門加第 4 項「該站舊 repo 的 `member_wallets` 建立點已全部帶幣別並發版」，且四項一律逐站判定（09-03 圓桌 timo＋rorin 交叉命中，RB 09-04 認可）**。逐站判準＝該站舊 repo 對應分支已發版＋該站 SP 清單（主庫 `information_schema.EVENTS` 與 SP 互相 `CALL` 交叉後仍有呼叫者的）已改完＋該站「同 `(member_id, wallet_type_id)` 多列」稽核為零＋該站兩元組唯一索引存在且 `SHOW CREATE TABLE` 欄位集合已比對；`DROP INDEX` 與 `ADD UNIQUE` 必須同一句 `ALTER TABLE`。理由：原三項只擋 APIs 與 SP，舊 repo 30 個以上建立點（單分支下界）未改完就改鍵，每天約 700 筆分裂列（21,246／30 天推得）且不報錯無告警。原文見 [`wallet-type2-cleanup-and-unique-key-plan.md`](wallet-type2-cleanup-and-unique-key-plan.md) Task 10 前置條件（09-18 自第一期計劃搬出），止血與回滾見影響盤點 7.6 | 有效 |
| **⑰** | **盤點數字單一來源制（RB 09-04 拍板，rhyse 圓桌提議）**：舊 repo `member_wallets` 寫入點的數字（目前 222 個寫入點／30 個建立點，09-03 口徑）以影響盤點 **7.1 的表為唯一權威**；設計文件、本檔、計劃、第零期契約、`writers.php` 註解等其他位置只寫總數＋口徑日期並指回 7.1，**不重述分 repo 明細**；重盤時只改 7.1 並更新口徑日期。適用範圍先限本案的舊 repo 寫入點數字。**09-06 RB 擴大射程**（Bonn 校閱後裁決）：舊 126 口徑的分 repo 明細與 API_External／API_Frontend 各分支命中數，同樣只在 7.1 出現（7.1 表有「舊數字」欄與分支命中數表），其他處只寫「舊口徑 126」或指回 7.1；讀取面明細（API_External 129 處等）**暫不比照**、SP 支數、資料表數等其他數字是否比照，下次重盤前決定。**09-07 再擴大**（Task 12 圓桌 Bonn 校閱後）：孤兒 parent／會員幣別解析鏈斷點的六站基準值，唯一權威＝影響盤點第八節；其他處只寫結論並指回，例外只有決議㉓的判準式與送 PM 的標的金額 | 有效 |
| **⑱** | **孤兒 parent 治理進第二期（RB 09-07 拍板，09-01 圓桌批次二 marz 提出）**：TPower 約 60.9% 會員的 `members.parent` 指向不存在的代理、幣別解析鏈第一步就斷，第二期補 task「六站量測＋歸屬提案」並**排在 Task 4 之前**——唯讀量測換準確基準值（圓桌只登記粗略量級、資料會漂移）＋歸屬判定提案，比照 Task 7 **只提案不改資料**，資料處置另拍板。理由：它是啟用第一期雙寫的前置，晚做會讓影子同步寫出大量 sentinel 0 | 有效 |
| **⑲** | **`event_vendor_infos` 納入第二期（RB 09-07 拍板）**：與 `vendor_infos` 同批加 `currency_id`、回填、一致性 guard，**只做技術對齊**（主檔列數少、成本低，避免活動線之後獨立再走一輪 DDL＋守衛）；「活動注單要不要分幣別」的業務決策仍交 PM，不被這步綁死。✅ **09-07 版控已落地**（migration `2026_09_07_000003`，索引 `event_vendor_infos_currency_id_index`；六站 `event_vendor_infos.currency` 清一色 MYR、合計 16 列），六站生產待部署；回填已隨 Task 6 進版控（`currency:backfill-vendor-info` 兩張表同批，09-07，生產待部署後才跑）、guard／稽核納入待 Task 9／10 | 有效 |
| **⑳** | **BND 暫不補進主檔（RB 09-07 拍板）**：VWorld 18 個商戶全停用（09-07 實查 active 0）、無回填需求；主檔列依決議三永不刪，不為「疑為」加永久列。待 Task 7 判定 `vworldbn`（573 啟用代理）幣別後，若為 BND 以與 PHP／USD／CNY 相同改法補 id 25 並同步搬到 `standard` | 有效 |
| **㉑** | **後台幣別欄輸入端驗證併入 Task 11（RB 09-07 拍板，契約 `docs/multi-currency-wallet-phase0-contract.md`「後台輸入端」段的第二期要求）**：Filament `VendorInfoResource` 的 `currency` 欄目前只有 `required`（自由文字），改為只接受 `currency_aliases.php` 的 standard∪unit_variant∪crypto∪non_currency 代碼；同一輸入端一次改完（正規化存量＋擋新髒寫法），契約改了驗證自動跟 | 有效 |
| **㉒** | **`currencies.code` 唯一鍵上線順序（RB 09-07 拍板，Timo 圓桌 Major）**：`API_Agent` 後台 `currency/add`／`edit` 的驗證規則缺 `unique:currencies,code`，唯一鍵先上會讓後台撞 code 時吐原生 SQL 訊息（`QueryException` 被 catch、HTTP 200、不會 500）。**先補 API_Agent 驗證並上版、再部署唯一鍵 migration**；API_Agent 分支 `fix/1267-currency-code-unique-validation` 已於 09-07 合進 master（merge commit `ca91f92ec`；API_Agent 走 Google CSR、無 MR），各站 prod 分支 push 屬 ops，**六站上線前不得部署唯一鍵 migration** | 有效 |
| **㉓** | **孤兒 parent「歷史死帳」的工程面規則（RB 09-07 拍板，Task 12 提案 B）**：TPower `parent_missing` 全群 ＋ TPower／VWorld 同源 `parent_zero` 全群（數量見影響盤點 8.1～8.3；全部非啟用或從未登入、2024-05-30 後零活動、無可推回線索）定義為歷史死帳——影子同步與各建立點對此群**維持 sentinel 0、不告警、不回填、不擋雙寫啟用**；判準＝`members.status <> 1 OR members.last_login IS NULL OR members.last_login < '2024-06-01'`（🔴 `last_login` 可為 NULL，parent_zero 那 238 列啟用中會員兩站實查全是 NULL，少了 `IS NULL` 會把它們判成活人——09-07 圓桌 Bonn 抓到、已改），其餘一律視為活人、解析不出即告警。資料層處置（停用／歸檔／刪除）另立工單；**持禮碼餘額的孤兒（09-07 口徑 3,869 名、合計 74,055.49，見盤點 8.2）可否作廢送 PM，未判定前不得動資料**。A 類 1 名併 C（Me77 3 名）人工處理——✅ **09-08 RB 拍板：Me77 members 3／4／5（parent 100004／100003／100003）確認為建站測試帳號、處置＝停用**（09-07 唯讀實查：零錢包、零注單、2025-06-17 建立當日登入後零活動，agents 100002～100004 缺號＝建站期建了又刪的代理；執行不裸寫 DB，走後台或 op 工單，執行後唯讀複驗）——✅ **09-08 04:17Z 已由 RB 從後台停用，唯讀複驗三列 `status=0`、`parent`／`last_login` 未動，C 類收官**；D 類組織幣別歸 Task 7／組織幣別補齊。數字單一來源＝影響盤點第八節 | 有效 |
| **㉔** | **`HKB`／`MSC` 代碼歸屬（RB 09-07 拍板，Task 4）**：`HKB` ＝ HKD 的**拼寫變體**（VWorld 唯一商戶 id 227「PEGASUS-HK(單錢包)」啟用中的代理連結只有組織 PGSHK、`currency_id=16`＝HKD，另有已停用連結掛 MMGames／vworld；69 個帳號全屬 PGSHK；rate 1 故非單位變體）⇒ `currency_aliases.php` `normalize` 加 `HKB → HKD`、currency_id 回填為 16，**字串本身另由 ops 走後台改成 HKD**（`target/op_tickets#3080`，✅ 09-07 已改並唯讀驗證；前置已查：該商戶 `info` 無 CURRENCY 鍵、PegasusSW 驅動與 controller 不讀 currency）。`MSC` **保留 `unresolved` 不動**（站名誤填進幣別欄、商戶已停用、151 帳號止於 2020-12，不阻斷回填；稽核持續列 unresolved 屬預期）。證據全文＝第二期計劃 Task 4 執行結果（該段已隨 09-18 縮減刪除，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-phase2-plan.md`「Task 4 執行結果」段） | 有效 |
| **㉕** | **幣別設定改用新表，比照代理組別模式（RB 09-08 拍板改用新表；🔄 **09-09 RB 改掛載層級**；09-08 經 Bonn 校閱修訂一次）**：🔄 **09-09 起掛代理組別、不掛組織**——比照 `agents` 既有的 `vendor_customize_group_id`／`cash_flow_group_id` 模式：`agents` 新增 `currency_group_id` 指向 `agent_groups`（新增 `agent_group_type = 8`「幣別設置組別」；🔴 **號碼是 8 不是 7**——`AgentGroupTypeEnum` 已登記 `EMAIL_GATEWAY = 7`，09-09 Bonn 校閱抓到；⚠️ 原稿據「六站資料 type 最大值皆為 6」推論 7 是空號，該推論錯誤：登記處是代碼的 Enum 不是資料，電子郵件組別已上線但六站尚未建列），明細表 `agent_group_currencies` 記「這個組別允許哪些幣別」，其中一列標記為預設；**新會員的錢包幣別改看這裡**。解析鏈＝`members.parent → agents.currency_group_id → agent_group_currencies(is_default=1, status=1) → currencies.id`，⚠️ **跳數與舊鏈相同（都是兩跳）**——換來的是粒度不是效能（09-09 Bonn 校閱更正原稿「少一跳」的說法）。⭐ 粒度變細的實益：同組織下不同代理樹可各自設定（vworldbn 組織另有 `vsmy88` 樹）。⚠️ 代價＝**六站十萬級代理要回填 `currency_group_id`**（VWorld 76,997、TPower 44,725，09-09 實查），且建代理的路徑要決定帶哪個組別（掛組織時新代理自動繼承、不必補）。🔴 `agents.currency_group_id` **預設值取 0 而非指向真實組別**，刻意偏離 `customized_setting_group_id` 預設 1／`cash_flow_group_id` 預設 3 的慣例——指向真實組別＝新代理靜默套用某個幣別，正是本決議要避免的。🔴 **查不到預設幣別就拋錯、不給任何墊底值**——留墊底就等於「填錯」和「沒填」在執行期沒有差別，那正是舊欄位變虛設的機制。靠上線順序保證資料齊全（🔄 **09-09 起是五步，見本格後段**；原四步「建表 → 每個組織建列 → 稽核 → 切換」隨掛載層級改變作廢）。🔴🔴 **只改 `Member::currencyId()`，`Agent::currencyId()` 一個字都不動**——`agents` 表沒有 `currency_id` 欄位，`MemberRegisterLimitService.php:247` 與 `MemberFieldSettingService.php:433` 寫的 `$agent->currency_id` 走的就是 `Agent::currencyId()`，**動了它，註冊限制與註冊欄位設定會跟著改讀新表、副作用原封不動回來**（09-08 Bonn 校閱抓到，原條文寫「同步改讀新表」是錯的）。`organizations.currency_id` **本期不改它的值**，且它不是「只服務舊系統」——APIs 端還有 `Agent::currencyId()` 與 `currency:audit-orphan-parent` 在讀，舊後台則是股東報表（`API_Agent/.../Shareholder/V3/ShareholderController.php:1124-1133`／`:1350-1357` 取組織幣別查匯率、`:1368` 換算成美元；09-08 實讀驗證）。⚠️ `currency:audit-orphan-parent` 判「組織幣別為 0」讀的是舊欄位（`AuditOrphanParentCommand.php:90`），**切換後要跟著改判新表**，否則那一類永遠不會歸零。已退場的組織**不建列**，其底下建錢包會拋錯——⚠️ vworldau 尚有 2 個啟用代理與 2 名啟用會員，此處置與「靜默維持 0、不告警」相反，**待 RB 確認**；🔴 **不得引用決議㉓ 為它背書**，決議㉓ 條文自己寫「組織幣別為 0 那一類歸 Task 7」＝不在其射程內。第一版只讓「預設幣別」生效，允許清單不當關卡（留第五期 5.3）；🔄 **09-09 起上線改五步**（建表與加欄 → 建組別 → 建幣別明細 → 回填代理 → 稽核歸零後才切換），屬**第二期 Task 7 的延伸**。🆕 掛組別新增一種錯法＝**掛錯組別會拿到別的組織的幣別**，稽核要查「組別的組織 vs 代理的組織是否一致」。🔴 **09-09 連帶影響盤點（設計文件第 9 節）**：對 19 項設計決策的衝擊＝**兩項需拍板**（決策 13 會員互轉的「同組織」檢查判不準了，同組織不等於同組別；決策 5 新建下層代理要不要繼承上層組別——掛組織時不存在的題）＋**兩項判定來源要換**（決策 8 支援幣別查組別、決策 11 建錢包失敗模式改為拋錯）；**第二期範圍實質變大**＝多出 `agents` 加欄位（十萬級表）、十萬級代理回填、建代理路徑要改；**第五期 5.3 的關卡對象**由組織改為組別。其餘 14 項不受影響。**本決議是設計決策 6 的修訂**：前半句（新增一張表）是細化，**後半句「組織幣別欄位保留當預設值」被推翻**。**不影響設計決策 5**（代理綁定遊戲商帶幣別），兩者管不同的事。細節見 `agent-group-currency-table-design.md` | 有效 |
| **㉖** | ⛔ **09-17 整項撤回，見決議㉚**。已進 master 的兩處落地（`database/data/currency_aliases.php` 的 `'USDT' => 'USD'`、`CurrencyEnum::walletExcluded()` 把 USDT 排除）要改回，屬程式變更，待 RB 看過文件後開工。以下為原文。<br>**USDT 對應 USD（RB 09-09 拍板）**：原本懸著的「加密貨幣是否為錢包幣別」到此解除——**USDT 就是對應 USD 幣別**。落地比照決議㉔ 的 `HKB → HKD`：`database/data/currency_aliases.php` 的 `normalize` 加 `USDT => USD`，並把 `USDT` 從 `crypto` 分類移出；`vendor_infos.currency` **字串不動**（字串直接進三方 payload 是既有約束），只讓 `currency_id` 解析成 USD（版控 id 23）；GFF USDT 組織（VWorld 20，978 名啟用中會員（該組織 09-07 仍有登入））的幣別組別預設幣別＝USD。🔴 **TON（id 20）與 BUSD（id 13）不比照**——TON 換算率 1.36～1.8（代收 1.36／代付 1.5，實測區間）、不錨定美元，當成 USD 會讓金額尺度直接錯掉；BUSD 主檔已是停用狀態。兩者維持 `crypto` 分類、不作為錢包幣別。`currencies` 主檔的 `USDT`(10) 保留該列、本期不動，它不再是解析目標；⚠️ **它現在既不是標準幣別也不是解析目標，與決議三「主檔只收標準幣別碼」有張力，處置（停用或留著）懸著待決**。🔴 **部署空窗期會出現稽核假警報**（09-09 Bonn 提出）：別名契約上線後、該站補上 USD 主檔列之前，USDT 的判定由 `crypto`（不阻斷）變成 `enum_only`（`currency_id=null`），而它有 2 家啟用商戶 ⇒ 依 `AuditVendorInfoCurrencyCommand.php:73-79` 會計入 `blocked_codes` 並回非零退出碼＝**每日稽核由綠轉紅**。要先告知 ops 值班這是預期的部署空窗、不是新缺陷；**最乾淨的作法是別名契約與主檔 seeder 同批部署**。⭐ **旁證**：4 家 USDT 商戶全是 `*-fongamer` 業務線，而 USD 商戶也有一大批同業務線的（PNG／JDB／FaChai／CQ9／PGSoft／PPlive／SG）＝同一條線只是填了不同字串；✅ 實查**無任何 `vendor_id` 同時掛 USD 與 USDT**，正規化不會打破「一幣別一商戶」。影響面（09-09 VWorld 唯讀實查）＝`vendor_infos` 的 `USDT` 商戶 4 家（2 啟用）、`USD` 29 家（14 啟用），**兩者 rate 皆為 1 ⇒ 不需要任何金額換算**（與 GFF USDT 帳本推算的「USDT 通道 rate 1」一致）；`TON`／`BUSD` 在該表零商戶。🔴 **前置**：VWorld 生產庫**尚無 `USD` 這一列**（09-09 實查該站 `currencies` 只有 USDT 10／FON 11／BUSD 13 停用／TON 20），USD(23) 是 09-07 才進版控的，**要等 MR !2156 合併並部署到該站**，這條裁決才落得了地 | 有效 |
| **㉗** | **代理幣別組別的繼承方式與後台形狀（09-09 釐清；未改動決議㉕ 的架構，只補它沒交代的部分）**：① **繼承＝建代理時從上層複製 `currency_group_id`**，不採「讀取時沿代理鏈往上找」——`agents` 上現有七個組別欄位全部是複製式（舊系統 `AgentController.php:589-597` 有註解「以下這些設定 預設會與上級一致」；VWorld 七萬多代理中三個組別欄位停在 DB 預設值的是 0 個）。沿鏈是 `agent_vendors` 這類**明細表**的機制，用在單一欄位上會讓它變成 `agents` 上唯一讀法不同的欄位，且後台無法用一句 SQL 反查「哪些代理屬於這個組別」。② **`agents.currency_group_id` 必須建索引**——七個同類欄位每個都有專屬索引，後台依組別篩代理靠它（`AgentController.php:179-180`／`:1658-1663`）。③ **改幣別清單會自動傳播、改代理指向不會**——建代理複製的是組別編號不是幣別清單本身，所以「父層加一個新幣別」零同步動作；但換組別**不影響已存在的下層**（實證：76,996 對父子中 `customized_setting` 有 156 對不一致；2026-08-03 有過一次子樹批次展開），因此指派端點要帶「是否套用到下層」選項，前例是 `AgentVendorController` 的 `data_switch`。④ **後台功能建在 APIs**，舊系統只加建代理那三行（`:595`／`:786`／`:959`）＋ APIs `ShareHolderService.php` 一行——新表與 `type=8` 碰不到舊系統既有邏輯，先例是 `type=7` 的 `EmailGatewayGroupSettingController`。⑤ **後台用「自動複製組別」隱藏組別層**，否則「只想關掉一個幣別」要走建組別→複製整套→改一列→指派→處理下層五個動作。🔴 **回填的前置條件＝建代理四個入口先改好並發版**，否則回填完又漏出新的 0、稽核永遠歸不了零。細節與查證數據見 `agent-group-currency-table-design.md` 第 11、12 節 | 有效 |
| **㉘** | **名詞正名與五項執行裁決（09-09）**：① **名詞表落在 repo 根目錄 [`CONTEXT.md`](../../../GLOSSARY.md)**（現已改名 `GLOSSARY.md`）——幣別 8 詞、代理與組別 3 詞、金額換算 5 詞，各附該避免的同義詞；設計文件只留「概念→欄位」對照，定義以 `CONTEXT.md` 為準。🔴 **`agent_cash_flows.rate` 正名為「入帳比例」，它不是匯率**——是匯率 × 買賣價差 × 通路係數相乘的結果（TON 代收 1.36／代付 1.5 是價差、Telco 0.8 是通路係數、啟用中 USDT 通道 20 列有 12 種 rate），方向固定為「1 單位**通道幣別** → 多少**錢包幣別**」（`OrderService.php:76` 直接相乘，方向寫反就是金額直接錯）。② **會員互轉**：幣別用轉出方的，並擋「收款方的**可用幣別**不含它」。⚠️ **這是行為變更**——現行零歸屬檢查（`assertMemberTransferAllowed()` 只查稽核流水／活動 V2／鎖分／實名），實測 TPower 跨代理互轉有 36% 發生在不相干的代理分支，要送 PM 決定錯誤訊息。③ **意圖旗標第一版就加**：`agents.currency_group_inherited`，回填時一律填「繼承」，子樹批次套用只覆蓋繼承來的（理由：那 156 對父子不一致至今無法分類，幣別線不重蹈覆轍）。④ **`agent_cash_flows` 必須加 `wallet_currency_id`**——多幣別的目的正是打破「一個代理只服務一種錢包幣別」，「用支付組別分幣別」的替代做法與此直接衝突；但**回填規則等盤點報告**再定。⑤ **入帳比例的拆解（匯率統一查 × 每列調整係數）另立議題**，不綁上線路徑——拆的前提是營運逐列說明 268 個數字含不含加成。🔴 **通道幣別 ≠ 錢包幣別**：MYR 錢包用 USDT 通道入金是常態，任何「錢包幣別必須在通道幣別範圍內」的檢查都是錯的（本輪一度提出、當場作廢）。🔄 **但「`rate = 1.0` 推導錢包幣別」是可行的，前提是先排除代付通道**——`agent_cash_flows.rate` 只在代收用（`OrderService.php:76`），**代付完全不讀它**（`WithdrawalPayoutTrait`、`WithdrawalPayoutManualService` 內無 `rate` 字串，`withdrawal_payout_records` 也沒有該欄位）。排除代付後，原本 6 個「有兩種 `rate = 1.0` 幣別」的支付組別只剩 **3 個**（28、37、89；70／93／634 的第二種都是代付通道），且那 3 組的異常列**全部零成交**：`agent_cash_flows.id = 31`（組別 28）與 `id = 49`（組別 37）皆為 Autobank 代收馬幣、**零訂單**，`id = 152`（組別 89）AllPay 代收 PayMaya、13 筆全停在 `OrderStatusEnum::CREATED`（2024-03～2025-02）零筆上分；另 `id = 97`（組別 634，名稱寫「AllPay代付 (MYR)」卻設成 THB）亦零筆。⇒ **④ 的回填可以自動推導**（取代收通道中 `rate = 1.0` 的幣別），只有這 3 組需人工確認，**不必等盤點報告**。~~⚠️ **仍未決**：`agent_cash_flows` 目前**完全沒有唯一鍵**，加欄位時要不要補、補成什麼組合未討論。~~ ✅ **09-10 已由決議㉙ 裁決：不補唯一鍵**。⚠️ **未驗證**：`withdrawal_payout_records` 沒有 `agent_cash_flow_id` 索引，依該欄位查詢在 VWorld 實測耗時 **94 秒**，要撈代付明細須另建索引或改走其他鍵。細節見 `agent-group-currency-table-design.md` 第 11.5 節 | 有效 |
| **㉙** | **`agent_cash_flows` 不補唯一鍵（RB 09-10 拍板）**：加 `wallet_currency_id` 時**不補唯一鍵**，只補非唯一複合索引 `(agent_group_id, status, wallet_currency_id)`（涵蓋現有 `idx_group_status` 前綴、可取代它）。🔴 **理由：這張表在業務上不存在唯一性**——一列 ACF ＝ 一個金流商**商戶帳號**，不是一條通道；同組別、同金流商、同通道幣別本來就可以並存多列，差別在 `name` 與 `info` 裡的商戶憑證。加 `wallet_currency_id` 也救不了，那些列的錢包幣別本來就相同。**兩站實測（09-10，`deleted_at IS NULL`）**：`(agent_group_id, cash_flow_id, currency_id)` 在 VWorld 撞 48 組／142 列（最多 10 列）、TPower 撞 50 組／162 列（最多 8 列）；加 `cash_flow_info_id` 幾乎無效（該欄 TPower 264/264、VWorld 286/323 都是 0）；再加 `name` 才降到 VWorld 8 組／16 列、TPower 1 組／2 列。⭐ **而那 8 組同名列經查證全非重複**——逐列比對 `info` 的 MD5（只比雜湊、不讀憑證內容），12 列得到 **12 個相異雜湊**（組別 95 的 id 61/62 與 59/60、組別 116 的 76/77 與 74/75、組別 674 的 162/163、組別 688 的 132/133），TPower 唯一那組（組別 688、id 132/133）亦 `distinct_info = 2` ⇒ 是同一家金流商的**兩個商戶號**（分流／備援）。真正的區辨鍵是 `info` 這個 TEXT 憑證欄位，進不了唯一索引也不該進。最極端的合法多列：VWorld 組別 3 與組別 6 各掛 **10 條 AllPay MYR 通道**且全部啟用。⚠️ **索引效益誠實標註**：此表僅 300 列上下，索引現階段效能效益接近零，加它是為多幣別拆列後的膨脹先留位置。⚠️ **軟刪除與唯一鍵的既有張力**（本次順帶查明，不影響本裁決）：`agent_cash_flows` 有 `deleted_at`，TPower 有 4 列軟刪且與有效列在 `(agent_group_id, cash_flow_id, currency_id)` 上相撞——若日後有人再提補唯一鍵，這是第二道障礙。細節見 `agent-group-currency-table-design.md` 第 11.6 節 | 有效 |
| **㉚** | **USDT 是獨立的錢包幣別，不對應 USD；TON 這類不錨定美元的虛擬貨幣也可以當錢包幣別（09-17 會議，RB 拍板）**。理由：USDT 在資料庫裡比 USD 早存在，且未來可能有客戶要求用 TON 或其他非主流貨幣，幣別定義要保持彈性。哪些幣別能開戶，由營運在後台勾選決定（見決議㉝），**程式不再寫死排除名單**。連帶：① 決議㉖ 撤回；② GFF USDT 組織的幣別填 USDT；③ PM 摘要第 8、9、10 題結案（原建議「只有錨定美元的穩定幣比照 USDT」不採用）。⚠️ TON 的入帳比例是 1.36～1.8，它成為錢包幣別之後，TON 通道進 TON 錢包的比例應為 1，現有比例是「TON 進美元尺度帳本」的數字，開放前要重設 | 有效 |
| **㉛** | **`currencies` 主檔做一次清洗，六個白標共用同一份 seeder（09-17 RB 拍板）**。① **清除七個不是幣別的代碼**：單位變體 `VND2`(4)／`KVND`(5)／`IDR2`(7)／`KIDR`(8)、付款方式 `GCASH`(12)／`PayMaya`(15)、站台點數 `FON`(11)。清完之後主檔每一筆都是真幣別，後台勾選清單不必再過濾。② **虛擬貨幣的 id 全部重編到千位數**，含既有的 `USDT`(10)／`BUSD`(13)／`TON`(20)；法幣約 178 種，前段留給法幣。③ **09-10 的「在 `currencies` 加用途旗標」撤回**（拆分設計決策 3）。④ 兩件事都在改主檔 id，**併成同一個工作包，引用面只盤一次**。🔴 **第一步必須是全量盤點引用面**：六站的通道、訂單類型、銀行、歷史訂單、注單表，加上舊系統與 stored procedure 裡的硬編號——這部分 09-17 開會當下沒盤過，重編的代價 RB 已知悉仍選此項。**09-17 會後已盤**：六站 12 張引用表（單位變體 4／5／7／8 零筆；USDT 是重編大宗）、APIs 的 app／config／routes、216 支 SP 零寫死；**未盤與審查補列的引用面**（`order_histories`、JSON 欄位、舊系統三 repo 與 RD-Studio-01 硬編號、舊 migration 會把 TON 插回、三套下拉快取、語系鍵、匯率流水與報表快照、`_bak` 表、FON 查核）逐項列在 [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md) 工作包 1。⑤ 現有 `SyncCurrenciesSeeder` 只會新增、不改不刪，且 id 被佔用時整批回滾，做不了清洗 ⇒ 要另寫一支一次性的清洗命令（預設 dry-run、先備份），seeder 只負責清洗之後的長期同步。⚠️ `FON` 刪除前要特別查：正式環境有 `fon666` 部署分支、GFF USDT 組織查到過 1 筆 FON 訂單，它可能是某站真正的計價單位（未驗證） | 有效 |
| **㉜** | **千分制是顯示方式，不是另一種幣別（09-17 RB 拍板）**。① **帳本永遠以「元」為單位存**，主檔只留 `VND`／`IDR`。② `agent_group_currencies` 加一欄「顯示單位」（1 或 1000）；同一組別同一幣別只有一列，所以結構上不可能同時出現 VND 與 KVND。③ **API 金額一律回元**，另外帶出顯示單位；格式化與輸入換算（玩家輸入 50 代表 50,000）由前端做。④ **後台 SR 報表的顯示單位跟著代理組別走**，玩家端與該組代理看到的數字一致。⑤ `KVND`／`KIDR` 進 `database/data/currency_aliases.php` 當別名（比照 `HKB → HKD`）；遊戲商的千元換算照舊走 `vendor_infos.rate`，現行行為不變。**依據（09-17 唯讀實查）**：Alfawin77 的帳本就是完整印尼盾——IDR 通道 58 條入帳比例全為 1、USDT 通道 4 條為 16,724～17,849；`KIDR` 只出現在遊戲商商戶（6 家，`rate = 0.001`）。🔴 **前置查核**：轉檔進 TBR 的 73 支 stored procedure 沒有任何一支引用 `rate`，TBR 的單位等於各家注單表的單位；新系統拉單在寫注單表當下就除以 `rate`（`app/Vendors/Pp/Driver.php:204`），但有這條路徑的 driver 只有 6 家（其中 Evo、E88Sport 沒用 `rate`），其餘約百家的寫入點未驗證 ⇒ 開工前要把現役 `rate ≠ 1` 的商戶逐家抽一筆，比對三方原始金額與 TBR 金額 | 有效 |
| **㉝** | **開戶幣別由 `agent_group_currencies` 決定；會員互轉時收款方可用幣別內自動建錢包（09-17 RB 拍板）**。① 會議說的「後台新增一張設定表讓營運勾選」就是決議㉕ 已設計的 `agent_group_currencies`（代理組別對應開放幣別），資料各站各一份；`currencies` 走 seeder 六站共用是另一回事。PM 摘要第 11 題結案。② **會員互轉**（含掃 QR Code）：幣別用轉出方的；收款方的可用幣別含這個幣別而他還沒有該幣別錢包 ⇒ **系統自動建錢包並入帳**；可用幣別不含 ⇒ 擋下並回業務錯誤。這是決議㉘ 第 ② 點的補充，防線保留——不檢查就自動建，會讓會員透過互轉拿到所屬組別沒開放的幣別。③ **不同幣別之間互轉不納入規劃** | 有效 |
| **㉞** | **各幣別的活動獨立配置，不設防重複參加（09-17 會議，RB 拍板）**。多幣別站點的每個幣別視為不同站，同一檔活動開了五個幣別，玩家可以各領一次；**不做活動群組**。⛔ **撤回簡報第 1 節的業務規則「會員不能同時跨幣別參加同一檔活動」**，PM 摘要第 4 題結案。🔴 **積分方案拍板後這題要重開**：會議同時在討論「活動獎勵統一送積分（平台幣），玩家再換回法幣」，但積分換幣的比例與流水卡控都還沒解，尚未拍板。與幣別無關的活動（純行為觸發）該送哪個幣別，也還沒有答案。⚠️ 動到 `app/Services/EventV2/` 前仍須先讀 `docs/event-v2/` | 有效 |
| **㉟** | **兩筆資金處置已由 PM 簽收（09-17 會議）**。① VWorld 停用錢包整批清除：1,053 個會員、餘額合計 121,991.62 同意銷帳，**條件是要有完整備份與可還原的後手**。② TPower 無主帳號的禮碼餘額：3,869 名、合計 74,055.49 同意作廢；上層代理查不到的會員一律視為無效帳號，不必再逐一複查。PM 摘要第 1、2 題結案。執行走 artisan 命令（預設 dry-run、先備份快照與清除記錄），禁止裸寫資料庫 | 有效 |
| **㊱** | **已關閉站點的資料另開工單清理，不在本分支做（09-17 RB 拍板）**。範圍：① VWorld 四條幣別對不上的通道（支付組別 28／37／89／634）；② 越南盾站（組別 28、37）的相關資料；③ `vworldbn` 與 `MMGames` 兩個組織，比照視為已關閉站點。PM 摘要第 3、13、14 題結案。🔴 `vworldbn` 底下有 572 個代理目前是啟用狀態，**工單第一步要先列出這些代理的最後登入與近期交易，確認是舊站殘留才動**。`agent_cash_flows` 已有 `deleted_at`，通道不必另加軟刪除欄位。這張工單同時解掉「四條通道卡住資料回填」的問題 | 有效 |
| **㊲** | **其餘五項（09-17 RB 拍板）**：① 錢包互轉（同一會員不同錢包之間）的多幣別改造**現階段不做，未來可能追加**；已知缺口＝現有「沒錢包就自動開一個」照舊運作，改造期間仍可能建出沒有幣別的錢包。② `event_vendor_infos`（活動專用商戶主檔）**納入幣別治理**，比照一般商戶主檔，補強決議⑲。③ **入帳比例不集中管理**：支付商的換算與會員錢包的換算是兩套獨立邏輯，各通道各自配置、人工維護 ⇒ 決議㉘ 留下的「入帳比例拆解」議題不做。④ **沒有硬上線期限**：客戶希望 11 月上線，已明確告知做不到；不必準備「先走原表加欄位」的降級路徑。⑤ 先開哪個白標，待時機明朗再定 | 有效 |
| **㊳** | **工作順序總表定案（09-18 RB 逐題拍板，經三席審查後補裁兩題）**：順序寫在 [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md)，摘要＝主檔清洗（㉛）最先 → 第二期 Task 9／10 → `agent_group_currencies`＋顯示單位 → 啟用影子同步觀察期 → 通道側三欄 → 舊系統改造 → 切換寫入 → 唯一鍵改三元組 → 會員互轉自動開戶 → 活動按幣別。三點與舊文件相反、以本條為準：① 舊系統改造（5.4）排在切換寫入（5.2）**之前**，因為切換後舊系統再直寫 `members.balance` 就會被反向同步抹掉；② 唯一鍵改三元組從「最後做」提前到會員互轉與活動之前，因 `WalletService` 定位錢包仍是兩元組；③ USDT 重編與 CCPayment 配商戶不綁時程。文件形狀：第零／第一期計劃刪除、第二期縮到 Task 9～11、契約搬 `docs/`、簡報降為歷史材料。 | 有效 |

---

## 六、五項既有結論更正（08-27 查證）

> 出處 `#verify-0827`。**這五項是舊文件最容易誤用的地方。**

| # | 原結論 | 更正後 |
|---|---|---|
| 一 | 「73 支轉檔 SP 零改動」 | **推翻「零改動」，但支數 73 本身正確**。`SP_transfer_bet_record_*` 是 72 支、72/72 全是具名 INSERT＋ODKU、0 支含 currency ⇒ 加欄位不會斷，但幣別永遠落 DEFAULT ⇒ **SELECT 全要改**。⚠️ 該 glob 漏掉 `SP_transfer_event_record_pgs`（也寫 TBR、結構同構）⇒ **實際要改 73 支**。另：這些寫的是 **TBR 不是 SBR**，SBR 的寫入者是 RD-Studio-01 的 PHP |
| 二 | 「歷史免回填」 | **推翻**。只在單幣別站台成立。VWorld `vendor_id=22` 底下混雜七種幣別，而 TBR **沒有 `vendor_info_id` 欄位**、幣別無法自身回推 |
| 三 | 「SBR 靠唯一鍵冪等」 | **修正**。SBR 只有 `primary(id, summary_time)`、**無任何業務唯一鍵**，冪等靠 delete-then-insert ⇒ 決議六的兩項配套是強制的 |
| 四 | 「`:32–:35` 非 APIs 排程分鐘 ⇒ 有第二寫入者」 | **修正**。該推論無效（兩邊都是 `hourlyAt(30)`）。有效證據是覆蓋面：舊系統 79 個硬編 code／APIs config 76 條／實際只跑 19 個／設定了卻不跑 57 個／實測外部寫入者 50 個 ⇒ 50 ⊆ 57 ⊆ 79 吻合 |
| 五 | 「前端過濾幣別需在 `games` 加欄位」 | **修正**。兩張表都不需加，幣別已隱含在 `vendor_info_games` 的關聯中（＝決議七） |

---

## 七、待拍板（禁止當已決結論引用）

- ~~🔴 **多幣別有無硬上線期限**~~（✅ 09-17 已答：沒有硬期限，見決議㊲）— 最大的風險判斷點；若「新表遷移案＋冷熱分離＋72 支 SP＋28 支報表＋Gate 2」合併交付，任一卡住多幣別就上不了線。有硬期限則需備「先走原表加欄位案上線」的降級路徑
- **熱表要不要按月分區** — 決議八已消除風險敞口，**建議採按月分區**（可 `DROP PARTITION` 秒級汰除），代價是唯一鍵須含月份欄
- **VWorld SBR 分區修復** — 只有 7 區、`p2025` 已是 MAXVALUE，2025 年起（含 2026 全年）全塞一區（441 萬列且成長中）；MAXVALUE 分區不能直接 `ADD PARTITION`，須先 `REORGANIZE`
- ~~🆕 **會員互轉的「同組織」檢查要不要改判組別**~~（✅ 09-09 已由決議㉘ 第 ② 點定案為「收款方的可用幣別含轉出幣別」，09-17 決議㉝ 再補自動建錢包；本條先前漏劃）（09-09 隨決議㉕ 浮現）——設計決策 13 原本要加「雙方必須同組織」，但幣別改掛代理組別後**同組織不等於同組別**，兩個同組織會員的可用幣別集合可能不同 ⇒ 這個檢查判不準。兩條路：改成「同組別」，或改成「幣別集合相容即可」。**待 RB 拍板**
- ~~🆕 **新建下層代理要不要繼承上層的幣別組別**~~（✅ 09-09 已由決議㉗ 定案為「建代理時複製上層欄位」；本條先前漏劃）（09-09 隨決議㉕ 浮現）——掛組織時新代理自動繼承組織、這題不存在；掛組別後 `agents.currency_group_id` 預設 0（決議㉕ 刻意不給墊底值）⇒ 新代理一建立就處於「查不到會拋錯」的狀態。要在建代理的路徑帶組別（後台與 API 都要改），還是改為從上層繼承？**待 RB 拍板**
- ~~🆕 **`currencies` 主檔的 `USDT`(10) 留著還是停用**~~（✅ 09-17 已解：決議㉖ 撤回，USDT 是獨立錢包幣別，留著並重編到千位數，見決議㉚㉛）（09-09 隨決議㉖ 浮現）——它既不是標準幣別、也不再是解析目標，與決議三「主檔只收標準幣別碼」有張力。**RB 已答「留著且維持啟用，另開工單」**，但工單範圍待定。⚠️ **09-09 補查：它在金流面還是活的**——VWorld 以 `currency_id = 10` 計價的啟用中資料有 `agent_cash_flows` 20 列、`order_types` 22 列、`cash_flow_banks` 2 列、`banks` 4 列，TPower 亦有（4／8／2／10）⇒ 直接停用主檔會打到啟用中的代收／代付通道設定。🔴 **但同日稍後查明：那裡的 `currency_id` 是「通道幣別」不是「錢包幣別」**（見決議㉘），所以先前寫的「錢包幣別改判 USD 後這些通道會對不上、存款找不到渠道」**是錯誤推論、已作廢**——GFF USDT 組織有 `rate = 1.0` 的 USDT 通道，會員付 USDT、1:1 入帳到 USD 錢包，路徑是通的。**工單範圍應為「主檔那一列的去留」，不含金流面正規化**
- ~~🆕 **要不要記錄「組別是刻意指定還是繼承來的」**~~（✅ 09-09 RB 裁決**第一版就加**，回填一律填「繼承」，見決議㉘）
- ~~🆕 **稽核要不要加第四種錯：幣別組別 ⊄ 支付組別的可用幣別**~~（⛔ 09-09 **提案作廢**：通道幣別與錢包幣別本來就可以不同，入帳比例是橋，該檢查會擋掉大量正常配置；改用 `rate = 1.0` 推導錢包幣別亦不可靠。改為**先做一份盤點報告**撈 6 個雙 `rate = 1.0` 組別的實際訂單，見決議㉘）
- **歷史 backfill 的帳號換商戶風險** — 決議四以「現在的帳號綁定」回推幣別；若帳號曾換過商戶，回推的是現在的幣別不是下注當時的
- **集團層跨幣別合計是否需要** — 決議二的唯一例外；不需要則整件事不用做
- **注單是否登記「append-only 改造」為技術債** — 是所有冪等紅線的根治解，本案不做
- **執行計劃形狀與工作包邊界**、~~**1003 免費幣排行榜是否廢除**（待 PM）~~（✅ 09-03 裁決廢除，見決議⑮）、~~**幣別設定掛組織還是代理**~~（✅ 09-08 RB 拍板改用新表；🔄 **09-09 RB 再裁掛載層級＝比照代理組別模式**，`agents.currency_group_id` → `agent_groups`(type=8) → `agent_group_currencies`，不掛組織，見決議㉕）、~~**126 張表的分批策略**~~（✅ 09-03 作廢，BR 不加欄位，見決議⑭）、**第二期先開哪個白標**（09-17 會議：待時機明朗再定，見決議㊲）
- **12 張自帶三方幣別字串的 BR 表，轉檔進 TBR 時「商戶幣別」與「三方字串」誰為準**（09-03 隨決議⑭浮現）——建議以商戶幣別為準、不一致時告警（第零期 `currency_aliases.php` 契約可拿來比對），待 RB 拍板
- ~~**`event_vendor_infos` 是否納入第二期幣別主檔治理**~~（✅ 09-07 RB 裁決納入，見決議⑲）（09-03 隨決議⑭浮現）——活動用單一錢包的帳號表帶 `event_vendor_info_id`、注單寫 `event_total_bet_records`、主檔只有 `currency` 字串；活動注單要不要分幣別屬業務決策，影響盤點列「待業務裁決」，第二期計劃目前零 task
- ~~**`HKB`／`MSC` 代碼語意**~~（✅ 09-07 RB 裁決見決議㉔：HKB＝HKD 拼寫變體、字串另由 ops 改；MSC 保留 unresolved）（HKB 啟用中，卡住 `vendor_infos.currency_id` 填值）、**VWorld 6 個組織 `currency_id=0`**（08-28 實查：六個組織**全都有啟用中代理**，其中 `vworldbn` 有 573 個 ⇒ 不是「清理」而是「必須判定並填對」；09-07 Task 12 量測：這六個組織下的會員幣別解析為 0，人數與逐組織明細見影響盤點 8.4；**09-08 Task 7 提案已出**＝`phase2-organization-currency-proposal.md`（已被 `agent-group-currency-table-design.md` 取代、09-18 刪除，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/phase2-organization-currency-proposal.md`）：範疇修正為**十個**（六站實查另有 Yes2Win／Alfawin77／Me77／VPlus33 各一個「gff 預設組織」為 0、TPower 零個且其預設組織已是 MYR 可當先例），逐組織建議與信心＝四站預設組織與 VWorld gff 填站台主幣別（高）、JiliGames MYR（中高）、vworldbn MYR（中、資料面零 BND 痕跡）、vworldau ✅ 09-08 RB 答已退場 ⇒ 維持 0 走決議㉓死帳規則（原建議 AUD 作廢、代理與會員停用另立工單）、MMGames MYR（低）、GFF USDT 帳本單位 09-08 實證＝USDT（USDT 通道 rate 1／MYR 0.23／TON 1.36～1.8，唯一活躍組織），✅ **09-09 RB 裁決「USDT 就是對應 USD 幣別」⇒ 預設幣別＝USD(23)、本題解除**（見決議㉖；TON／BUSD 不比照；前置＝該站要先有 USD 列，等 MR !2156 部署）；要問業務的兩題（vworldbn／MMGames）列在提案第 4 節，JiliGames 與四站預設組織、VWorld gff 只需 RB 同意，**未拍板前不改資料**；🔄 **09-08 起填的地方變了**——依決議㉕改為「在 `agent_group_currencies` 建列、再把該組織底下的代理指過去」（🔄 09-09 由掛組織改為掛代理組別），不再改 `organizations.currency_id`，判定證據與建議值不變，vworldau 由「維持 0」改稱「不建列」）
- ~~`deposit_rate`／`withdrawal_rate` 語意未查明~~ ✅ **08-28 已查明**：來自 `2026_03_16_000001_refactor_payment_channel_schema.php`（金流通道重構時加），**全 repo 零讀寫**（只有 `app/Models/Currency.php:18-19` 的 property 註解）⇒ 已定義但未啟用，不是死欄位
- **舊 repo 逐白標分支的盤點範圍**（09-03 隨四 repo 盤點浮現）——API_External／API_Frontend 一白標一分支且程式量差數倍（`master` 已死；各分支命中數與「兩 repo 度量不同、不可跨列比」的警語見影響盤點 7.1，本檔不重述），222 絕大多數來自這兩個 repo（RD-Studio-01 與 API_Agent 非分支制）；第五期要覆蓋哪些白標就得盤哪些分支，部分分支還有 `vw-master` 沒有的 1003 排行榜錢包建立點
- ~~**三個疑似缺陷是否併入第五期修**~~（✅ 09-04 RB 裁決：**不直接併入，先起草 PM 詢問稿送 PM 判定 bug／刻意**；同條末尾的 `wallet_types` migration `down()` DDL 地雷**單獨開 GitLab 工單**＝`target/tickets#4298`（09-06 已建，標 `Type::Bug`＋`API::Frontend`）；PM 詢問稿仍留在對話內、不入版控，repo 內找不到是正常的）（09-03）——API_Frontend `EventNewInviteController.php:674` 建立時硬寫禮碼型別（遊戲錢包獎勵會誤建成禮碼錢包）、API_External `EventUUSlotSingleWallet/Libs/CashBonus.php:73` 型別與同族不一致、API_Agent `AgentWalletController.php:408` 建錢包在交易之外（rollback 留孤兒列）；需 PM 確認是 bug 還是刻意，詳影響盤點第七節 7.3。同節另三項不需 PM、但要有出口：API_External `Rcb988Controller.php:2585`／`:2604` 用 `DB::raw` 改餘額（改造時單獨處理）、`EventFumaSW/Libs/Bet.php:52`／`Settle.php:58` 無 `lockForUpdate()`（併發疑慮）、**API_Frontend `2023_04_11_084334_create_wallet_types_table.php:9` 的 `down()` 會 `dropIfExists('member_wallets')`**（DDL 地雷，建議單獨開單修掉）
- ~~**孤兒 parent 歸屬提案四類處置（09-07 Task 12 產出，待 RB／PM 拍板）**~~（✅ 09-07 RB 拍板採 B 類工程面規則，見決議㉓；禮碼餘額另送 PM、資料層處置另開單）——四類提案、判準與數量見決議㉓、影響盤點第八節與第二期計劃 Task 12 執行結果（該段已隨 09-18 縮減刪除，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-phase2-plan.md`），本項不再重述
- **`target/support` 共用套件的改造歸屬**（09-03）——不在任何 repo 版控（composer 指向 `target/components/support.git`），其入款確認路徑會建立 `wallet_type_id=2` 錢包，在 API_Agent 是活路徑；不改它入款建的錢包就沒幣別，須跨 repo 協調誰改、何時發
- ~~**Task 10 改唯一鍵的閘門要不要加第 4 項「該站舊 repo 建立點已帶幣別並發版」並改為逐站判準**~~（✅ 09-04 RB 認可，見決議⑯）（09-03 圓桌，timo＋rorin 交叉命中）——現行三項只擋 APIs 與 SP，舊 repo 30 個以上建立點若未改完，改鍵後每天約 700 筆分裂列（由 21,246／30 天推得的量級）且無告警；計劃 Task 10 段落已依此改寫
- **Task 10 migration `down()` 不可回滾需另開單**（09-03 圓桌，rorin）——計劃內的草稿在重複列存在時撞 1062，且寫死重建成 `unique_index` 與各站實名不符；修法見影響盤點 7.6
- **前置阻斷**：`currencies` 主檔缺 PHP／USD／CNY（必要時 BND）⇒ **32 個啟用中商戶的 `currency_id` 現在填不出來**，必須排在 `vendor_infos` 加欄位之前。⚠️ **08-31 更正**：這 32 個裡有 1 個是 `HKB`（語意待裁決，不是主檔缺項），**補完主檔只解 31 個**（PHP 16 ＋ USD 14 ＋ CNY 1），稽核仍會因 `HKB` 回非零狀態碼——要讓稽核轉綠，主檔補齊與 `HKB` 裁決兩件事都得做完。✅ **09-07 版控已落地**（`currencies.php`／`CurrencyEnum` 補 PHP 22／USD 23／CNY 24），六站生產待部署與 seeder 執行；稽核命令同日改為 Enum ∩ 主檔，主檔未補的站會回 `enum_only` 而非綠燈

---

## 八、未驗證（禁止當已驗結論引用）

- 20.6 億列規模的 INSTANT（只測到 209.7 萬列）
- instant 欄位對讀取 p95／p99 的額外解碼成本
- replica 端的 MDL 條件
- `REORGANIZE PARTITION` 的鎖與耗時（**不可假設 metadata-only**）
- 1,266 ms planning 成本的組成
- 其他五站生產 SP 集合是否與 VWorld 一樣多出 repo 沒有的備份／ADMIN 副本（09-03 只查計數、未逐名核對；VWorld 多 5 支）
- 六站直寫 `member_wallets` 的 SP 是否**全部** INSERT 明列欄位（09-03 只抽查 4 支原文）
- ~~API_Frontend `ThirdPartyController.php:1903` 三方開分有無多錢包開關守衛~~ ✅ 09-03 圓桌實證**無守衛**，且原先轉向的 `VendorController:4767`／`:5437` 與 `target/support` 入款全有 6008 守衛——它是唯一不看開關的 type=2 建立路徑（影響盤點 7.4）；21,246 筆的歸屬仍待用 `wallet_transaction_logs.type` 一刀驗證
- 「六站多錢包開關全關」是 09-03 時點值，30 天內有無被開過未驗證；量測口徑須為有效值 `IFNULL(acs.value, asd.default_value)` 逐代理群組
- 舊 repo 分支與六站的對照（兩 repo 皆無 `me77` 分支；`fon666`／`wintoto`／`vwb2c` 不在六站）——逐站發版前必須先建表
- API_External／API_Frontend 其他白標分支的寫入點與建立點（`vw-master` 已實證有 `tpower-master` 沒有的 3 個建立點，222／30 皆為單分支下界，數字以影響盤點 7.1 為單一來源）
- API_Frontend、RD-Studio-01、API_Agent 三個 repo 的讀取面只有計數未逐筆盤
- API_Frontend `WalletRepository` 的 `int $balance` 簽章截斷小數獎金的實際曝險（打卡／綁機獎勵設定有無小數）
- API_External `updateSubBalance()` 五個多型漏斗「不寫 `member_wallets`」是靠追呼叫端得出，非型別保證
- `ADD INDEX` 外推 20.6 億列 ≈ 56～116 分鐘（線性粗估，生產只會更久＝小時級）
- row version 上限 64（引自 codex 查證，未二次核對官方文件）
- 歷史補跑的實際頻率與最大回溯月數（可撈 RD-Studio-01 執行 log 或 `command_logs`）
- ~~SBR `delete` 邏輯的實際條件（僅由函式名 `deleteSummaryBetRecordNotCorrection()` 推斷）~~ ✅ **08-31 已驗**：三處實際條件逐字相同，都是 `vendor_id` ＋ `summary_time` ＋ `correction != 1`——APIs `SummaryBetRecordService.php:375`、RD-Studio-01 `Services/SummaryBetRecords/Base.php:385`（新版，76 個遊戲商共用）、RD-Studio-01 `Console/Commands/SummaryBetRecordCommand.php:3812`（舊版）。⇒ 決議六「delete 條件與 GROUP BY 同步加幣別」不存在兩邊條件不一致的風險，但**第四期三處要同時改**
- ⑤50 個 vendor_id ⊆ ①79 個 code 尚未逐一比對；未查 TPower crontab／log；未排除第三寫入者
- ~~`currencies.code` 沒有 unique constraint（六站 id 與 code 一致是巧合不是保證）~~ ✅ **08-28 已驗**：六站皆 21 筆、`id → code` 逐字一致、`code` 零重複（指紋 `d9f31b900e19430c8dcc923b469262e2`）⇒ 加唯一鍵沒有資料障礙。**但 `code` 目前只有 `PRIMARY(id)`、完全沒有索引**，而 `SyncCurrenciesSeeder` 卻以 `code` 當 uniqueKey 做同步 ⇒ 唯一性一直是程式假設而非資料庫保證（第二期 Task 2 補）。✅ **09-07 版控已落地**（migration `2026_09_07_000001`，索引名 `currencies_code_unique`），六站生產待部署
- SBR 的 delete-then-insert 未包在同一 transaction；`prunePartitions()` 命名誤導（實際是 DROP 月份實體表）；`CREATE TABLE ... LIKE` 不複製 FK
- 🆕 **`vendor_infos.rate = 0.01` 的語意**（2026-08-31 六站全量複驗發現）：VWorld 2 筆、幣別 `MMK`／`THB`、其中 1 筆啟用中。先前文件只記載四種 `rate` 值（`1`／`0.001`／`100`／`0.00025`），**漏了這一組**。倒數為 100，可能是百分制計價，但**沒有任何文件或代碼佐證**。⇒ 動到這兩筆商戶、或要為 `rate` 建立語意分類之前，必須先問清楚。詳見 [`docs/multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md) 第三節

---

## 九、08-28 執行期的新查證（第零期幣別契約開工後）

> 以六站生產資料與本機原始碼查得。**沒有推翻任何已拍板決議**，但補上了決議三缺的細節，並發現三項先前未記載的技術債。

### 補強決議三：`vendor_infos.currency` 到底被誰用

決議三說這個欄位「送三方」。08-28 查出精確位置：

全 repo 實測 `vendorInfo->currency` 共 **57 處／29 檔**：

| 層 | 檔數 | 代表位置 | 意義 |
|---|---|---|---|
| 單一錢包 controller | 12 | `SexySlotCq9SWController.php:148`、`PlayTechController.php:146`、`ImperiumController.php:196`、`BoleController.php:254` | 直接回傳給三方，改值等於改 API 回應內容 |
| **遊戲商 Driver／Service** | **14** | 七支 Driver（`CRSW/Driver.php:72`、`PPPOP/Driver.php:64`、`WFSW`、`ClotPlaySW`、`FFFSW`、`Baison`）＋ `WudiSWService` ＋ `MotivationSWService` ＋ `MotivationSW/Libs/` 六支（`Bet.php:64`／`Credit`／`Debit`／`Refund`／`Rollin`／`Endround`） | 同上 |
| **轉帳錢包 controller** | **2** | `NextSpinController.php:115`、`EbetController.php:65` | 同上 |
| 後台顯示標籤 | 1 | `app/Filament/Admin/Pages/Queries/BetRecordCorrection.php:171` | 純顯示，唯一非對三方的用途 |

其中 **2 處是拿它擋請求**：`PxplaySwController.php:121`、`TTHSWController.php:1286` 以 `!==` 比對三方傳入的 currency，**PHP 字串比較大小寫敏感**——改了寫法而三方沒跟著改，該商戶的請求會開始被拒絕。

⚠️ **別把曝露面窄化成「單一錢包 controller」**：08-28 初版曾這樣寫（只算 13 處），漏掉轉帳錢包與 Driver 層，會低估改值風險。

⇒ 決議三「兩者外觀不一致是設計」的理由更強：這個字串欄位有對外 API 契約，不能為了整齊而改。

### 新發現一：幣別參數是雙來源

`vendor_infos.info`（`text`、註解「連接資訊」，VWorld 614 筆全為合法 JSON）內另有 `CURRENCY`／`Currency`／`CURRENCY_NAME` 鍵，那是三方要求的格式，與欄位不必然相同：

| 商戶 | `currency` 欄位 | `info.CURRENCY` |
|---|---|---|
| IBC（saba，AlfaWin77） | `IDR` | `15`（三方用數字碼） |
| MMK 商戶（VWorld） | `MMK` | `MK2` |
| USDT 商戶（VWorld） | `USDT` | `UST` |
| M9BET／AE Sexy（AlfaWin77） | `KIDR` | `IDR` |
| NEW4D（AlfaWin77） | `MYR` | `IDR`（`rate = 0.00025`） |

最後一列正是決議二引用的那個商戶，印證「`rate` 同時承擔單位換算與匯率換算」。

⚠️ AlfaWin77 的 `Vpower`(id=28)／`Vpower(E2)`(id=61) 的 `info` **沒有** `CURRENCY` 鍵，三方參數可能直接取自欄位——這兩筆若要改值風險最高。

### 新發現二：同一站有兩種大小寫寫法

VWorld 的 `vendor_infos.currency` 同時存在 `KIDR`(3 筆) 與 `kIDR`(3 筆)，後者是 `100Plus`(319)／`Obet33`(321)／`DG`(326)，**全部停用**。其餘五站無變體。

🔴 **查的時候絕對不能用大小寫不敏感的分組**——資料庫預設定序不分大小寫，直接 `GROUP BY currency` 會把兩種寫法併成一列而看不出問題。⇒ 第二期 Task 11 處理。

兩種正確作法，依情境擇一（**08-31 補**）：

| 作法 | 用在哪 | 為什麼 |
|---|---|---|
| SQL `GROUP BY CAST(currency AS BINARY)` | 臨時查詢、回填後的複驗 SQL | 一行就能查，適合手動核對；搭配 `HEX()` 可直接看出位元組差異 |
| ~~全量取出後在 PHP 層分組~~ | ~~稽核／回填類命令~~ | 🔴 **08-31 由 RB 推翻**：大小寫不一致是資料缺陷，應該修掉而不是設計機制去容忍。稽核命令已改回 SQL 分組——合併不影響判定（同組成員對到同一個 `currency_id`），髒寫法的偵測與修正由第二期的正規化作業負責 |

⚠️ ~~命令類**優先用 PHP 層分組**：它不會因為有人「為了效能」把查詢改回 `GROUP BY currency` 就默默失去偵測能力。~~
🔴 **這句與上表第二列同屬 08-31 被推翻的那套做法，2026-09-01 一併劃掉。** 它原本是「PHP 層分組」的配套理由，推翻時漏改。
現行做法是**稽核命令一律用 SQL 分組**（`AuditVendorInfoCurrencyCommand.php:133` 的 `groupBy('currency')`）：大小寫不一致是**資料缺陷、要從資料面修掉**，不靠命令長期容忍；髒寫法的偵測與修正由第二期的正規化作業負責。
上面那句擔心的「偵測能力流失」不再是問題——因為偵測本來就不該是這支命令的職責。

### 新發現三：兩份幣別種子資料不同步

`database/data/currencies.php`（21 筆，由 `SyncCurrenciesSeeder` 讀，**只增不改不刪**）與 `database/seeders/CurrencySeeder.php`（18 筆自帶清單、缺 `BDT`／`TON`／`INR`，用 `insertOrIgnore` 明確指定 id）並存。改了資料檔卻沒改後者，新環境重建會拿到舊基線。⇒ 第二期 Task 3 收斂為單一來源。

✅ **09-07 已收斂**：`CurrencySeeder` 改讀同一份 `currencies.php`，兩支 seeder 寫入前經 `GuardsCurrencyMasterData` 比對主檔 id→code、衝突即拋例外（原本 insertOrIgnore 靜默略過、批次 insert 整批回滾且退出碼 0）。本段保留為背景。

### 09-07 補查：六站 `vendor_infos` 沒有任何商戶以 GCASH／PayMaya 為幣別

簡報第 9 節對 `PHP` 附了「與既有 GCASH／PayMaya 的關係要一併釐清」（Bonn 09-07 校閱指出未被接住）。六站唯讀實查 `vendor_infos.currency IN ('GCASH','PayMaya','PHP','BND')`：TPower／Yes2Win／Alfawin77／Me77／VPlus33 **四碼皆零筆**，VWorld 只有 PHP 27（16 啟用）與 BND 18（0 啟用）。⇒ GCASH(12)／PayMaya(15) 只是主檔列、沒有商戶用它們當幣別，**沒有「改指 PHP」的對象**；維持 Task 8 白名單將兩碼排除在錢包幣別之外、主檔列依決議三保留。若日後有商戶以 GCASH／PayMaya 登記，稽核命令會把它們判為 `non_currency`（有 id、不擋單），再回頭議。

### 新增需求：`vendor_infos.currency_id` 的索引不可拿掉

**玩家切換幣別後要過濾出支援該幣別的遊戲商，走的就是這個索引。** 第二期 Task 5 的 migration 原本就會建它，這裡補的是「為什麼不能以『還沒人查』為由拿掉」的理由。✅ **09-07 版控已落地**（migration `2026_09_07_000002`，索引 `vendor_infos_currency_id_index`；`VendorInfosTableSchemaTest` 守索引存在、訊息即這段理由），六站生產待部署。

### 複驗決議三的「12 張業務表空集合」

以 VWorld 與 AlfaWin77 複驗其中 8 張（`organizations`／`banks`／`cash_flow_banks`／`standard_banks`／`order_types`／`agent_register_limits`／`agent_member_field_settings`／`agent_cash_flows`）：變體 id（4／5／7／8）全部零筆，與決議三一致。

🔄 **09-10 補掃，結論部分推翻**：

- ✅ **`orders` 已掃**（VWorld 全表 750 萬列，`GROUP BY currency_id` 實測僅 **7.9 秒**——原本擔心的「沒有索引所以不敢掃」成本遠低於預期）。分布只有 `1` MYR 9,235,211／`2` SGD 8,947／`10` USDT 1,676／`0` 53／`20` TON 5，**變體 id 零筆**，與決議三一致。
- ⛔ **09-17 更正：下面這一點的證據是錯的。** `ia_bet_records.currency_id` 存的是**遊戲商 IA 自己的幣別編號**，不是我們的 `currencies.id`。實查那 19,841 筆的平均下注是 2.82、附帶的人民幣匯率是 1.55，是馬幣，不是越南盾變體。所以「變體 id 在業務表皆為空集合」這個原結論**沒有被這兩張表推翻**；`ia_daily_win_losses` 同理（未逐筆複驗）。對決議㉛ 的主檔清洗是好消息：變體代碼的引用比先前以為的少。原文保留如下。
- ❌ **但複驗清單本身漏了兩張表**：VWorld 的 `ia_bet_records` 有 `currency_id = 4`（VND2）**19,841 筆**、`ia_daily_win_losses` 有 **91,966 筆**（VND2）與 **2 筆**（KVND，id 5）。⇒ 「變體 id 在業務表皆為空集合」**不成立**，它們活在 IA 遊戲商的注單與日結表裡（其餘四站該兩表皆零列）。這不影響「不作為錢包幣別」的裁決，但**推翻「停用零遷移」的前提**。
- ⏳ `order_histories`（VWorld 1,457 萬列）仍未掃。依 `orders` 的實測耗時推估同樣可負擔。

### ⚠️ 一次被攔下的偏差（記錄以免重犯）

> 🔄 09-17 補：這一節守的是「當時的決議三」。決議三的「變體 id 不刪列」已於 09-17 由 RB 撤回（決議㉛），變體代碼現在的處置是**從主檔清除**。教訓本身（改動前先核對決議清單）仍然成立。

08-28 執行期一度把 `VND2`／`IDR2` 寫成「廢除候選」，理由是六站零使用。**這與決議三「變體 id（4／5／7／8）不刪列、不加 DB 旗標」直接衝突**，已更正。

**錯在哪**：把「零使用」推論成「該刪除」。正確的讀法是——零使用只代表「不必遷移」，變體 id 的處置一律是「留在主檔、由 `CurrencyEnum` 的錢包幣別白名單排除」。

**為什麼會錯**：執行期直接依實測資料下判斷，沒有回頭核對決議清單。⇒ **改動計劃或契約前，先確認該項目在本檔有沒有既有決議。**

### `KVND`／`KIDR` 保留的業務理由（08-28 補）

> 🔄 09-17 補：「有代理商希望以千分制計價」這個需求由決議㉜ 的**顯示單位**滿足，不需要這兩個代碼留在主檔。它們改放在別名契約裡，只用來辨認遊戲商那一側的寫法。

決議三只寫了機制（`KVND` 走字串欄位、`currency_id` 維持 `VND`），沒寫理由。08-28 補上：**有代理商明確希望以千分制計價**，所以這兩個代碼是「計價單位」而非「幣別」，尺度差由 `vendor_infos.rate` 吸收。這個理由讓決議三從「技術取捨」變成「業務需求」，強度提高。

---

## 簡報章節 anchor 對照

| 節 | anchor | 內容 |
|---|---|---|
| 0 | `#terms` | 名詞說明、速查表 |
| 7 | `#sp` | 要改的 stored procedure |
| 8 | `#tables` | 要改的資料表 |
| 9 | `#plan` | 六期實作計劃、Gate 定義、新舊代號對照 |
| 10 | `#open` | 08-18 會議決議與待決事項 |
| 11 | `#currency-config` | 新增幣別配置盤點 |
| 12 | `#tbr-mech` | TBR／SBR 方案評估與分表機制（含 P5、熱表分區實作做法） |
| 13 | `#amount-semantics` | 幣別金額語意、KVND 怎麼併回 VND |
| 14 | `#verify-0827` | 五項既有結論更正 |
| 15 | `#decisions-0827` | 08-27 收斂決議一～八 |
| 16 | `#codex-review` | 五項已驗證的缺口 |
