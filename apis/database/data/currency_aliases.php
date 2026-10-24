<?php

declare(strict_types=1);

/**
 * 幣別 alias 契約（多幣別錢包 第零期／階段 0.3 交付）。
 *
 * vendor_infos.currency 是 varchar(5) 的自由字串（欄位註解只寫「幣種代碼」），
 * 實際同時裝了好幾種東西：標準法幣、單位變體、加密貨幣、電子錢包通道，
 * 以及主檔查無的代碼。本檔是「字串 → 標準幣別」的對照規則，
 * 第二期為 vendor_infos 補 currency_id 時以它為準。
 *
 * ── 這個欄位不是純顯示標籤（2026-08-28 實查）──
 *
 * 🔴 它已經流進對外 API，改值等於改對三方的 API 行為。全 repo 實測 57 處／29 檔：
 *    - 單一錢包 controller 12 檔（SexySlotCq9SW／PlayTech／Imperium／Bole／SpadegamingSW 等）
 *    - 遊戲商 Driver 與 Service 15 檔（CRSW／PPPOP／WFSW／ClotPlaySW／FFFSW／WudiSW／Baison，
 *      以及 MotivationSW 的 6 個 Libs：Bet／Credit／Debit／Refund／Rollin／Endround）
 *    - 轉帳錢包 controller 2 檔（NextSpinController／EbetController）
 *    - 後台顯示 1 檔（Filament BetRecordCorrection.php:171 的下拉標籤）——唯一非對三方的用途
 *    其中兩處是拿它擋請求：PxplaySwController.php:121、TTHSWController.php:1286，
 *    以 !== 比對三方傳入的 currency，PHP 字串比較大小寫敏感 ⇒ 改大小寫會開始拒絕三方請求。
 *    ⚠️ 別把曝露面窄化成「單一錢包 controller」——轉帳錢包與遊戲商 Driver 層同樣在用。
 *
 * ⚠️ 幣別參數是雙來源，且兩者不必然相同。vendor_infos.info（text，VWorld 614 筆全為合法
 *    JSON）內另有 CURRENCY／Currency／CURRENCY_NAME 鍵，那才是三方要求的參數格式：
 *      IBC(saba)      currency=IDR  info.CURRENCY=15    三方用數字碼
 *      MMK 商戶        currency=MMK  info.CURRENCY=MK2   三方自訂縮寫
 *      USDT 商戶       currency=USDT info.CURRENCY=UST   同上
 *      M9BET／AE Sexy  currency=KIDR info.CURRENCY=IDR   欄位是本地計價單位、三方收標準碼
 *      NEW4D          currency=MYR  info.CURRENCY=IDR   rate=0.00025，MYR 站接印尼盤
 *    ⇒ vendor_infos.rate 同時承擔「計價單位換算」（0.001 千分制）與「跨幣別匯率」
 *      （0.00025 ≈ 1 MYR / 4000 IDR）兩種語意，不能只靠 rate 反推幣別。
 *
 * ── 欄位分工（2026-08-28 拍板）──
 *
 * currency_id（第二期新增，必須建索引）＝ 機器用。玩家切換幣別後要過濾出符合該幣別的
 *   遊戲商，走的就是這個索引；錢包對應與報表彙總也以它為準，不必解析字串變體。
 * currency（現有字串）＝ 人用。後台識別、免 join 就看得懂，保留原樣。
 * ⇒ 兩者並存不是重複，是「給程式判讀」與「給人識別」的分工。
 *
 * ⭐ KVND／KIDR 必須保留，不是待清除的錯誤代碼——有代理商明確希望以千分制計價
 *    （08-28 補充的業務理由）。這與 08-27 決議三一致：KVND 走 vendor_infos.currency
 *    這個字串欄位送三方，currency_id 維持 VND，兩者外觀不一致是刻意設計。
 *    定位是「計價單位」而非「幣別」，千分制的尺度差由 vendor_infos.rate 吸收。
 *    ⇒ unit_variant 分類的目的不是「消滅這些代碼」，而是「讓機器知道它們對應哪個標準幣別」。
 *
 * ⚠️ VND2／IDR2 也一樣不刪。決議三已定：變體 id（4／5／7／8）一律不刪列、不加 DB 旗標，
 *    改由 CurrencyEnum 的「可作為會員錢包幣別」白名單排除。六站實測 VND2 零筆、
 *    IDR2 僅 VWorld 1 筆停用列（該列 rate 為 1.0，與千分制命名不符），但零使用
 *    只代表「不必遷移」，不代表要刪。
 *
 * ── currencies 主檔現況（2026-08-28 六站全量實測）──
 *
 * 六站（TPower／VWorld／Yes2Win／AlfaWin77／Me77／VPlus33）皆 21 筆、id→code 逐字一致
 * （指紋 d9f31b900e19430c8dcc923b469262e2），由 SyncCurrenciesSeeder 統一寫入。
 * 其中 VND2(4)／KVND(5)／IDR2(7)／KIDR(8) 四個 id，在 organizations、banks、
 * cash_flow_banks、standard_banks、order_types、agent_register_limits、
 * agent_member_field_settings、agent_cash_flows 全部零引用；有資料的只有 VND(3) 與 IDR(6)。
 * ⚠️ orders（816 萬列）與 order_histories（1,358 萬列）沒有 currency_id 索引，尚未全量
 *    掃描 ⇒ 「四個 id 完全無人使用」目前是未驗證結論。這不影響「不刪列」的決議，
 *    但任何要動這些 id 的行為（含改 code）之前都必須先補掃這兩張表。
 * ✅ 兩份幣別種子資料曾並存且不同步（SyncCurrenciesSeeder 讀 currencies.php；CurrencySeeder
 *    另自帶 18 筆、缺 BDT／TON／INR、以 insertOrIgnore 明寫 id），已於 2026-09-07 第二期 2.1 收斂：
 *    CurrencySeeder 改讀同一份 currencies.php，兩支寫入前都經 GuardsCurrencyMasterData 比對主檔
 *    id→code，不一致即拋例外，不再靜默略過或整批回滾。SyncCurrenciesSeeder 仍是只增不改不刪
 *    （buildToInsert 只在 code 不存在時插入）。
 *
 * 分類：
 *   normalize         需要先改寫才對得上的原始值（大小寫不一致如 kIDR → KIDR、拼寫變體如 HKB → HKD）
 *   standard          ISO 4217 標準法幣，且已在 currencies 主檔內
 *   pending_standard  已知的標準法幣，但主檔還沒建 ⇒ 補進主檔後要搬到 standard
 *   unit_variant      單位變體 → 標準幣別。尺度差異由 vendor_infos.rate 在遊戲商層吸收
 *   crypto            虛擬貨幣。**可作為錢包幣別**（2026-09-17 決議㉚，撤回決議㉖）：各自是獨立幣別，
 *                     解析結果帶得出自己的 currency_id；本分類只是稽核輸出時的標籤
 *   non_currency      不是幣別（電子錢包通道、站台點數），需業務裁決是否保留
 *   unresolved        語意未查明的代碼；值為處置說明
 *
 * 🔴 KVND 必須映射到 VND，不可映射到 KVND(5)。指錯會讓代理商報表出現兩列越南盾、
 *    尺度差 1000 倍且不能相加。
 *
 * ⚠️ 同一個代碼只能屬於一個分類（由 CurrencyContractTest 強制），
 *    否則回填時會取到不確定的結果。
 */

return [
    'normalize' => [
        // 2026-08-28 六站全量實測（以 CAST(currency AS BINARY) 分組——預設 collation 大小寫
        // 不敏感，直接 GROUP BY 會把 kIDR 與 KIDR 併成一列而看不出問題）：
        //   小寫變體只出現在 VWorld，3 筆（id=319 100Plus／321 Obet33／326 DG，
        //   同屬 wintotoidn 組織），status 全為 0；同站另有大寫 KIDR 3 筆亦全停用
        //   ⇒ 兩種寫法在同一站並存。其餘五站無任何大小寫變體。
        'kIDR' => 'KIDR',
        // 2026-09-07 RB 裁決（第二期 2.1）：HKB 是 HKD 的拼寫變體，不是單位變體。
        //   證據：VWorld 唯一的 HKB 商戶 id=227「PEGASUS-HK(單錢包)」啟用中的代理連結只有組織 15 PGSHK
        //   （organizations.currency_id=16＝HKD；另有已停用連結掛 MMGames／vworld），69 個帳號全屬
        //   PGSHK 會員，rate 1.00000；
        //   後台建商戶命令的幣別選項只有 HKD。字串本身另由 ops 走後台改成 HKD（op 工單），
        //   改之前須確認該商戶 info 無 CURRENCY 鍵且對外 API 消費端不依賴 HKB 字面值。
        'HKB' => 'HKD',
    ],

    'standard' => [
        'MYR', 'SGD', 'VND', 'IDR', 'THB', 'MMK', 'HKD', 'PGK', 'AUD', 'BDT', 'INR',
        'PHP', 'USD', 'CNY',    // 2026-09-07 第二期 2.1 補進主檔（id 22／23／24）
    ],

    'pending_standard' => [
        // 2026-08-28 VWorld 實測：PHP(27 筆／16 啟用)、USD(29／14)、CNY(1／1)、BND(18／0) 四種在
        // vendor_infos.currency 有商戶但主檔查無。前三種有啟用中商戶，已於 2026-09-07 第二期 2.1
        // 補進主檔並移到 standard；BND 全數停用，是否補進主檔待 RB 裁決（若補則 id=25，並同步搬到 standard）。
        'BND',
    ],

    'unit_variant' => [
        // 2026-08-28 六站實測的存量：
        //   VND2  六站零筆（主檔 id=4 目前無商戶在用，但依決議三不刪列）
        //   IDR2  僅 VWorld 1 筆（id=328 RedMario-wintotoidn，停用）
        //         🔴 該筆 rate 實測為 1.00000 而非千分制的 0.001，命名與實際尺度不符
        //   KVND  僅 VWorld 1 筆（id=62 SA(正式機)，停用）
        //   KIDR  VWorld 3 筆全停用；AlfaWin77 6 筆全啟用
        //         （M9BET／AE Sexy／Vpower 及三個 E2 副本，rate 皆 0.001）
        // ⭐ KVND／KIDR 保留（理由見檔頭）。本分類只負責「字串 → 標準幣別」的對照，
        //    不代表要把這些代碼改掉。實際需要修正的只有大小寫不一致的 kIDR（VWorld 3 筆、
        //    全停用）；AlfaWin77 那 6 筆啟用中的 KIDR 本來就是標準寫法，不需異動。
        // 🔴 任何要改 currency 字串值的動作，都須先確認該商戶的 info 是否有 CURRENCY 鍵——
        //    Vpower(28)／Vpower(61) 的 info 沒有該鍵，三方參數可能直接取自本欄位。
        'VND2' => 'VND',
        'KVND' => 'VND',
        'IDR2' => 'IDR',
        'KIDR' => 'IDR',
    ],

    'crypto' => [
        // 與法幣同表但語意不同：無單位變體、匯率波動大。
        // 2026-09-17 RB 裁決（決議㉚，撤回決議㉖）：每一種虛擬貨幣都是獨立幣別，可作為錢包幣別。
        //   USDT 不對應 USD——它在資料庫裡比 USD 早存在，且未來可能有客戶要求用 TON 或其他
        //   非主流貨幣，幣別定義要保持彈性。要不要開放給某一群代理由 agent_group_currencies 決定。
        //   落在本分類的代碼解析結果仍帶得出自己的 currency_id（USDT → 10），分類只是稽核輸出的標籤。
        //   存量（2026-09-09 VWorld 唯讀實查）：USDT 商戶 4 家（2 啟用）；TON／BUSD 零商戶。
        //   ⚠️ 決議㉛ 會把這三個 id 重編到千位數，屆時 CurrencyEnum 的值跟著改，本檔不必動。
        'USDT', 'TON', 'BUSD',
    ],

    'non_currency' => [
        'GCASH',    // 菲律賓電子錢包通道，不是幣別
        'PayMaya',  // 同上
        'FON',      // 站台點數
    ],

    'unresolved' => [
        // HKB 已於 2026-09-07 裁決為 HKD 拼寫變體、移到 normalize。
        // MSC：2026-09-07 RB 裁決保留在本段不動。查證結果：VWorld 商戶 id=34「target(MSC)」已停用、
        //   account_prefix MSCVP，target 其他商戶全以站名命名，MSC 是站名被填進幣別欄；151 個帳號最後建於
        //   2020-12-01，掛的代理連結全停用。商戶停用故不阻斷回填，稽核會持續列為 unresolved（預期）。
        'MSC' => '站名誤填進幣別欄、已停用（VWorld 1 個商戶，啟用中 0 個）；RB 2026-09-07 裁決保留待查、不歸類',
    ],
];
