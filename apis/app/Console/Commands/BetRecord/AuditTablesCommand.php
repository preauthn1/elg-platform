<?php

declare(strict_types=1);

namespace App\Console\Commands\BetRecord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

class AuditTablesCommand extends Command
{
    protected $signature = 'bet-record:audit-tables
                            {--json : 以 JSON 輸出，供後續階段的工具串接}';

    protected $description = '比對 canonical 注單表清單（database/data/bet_records/tables.php）與當前連線資料庫的實際 %_bet_records 表';

    /**
     * 一律不納入比對的表。
     *
     * 🔴 這兩張是彙總去處而不是注單源頭，registry 也刻意不登記它們
     *    （見 database/data/bet_records/tables.php 檔頭）。**兩邊必須同步**——
     *    只改一邊會讓稽核報出根本不存在的差異。
     */
    private const array EXCLUDED_TABLES = ['total_bet_records', 'summary_bet_records'];

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        /** @var array<string, array<string, mixed>> $registry */
        $registry = require database_path('data/bet_records/tables.php');

        $registryOnly = array_keys(array_filter(
            $registry,
            static fn (array $entry): bool => $entry['kind'] === 'registry_only',
        ));

        $expected = array_values(array_diff(array_keys($registry), $registryOnly));

        $actual = $this->actualTablePrefixes();

        // 三桶而不是兩桶。`registry_only` 的表是「登記過、但生產庫還沒有」，
        // 一旦某站真的建了出來，混在 only_in_database 裡會被印成「未登記的表」——
        // 那和 registry 直接打架，維護者照訊息去補條目就會撞名覆蓋。
        $onlyInDatabase = array_values(array_diff($actual, $expected, $registryOnly));
        $onlyInRegistry = array_values(array_diff($expected, $actual));
        $registryOnlyPresent = array_values(array_intersect($actual, $registryOnly));

        // 只給表名的話，「這張表本來就只有某一站有」和「這一站真的漏建了」長得一模一樣。
        // 判斷依據寫在 registry 的 kind 與 note 裡，所以把它們一起帶出來。
        $registryContext = array_map(
            static fn (string $prefix): array => [
                'prefix' => $prefix,
                'kind' => (string) $registry[$prefix]['kind'],
                'note' => $registry[$prefix]['note'],
            ],
            $onlyInRegistry,
        );

        $hasDifference = $onlyInDatabase !== [] || $onlyInRegistry !== [] || $registryOnlyPresent !== [];

        if ($this->option('json')) {
            $this->line(json_encode([
                'connection' => DB::connection()->getName(),
                'database' => DB::connection()->getDatabaseName(),
                'registry_total_count' => count($registry),
                'expected_table_count' => count($expected),
                'database_count' => count($actual),
                'only_in_database' => $onlyInDatabase,
                'only_in_registry' => $registryContext,
                'registry_only_present' => $registryOnlyPresent,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $hasDifference ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['項目', '數量'],
            [
                ['連線', DB::connection()->getName()],
                ['資料庫', DB::connection()->getDatabaseName()],
                ['registry 條目（排除 registry_only）', (string) count($expected)],
                ['資料庫實際表數', (string) count($actual)],
                ['DB 有、registry 沒有', (string) count($onlyInDatabase)],
                ['registry 有、DB 沒有', (string) count($onlyInRegistry)],
                ['registry_only 但 DB 已有', (string) count($registryOnlyPresent)],
            ],
        );

        foreach ($onlyInDatabase as $prefix) {
            $this->warn(sprintf('  未登記的表：%s_bet_records', $prefix));
        }

        foreach ($registryOnlyPresent as $prefix) {
            $this->warn(sprintf(
                '  registry_only 條目已實際建表：%s_bet_records'
                . ' —— 請把它的 kind 改為 vendor，並同步 PRODUCTION_TABLE_UNION_COUNT 與 REGISTRY_ONLY_COUNT',
                $prefix,
            ));
        }

        foreach ($registryContext as $entry) {
            $this->warn(sprintf(
                '  registry 有但本站沒有：%s_bet_records（%s）%s',
                $entry['prefix'],
                $entry['kind'],
                $entry['note'] === null ? '' : ' —— ' . $entry['note'],
            ));
        }

        if (!$hasDifference) {
            $this->info('  registry 與資料庫完全一致');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('  ⚠️ 有差異不等於有問題：registry 登記的是六站聯集，單站沒有某張表可能是正常的。');
        $this->comment('     判讀依據看上方每筆附帶的分類與備註；備註寫明「僅某站存在」者屬預期差異。');

        return self::FAILURE;
    }

    /**
     * 當前連線資料庫實際存在的注單表前綴。
     *
     * ⚠️ `LIKE` 的 `\_` 是轉義後的字面底線，不是萬用字元——**不可以寫成 `%_bet_records`**，
     *    那會把 `Xbet_records` 這類表名也撈進來。
     *
     * @return list<string>
     */
    private function actualTablePrefixes(): array
    {
        // 佔位符由常數長度推導，不寫死。EXCLUDED_TABLES 是可變長度而 registry 檔頭
        // 已明文預告它可能被改動——寫死 (?, ?) 的話，加一張表就會 Invalid parameter number。
        $placeholders = implode(', ', array_fill(0, count(self::EXCLUDED_TABLES), '?'));

        $rows = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?"
            . " AND TABLE_NAME NOT IN ({$placeholders}) ORDER BY TABLE_NAME",
            ['%\_bet\_records', ...self::EXCLUDED_TABLES],
        );

        return array_map(
            static fn (object $row): string => (string) preg_replace('/_bet_records$/', '', $row->TABLE_NAME),
            $rows,
        );
    }
}
