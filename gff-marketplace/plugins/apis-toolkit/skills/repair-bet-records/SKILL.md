---
name: repair-bet-records
description: 依對帳診斷的分流結論，修復遊戲商三表帳目並寫入（每個寫入命令前都要確認）。當使用者說「進行 PTSW 5/15 補帳作業」「把差額補進 SBR」「TBR 跟 BR 對不上幫我修」這類要動手修、要補的話時載入。🚫 只看不修走 reconcile-bet-records；查補帳紀錄走 list-sbr-corrections。
argument-hint: "[vendor] [日期 Y-m-d] [分流結論?]"
---

你是博弈平台的對帳工程師，負責依診斷結論**修復**任一遊戲商的三表帳目並寫入。

依本 SOP 處理：$ARGUMENTS

> **進場前必讀**：本 SOP 為**單一 (vendor × environment) 修復作業**，且**一律涉及寫入**。多白標需逐 connection 依序重跑，不混場。
>
> **vendor 專屬細節**（取資料命令、時區、欄位 mapping、status enum、特殊 incident）放在 `docs/reconcile/vendors/{name}.md`。處理特定 vendor 前先讀對應卡片。

## 觸發與分流

- **範圍**：依 `reconcile-bet-records` 的分流結論，跑 Phase B′（BR→TBR 回寫）／Phase C（重建 SBR）／Phase D（取三方修 BR、TBR）／Phase E（殘留處置）；全程經 `bet_record_recovery_logs` 或 vendor 專屬 patch 留審計，每個寫入命令前都要過 STOP gate 確認。
- **觸發**：「進行／跑／執行／做／修／補／補進／補帳／對齊並修」這類動作加寫入意圖的說法。典型句型：「進行 PTSW 5/15 補帳作業」「把 PPSW 5/15 的差額補進 SBR」「PTSW TBR 跟 BR 對不上幫我修」。
- **起手必須先有分流結論**（走哪條修復路徑）；沒有就先回 `reconcile-bet-records` 跑診斷，見下節「起手 gate」。
- **🚫 不觸發**：只想對帳／先看不要動／跑對齊但不修／查差在哪，走 `reconcile-bet-records`；查補帳結果、SBR correction 走 `list-sbr-corrections`。

---

## 🛑 起手 gate：先確認分流結論

本 skill **不做診斷**，只執行修復。動工前必須先握有 `reconcile-bet-records` 診斷產出的**分流結論**——它決定走哪條修復路徑：

| 分流結論（來自 reconcile 診斷） | 本 skill 對應路徑 |
|---|---|
| `TBR=BR ≠ SBR`（含 rc/comm/jp drift） | **Phase C**（純內部重建 SBR） |
| `BR ≠ TBR · B-1`（BR 已結算、TBR 漏寫） | **Phase B′**（純內部從 BR 回寫 TBR） |
| `BR ≠ TBR · B-2`（BR 未結算 / 金額 mismatch 無法判定） | **Phase D**（取三方拿結算真值） |
| `三表全等 ≠ BO`（非切口） | **Phase D**（殘差在 BR↔vendor 邊界，取三方逐筆查修） |
| 殘留 unsettled 盤點處置 | **Phase E** |

> **沒有分流結論就別動手**：若使用者直接喊「補帳」但未先診斷，先回 [`reconcile-bet-records`](../reconcile-bet-records/SKILL.md) 跑 Phase A/B 取得分流結論（或在本 skill 內先請使用者確認「已知走哪條」），再進對應 Phase。**禁止跳過診斷直接寫入**。

> **共享知識在 reconcile（不在此重複）**：五層資料心智模型 + 三個對齊邊界見 [`reconcile-bet-records` §0](../reconcile-bet-records/SKILL.md)；基本三表 SUM 驗證 SQL（Step 2 範本）見 [`reconcile-bet-records` §4](../reconcile-bet-records/SKILL.md)。本 skill 的所有「重跑 Step 2 SQL 驗證」「Step 2.5 逐筆驗證」均指回該處，不另列。

> **補帳範圍：對帳或修 bug 時順帶發現的歷史漏帳，已月結的月份不主動補**。「已月結」指財務已經做完月結的月份，和 SBR 每天台北 00:00 的關帳是兩回事。動補帳前先確認兩件事：這個月份月結了沒有，以及有沒有外部審計、客服單或玩家投訴要求補。使用者點名日期要求補，視同使用者要求。月份已月結、也沒有人要求時，只補當月與之後。上個月還沒月結時，問使用者要不要一起補；預設仍從當月第一天。「當月」以台北時間切。
> - 補帳範圍的起日預設當月第一天，不是 bug 上線那天。自寫補帳命令有起日參數（例如 `--from`）時，預設值也照這條。
> - dry-run 可以跨歷史月份掃；預設實寫只跑當月。
> - 修 bug 的 MR 先合併並部署，再補帳，免得還沒修好的程式又把補好的帳蓋掉。
> - 使用者主動要求補歷史（例如審計指出）才跑全範圍實寫，不從工程角度「順便補完整」。
> - 理由：動已月結的月份要財務重新解釋，還會在當月 SBR 產生調帳差額列，影響之後的報表。

---

## ⛔ 寫入 STOP gate（每個寫入命令前必過）

**依分流結論進入對應 Phase 後，進入任何寫入命令前一律先 confirm：**

| 分流結論 | 寫入鏈與閘門 |
|---|---|
| TBR=BR ≠ SBR | Phase C Step 3 dry-run（純讀可直接跑）→ **Step 4 SBR 實寫前必 confirm**（無 Step 6 fetch、無 Step 9） |
| BR ≠ TBR · B-1 | **Phase B′ 回寫 TBR 前必 confirm**（純內部、不取三方）→ 回 reconcile 重驗；TBR=BR 後 SBR 仍落後則接 Phase C |
| BR ≠ TBR · B-2 | cache 無 → **confirm Step 6 fetch（寫 cache）** → Step 7 純讀 diff → **Step 8 修 BR/TBR 前 confirm** → Step 9.1-9.2 驗證+dry-run → **Step 9.3 SBR 實寫前再次 confirm**（三道獨立閘門，不可因前一道已 confirm 就略過後一道） |
| 三表全等 ≠ BO（非切口） | cache 無 → **confirm Step 6 fetch** → Step 7 純讀 diff → 若需修原始層 → **Step 8 + Step 9.3 各自獨立 confirm** |
| 殘留處置（Phase E Step 10） | **status 更新 / 重拉 / fix 前必 confirm** |

**Confirm 模板**（進入**每一個**寫入命令前必發，明確列出環境 + vendor + 日期範圍 + 寫入指令）：

> 「分流結論：<X>。準備在 `<env>` 跑 `<vendor>` `<日期範圍>` 的 `<Phase B′ 回寫 TBR / Phase C Step 4 SBR 實寫 / Phase D Step 6 vendor fetch（寫 cache 表）/ Phase D Step 8 修 BR+TBR / Phase D Step 9.3 SBR 實寫 / Phase E Step 10 殘留處置（status 更新 / 重拉 / fix）>` 寫入命令：`<完整 artisan 指令 / patch>`。確認執行請打 `yes`；改純對帳請打 `stop`。」

> ⚠️ **Phase D 三寫入點**：Step 6（vendor fetch 寫 cache）、Step 8（修 BR/TBR + recovery_logs）、Step 9.3（SBR 實寫 + SP 衍生表）是**三道獨立寫入閘門**，必須分別 confirm。前一道通過不代表後一道自動通過——使用者可能只同意修原始層而暫不重建 SBR。

> 🚦 **收到 `stop` 時**：等同切回純對帳，後續純讀步驟（dry-run、Step 7 逐筆 diff〔前提 cache 已存在〕、驗證 SQL）可繼續、寫入命令一律不執行。若使用者要「只看不修」，請改用 [`reconcile-bet-records`](../reconcile-bet-records/SKILL.md)。

---

## Phase B′ — BR→TBR 內部重建（B-1 路徑，純內部、不取三方）

> 進入條件：診斷判定「BR ≠ TBR」**且**為 **B-1**——BR-only 那批 row `status=已結算`、`valid_bet/win_lose` 齊全，純粹是 TBR INSERT 漏寫。（若 BR-only row 未結算 / 缺結算結果，或差異是 TBR-only / 金額 mismatch 無法判定誰對 → 屬 B-2，走 Phase D。）
>
> 🛑 **回寫前必過 BO 佐證防呆（用診斷時已在手的 OP excel，零成本、不取三方）**：B-1 的前提是「BR 對、只是漏寫 TBR」，但**一筆 settled BR-only row 也可能是 BR 端的孤兒**（vendor 根本沒這筆）——直接回寫等於把壞 BR 變成 TBR/SBR 報表數字。回寫前確認：
> - OP 回報的 BO 落差**方向**＝「我方少」，且**金額 ≈ 這批 BR-only row 的 `SUM(valid_bet)`／`SUM(win_lose)`** → 佐證這批 BR 確實對應 vendor 真實注單 → 可回寫
> - 若 **BO 與目前 TBR/SBR 總額本來就一致**（OP 沒回報短少），卻冒出 settled BR-only row → 這批**很可能是 BR 孤兒**，**不可回寫**，改走 Phase D 用 vendor source-of-truth 確認後再決定（可能是 Step 7 的「孤兒」要刪 BR、而非補 TBR）
> - 金額對不上 BO 落差、或方向相反 → 同樣轉 Phase D 查證，不在 B-1 自動回寫

> ⚠️ **目前無通用 CLI 命令可「依日期範圍從 BR 批次重建 TBR」（known gap）**。現況做法：
> - 走 **vendor-specific patch**（參考 `app/Console/Commands/Vendor/KissH5SW/Maintenance/RepairOrphans.php`、`Vendor/PragmaticPlay/FixRecords.php`），於 patch 內針對 BR-only row 從既有 BR 寫 TBR
> - 🛑 **必須 `lockForUpdate` SELECT assert「該 BR 尚無對應 TBR」後做單次 INSERT**（與 Step 8 Type C 規則 #3 一致）；**禁用 `TotalBetRecordService::createOrUpdate()` / `updateOrCreate()`**——它們對既有 TBR 會**累加** `win_lose`/`bonus`/`jackpot`（非冪等），重跑或誤判金額 mismatch 時會把財務欄位寫壞。**若 assert 發現 TBR 已存在（金額對不上）= 那是 Type B，不屬 B-1**，改走 Type B 專屬 patch（見 Step 8）
> - patch 必須自帶 audit + revert（同 Phase D Step 8 Type A 的設計要求：記 `inserted_tbr_id`、獨立 revert 子命令）
> - **未來應補一支通用 `total_bet_record:rebuild --vendor --date` 命令**收斂此路徑；在此之前每個 vendor 各寫各的 patch 是技術債，需工單追蹤

> ⛔ **屬寫入步驟**：回寫 TBR 是 INSERT 操作，須通過寫入 STOP gate confirm。

**完成後**：回 [`reconcile-bet-records` Step 2](../reconcile-bet-records/SKILL.md) 重跑三表 SUM。`TBR=BR` 達成後若 `SBR` 仍落後（TBR≠SBR）→ 進 Phase C 重建 SBR。

---

## Phase C — SBR 重建路徑（輕、最常見）

> 進入條件：診斷判定「BR=TBR ≠ SBR」（或 Phase B′ 把 TBR 補齊後 TBR≠SBR）。Step 3 dry-run 屬純讀可直接跑；**Step 4 SBR 實寫前須通過寫入 STOP gate confirm**。

> **⚠️ Phase C 寫入命令安全規則**（每次跑都套用）：
> 1. **所有 artisan 必須包 `docker exec`**：APIs 專案 Laravel 命令在 `php-fpm8` 容器內跑；bare `php artisan` 會打到 host 或預設 `.env`，可能誤改非目標白標
> 2. **必須顯式注入 `DB_CONNECTION=<env>`**：用 `-e DB_CONNECTION=<env>` 覆蓋，禁止修改 `.env`（見 `whitelabel-container-exec` skill）
> 3. **`--vendor={id}` 強制必填**：本 SOP 為單一 vendor 對帳；省略或 `--vendor=0` 會跑全 vendor、產生無關 correction，跨環境難 rollback
> 4. **實寫類命令（無 `--dry-run`）執行前必須先 confirm**：以文字明確 acknowledge 環境 + vendor + 日期範圍，操作者打「yes」才執行

### Step 3：SBR dry-run 評估規模

跑歷史月份 / 跨日補帳前**一定先 dry-run**，看 correction 筆數確認規模：

```bash
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor={id} --date={Y-m-d} --dry-run"
```

輸出 `調帳寫入：新增或補差值 N 筆，沖銷舊記錄 M 筆` — N+M 規模超過直覺值（例如某日只有 100 筆投注但 correction 寫 500 筆）就先停下來檢查，可能是時區或 vendor_id 抓錯。

> 命令完整參數、關帳判斷、輸出語意、註冊新 vendor → [`reference/sbr_command.md`](reference/sbr_command.md)

### Step 4：跑 summary_bet_record 實寫 + TBR↔SBR 驗證

> ⚠️ 實寫命令，執行前必須先文字 confirm：「將在 `<env>` 對 vendor=`{id}` 跑 `{Y-m-d}` 的 SBR 實寫」並等操作者打「yes」。

```bash
# 單日整天 24 小時
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor={id} --date={Y-m-d}"

# 指定小時
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor={id} --date={Y-m-d} --hour={H}"
```

跑完重跑 [`reconcile-bet-records` Step 2](../reconcile-bet-records/SKILL.md) SQL 驗證。

### Step 5：仍不對齊 → revert SBR、進 Phase D

跑完 SBR 後 **TBR ↔ SBR(data_time) 仍不等** = 內部三表對不上 = 必有真實漏算或錯帳。**不可結案**。

```
TBR ↔ SBR(data_time) 仍不等
↓
★ 強制走「補償式重跑」(唯一安全路徑):
  進 Phase D 修 BR/TBR → 修完再跑 Step 4
  SBR 命令會自動產生新 correction 沖銷之前的錯誤值,不必手動刪
```

> **為何不提供「手動刪 SBR correction」路徑**：
>
> `summary_bet_record` 命令在寫完 `summary_bet_records` 後會接著跑 `SP_summary_bet_record_team`、`SP_summary_rolling_chip_record` 兩個 SP 彙總到衍生表（team / rolling_chip）。手動 DELETE base table 的 correction row **不會回滾衍生表**，會造成「base 看似乾淨、team / 會計報表仍見錯誤調帳」的隱性污染，且本 SOP 沒有提供衍生表 rollback SQL 或驗證查詢。
>
> **「補償式重跑」只解決「對 intended vendor」的失敗**：跑「正確 vendor_id」會產生新 correction 沖銷該 vendor 的錯誤值 + 同步更新該 vendor 的 SP 衍生表。
>
> **⚠️ vendor_id 抓錯（wrong-vendor 誤跑）— 不是「補償式重跑可解決」的情境**：
>
> `summary_bet_record` 命令以 `--vendor` 過濾、只處理該 vendor。**重跑 intended vendor 不會碰 wrong vendor**。但即使對 wrong vendor 再跑，也**不一定能反轉誤寫**：
>
> - **rerun ≠ rollback**：SBR 命令是「比對 current TBR aggregate vs existing SBR rows、寫差值 correction」。若誤跑當時 wrong vendor 的 TBR 與寫入的 SBR 剛好內部一致（這是常見情境，因為 SBR 是從 TBR 重算的），再跑會發現「current TBR = existing SBR」→ 寫 0 corrections → 原誤寫 correction + SP 衍生表的污染**完全留在帳上**，但操作者看到「跑完了、無差異」會誤以為清乾淨。
>
> 正確處理流程（須 DB admin 介入）：
>
> 1. **不要直接 rerun** — 先用 SQL 定位本次誤跑寫入的 SBR correction rows（用 summary_time window + vendor_id + correction=1）
> 2. **比對 backup**：若 DB 有 PITR / binlog backup，撈出誤跑前的 SBR 狀態作對比，明確列出受污染 row
> 3. **規劃補償 correction**：對 wrong vendor 寫一筆人工 audit 紀錄的「反向 correction」（手動 INSERT 沖銷的 correction row）並同步重建 SP 衍生表（`SP_summary_bet_record_team` / `SP_summary_rolling_chip_record` 需要 DB admin 用對應參數重跑）
> 4. **驗證**：確認 SBR base、team、rolling_chip 三者都回到誤跑前狀態
>
> 此流程**不在本 SOP 通用路徑內**；wrong-vendor 誤跑屬「需 DB admin 介入的事故」，本 SOP 只負責防止發生（reconcile 診斷 Step 0 環境健檢 + Step 4 實寫前必 confirm vendor）。

> **不要拿「跨日 correction 切口」解釋這個情境** — Step 2 / Step 4 的 SBR 查詢都以 `data_time` 切口，跨日 correction（`summary_time` 與 `data_time` 落不同日）只會影響 `summary_time` 切口的對齊，不會造成 `data_time` 切口的 TBR↔SBR 差。若把真實差異誤判為切口問題結案，會留下漏彙總在帳上。
>
> 跨日 correction 切口分析的正確使用場景，是「**內部三表 (data_time) 已對齊、但 SR11 / OP 用 summary_time 切口的報表不一致**」— 見 [`../reconcile-bet-records/reference/sum_validation_advanced.md`](../reconcile-bet-records/reference/sum_validation_advanced.md) §4。該情境屬診斷分流「三表對齊 ≠ OP」分支下、要走 Phase D 取三方前，先判斷是否其實只是切口語意差異。

---

## Phase D — 取三方比對 + 修 BR/TBR（重、**最後手段**）

> 🛑 **Phase D 是成本最高的一步（BO 後台匯出 / API 調用），是最後手段、不是第一反應**。進入前必須先窮盡內部路徑（Phase B′ 回寫 TBR / Phase C 重建 SBR）。
>
> **三個進入條件**（滿足任一）：
> 1. **B-2 待結算**：診斷判定 BR≠TBR，但 BR-only row 未結算 / 缺結算結果，或 TBR-only / 金額 mismatch 無法判定誰是真值——**需三方拿結算真值**才能判斷 BR 對錯
> 2. **三表全等但 ≠ BO**：我方三表內部已全等、仍對不上 BO ⟹ 殘差**落在 BR↔vendor 邊界**（缺漏 / 孤兒 / 金額 / 歸戶四類皆可能，見 Step 7）——此為三方無可取代的唯一情境
> 3. **Phase C 補償式重跑**：Phase C Step 5 已判定「SBR 實寫後 TBR↔SBR(data_time) 仍不等」⟹ 有真實漏算/錯帳需回上游修 BR/TBR，依其強制路徑進 Phase D（修完回 Step 4 重跑 SBR）
>
> ❌ **不該進 Phase D 的情況**：B-1（BR 已結算、純 TBR 漏寫）走 Phase B′ 純內部回寫；TBR=BR≠SBR 走 Phase C 純內部重建。這兩種都不需要三方。
>
> **Phase D 含三個寫入閘門**：Step 6（vendor fetch 寫 cache 表，vendor-specific）、Step 8（修 BR+TBR + recovery_logs）、Step 9.3（SBR 實寫 + SP 衍生表），各自需獨立通過寫入 STOP gate confirm，不可因為已 confirm 前一道就略過後一道。Step 7 vendor↔BR 逐筆 diff 屬純讀，前提是 vendor cache 已存在。

### Step 6：取得三方當日全部投注紀錄

> ⛔ **寫入閘門**：多數 vendor 的取資料命令（例：`ppsw:game-rounds` → INSERT `ppsw_game_rounds`、`ptsw:*` → INSERT `ptsw_game_rounds`）會**寫入 vendor cache 表**，屬寫入操作。執行前須通過寫入 STOP gate confirm；若 cache 已存在可直接跳到 Step 7。各 vendor fetch 是否寫 cache 見 `docs/reconcile/vendors/{name}.md`。

**每家 vendor 取資料方式不同**，必須先讀對應 vendor 卡：

卡片在 APIs `docs/reconcile/vendors/{name}.md`，清單以該目錄為準；沒有卡時依 reconcile 的「vendor 卡片索引」一節建卡（範本 `_template-dedicated.md`／`_template-aggregator.md`）。

vendor 卡會說明：取資料命令 / API、時區規則、欄位 mapping、status enum、特殊 incident。

**三方資料必須含以下欄位**才能對齊 BR：

| 欄位 | 對應 BR 欄位 | 用途 |
|---|---|---|
| 注單唯一 ID | `bet_id` | 主 join key |
| 訂單號 / Tran ID | `reference_no` | 結算後的次 join key |
| 玩家帳號 | `username` | 跨玩家配對檢查 |
| 投注金額 | `bet` | 對齊指標 |
| 派彩金額 | `win` | 對齊指標 |
| 投注 / 派彩時間 | `bet_time` / `payout_time` | 時區換算 |

### Step 7：vendor ↔ BR 逐筆校對

找出**四類差異**（前三類是 bet_id 集合 / 金額；第四類是 ownership = 玩家歸屬，三表內部一致仍可能錯）：

```sql
-- (1) vendor 有但我方沒（遺失）
SELECT v.bet_id FROM vendor_csv v
LEFT JOIN xxx_bet_records b ON b.bet_id = v.bet_id
WHERE b.id IS NULL;

-- (2) 我方有但 vendor 沒（孤兒）
SELECT b.id, b.bet_id FROM xxx_bet_records b
LEFT JOIN vendor_csv v ON v.bet_id = b.bet_id
WHERE v.bet_id IS NULL AND b.bet_time >= ? AND b.bet_time < ?;

-- (3) 雙方都有但金額不一致
SELECT b.id, b.bet, v.bet AS vendor_bet, b.win, v.win AS vendor_win
FROM xxx_bet_records b
JOIN vendor_csv v ON v.bet_id = b.bet_id
WHERE b.bet != v.bet OR b.win != v.win;

-- (4) ★ 玩家歸屬錯(bet_id 與金額都對、但 username/member 對不上)
--    執行前必須先「依 vendor 卡片選擇 member mapping」,各 vendor mapping 不同:
--      A. 直接映射(多數 vendor):vendor.username = members.username
--         例:PPSW、PTSW、KissH5SW、JokerBaccaratSW 等
--      B. vendor account 表映射(部分 vendor):需 join vendor 專屬 account 表
--         例:WESW → wesw_accounts、MOTIVATIONSW → motivationsw_accounts
--         查 SBR service_class 是否指向 *SummaryBetRecordService 即可初判
--      C. mapping 未定義 → ★ 禁止標記「Source-of-truth 結案」、必須先在 vendor 卡定義 join 規則

-- === 範本 A:直接映射(vendor.username = members.username) ===
-- ★ NULL-safe:vendor.username / members.id / BR.member_id / BR.username 任一為 NULL
--   或 mapping 找不到 都必須列為 mismatch(不能因 <> 對 NULL 回 UNKNOWN 漏掉)
SELECT b.id, b.bet_id, b.member_id AS br_mid, m.id AS expected_mid,
       v.username AS vendor_user, b.username AS br_user
FROM xxx_bet_records b
JOIN vendor_csv v ON v.bet_id = b.bet_id
LEFT JOIN members m ON m.username = v.username
WHERE v.username IS NULL                                  -- vendor 沒給 username = degraded
   OR m.id IS NULL                                         -- 找不到對應 member = unmapped
   OR b.member_id IS NULL                                  -- BR 端 member_id 缺失
   OR NOT (b.member_id <=> m.id)                          -- NULL-safe ≠
   OR NOT (b.username  <=> v.username);

-- === 範本 B:vendor account 表映射(例 WESW) ===
-- 替換 {vendor}_accounts 表名與該表的 join key(通常是 vendor_username / external_id)
SELECT b.id, b.bet_id, b.member_id AS br_mid, m.id AS expected_mid,
       v.username AS vendor_user, b.username AS br_user
FROM xxx_bet_records b
JOIN vendor_csv v ON v.bet_id = b.bet_id
LEFT JOIN {vendor}_accounts va ON va.vendor_username = v.username
LEFT JOIN members m ON m.id = va.member_id
WHERE v.username IS NULL                                  -- vendor 沒給 username
   OR va.member_id IS NULL                                 -- account 表查不到對應
   OR m.id IS NULL                                         -- account 指向不存在的 member
   OR b.member_id IS NULL                                  -- BR 端 member_id 缺失
   OR NOT (b.member_id <=> m.id);                         -- NULL-safe ≠
```

> **NULL-safe 原則**：vendor 端 `username` 缺、member mapping 解不到、BR 端 `member_id`/`username` 缺 — 都必須列為 mismatch；任何 vendor row 無法解析到「唯一 expected member」就不能視為 0 row pass。SQL 用 `<=>` (MySQL NULL-safe equal) 或顯式 `IS NULL` 檢查；不可用 `<>` + `COALESCE(0)` 之類，會吞 NULL 漏報。

> **bet_id 大整數陷阱**：`bet_id` 若為 17~18 位整數，PHP int 比較會走 float 命中相鄰記錄。所有 query / where 必須以 **string** 處理：`where('bet_id', (string) $betId)`

> ⚠️ **Source-of-truth 結案的必要條件**（四個查詢都歸 0）：
> (1) vendor-only = 0；(2) BR-only = 0；(3) 金額不一致 = 0；**(4) ownership mismatch = 0**。
>
> **若該 vendor 屬 mapping 未定義（範本 C）→ 直接禁止標記「結案」**，必須先在 vendor 卡定義 username→member join 規則才能繼續。
>
> 任一不為 0 → 不可標記「結案」、回 Step 8 修正後再驗。Ownership mismatch 不修會讓會員報表、爭議處理、佣金結算長期錯亂（資金 sum 對、但歸戶錯）。

### Step 8：修 BR + TBR ＋ audit（路徑依 Type 分流）

> 所有修正都**必經 audit**（不可裸改 BR/TBR），但 audit 寫入位置依 Type 分流：
> - **Type C** → 通用 `bet_record_recovery_logs`（+ 通用 `bet-record-recovery:revert`）
> - **Type A / B** → vendor-specific patch 自帶的 audit 表（+ patch 自帶的 revert 子命令）
>
> 三類修正路徑：

| 類型 | 觸發 | 對 BR | 對 TBR | 走通用 recovery_logs? |
|---|---|---|---|---|
| **A. 補建** | vendor 有、我方無 | INSERT | INSERT（新建） | ❌ **必須走專屬 patch** — 通用 revert 沒有「delete BR」分支、會留孤兒 BR |
| **B. 修既有 TBR 金額** | 雙方都有但金額錯 | UPDATE | UPDATE 既有 | ❌ **必須走專屬 patch** — 通用 revert 會 DELETE 既有合法 TBR |
| **C. 結算** | BR status=未結算、vendor 已結算（無對應 TBR） | UPDATE status | INSERT（新建） | ✅ type=recover, before=未結算狀態快照 — revert 可 restore BR + delete TBR 對稱 |

> **⚠️ Type A / B 為何不可走通用 recover**：
> - **Type A**：通用 `bet-record-recovery:revert` **沒有 delete BR 分支**（`Revert.php:480-485` 只用 `before` JSON restore BR 欄位、line 514 delete TBR）。Type A INSERT BR + INSERT TBR 後若走通用 revert → 只刪 TBR、留下孤兒 BR → BR↔TBR drift。
> - **Type B**：通用 revert 依 `after._meta.total_bet_record_id` **直接 DELETE** TBR（見 `Revert.php:514`），假設「recover 必新建 TBR」。若 Type B 對既有合法 TBR 寫通用 log，未來 revert 會把**原本合法的 TBR 刪掉** → BR↔TBR 永久不一致並汙染 SBR。
>
> Type A / B 都必須另寫 vendor-specific patch、自帶 audit 表 + 獨立 revert 子命令（含 backup_br / backup_tbr 完整快照、drift check、reversal symmetry）。**禁止寫入通用 `bet_record_recovery_logs`**。
>
> ⚠️ **目前 repo 尚無完整 Type B safe 範例**。反例型態：對既有 TBR 做 UPDATE 卻寫進通用 `bet_record_recovery_logs`、並把該 TBR id 放進 `after._meta.total_bet_record_id`。之後跑通用 revert 會刪掉這筆合法 TBR。新 patch 不可照這種寫法。詳見 [`reference/recovery_logs.md`](reference/recovery_logs.md) §2.0。

**Type C 的強制規則**（通用 recovery_logs 寫入時必遵）：

1. 修 BR/TBR/wallet 與寫 `bet_record_recovery_logs` 必須在**同一 transaction**
2. Transaction 開頭就 **`lockForUpdate()` 鎖 BR**；鎖內呼叫 `isCurrentlyRecovered()` 冪等檢查
3. TBR 必須 **顯式 SELECT + lockForUpdate 確認不存在**才 INSERT（禁用 `updateOrCreate` — 命中既有即 Type B 走錯範本應 abort）
4. 鎖定順序固定 **BR → TBR → wallet**（與 revert 一致以避免 deadlock）
5. `before` = 未結算狀態快照（供 revert restore）、`after` 含 `_meta.total_bet_record_id`（供 revert 刪 TBR）+ `transaction_log_id` + `is_multiple_wallet`
6. 必填 `reason`（票號 / 原因）；`member_id` 必填（漏寫拋 1364）
7. `source` 欄位帶 batch 唯一識別（如 `csv:filename:row_N`、`patch:ticket-1234`）供同批去重與 revert 追溯
8. 通用 Type C **預設不動 wallet**（`balance_diff = '0'`）；要動 wallet 走 vendor-specific patch 路徑（見 reference/recovery_logs.md §2.4）— 對 TW / 已部分入帳的 SW 注單無條件派彩會雙重入帳
9. 無縫錢包遊戲商補卡單（BR 停在未結算、等不到結束回調），除了更新 BR 與建 TBR，還要同時補一筆對應結束事件的 `{tname}_deal_records`；細節見 [`reference/recovery_logs.md`](reference/recovery_logs.md) 2.3 第 12 點。

> 完整寫入範本、鎖順序、冪等實作、Model helper、撤回命令、deal_records 沖銷事件 + applied_at NULL 陷阱（PTSW/PlayTech）、wallet 派彩決策、Type A/B patch 設計指引 → [`reference/recovery_logs.md`](reference/recovery_logs.md)

### Step 9：BR↔TBR 驗證 → SBR dry-run → SBR 實寫 → TBR↔SBR 驗證

按順序執行：

1. 重跑 [`reconcile-bet-records` Step 2](../reconcile-bet-records/SKILL.md) SQL，**BR↔TBR 必須完全歸零**才往下走（純讀）
2. SBR dry-run（同 Step 3）評估 correction 規模（純讀）
3. **⛔ 寫入閘門**：SBR 實寫（同 Step 4）— 此處與 Step 8 是 Phase D 的**第二道獨立寫入閘門**，須再次通過寫入 STOP gate 的 confirm 模板，不可因為已 confirm 過 Step 8 自動往下執行
4. 重跑 Step 2 SQL，TBR↔SBR 驗證；過後再跑 [`reconcile-bet-records` Step 2.5](../reconcile-bet-records/SKILL.md) 逐筆 / 分組驗證（純讀）
5. **跑完 Step 9.3 = SBR base + team / rolling_chip SP 衍生表都已寫入**。後續任何失敗 / 回滾 = 都要連帶處理這些 derived 表

### 失敗路徑（補償式重跑、必經序列）

若 Step 9.4 驗證仍不對齊，**禁止只 revert recovery_logs 就返回 Step 7** — Step 9.3 已寫的 SBR correction + SP 衍生表（team / rolling_chip）會留在錯狀態。完整補償序列：

```
1. revert 該批 recovery_logs(Type C 用 bet-record-recovery:revert;
   Type A/B 用 patch 自帶 revert)
   ↓
2. 立刻重跑 SBR 命令(同 Step 4 參數、同 --vendor / --date / --hour)
   → 命令會依「revert 後的 BR/TBR 真值」產出新 correction 沖銷錯誤值
   → 同時更新 SP 衍生表(team / rolling_chip)、保證 derived 表跟 base 同步
   ↓
3. 重跑 Step 2 SQL 驗證 TBR↔SBR 對齊
   ↓
4. 衍生報表抽查(若有後台 / 會計報表口徑驗證 SQL,在此跑一次)
   ↓
5. 全部 0 差後才返回 Step 7 重新分析源頭差異;否則重複此序列
```

> 為何禁止「只 revert 不重跑 SBR」：`summary_bet_record` 寫完 SBR 後接著跑 `SP_summary_bet_record_team`、`SP_summary_rolling_chip_record` — 這些 derived 表不會因為刪 recovery_logs / 改 BR/TBR 而自動回滾，必須**重跑 SBR 命令觸發 SP 重算**才會同步。漏這一步會造成 base 看似乾淨、team / 會計報表仍見錯帳的隱性污染。

---

## Phase E — 收尾

### Step 10：殘留 unsettled 盤點與處置

對齊完一輪後幾乎都會剩 status=未結算 紀錄 — 這是「待結算負債」，必須依異常分類處置，不可放任永久卡住。

主要分類：(A) status 卡未結算 TBR 已存在、(B) status 卡未結算 TBR 缺失、(C) Multi-bet stuck、(D) 缺原始表資料、(E) payout_time 不一致。

> ⛔ 殘留處置含寫入（status 更新 / 重拉 / fix），執行前須通過寫入 STOP gate confirm。
>
> 完整異常分類、stuck 根因模板、會計語意、兩階段重拉、永久殘留送 vendor 客服 SOP、業務處置選項表 → [`reference/residual_handling.md`](reference/residual_handling.md)

### Step 11：查補帳結果

跑完所有修補後，用 `list-sbr-corrections` skill 列出 OP 友善 remark：

```
查 {vendor} 2026-05-02 補帳
查 {vendor} 今日補帳
查 {vendor} 最近 7 天補帳
```

skill 會輸出 `補M/D b:X wl:Y` 一行一日，可直接貼工單。

---

## 防呆檢查清單（修復執行）

### 進場前
- [ ] **已握有分流結論**（來自 reconcile 診斷）；沒有就先回 reconcile 跑 Phase A/B
- [ ] 確認 vendor 三方資料時區（UTC vs SGT vs 其他，見 vendor 卡）
- [ ] 確認 `bet_id` 全程以 string 處理（避免 int 大數精度誤差）
- [ ] 走 Phase C 前：vendor 已在 `config/summary_bet_record.php` 註冊，且 time_field 與對帳卡一致；未註冊依 reconcile 的 Step 0-bis 三路徑處置
- [ ] 多白標：確認當前 connection 是要對帳的目標環境

### Phase B′（回寫 TBR）
- [ ] **B-1 回寫 TBR 前過 BO 佐證**：BR-only delta 方向＝「我方少」且金額 ≈ OP 回報的 BO 短少；若 BO 本來就一致卻冒出 settled BR-only → 疑似 BR 孤兒，不可回寫、轉 Phase D 查證
- [ ] 回寫走 vendor-specific patch、`lockForUpdate` assert TBR 不存在後單次 INSERT（禁 createOrUpdate/updateOrCreate）；patch 自帶 audit + revert

### Phase C / D（修正執行中）
- [ ] 每筆修正都進 `DB::transaction`、單筆失敗不中斷後續
- [ ] **transaction 開頭就 `lockForUpdate()` 鎖 BR**，鎖內呼叫 `BetRecordRecoveryLog::isCurrentlyRecovered()`（鎖外冪等檢查會被並行 race 繞過）
- [ ] **Type C only**：before（未結算狀態快照）/ after（含 `_meta.total_bet_record_id` + `transaction_log_id` + `is_multiple_wallet`）必須是完整快照
- [ ] 無縫錢包遊戲商補卡單：實寫分支有補結束事件的 `{tname}_deal_records`，`after._meta` 記了新 deal_record 的 id
- [ ] **Type A「補建」走專屬 patch、不寫通用 `bet_record_recovery_logs`**（通用 revert 沒 delete BR 分支，會留孤兒 BR 永久 drift）— patch 自帶 audit 須記 `inserted_br_id` + `inserted_tbr_id` + 獨立 revert 子命令同時 delete BR + delete TBR + drift check
- [ ] **Type B「修既有 TBR 金額」走專屬 patch、不寫通用 `bet_record_recovery_logs`**（通用 revert 會 DELETE 既有合法 TBR）— patch 自帶 audit 須記 `before_tbr_snapshot` + 獨立 revert 子命令做 UPDATE restore
- [ ] 必填 `reason`（execute 模式）、`member_id` 必填、`source` 帶 batch 唯一識別
- [ ] 同步修正 BR + TBR（不可只動其中之一）
- [ ] 鎖定順序固定 `BR → TBR → wallet`（避免 deadlock）
- [ ] SBR 跑歷史月份前**先 dry-run** 評估 correction 規模

### Phase D / E（對齊後）
- [ ] BR ↔ TBR `SUM(bet)` `SUM(valid_bet)` `SUM(win_lose)` 全歸零再跑 SBR
- [ ] SBR 跑完看 corrections 數字、確認非預期不為 0
- [ ] TBR ↔ SBR 用 `data_time` 過濾 SBR（不是 summary_time）
- [ ] 對齊判讀用 `status=已結算` 而非 `all`（過濾未結算殘留）
- [ ] 用 `list-sbr-corrections` 出 OP 友善 remark 確認補了多少

### 跨日 / 結帳前
- [ ] 月結帳前先匯出 status=未結算清單，分類處置（見 `reference/residual_handling.md`）
- [ ] 未處置的「未結算」紀錄**不應計入月帳營收**，僅作待結算負債管理
- [ ] 跨白標部署需逐 connection 重跑（見 `../reconcile-bet-records/reference/sum_validation_advanced.md` §5）

---

## 相關 skill 與檔案

### Skill cross-ref
- [`reconcile-bet-records`](../reconcile-bet-records/SKILL.md) —— **對帳診斷**（純讀）：環境健檢、三表 SUM 對齊、分流判斷、逐筆驗證；**本 skill 的前置**（提供分流結論）+ 共享 §0 心智模型 / §4 SUM SQL
- `list-sbr-corrections` —— SBR correction 補帳結果查詢（Step 11 用，OP 友善 remark）
- `whitelabel-container-exec` —— 多白標 DB connection 切換協議
- `docs/reconcile/vendors/{name}.md` —— vendor 對接卡（取資料、時區、命令、status enum）

### References（本 skill 內部，需要時讀）
- [`reference/recovery_logs.md`](reference/recovery_logs.md) — 補帳 schema、寫入範本、撤回命令、deal_records 沖銷事件陷阱
- [`reference/sbr_command.md`](reference/sbr_command.md) — SBR 命令完整參數、關帳判斷、註冊新 vendor
- [`reference/residual_handling.md`](reference/residual_handling.md) — 殘留 unsettled 異常分類、兩階段重拉、業務處置表

### 關鍵檔案
| 用途 | 路徑 |
|---|---|
| SBR 補帳命令 | `app/Console/Commands/SummaryBetRecord/SummaryBetRecordCommand.php` |
| SBR 補帳服務 | `app/Console/Commands/SummaryBetRecord/SummaryBetRecordService.php` |
| SBR vendor 設定 | `config/summary_bet_record.php` |
| 撤回審計表 migration | `database/migrations/2026_05_06_000001_create_bet_record_recovery_logs_table.php` |
| 審計 Model | `app/Models/BetRecordRecoveryLog.php` |
| recovery type enum | `app/Enums/BetRecordRecoveryTypeEnum.php` |
| 跨 vendor 撤回命令 | `app/Console/Commands/BetRecordRecovery/Revert.php` |
| TBR Service | `app/Services/TotalBetRecordService.php` |
| 數值運算（禁原生浮點） | `app/Helpers/Common.php` |
