<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n;

use App\Data\Report\Sr02nFilter;
use App\Enums\Agent\ReservedAgentIdEnum;
use App\Enums\Agent\RoleEnum;
use App\Enums\Report\Sr02nPairEnum;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * SR02N「篩選」配對 → 各段 where（target/tickets#4356 D5～D8、計劃 A3）。
 *
 * 操作者（op／f）的身分：
 *  - 角色 role(x) ＝ COALESCE(NULLIF(x.role_id, 0), 主帳號.role_id, 0)：子帳號 role 為 0 時以主帳號角色算。
 *  - Admin 線 ＝ x.id = 1 OR x.host_id = 1（{@see \App\Models\Agent::isPlatformAdmin()} 同源）。
 *  - 路徑 path(x) ＝ COALESCE(主帳號.hierarchy_path, x.hierarchy_path)；有效 id ＝ COALESCE(NULLIF(x.host_id, 0), x.id)。
 *  - 總代 11、客服 12、代理 0／21。
 *
 * 代理兩段的對象（t）身分（2026-09-30 定案：對象子帳號依自身角色）：
 *  - 角色直接取 t.role_id、路徑直接取 t.hierarchy_path，不回退主帳號：子帳號 role 12＝客服、0／21＝代理。
 *    例：總代 → 自己的 role 0 子帳號＝總代對代理；總代 → 自己的 role 12 子帳號＝總代對客服。
 *  - 「下級」以子帳號自身路徑（主帳號路徑＋自身 id）判定；若改用主帳號路徑，
 *    「主帳號本人 → 自己的子帳號」永遠不在操作者路徑之下。
 *
 * 規則：
 *  - 必須只在 {@see Sr02nFilter::hasPairs()} 時加 join／where：全不勾時 SQL 形狀與 SR02 一致，利於對帳。
 *  - 多配對為 OR；操作者為 NULL（agent_id=0 的系統派發）時條件整體為 NULL，只在全不勾出現。
 *  - 會員兩段的每個配對（含 admin）必須另外 AND「op 真的是操作者」：`l.operator = op.username`（主錢包）、
 *    `wl.operator = op.id`（遊戲錢包）。理由：transaction_logs／wallet_transaction_logs 的 agent_id 對三方充值、
 *    活動獎勵、自助洗分、系統派發等列存的是**會員上層代理**（TransactionLogCreateService `agentId ?? member->parent`），
 *    不代表操作者；只看 agent_id 會把這些列誤貼成總代／代理／Admin 對會員（dev 實測：八配對聯集＝全不勾列數）。
 *    後台開洗分列一律 l.operator＝操作代理帳號、wl.operator＝操作代理 id，其餘列不是。
 *    代理兩段的 from_agent_id／operator 本來就是操作者，不需此條件。
 *  - SQL 片段只含欄位運算，所有值一律走 binding。
 *
 * @knowledge rules/report-sr02n
 */
readonly class Sr02nPairCondition
{
    /** agents.role_id＝0：未指派角色（一般代理的主力，或沿用主帳號角色的子帳號） */
    public const int UNASSIGNED_ROLE_ID = 0;

    /** agents.host_id＝0：主帳號（非 0 為子帳號，值為主帳號 id） */
    public const int MAIN_ACCOUNT_HOST_ID = 0;

    public const array GENERAL_AGENT_ROLES = [RoleEnum::GENERAL_AGENT->value];

    public const array CUSTOMER_SERVICE_ROLES = [RoleEnum::CUSTOMER_SERVICE->value];

    public const array AGENT_ROLES = [self::UNASSIGNED_ROLE_ID, RoleEnum::AGENT->value];

    /**
     * 會員兩段：op ＝ 紀錄上的代理（`l.agent_id`／`wl.agent_id`），對象 ＝ 會員 m（其代理 a）。
     *
     * @param  string  $agentColumn  紀錄的代理 id 欄（join op 用）
     * @param  string  $operatorColumn  紀錄的操作者欄（`l.operator`／`wl.operator`）
     * @param  string  $operatorKey  操作者欄存的是代理的哪個欄位：`username`（主錢包）或 `id`（遊戲錢包）
     */
    public function applyToMemberSegment(
        Builder $query,
        Sr02nFilter $filter,
        string $agentColumn,
        string $operatorColumn,
        string $operatorKey,
    ): void {
        if (!$filter->hasPairs()) {
            return;
        }

        $query->leftJoin('agents as op', 'op.id', '=', $agentColumn)
            ->leftJoin('agents as oph', 'oph.id', '=', 'op.host_id');

        $this->whereAnyPair($query, array_map(
            fn (Sr02nPairEnum $pair): array => $this->memberPairSql($pair, $operatorColumn, $operatorKey),
            $filter->memberPairs(),
        ));
    }

    /**
     * 代理兩段：op ＝ f（額度段 from_agent_id／佔成段 operator），對象 ＝ t；呼叫前 f、t 須已 join。
     */
    public function applyToAgentSegment(Builder $query, Sr02nFilter $filter): void
    {
        if (!$filter->hasPairs()) {
            return;
        }

        // 對象 t 依自身 role_id／hierarchy_path 判定，不需 join 其主帳號
        $query->leftJoin('agents as fh', 'fh.id', '=', 'f.host_id');

        $this->whereAnyPair($query, array_map(
            fn (Sr02nPairEnum $pair): array => $this->agentPairSql($pair),
            $filter->agentPairs(),
        ));
    }

    /**
     * @return array{0: string, 1: array<int, int|string>}
     */
    public function memberPairSql(Sr02nPairEnum $pair, string $operatorColumn, string $operatorKey): array
    {
        if (!$pair->targetsMember()) {
            return ['0 = 1', []];
        }

        $isOperator = [$this->isOperator($operatorColumn, $operatorKey), []];

        return match ($pair) {
            Sr02nPairEnum::GA_TO_MEMBER => $this->all(
                $isOperator,
                $this->notAdmin('op'),
                $this->roleIn('op', 'oph', self::GENERAL_AGENT_ROLES),
                $this->inLine('a', 'op', 'oph'),
            ),
            Sr02nPairEnum::CS_TO_MEMBER => $this->all(
                $isOperator,
                $this->notAdmin('op'),
                $this->roleIn('op', 'oph', self::CUSTOMER_SERVICE_ROLES),
                $this->inLine('a', 'op', 'oph'),
            ),
            Sr02nPairEnum::AGENT_TO_MEMBER => $this->all(
                $isOperator,
                $this->notAdmin('op'),
                $this->roleIn('op', 'oph', self::AGENT_ROLES),
                ['m.parent = COALESCE(NULLIF(op.host_id, ?), op.id)', [self::MAIN_ACCOUNT_HOST_ID]],
            ),
            default => $this->all($isOperator, $this->isAdmin('op')),
        };
    }

    /**
     * 「op 真的是這筆的操作者」：純欄位比對；$operatorKey 只允許 username／id（呼叫端常量，非外部輸入）。
     */
    private function isOperator(string $operatorColumn, string $operatorKey): string
    {
        $key = match ($operatorKey) {
            'username', 'id' => $operatorKey,
            default => throw new InvalidArgumentException('operatorKey must be username or id'),
        };

        return "{$operatorColumn} = op.{$key}";
    }

    /**
     * @return array{0: string, 1: array<int, int|string>}
     */
    public function agentPairSql(Sr02nPairEnum $pair): array
    {
        return match ($pair) {
            Sr02nPairEnum::GA_TO_CS => $this->all(
                $this->notAdmin('f'),
                $this->roleIn('f', 'fh', self::GENERAL_AGENT_ROLES),
                $this->targetRoleIn(self::CUSTOMER_SERVICE_ROLES),
                $this->inLine('t', 'f', 'fh'),
            ),
            Sr02nPairEnum::GA_TO_AGENT => $this->agentToAgent(self::GENERAL_AGENT_ROLES),
            Sr02nPairEnum::CS_TO_AGENT => $this->agentToAgent(self::CUSTOMER_SERVICE_ROLES),
            Sr02nPairEnum::AGENT_TO_AGENT => $this->agentToAgent(self::AGENT_ROLES),
            Sr02nPairEnum::ADMIN => $this->isAdmin('f'),
            default => ['0 = 1', []],
        };
    }

    /**
     * 操作者角色 ∈ $operatorRoles、對象為代理（0／21）且在操作者路徑之下（不限直屬）。
     *
     * @param  array<int, int>  $operatorRoles
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function agentToAgent(array $operatorRoles): array
    {
        return $this->all(
            $this->notAdmin('f'),
            $this->roleIn('f', 'fh', $operatorRoles),
            $this->targetRoleIn(self::AGENT_ROLES),
            ['t.hierarchy_path LIKE CONCAT(' . $this->path('f', 'fh') . ', ?)', ['/%']],
        );
    }

    /**
     * @param  array<int, array{0: string, 1: array<int, int|string>}>  $conditions
     */
    private function whereAnyPair(Builder $query, array $conditions): void
    {
        // 段內無適用配對時該段本應不併（Segment::enabled），此處防呆：寧可無資料也不放行全部
        if ($conditions === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $where) use ($conditions) {
            foreach ($conditions as [$sql, $bindings]) {
                $where->orWhereRaw('(' . $sql . ')', $bindings);
            }
        });
    }

    /**
     * @param  array{0: string, 1: array<int, int|string>}  ...$parts
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function all(array ...$parts): array
    {
        return [
            implode(' AND ', array_column($parts, 0)),
            array_merge(...array_column($parts, 1)),
        ];
    }

    /** @return array{0: string, 1: array<int, int|string>} */
    private function isAdmin(string $alias): array
    {
        $adminId = ReservedAgentIdEnum::ADMIN->value;

        return ["({$alias}.id = ? OR {$alias}.host_id = ?)", [$adminId, $adminId]];
    }

    /** @return array{0: string, 1: array<int, int|string>} */
    private function notAdmin(string $alias): array
    {
        [$sql, $bindings] = $this->isAdmin($alias);

        return ['NOT ' . $sql, $bindings];
    }

    /**
     * @param  array<int, int>  $roles
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function roleIn(string $alias, string $hostAlias, array $roles): array
    {
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));

        return [
            "COALESCE(NULLIF({$alias}.role_id, ?), {$hostAlias}.role_id, ?) IN ({$placeholders})",
            [self::UNASSIGNED_ROLE_ID, self::UNASSIGNED_ROLE_ID, ...$roles],
        ];
    }

    /**
     * 代理段對象 t 的角色：直接取自身 role_id（子帳號不回退主帳號，2026-09-30 定案）。
     *
     * @param  array<int, int>  $roles
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function targetRoleIn(array $roles): array
    {
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));

        return ["t.role_id IN ({$placeholders})", array_values($roles)];
    }

    /**
     * 對象 $target 的 hierarchy_path 為操作者路徑本身或其線下。
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function inLine(string $target, string $operator, string $operatorHost): array
    {
        $path = $this->path($operator, $operatorHost);

        return ["({$target}.hierarchy_path = {$path} OR {$target}.hierarchy_path LIKE CONCAT({$path}, ?))", ['/%']];
    }

    private function path(string $alias, string $hostAlias): string
    {
        return "COALESCE({$hostAlias}.hierarchy_path, {$alias}.hierarchy_path)";
    }
}
