<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecord;

use Illuminate\Support\Facades\Artisan;
use JsonException;
use Tests\TestCase;

/**
 * bet-record:audit-writers 的行為測試。
 *
 * 這支命令的退出碼是 Gate B（簡報 Gate 2）第 4 項的機器判定：
 * 0 ＝ 所有 writer 都已登記、都有 owner、都有並行防護規則。
 *
 * 🔴 不要在這裡加 RefreshDatabase：它對 MySQL 測試庫會 drop 全部資料表重建。
 *    父類 Tests\TestCase 已 use DatabaseTransactions。本命令全程不碰資料庫。
 *
 * ⚠️ 與 AuditTablesCommandTest 的差別：那支的比對對象是「當前連線資料庫的實際表」，
 *    是會變動的外部狀態所以刻意不斷言絕對值；**本支讀的是版控內的 registry**，
 *    因此可以斷言得精確。但仍不硬編 307 這類筆數——改用「命令算出來的數字要與直接
 *    讀 registry 算出來的數字一致」，registry 增修時測試不必跟著改。
 *
 * php artisan test tests/Feature/Console/Commands/BetRecord/AuditWritersCommandTest.php
 */
class AuditWritersCommandTest extends TestCase
{
    public function test_command_reports_coverage_by_section(): void
    {
        $this->artisan('bet-record:audit-writers')
            ->expectsOutputToContain('sp')
            ->expectsOutputToContain('php')
            ->expectsOutputToContain('external');
    }

    public function test_command_reports_gate_b_verdict(): void
    {
        $this->artisan('bet-record:audit-writers')
            ->expectsOutputToContain('Gate B');
    }

    /**
     * @throws JsonException
     */
    public function test_json_counts_match_the_registry_file(): void
    {
        $payload = $this->auditAsJson();

        /** @var array<string, array<string, array<string, mixed>>> $registry */
        $registry = require database_path('data/bet_records/writers.php');

        foreach (['sp', 'php', 'external'] as $section) {
            $this->assertArrayHasKey($section, $payload['sections'], sprintf('--json 輸出缺少 %s 區段', $section));

            $this->assertSame(
                count($registry[$section]),
                $payload['sections'][$section]['total'],
                sprintf('%s 區段的 writer 總數與 registry 檔案不一致', $section),
            );
        }

        $this->assertSame(
            count($registry['sp']) + count($registry['php']) + count($registry['external']),
            $payload['total'],
            'total 不等於三個區段的加總',
        );
    }

    /**
     * @throws JsonException
     */
    public function test_gate_b_verdict_is_consistent_with_the_gap_lists(): void
    {
        $payload = $this->auditAsJson();

        // 退出碼是 Gate B 的機器判定，不能與它自己報出來的缺口清單打架——
        // 「缺口清單是空的但判定不通過」或反之，都會讓下游照著錯的訊號動作。
        $this->assertSame(
            $payload['missing_owner'] === [] && $payload['missing_fencing'] === [],
            $payload['gate_b_clear'],
            'gate_b_clear 與缺口清單不一致',
        );

        $this->assertSame(
            $payload['gate_b_clear'] ? 0 : 1,
            Artisan::call('bet-record:audit-writers', ['--json' => true]),
            '退出碼與 gate_b_clear 不一致',
        );
    }

    /**
     * @throws JsonException
     */
    public function test_gap_lists_match_an_independent_recount(): void
    {
        $payload = $this->auditAsJson();

        /** @var array<string, array<string, array<string, mixed>>> $registry */
        $registry = require database_path('data/bet_records/writers.php');

        $missingOwner = [];
        $missingFencing = [];

        foreach (['sp', 'php', 'external'] as $section) {
            foreach ($registry[$section] as $name => $entry) {
                $owner = $entry['owner'] ?? [];

                if (!is_array($owner) || $owner === [] || in_array('待查', $owner, true)) {
                    $missingOwner[] = sprintf('%s.%s', $section, $name);
                }

                $fencing = $entry['fencing'] ?? null;

                if (!is_string($fencing) || trim($fencing) === '') {
                    $missingFencing[] = sprintf('%s.%s', $section, $name);
                }
            }
        }

        // 缺口清單是拿去逐筆修的工作清單，也是 Gate B 判定的依據。這裡獨立重算一次
        // 再比對，而不是只檢查「命令報出來的 key 存在」——後者在缺口為空時零斷言，
        // 測試會變成 risky 而且什麼都沒守到。
        $this->assertSame($missingOwner, $payload['missing_owner'], '缺 owner 清單與 registry 重算結果不一致');
        $this->assertSame($missingFencing, $payload['missing_fencing'], '缺並行防護清單與 registry 重算結果不一致');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function auditAsJson(): array
    {
        Artisan::call('bet-record:audit-writers', ['--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
