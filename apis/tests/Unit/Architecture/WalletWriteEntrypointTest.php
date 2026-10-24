<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * 錢包寫入入口守衛
 *
 * member_wallets 的唯一鍵日後會改成「會員＋類型＋幣別」三元組（時點已移出第一期，
 * 排在 14 支直寫 SP 改造完成之後）。到那一刻，繞過 WalletService 的直寫不會報錯，
 * 只會靜默寫進幣別欄位＝預設值的那一筆——這是本案最容易漏掉的失敗模式。
 * 2026-09-01 已把 app/ 的直寫全數收斂歸零，本測試讓歸零狀態持續成立。
 *
 * 守五種繞過型態（對應寫入路徑掃描的既知盲區；2026-09-01 圓桌審查後擴充）：
 *   A. alias import（`use ... MemberWallet as X`）——別名的唯一效果是讓靜態掃描失明，直接禁止
 *   B. `new MemberWallet` 實例化後 save()——不含 `::` 的寫入；含 FQCN／部分限定
 *      （`new \App\Models\MemberWallet`、`new Models\MemberWallet`）
 *   C. `DB::table('member_wallets')` 繞過 Model（CLAUDE.md 本就禁止，這裡多鎖一層）
 *   D. `MemberWallet::` 起頭的語句（到分號為止，可跨行）內出現寫入動詞——含
 *      `MemberWallet::create()` 直呼、`MemberWallet::query()->…->createOrFirst()` 鏈式，
 *      與 `MemberWallet::firstOrNew()->save()`、靜態起頭的 mass update
 *   E. 表名字面 `member_wallets` 出現在允許清單以外的檔案——一條規則涵蓋
 *      `SQLHelper::insertIgnore('member_wallets', …)`（被刪除的 WalletRepository 舊碼、
 *      revert 即復活）、`DB::connection()->table()`、`'member_wallets as mw'` 別名、
 *      `DB::statement('INSERT … INTO member_wallets …')` 等所有靠字串點名表的路徑
 *
 * 已知防不到的（誠實記錄，別誤信本測試是完備的）：
 *   - Model 實例存進變數／屬性後在遠處 save()／update()（不經表名字面、語句不以
 *     MemberWallet:: 起頭）；字串拼出的類名
 *   - D 型語句內含字面分號的字串（`where('memo', 'a;b')`）會提早截斷語句、漏看其後動詞
 *   - D 型從嚴匹配的代價：MemberWallet:: 起頭語句內 closure 裡「別的 Model」的寫入
 *     動詞會誤歸戶到錢包頭上（false positive，寧可錯報供人工看一眼）
 *   前兩類靠 code review 與第四期的反向對帳，不靠這支。
 *
 * 偵測器本身由 test_detector_catches_known_bypass_shapes() 以字串 fixture 釘住行為，
 * 防止改壞正則或清空 WRITE_METHODS 後守衛「永遠綠」而無人發現。
 *
 * php artisan test tests/Unit/Architecture/WalletWriteEntrypointTest.php
 */
class WalletWriteEntrypointTest extends TestCase
{
    /** 允許寫入 MemberWallet 的檔案（相對 base_path）——就是統一入口本人 */
    private const array ALLOWED_FILES = [
        'app/Services/WalletService.php',
    ];

    /**
     * E 型（表名字面）額外允許的檔案：唯讀 join／報表查詢。
     *
     * EventV2Controller 的 5 處字面是活動報表 leftJoin 與欄位限定（2026-09-01 圓桌
     * rorin 席逐處查證：唯讀、後台調整另走 lockForUpdate＋型別限定，防護完整）。
     * 它此前能通過守衛是舊正則的巧合而非刻意放行，這裡改為顯式登記。
     */
    private const array TABLE_LITERAL_ALLOWED_FILES = [
        'app/Http/Controllers/Agent/EventV2Controller.php',
        // 影子餘額每日校驗：members join member_wallets 的唯讀比對（差異只告警不改資料）
        'app/Console/Commands/Schedule/VerifyMemberWalletBalance.php',
        // 新人挑戰後台進度列表：leftJoin 活動錢包餘額供列表顯示（唯讀）；後台調整餘額走
        // WalletService::getLockedMemberWallet()，不經表名字面（2026-09-03 合併 master 時登記）
        'app/Services/Agent/NewbieChallengeAgentService.php',
    ];

    /** 視為寫入的方法（firstOrNew 本身不落庫，但唯一用途是接 save()，從嚴列入） */
    private const array WRITE_METHODS = [
        'create',
        'forceCreate',
        'firstOrCreate',
        'createOrFirst',
        'firstOrNew',
        'updateOrCreate',
        'insert',
        'insertOrIgnore',
        'insertGetId',
        'upsert',
        'update',
        'increment',
        'decrement',
        'save',
        'fill',
    ];

    public function test_allowed_file_lists_point_at_existing_files(): void
    {
        // 允許清單裡的路徑若因改名／刪除而失效，條目會靜默留存——
        // 日後新檔佔到同一路徑就自動繼承豁免，這裡釘住清單與現實同步
        foreach ([...self::ALLOWED_FILES, ...self::TABLE_LITERAL_ALLOWED_FILES] as $file) {
            $this->assertFileExists(base_path($file), "守衛允許清單指向不存在的檔案：{$file}");
        }
    }

    public function test_no_direct_member_wallet_writes_outside_wallet_service(): void
    {
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            if (in_array($relative, self::ALLOWED_FILES, true)) {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents($file->getPathname()));
            $allowTableLiteral = in_array($relative, self::TABLE_LITERAL_ALLOWED_FILES, true);

            foreach ($this->findViolations($source, $allowTableLiteral) as [$line, $excerpt]) {
                $violations[] = "{$relative}:{$line} → {$excerpt}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            '錢包建立／寫入必須走 WalletService::getOrCreateWallet()／getLockedWallet()，'
            . "不可繞過統一入口（唯一鍵改三元組後，繞過的寫入會靜默落在幣別＝預設值的列）。違規：\n"
            . implode("\n", $violations)
        );
    }

    /**
     * 偵測器自測：餵字串 fixture 釘住五種型態的攔截行為與七種不得誤報的形狀。
     *
     * 沒有這支的話，改壞正則或清空 WRITE_METHODS 只會讓主測試「永遠綠」——
     * 主測試唯一的斷言是「掃不到違規」，偵測器安靜失效時它分不出「乾淨」與「瞎了」。
     */
    public function test_detector_catches_known_bypass_shapes(): void
    {
        $mustCatch = [
            'D 直呼' => 'MemberWallet::create([\'member_id\' => 1]);',
            'D 鏈式' => 'MemberWallet::query()->where(\'member_id\', 1)->createOrFirst([], []);',
            'D firstOrNew 接 save' => 'MemberWallet::firstOrNew([\'member_id\' => 1])->save();',
            'D 靜態 mass update' => 'MemberWallet::where(\'member_id\', 1)->update([\'balance\' => 1]);',
            'B 裸 new' => '$w = new MemberWallet();',
            'B FQCN new' => '$w = new \App\Models\MemberWallet();',
            'B 部分限定 new' => '$w = new Models\MemberWallet();',
            'A alias import' => 'use App\Models\MemberWallet as Wallet;',
            'C DB::table' => 'DB::table(\'member_wallets\')->insert([]);',
            'E SQLHelper（被刪的 WalletRepository 舊碼形狀）' => 'SQLHelper::insertIgnore(\'member_wallets\', [$data]);',
            'E connection 繞道' => 'DB::connection(\'mysql\')->table(\'member_wallets\')->insertOrIgnore([]);',
            'E 表別名' => 'DB::table(\'member_wallets as mw\')->insert([]);',
            'E raw SQL' => 'DB::statement("INSERT IGNORE INTO member_wallets (member_id) VALUES (1)");',
        ];

        foreach ($mustCatch as $label => $code) {
            $this->assertNotEmpty(
                $this->findViolations($this->stripComments("<?php\n" . $code), false),
                "偵測器應攔截【{$label}】卻放行了：{$code}"
            );
        }

        $mustPass = [
            'new 到別的類' => '$d = new MemberWalletData($a, $b, true);',
            '唯讀查詢' => '$w = MemberWallet::where(\'member_id\', 1)->first();',
            '// 註解內的寫入' => '// MemberWallet::create([\'member_id\' => 1]);',
            '# 註解內的寫入' => '# MemberWallet::create([\'member_id\' => 1]);',
            '字串內 // 不是註解、同行其後仍要看' => '$u = \'https://x/y\'; $w = MemberWallet::query()->first();',
            '實例寫入不以 MemberWallet:: 起頭（已知防不到，釘住以免改 D 型時誤傷）' => '$memberWallet->update([\'balance\' => 1]);',
            'E 允許清單內的表名字面' => '$q->leftJoin(\'member_wallets\', \'a\', \'b\');',
        ];

        foreach ($mustPass as $label => $code) {
            $allowTableLiteral = str_starts_with($label, 'E ');

            $this->assertSame(
                [],
                $this->findViolations($this->stripComments("<?php\n" . $code), $allowTableLiteral),
                "偵測器不應誤報【{$label}】：{$code}"
            );
        }

        // 字串內 // 的反向驗證：同行 // 之後的寫入必須仍被看見（舊實作會把它當註解抹掉）
        $this->assertNotEmpty(
            $this->findViolations($this->stripComments("<?php\n" . '$u = \'https://x/y\'; MemberWallet::create([]);'), false),
            '字串內的 // 不得被當成註解、吃掉同行其後的寫入語句'
        );

        // E 允許清單只豁免表名字面，不豁免 DB::table 繞 Model（C 型無條件）
        $this->assertNotEmpty(
            $this->findViolations($this->stripComments("<?php\n" . 'DB::table(\'member_wallets\')->update([\'balance\' => 0]);'), true),
            'E 允許清單內的檔案仍不得使用 DB::table(\'member_wallets\')'
        );
    }

    /**
     * @return list<array{int, string}>
     */
    private function findViolations(string $source, bool $allowTableLiteral): array
    {
        $found = [];
        $write = implode('|', self::WRITE_METHODS);

        // A. alias import：一出現就算違規，不看它後續怎麼用
        // B. new MemberWallet（含 FQCN／部分限定；尾字界防止 MemberWalletData 誤中）
        // C. DB::table('member_wallets')——無條件違規，不受 E 允許清單豁免：
        //    允許清單的正當用途是唯讀 join 的表名字面，不是 DB::table 繞 Model
        $patterns = [
            '/use\s+App\\\\Models\\\\MemberWallet\s+as\s+\w+/',
            '/new\s+[\w\\\\]*MemberWallet\s*[(;)]/',
            '/DB::table\(\s*[\'"]member_wallets/',
        ];

        // E. 表名字面（允許清單外一律違規；後綴字界擋 member_wallets_bak 這類他表，
        //    不錨定引號所以 raw SQL 內的裸表名也抓得到）
        if (!$allowTableLiteral) {
            $patterns[] = '/(?<![\w])member_wallets(?![\w])/';
        }

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$text, $offset]) {
                    $found[] = [$this->lineAt($source, $offset), trim($text)];
                }
            }
        }

        // D. MemberWallet:: 起頭的語句（到分號為止，可跨行）內含寫入動詞——
        //    同時涵蓋 `MemberWallet::create()` 直呼與 `MemberWallet::query()->…->create()` 鏈式
        if (preg_match_all('/MemberWallet::[^;]*;/', $source, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$statement, $offset]) {
                if (preg_match('/(::|->)\s*(' . $write . ')\s*\(/', $statement)) {
                    $firstLine = trim(strstr($statement, "\n", true) ?: $statement);

                    $found[] = [$this->lineAt($source, $offset), $firstLine . ' …'];
                }
            }
        }

        return $found;
    }

    /**
     * 以 tokenizer 把註解換成等長空白（保留換行，行號不漂移）。
     *
     * 不用正則剝註解：正則版分不出「字串裡的 //」與真註解（`'https://…'` 之後
     * 整行被抹白、同行其後的寫入語句跟著消失），也剝不到 `#` 註解——兩者都是
     * 2026-09-01 圓桌實測出的偵測器盲區。T_COMMENT 涵蓋 `//`、`#` 與 block comment。
     */
    private function stripComments(string $source): string
    {
        $stripped = $source;

        foreach (\PhpToken:[REDACTED] as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $blank = (string) preg_replace('/[^\n]/', ' ', $token->text);
                $stripped = substr_replace($stripped, $blank, $token->pos, strlen($token->text));
            }
        }

        return $stripped;
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }
}
