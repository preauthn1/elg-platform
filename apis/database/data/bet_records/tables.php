<?php

declare(strict_types=1);

/**
 * canonical 注單表清單（多幣別錢包 第零期／階段 0.2 交付）。
 *
 * key   = 表名去掉 `_bet_records` 後綴的前綴
 * kind  = vendor｜event｜non_bet_record｜orphan｜registry_only
 *   vendor         遊戲商注單源頭表（2026-09-03 決議⑭：不加欄位，幣別與商戶記在 TBR）
 *   event          活動注單表，寫入者不同（EventTotalBetRecordService）且走獨立鏈路：
 *                  帳號表帶 event_vendor_info_id → event_vendor_infos（只有 currency 字串），
 *                  注單進 event_total_bet_records 不進 TBR；是否分幣別待拍板（決議檔第七節）
 *   non_bet_record 命名撞上 `%_bet_records` 但不是注單源頭，不可納入加欄位範圍
 *   orphan         生產庫存在但 vendors.php 查無對應，來源待查
 *   registry_only  vendors.php 有 tname 但生產庫無對應表
 * currency_source = 該表可取得幣別的欄位；null 表示尚未確認（BR 不加欄位後僅供轉檔 SP 取值參考，第三期 3.1 視需要填）
 * owner           = 寫入邏輯所在的 repo，**陣列**（APIs／RD-Studio-01／API_External／待查）。
 *                   ⚠️ 這是「寫入邏輯放在哪」，不是「誰觸發」——排程歸屬與 fencing
 *                   由 Task 3～6 的 writers.php 定案，兩者可能不同。
 *                   🔴 用陣列是因為**雙寫是現行常態**：遊戲商是逐家遷移的，APIs 接手後
 *                      舊系統的排程／路由未必同步收掉（例：ace333 兩邊都在寫）。單值字串
 *                      記不下這件事，第三期照單值改就會漏掉舊系統那一端的寫入路徑。
 *                   ⚠️ API_Agent 經四個舊 repo 全掃確認對注單表零寫入，已從值域移除。
 *
 * 2026-08-31 六站生產庫實測（`RIGHT(TABLE_NAME, 12) = '_bet_records'`）：
 *   VWorld／TPower／Alfawin77／Me77／VPlus33  各 138 張，表名逐字相同
 *                                            （前綴清單 MD5 d13e0543b53bfbd19ec9e5f366c4f512）
 *   Yes2Win                                  140 張，多出 bolakk4d0217、bolakk4d_0217
 * 扣掉一律排除的 total 與 summary 兩張後，前五站 136 張、Yes2Win 138 張。
 * 本檔登記六站聯集 138 張，另加 5 筆 registry_only，共 143 筆。
 *
 * 🔴 `total_bet_records`（TBR）與 `summary_bet_records`（SBR）刻意不登記。
 *    兩者是彙總去處而非注單源頭，第三期對它們的改造走本體流程、不走這份清單；
 *    Task 2 的稽核命令 SQL 亦以 `NOT IN` 一致排除，兩邊口徑必須保持同步——
 *    若日後要把它們納入，命令的 SQL 也要同時改，否則會報出假差異。
 * ⚠️ vendors.php 的 id=7「沒有雞的鬥雞」tname 為空字串且已停用，刻意不建條目。
 *
 * 🔴 **新接遊戲商必須同步補條目**，由
 *    BetRecordTableRegistryParityTest::test_every_vendor_with_a_table_name_is_covered_by_the_registry
 *    守住：vendors.php 只要有 tname，就必須有本檔條目引用它的 id（tname 為空者除外）。
 *    生產庫還沒有那張表時登記為 registry_only；等跨白標稽核報出「registry_only 但 DB 已有」
 *    再改標 vendor。這條守衛是 2026-09-03 補的——在那之前，新遊戲商只加 vendors.php 與
 *    自己的注單表、不碰本清單時全部照綠，清單會靜默與生產庫脫節。
 * ✅ **首個實例（已登記）**：AI GAMING（tname `aigamingsw`、vendor_id 260、單一錢包）的
 *    `aigamingsw_bet_records` 只在 Dev 存在、六站生產庫皆無；其 vendors.php 條目隨工單
 *    target/tickets#4251 上 master 時，該工單已同步補上 registry_only 條目（見下方 `aigamingsw`），
 *    正是上述守衛在 develop 擋下後補的。TBR 寫入路徑走 TotalBetRecordService，
 *    已被 writers.php 的既有通用條目涵蓋，writers.php 不需新增。
 * ⚠️ 生產庫實況比對走 `php artisan bet-record:audit-tables`（可跨白標）。
 *    🔴 「唯讀」只到查詢層：命令自己只有一條 SELECT，但框架層的 CommandProvider 會對每次
 *       artisan 執行寫一筆 command_logs，而 CommandLog 未指定 connection ⇒ 寫進當下連線的
 *       生產庫。2026-08-31 六站實跑已在 VWorld 留下 3 筆（status=-1）、Yes2Win 2 筆。
 *
 * 🔴 owner 的實證方法（2026-08-31 全掃四個舊 repo）：以各 repo 的 `*BetRecord.php` Model
 *    解析表名（`$table` 或 Laravel 命名慣例），再回查該 Model 的 create／insert 呼叫點。
 *    ⚠️ 兩個已知盲區，是 37 筆「待查」的主因，也是 Task 3～6 writers.php 要補的：
 *      1. 舊系統部分抓單命令的目標表名由 `vendors` 表資料動態決定（`$this->table`），
 *         靜態解析不到；這類條目的 note 標了「未驗證」。
 *      2. 走 stored procedure 或動態 Model 的寫入路徑不在掃描範圍內。
 */

return [
    'ace333' => ['kind' => 'vendor', 'vendor_ids' => [6], 'currency_source' => null, 'owner' => ['APIs', 'RD-Studio-01'], 'note' => '全 registry 唯一「APIs 有自動排程、舊系統排程也還開著」的雙寫：APIs 端 Ace333/Driver.php 是唯一實作 ShouldScheduleBetRecordFetch 的 Driver，RD-Studio-01 的 Kernel.php:186 `ace333_bet_record` 同時未註解'],
    'ace333sw' => ['kind' => 'vendor', 'vendor_ids' => [109], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'acewin' => ['kind' => 'vendor', 'vendor_ids' => [110], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'aes' => ['kind' => 'vendor', 'vendor_ids' => [25], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'afbsw' => ['kind' => 'vendor', 'vendor_ids' => [176], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'ag' => ['kind' => 'vendor', 'vendor_ids' => [1], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'aigamingsw' => ['kind' => 'registry_only', 'vendor_ids' => [260], 'currency_source' => null, 'owner' => ['APIs'], 'note' => 'AI GAMING 單一錢包接入中（target/tickets#4251），寫入邏輯在本 repo AiGamingSW controller；六站生產庫尚無對應表，上線建表後改 kind=vendor 並同步更新標頭計數與測試常數'],
    'allbet' => ['kind' => 'vendor', 'vendor_ids' => [35], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'astar' => ['kind' => 'vendor', 'vendor_ids' => [101], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'baison' => ['kind' => 'vendor', 'vendor_ids' => [113], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `baison_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'baracayfish' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => 'vendors.php 兩邊皆查無；與既有 boracayfish 僅一字之差，疑為 typo 建表。12 欄、VWorld 實測 0 列，空殼遺留'],
    'besoftsw' => ['kind' => 'vendor', 'vendor_ids' => [224], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'bng' => ['kind' => 'vendor', 'vendor_ids' => [49], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'bola2' => ['kind' => 'vendor', 'vendor_ids' => [45], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'bolakk4d' => ['kind' => 'vendor', 'vendor_ids' => [104], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'bolakk4d0217' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => '僅 Yes2Win 存在（2026-08-31 六站實測）。COUNT(*) 精確計數 0 列、18 欄與正式表 bolakk4d 逐欄相同（結構 MD5 7e11feae8bdc9612e7c9758d46f6de07）、APIs 與四個相關 repo 皆零引用，建於 2026-02-22。RB 判讀為 bolakk4d 的備份表，待 ops 確認建表意圖後清理；清掉後六站表集合即一致'],
    'bolakk4d_0217' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => '同上，僅 Yes2Win 存在；COUNT(*) 精確計數 0 列、結構與正式表 bolakk4d 相同、零引用，同為待 ops 確認清理的備份表'],
    'bolesw' => ['kind' => 'vendor', 'vendor_ids' => [127], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'boongosw' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => 'vendors.php 的 tname 與 code 兩邊皆查無（故列 orphan），但**不是空殼**：API_External 的 BoongoSingleWallet/TransferService.php:269、501 有 `BoongoswBetRecord::create()`，routes/api.php:45 路由未下線。性質是「尚未遷入 APIs」而非無主，與 baracayfish 不同'],
    'boracayfish' => ['kind' => 'vendor', 'vendor_ids' => [124], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `boracayfish_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'c93' => ['kind' => 'vendor', 'vendor_ids' => [58], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'clotlssw' => ['kind' => 'vendor', 'vendor_ids' => [213], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'clotp2psw' => ['kind' => 'vendor', 'vendor_ids' => [147], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'clotsw' => ['kind' => 'vendor', 'vendor_ids' => [142], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'cosmoplaysw' => ['kind' => 'vendor', 'vendor_ids' => [249], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'crsw' => ['kind' => 'vendor', 'vendor_ids' => [121], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'ct' => ['kind' => 'vendor', 'vendor_ids' => [27], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `ct_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'dct' => ['kind' => 'vendor', 'vendor_ids' => [69, 71, 72, 97], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'dg' => ['kind' => 'vendor', 'vendor_ids' => [29], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `dg_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'ds88' => ['kind' => 'vendor', 'vendor_ids' => [68], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'dstplaysw' => ['kind' => 'vendor', 'vendor_ids' => [256], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'e88sport' => ['kind' => 'vendor', 'vendor_ids' => [247], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'ebet' => ['kind' => 'vendor', 'vendor_ids' => [13], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'epic_sw' => ['kind' => 'vendor', 'vendor_ids' => [85], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'esport' => ['kind' => 'vendor', 'vendor_ids' => [38], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'event_fumasw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'event_pgssw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'event_ppsw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'event_total' => ['kind' => 'non_bet_record', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => '活動版 TBR（event_total_bet_records），是彙總去處不是注單源頭，不納入第三期加欄位範圍；具 vendor_id／member_id／summary_time，VWorld 實測 1,110 萬列（活躍）'],
    'event_uuslotsw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'event_vpowersw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'event_wfsw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'event_wudisw' => ['kind' => 'event', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'evo' => ['kind' => 'vendor', 'vendor_ids' => [57, 63, 64, 65, 66, 67, 73, 74, 76, 78, 79, 80, 84, 86, 87, 88, 89, 90, 92, 93, 94, 95, 96, 98, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140, 141, 149, 150, 151, 152, 153, 154, 155, 156, 157, 158, 159, 160, 161, 162, 163, 164, 165, 166, 173, 174, 175, 180], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'fffsw' => ['kind' => 'vendor', 'vendor_ids' => [143], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'fishing' => ['kind' => 'vendor', 'vendor_ids' => [39], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'fumasw' => ['kind' => 'vendor', 'vendor_ids' => [171, 214], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'gfg2' => ['kind' => 'vendor', 'vendor_ids' => [105], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'gmsw' => ['kind' => 'vendor', 'vendor_ids' => [237, 238, 239, 240, 241, 242, 243, 244, 245, 246], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'great_wall' => ['kind' => 'vendor', 'vendor_ids' => [5], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'haolong' => ['kind' => 'vendor', 'vendor_ids' => [4], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'ia' => ['kind' => 'vendor', 'vendor_ids' => [21], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'igs' => ['kind' => 'vendor', 'vendor_ids' => [26], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `igs_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'impsw' => ['kind' => 'vendor', 'vendor_ids' => [148], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'isb' => ['kind' => 'vendor', 'vendor_ids' => [14], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'j3' => ['kind' => 'vendor', 'vendor_ids' => [122], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `j3_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'jkbsw' => ['kind' => 'vendor', 'vendor_ids' => [235], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'joker' => ['kind' => 'vendor', 'vendor_ids' => [2], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'kissh5sw' => ['kind' => 'vendor', 'vendor_ids' => [233], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'ky' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => 'vendors.php 的 tname 與 code 兩邊皆查無（故列 orphan），但**不是空殼**：RD-Studio-01 的 Kernel.php:553 有每 5 分鐘排程 `ky_bet_record`，GetKyBetRecord.php:268 `KyBetRecord::insert()`。VWorld 現為 0 列只代表目前無 active vendor_info，該遊戲商一旦啟用即開始寫入'],
    'l4' => ['kind' => 'vendor', 'vendor_ids' => [59], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'lbb' => ['kind' => 'vendor', 'vendor_ids' => [60], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'lfc888' => ['kind' => 'vendor', 'vendor_ids' => [100], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `lfc888_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'lucky' => ['kind' => 'vendor', 'vendor_ids' => [12], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'm8bet' => ['kind' => 'vendor', 'vendor_ids' => [103], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'mega888' => ['kind' => 'vendor', 'vendor_ids' => [10], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `mega888_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'megah5sw' => ['kind' => 'vendor', 'vendor_ids' => [119], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'megawinsw' => ['kind' => 'vendor', 'vendor_ids' => [112], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'menang88' => ['kind' => 'vendor', 'vendor_ids' => [40], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'metagamingsw' => ['kind' => 'vendor', 'vendor_ids' => [252], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'mgc' => ['kind' => 'vendor', 'vendor_ids' => [51], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'motivationsw' => ['kind' => 'vendor', 'vendor_ids' => [125], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'mt' => ['kind' => 'vendor', 'vendor_ids' => [19], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'n918' => ['kind' => 'vendor', 'vendor_ids' => [33], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'new4d2026' => ['kind' => 'vendor', 'vendor_ids' => [181], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'nex4dsw' => ['kind' => 'vendor', 'vendor_ids' => [179], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'npt' => ['kind' => 'vendor', 'vendor_ids' => [41], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'ns' => ['kind' => 'vendor', 'vendor_ids' => [48], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'obet33' => ['kind' => 'vendor', 'vendor_ids' => [43], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'onebxsw' => ['kind' => 'vendor', 'vendor_ids' => [168], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'oppo' => ['kind' => 'vendor', 'vendor_ids' => [169], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'pg' => ['kind' => 'vendor', 'vendor_ids' => [20], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'pg_round' => ['kind' => 'non_bet_record', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['API_External'], 'note' => 'PG 局次表（對照 ppsw_game_rounds 的同類），非注單本身；29 欄、VWorld 實測 145 萬列（活躍）。⚠️ 本表帶 currency 欄，第三期若要對局次資料做幣別處理需另案評估——⚠️ 評估對象是 API_External 的 PgController.php:91，APIs 端查無寫入點'],
    'target' => ['kind' => 'vendor', 'vendor_ids' => [24, 61, 62, 70], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `pgs_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'pgslot' => ['kind' => 'vendor', 'vendor_ids' => [55], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'pgssw' => ['kind' => 'vendor', 'vendor_ids' => [50, 91, 145, 177], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'play8oy' => ['kind' => 'vendor', 'vendor_ids' => [30], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'plus100' => ['kind' => 'vendor', 'vendor_ids' => [46], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'pp' => ['kind' => 'vendor', 'vendor_ids' => [54, 253], 'currency_source' => null, 'owner' => ['APIs', 'RD-Studio-01'], 'note' => null],
    'ppsw' => ['kind' => 'vendor', 'vendor_ids' => [83], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'pt' => ['kind' => 'vendor', 'vendor_ids' => [17], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'ptsw' => ['kind' => 'vendor', 'vendor_ids' => [144], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'pussy888' => ['kind' => 'vendor', 'vendor_ids' => [23], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `pussy888_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'pxplaysw' => ['kind' => 'vendor', 'vendor_ids' => [236], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'qqpk' => ['kind' => 'vendor', 'vendor_ids' => [232], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'qtech' => ['kind' => 'orphan', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => 'vendors.php 的 tname 與 code 兩邊皆查無（故列 orphan），但**不是空殼**：RD-Studio-01 的 Kernel.php:532 有每 5 分鐘排程 `qtech_bet_record`，GetQtechBetRecord.php:243 `QtechBetRecord::insert()`。VWorld 現為 0 列只代表目前無 active vendor_info'],
    'rcb988' => ['kind' => 'vendor', 'vendor_ids' => [106], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'rectanglesw' => ['kind' => 'vendor', 'vendor_ids' => [234], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'rgsw' => ['kind' => 'vendor', 'vendor_ids' => [250], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'rm' => ['kind' => 'vendor', 'vendor_ids' => [32], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'sa' => ['kind' => 'vendor', 'vendor_ids' => [37], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'saba' => ['kind' => 'vendor', 'vendor_ids' => [44], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'sbo' => ['kind' => 'vendor', 'vendor_ids' => [18], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'scr' => ['kind' => 'vendor', 'vendor_ids' => [3], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `new_scr_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'sexyslotcq9sw' => ['kind' => 'vendor', 'vendor_ids' => [255], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'sexyslotsw' => ['kind' => 'vendor', 'vendor_ids' => [248], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'sky1388' => ['kind' => 'vendor', 'vendor_ids' => [28], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'skybet' => ['kind' => 'vendor', 'vendor_ids' => [31], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'sl' => ['kind' => 'vendor', 'vendor_ids' => [56], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'slot4dsw' => ['kind' => 'vendor', 'vendor_ids' => [254], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'sma' => ['kind' => 'vendor', 'vendor_ids' => [77], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'spadegaming' => ['kind' => 'vendor', 'vendor_ids' => [47], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'spadegamingsw' => ['kind' => 'vendor', 'vendor_ids' => [81], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'suncity' => ['kind' => 'registry_only', 'vendor_ids' => [292], 'currency_source' => null, 'owner' => ['APIs'], 'note' => 'SunCity 轉帳錢包接入中（target/tickets#4434），注單由本 repo fetch_bet_record 拉取（Suncity Driver）；六站生產庫尚無對應表，上線建表後改 kind=vendor 並同步更新標頭計數與測試常數'],
    'summary_pp' => ['kind' => 'non_bet_record', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => '已實證：22 欄且具 vendor_id／member_id／summary_time，與 SBR 同型的彙總表，非注單源頭；VWorld 實測 0 列，疑似未啟用'],
    'terra' => ['kind' => 'vendor', 'vendor_ids' => [34], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'test_summary' => ['kind' => 'non_bet_record', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => '已實證：19 欄且具 vendor_id／member_id／summary_time，與 SBR 同型的彙總表；VWorld 實測 0 列。測試殘留，清理與否屬 ops，本階段只登記不刪'],
    'tfgamingsw' => ['kind' => 'vendor', 'vendor_ids' => [114], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'top55sw' => ['kind' => 'vendor', 'vendor_ids' => [182, 183, 184, 185, 186, 187, 188, 189, 190, 191, 192, 193, 194, 195, 196, 197, 198, 199, 200, 201, 202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'transaction_log_total' => ['kind' => 'non_bet_record', 'vendor_ids' => [], 'currency_source' => null, 'owner' => ['待查'], 'note' => '交易紀錄彙總表，命名撞上 %_bet_records 模式，非注單源頭；僅 5 欄、VWorld 實測 0 列'],
    'tthsw' => ['kind' => 'vendor', 'vendor_ids' => [178, 215, 216, 217, 218, 219, 220, 221, 222, 225, 226, 227, 228, 229, 230, 231, 251, 257, 258, 259, 261, 262, 263, 264, 265, 266, 267, 268, 269, 270, 271, 272, 273, 274, 275, 276, 277, 278, 279, 280, 281, 282, 283, 284, 285, 286, 287, 288, 289, 290, 291, 293, 294, 295], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'uuslotsw' => ['kind' => 'vendor', 'vendor_ids' => [82], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'v8' => ['kind' => 'vendor', 'vendor_ids' => [42], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'vplay' => ['kind' => 'vendor', 'vendor_ids' => [8], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'vplussw' => ['kind' => 'vendor', 'vendor_ids' => [172], 'currency_source' => null, 'owner' => ['APIs'], 'note' => null],
    'vpower' => ['kind' => 'vendor', 'vendor_ids' => [22], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'vpowersw' => ['kind' => 'vendor', 'vendor_ids' => [117], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'vspoker' => ['kind' => 'vendor', 'vendor_ids' => [36], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'vsport' => ['kind' => 'vendor', 'vendor_ids' => [53], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'wbet' => ['kind' => 'vendor', 'vendor_ids' => [102], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'wcasino' => ['kind' => 'vendor', 'vendor_ids' => [111], 'currency_source' => null, 'owner' => ['待查'], 'note' => '表名取自 vendors.php 的 code 欄而非 tname；vendor_id=111 因此對到兩張表（另一張是 tname 對應的 wcasinosw），全 registry 僅此一組'],
    'wcasinosw' => ['kind' => 'vendor', 'vendor_ids' => [111], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'wesw' => ['kind' => 'vendor', 'vendor_ids' => [123], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'wfsw' => ['kind' => 'vendor', 'vendor_ids' => [120], 'currency_source' => null, 'owner' => ['API_External'], 'note' => null],
    'wm' => ['kind' => 'vendor', 'vendor_ids' => [16], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'wmlivesw' => ['kind' => 'vendor', 'vendor_ids' => [126], 'currency_source' => null, 'owner' => ['待查'], 'note' => null],
    'wudisw' => ['kind' => 'vendor', 'vendor_ids' => [170, 223], 'currency_source' => null, 'owner' => ['APIs', 'API_External'], 'note' => null],
    'xe88' => ['kind' => 'vendor', 'vendor_ids' => [11], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'ybet' => ['kind' => 'vendor', 'vendor_ids' => [52], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => null],
    'ygg' => ['kind' => 'vendor', 'vendor_ids' => [15], 'currency_source' => null, 'owner' => ['RD-Studio-01'], 'note' => '⚠️ 未驗證：RD-Studio-01 有排程 `ygg_bet_record` 且含寫入呼叫，但該命令的目標表名由 vendors 表資料動態決定（`$this->table`），未逐筆實證表名對應'],
    'coke_fighting' => ['kind' => 'registry_only', 'vendor_ids' => [9], 'currency_source' => null, 'owner' => ['待查'], 'note' => 'vendors.php 有 tname，六站生產庫皆無對應表（2026-08-31 實測）'],
    'testgame' => ['kind' => 'registry_only', 'vendor_ids' => [99], 'currency_source' => null, 'owner' => ['待查'], 'note' => '同上，測試用'],
    'bngsw' => ['kind' => 'registry_only', 'vendor_ids' => [146], 'currency_source' => null, 'owner' => ['待查'], 'note' => '同上'],
];
