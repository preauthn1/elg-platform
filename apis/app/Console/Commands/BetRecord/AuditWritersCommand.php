<?php

declare(strict_types=1);

namespace App\Console\Commands\BetRecord;

use Illuminate\Console\Command;
use JsonException;

/**
 * TBR／SBR writer 覆蓋率稽核。
 *
 * 🔴 本命令的退出碼就是 Gate B（簡報 Gate 2）第 4 項的機器判定：
 *    0 ＝ registry 內每一筆 writer 都有 owner、也都有並行防護規則。
 *    Gate B 的前三項（找全 writer、指定 owner、指定並行防護）沒有機器判定，
 *    現況記在 docs/multi-currency-wallet-phase0-contract.md 第六節。
 *
 * ⚠️ 「退出碼 0」只代表技術條件滿足，不代表閘門正式解除——並行防護規則必須被
 *    兩個專案的負責人認可，最終由 RB 拍板。
 *
 * ⚠️ 與 bet-record:audit-tables 不同，本命令**全程不碰資料庫**，比對對象是版控內的
 *    database/data/bet_records/writers.php。因此在任何白標、任何連線下結果都一樣。
 */
class AuditWritersCommand extends Command
{
    protected $signature = 'bet-record:audit-writers
                            {--json : 以 JSON 輸出，供後續階段的工具串接}';

    protected $description = 'TBR／SBR writer 覆蓋率稽核：檢查每個 writer 是否都有 owner 與並行防護規則（Gate B 第 4 項的機器判定）';

    /**
     * registry 的區段，順序即輸出順序。
     *
     * 🔴 與 BetRecordWriterRegistryParityTest 的區段清單必須同步——那支測試守的是
     *    「registry 檔案有這三段且每筆結構完整」，本命令守的是「內容有沒有填完」。
     *    少一段會讓稽核在不知情的狀況下漏掉整批 writer。
     */
    private const array SECTIONS = ['sp', 'php', 'external'];

    /**
     * 代表「尚未查明」的 owner 值，登記了也不算數。
     *
     * 見 writers.php 檔頭的 owner 值域說明。
     */
    private const string OWNER_UNKNOWN = '待查';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        /** @var array<string, array<string, array<string, mixed>>> $registry */
        $registry = require database_path('data/bet_records/writers.php');

        $sections = [];
        $missingOwner = [];
        $missingFencing = [];

        foreach (self::SECTIONS as $section) {
            $entries = $registry[$section] ?? [];
            $withOwner = 0;
            $withFencing = 0;

            foreach ($entries as $name => $entry) {
                if ($this->hasOwner($entry)) {
                    $withOwner++;
                } else {
                    $missingOwner[] = sprintf('%s.%s', $section, $name);
                }

                if ($this->hasFencing($entry)) {
                    $withFencing++;
                } else {
                    $missingFencing[] = sprintf('%s.%s', $section, $name);
                }
            }

            $sections[$section] = [
                'total' => count($entries),
                'with_owner' => $withOwner,
                'with_fencing' => $withFencing,
            ];
        }

        $clear = $missingOwner === [] && $missingFencing === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'sections' => $sections,
                'total' => array_sum(array_column($sections, 'total')),
                'missing_owner' => $missingOwner,
                'missing_fencing' => $missingFencing,
                'gate_b_clear' => $clear,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $clear ? self::SUCCESS : self::FAILURE;
        }

        $this->renderTable($sections);
        $this->renderGaps($registry, $missingOwner, $missingFencing);

        if ($clear) {
            $this->info('  Gate B 第 4 項通過：每一筆 writer 都有 owner 與並行防護規則');
            $this->line('  ⚠️ 技術條件滿足不等於閘門正式解除，並行防護規則仍需兩個專案的負責人認可');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '  Gate B 未解除：缺 owner %d 筆、缺並行防護 %d 筆（完整清單用 --json）',
            count($missingOwner),
            count($missingFencing),
        ));

        return self::FAILURE;
    }

    /**
     * owner 是陣列而不是字串（見 writers.php 檔頭），空陣列與含「待查」都不算已指定。
     *
     * @param  array<string, mixed>  $entry
     */
    private function hasOwner(array $entry): bool
    {
        $owner = $entry['owner'] ?? [];

        if (!is_array($owner) || $owner === []) {
            return false;
        }

        return !in_array(self::OWNER_UNKNOWN, $owner, true);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function hasFencing(array $entry): bool
    {
        $fencing = $entry['fencing'] ?? null;

        return is_string($fencing) && trim($fencing) !== '';
    }

    /**
     * @param  array<string, array<string, int>>  $sections
     */
    private function renderTable(array $sections): void
    {
        $rows = [];

        foreach ($sections as $section => $counts) {
            $rows[] = [
                $section,
                (string) $counts['total'],
                $this->ratio($counts['with_owner'], $counts['total']),
                $this->ratio($counts['with_fencing'], $counts['total']),
            ];
        }

        $this->table(['區段', 'writer 數', '已指定 owner', '已指定並行防護'], $rows);
    }

    /**
     * 缺口逐筆印出來會有數百行、沒人讀得完，所以人看的模式印按目標表分組的摘要，
     * 完整清單留給 --json（那是拿去逐筆修的工作清單）。
     *
     * @param  array<string, array<string, array<string, mixed>>>  $registry
     * @param  list<string>  $missingOwner
     * @param  list<string>  $missingFencing
     */
    private function renderGaps(array $registry, array $missingOwner, array $missingFencing): void
    {
        foreach (['owner' => $missingOwner, '並行防護' => $missingFencing] as $label => $gaps) {
            if ($gaps === []) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('  缺 %s 的 %d 筆，依「區段 × 目標表」分組：', $label, count($gaps)));

            foreach ($this->groupByTarget($registry, $gaps) as $group => $count) {
                $this->line(sprintf('    %-40s %d', $group, $count));
            }
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $registry
     * @param  list<string>  $gaps
     * @return array<string, int>
     */
    private function groupByTarget(array $registry, array $gaps): array
    {
        $grouped = [];

        foreach ($gaps as $reference) {
            [$section, $name] = explode('.', $reference, 2);

            $target = (string) ($registry[$section][$name]['target'] ?? '未知');
            $key = sprintf('%s / %s', $section, $target);

            $grouped[$key] = ($grouped[$key] ?? 0) + 1;
        }

        ksort($grouped);

        return $grouped;
    }

    private function ratio(int $done, int $total): string
    {
        return $total === 0
            ? '0 / 0'
            : sprintf('%d / %d', $done, $total);
    }
}
