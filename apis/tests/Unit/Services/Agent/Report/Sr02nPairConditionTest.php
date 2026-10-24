<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent\Report;

use App\Data\Report\Sr02nFilter;
use App\Enums\Report\Sr02nPairEnum;
use App\Enums\Report\Sr02nSourceEnum;
use App\Services\Agent\Report\Sr02n\Sr02nPairCondition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * SR02N 配對條件的 SQL 形狀與 bindings（target/tickets#4356）。
 *
 * php artisan test tests/Unit/Services/Agent/Report/Sr02nPairConditionTest.php
 */
class Sr02nPairConditionTest extends TestCase
{
    /** @param  array<int, Sr02nPairEnum>  $pairs */
    private function filter(array $pairs): Sr02nFilter
    {
        return new Sr02nFilter(
            agentUsername: '',
            memberUsername: '',
            memberNickname: '',
            transactionNo: '',
            executionUsername: '',
            source: Sr02nSourceEnum::ALL,
            cashFlowId: null,
            agentCashFlowId: null,
            orderTypeId: null,
            startUtc: '2026-09-01 00:00:00',
            endUtc: '2026-09-01 23:59:59',
            isCreatedPeriod: false,
            pairs: $pairs,
        );
    }

    private function memberQuery(): Builder
    {
        return DB::query()->from('transaction_logs as l');
    }

    private function agentQuery(): Builder
    {
        return DB::query()->from('transaction_log_agents as la')
            ->join('agents as t', 't.id', '=', 'la.to_agent_id')
            ->join('agents as f', 'f.id', '=', 'la.from_agent_id');
    }

    #[TestDox('無配對：不加 join、不加 where（SQL 形狀與 SR02 一致）')]
    public function test_no_pairs_adds_nothing(): void
    {
        $condition = new Sr02nPairCondition;

        $member = $this->memberQuery();
        $condition->applyToMemberSegment($member, $this->filter([]), 'l.agent_id', 'l.operator', 'username');
        $this->assertSame($this->memberQuery()->toSql(), $member->toSql());

        $agent = $this->agentQuery();
        $condition->applyToAgentSegment($agent, $this->filter([]));
        $this->assertSame($this->agentQuery()->toSql(), $agent->toSql());
    }

    #[TestDox('會員段：加 op／oph 兩個 LEFT JOIN，配對以 OR 串接')]
    public function test_member_segment_joins_and_or(): void
    {
        $query = $this->memberQuery();
        (new Sr02nPairCondition)->applyToMemberSegment(
            $query,
            $this->filter([Sr02nPairEnum::GA_TO_MEMBER, Sr02nPairEnum::AGENT_TO_MEMBER]),
            'l.agent_id',
            'l.operator',
            'username',
        );
        $sql = $query->toSql();

        $this->assertStringContainsString('left join `agents` as `op` on `op`.`id` = `l`.`agent_id`', $sql);
        $this->assertStringContainsString('left join `agents` as `oph` on `oph`.`id` = `op`.`host_id`', $sql);
        $this->assertStringContainsString(') or (', $sql);
        $this->assertStringContainsString('m.parent = COALESCE(NULLIF(op.host_id, ?), op.id)', $sql);
        // 每個會員配對都要求 op 真的是操作者（agent_id 對系統列是會員上層代理）
        $this->assertSame(2, substr_count($sql, '(l.operator = op.username AND NOT (op.id = ? OR op.host_id = ?)'));
        // ga_member：notAdmin[1,1] ＋ role[0,0,11] ＋ inLine['/%']；agent_member：notAdmin[1,1] ＋ role[0,0,0,21] ＋ host[0]
        $this->assertSame([1, 1, 0, 0, 11, '/%', 1, 1, 0, 0, 0, 21, 0], $query->getBindings());
    }

    #[TestDox('代理段：只加操作者主帳號 fh 的 LEFT JOIN；admin 只判操作者是否 Admin 線')]
    public function test_agent_segment_admin(): void
    {
        $query = $this->agentQuery();
        (new Sr02nPairCondition)->applyToAgentSegment($query, $this->filter([Sr02nPairEnum::ADMIN]));
        $sql = $query->toSql();

        $this->assertStringContainsString('left join `agents` as `fh` on `fh`.`id` = `f`.`host_id`', $sql);
        // 對象 t 依自身角色／路徑判定（2026-09-30 定案），不再 join 其主帳號
        $this->assertStringNotContainsString('`th`', $sql);
        $this->assertStringContainsString('((f.id = ? OR f.host_id = ?))', $sql);
        $this->assertSame([1, 1], $query->getBindings());
    }

    /**
     * @return array<string, array{0: Sr02nPairEnum, 1: string, 2: array<int, int|string>}>
     */
    public static function agentPairs(): array
    {
        return [
            'ga_cs' => [
                Sr02nPairEnum::GA_TO_CS,
                't.hierarchy_path = COALESCE(fh.hierarchy_path, f.hierarchy_path)',
                [1, 1, 0, 0, 11, 12, '/%'],
            ],
            'ga_agent' => [
                Sr02nPairEnum::GA_TO_AGENT,
                't.hierarchy_path LIKE CONCAT(COALESCE(fh.hierarchy_path, f.hierarchy_path), ?)',
                [1, 1, 0, 0, 11, 0, 21, '/%'],
            ],
            'cs_agent' => [
                Sr02nPairEnum::CS_TO_AGENT,
                'COALESCE(NULLIF(f.role_id, ?), fh.role_id, ?) IN (?)',
                [1, 1, 0, 0, 12, 0, 21, '/%'],
            ],
            'agent_agent' => [
                Sr02nPairEnum::AGENT_TO_AGENT,
                'COALESCE(NULLIF(f.role_id, ?), fh.role_id, ?) IN (?, ?)',
                [1, 1, 0, 0, 0, 21, 0, 21, '/%'],
            ],
        ];
    }

    /** @param  array<int, int|string>  $bindings */
    #[DataProvider('agentPairs')]
    #[TestDox('代理段各配對的 SQL 片段與 bindings 順序')]
    public function test_agent_pair_sql(Sr02nPairEnum $pair, string $fragment, array $bindings): void
    {
        [$sql, $actual] = (new Sr02nPairCondition)->agentPairSql($pair);

        $this->assertStringStartsWith('NOT (f.id = ? OR f.host_id = ?)', $sql);
        $this->assertStringContainsString($fragment, $sql);
        $this->assertSame($bindings, $actual);
        // 對象 t 直接取自身 role_id／hierarchy_path，不回退主帳號
        $this->assertStringNotContainsString('th.', $sql);
        $this->assertStringNotContainsString('NULLIF(t.role_id', $sql);
    }

    #[TestDox('遊戲錢包段 admin 配對：以 wl.operator = op.id 比對操作者，再判 Admin 線')]
    public function test_game_segment_admin_requires_operator_id(): void
    {
        [$sql, $bindings] = (new Sr02nPairCondition)->memberPairSql(Sr02nPairEnum::ADMIN, 'wl.operator', 'id');

        $this->assertSame('wl.operator = op.id AND (op.id = ? OR op.host_id = ?)', $sql);
        $this->assertSame([1, 1], $bindings);
    }

    #[TestDox('operatorKey 只允許 username／id')]
    public function test_operator_key_whitelist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Sr02nPairCondition)->memberPairSql(Sr02nPairEnum::ADMIN, 'l.operator', 'password');
    }

    #[TestDox('配對套到不適用的段：回 0 = 1（防呆，寧可無資料）')]
    public function test_inapplicable_pairs_match_nothing(): void
    {
        $query = $this->memberQuery();
        (new Sr02nPairCondition)->applyToMemberSegment($query, $this->filter([Sr02nPairEnum::GA_TO_CS]), 'l.agent_id', 'l.operator', 'username');

        $this->assertStringContainsString('0 = 1', $query->toSql());
        $this->assertSame(['0 = 1', []], (new Sr02nPairCondition)->memberPairSql(Sr02nPairEnum::GA_TO_CS, 'l.operator', 'username'));
    }
}
